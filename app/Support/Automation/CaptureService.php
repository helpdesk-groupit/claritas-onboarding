<?php

namespace App\Support\Automation;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowCapture;
use App\Models\EmailWorkflowConnection;
use App\Models\EmailWorkflowRun;
use App\Support\Automation\Contracts\EmailSourceAdapter;
use App\Support\Automation\Contracts\LogAdapter;
use App\Support\Automation\Contracts\StorageAdapter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The capture runtime: mailbox → detection → storage → log.
 *
 * Depends only on the adapter contracts, so it is provider-independent and
 * testable with a faked HTTP layer.
 *
 * Delivery semantics are at-least-once with DB-enforced dedupe. Each attachment
 * claims a row in email_workflow_captures (UNIQUE on workflow + key_hash) before
 * any bytes move, then walks pending → stored → logged. A crash mid-way leaves a
 * resumable row rather than either a duplicate or a silently dropped document —
 * the next run picks it up at the step it reached. The Drive filename check and
 * the sheet's Message ID column are the second and third lines of defence.
 */
class CaptureService
{
    /** Lookback window. Matches the "Test rules" preview so results agree. */
    public const DEFAULT_SINCE_DAYS = 30;

    /**
     * Messages per PASS; 0 = unlimited. A memory bound, not a coverage bound.
     *
     * It used to be both, and that is what silently lost mail: a sweep read the
     * newest 500 and stopped, so a mailbox taking more than 500 a day left the
     * gap between two runs read by neither, and the window then carried it away.
     * A sweep now keeps asking for older slices until it reaches back past the
     * previous run's coverage — see execute(). 500 stays 500 because it is the
     * measured-safe slice (~40MB held, ~60s on Graph), and each pass is released
     * before the next is fetched, so peak memory tracks one pass however many
     * passes a sweep needs.
     */
    public const DEFAULT_MESSAGE_LIMIT = 500;

    /** Seconds a synchronous "Run now" may take before PHP kills it. */
    public const REQUEST_TIME_LIMIT = 900;

    /**
     * Passes a sweep may make before it gives up on reaching its coverage
     * target, and the wall-clock budget that usually binds first.
     *
     * Backstops, not stopping points: in the steady state a sweep needs one or
     * two passes. The budget must stay well under STALE_RUN_MINUTES (or a
     * healthy long sweep gets reaped as dead while still working) and under
     * RunEmailWorkflowCapture::$timeout (or the worker kills it before the
     * budget can record anything). Neither bounds the first pass — a sweep
     * always makes at least one, exactly as it always did.
     */
    public const DEFAULT_MAX_PASSES = 40;

    public const DEFAULT_MAX_SWEEP_SECONDS = 2400;

    /**
     * Messages each pass re-reads from the end of the previous one.
     *
     * An offset is a position in a LIVE mailbox. Mail arriving mid-sweep shifts
     * everything down, which is harmless (a re-read, which the captures table
     * dedupes away), but a deletion shifts the other way and would slide a
     * message through the seam unread — and nothing would ever look at it again,
     * because the next run starts from the newest. The overlap costs a handful
     * of duplicate reads per pass and closes the seam.
     */
    public const DEFAULT_PASS_OVERLAP = 10;

    /**
     * After this long, a run still marked `running` is presumed dead.
     *
     * A sweep can be killed in ways its own try/catch can never see: a PHP OOM
     * (fatal, uncatchable), a queue worker timeout, SIGHUP when the shell that
     * launched it goes away. The row then says `running` forever and the list
     * page shows a workflow that looks busy but isn't — the same class of lie as
     * a green tick over a broken step. Generous, because an unlimited sweep on a
     * large mailbox legitimately takes a long time and must never be declared
     * dead while it is still working.
     */
    public const STALE_RUN_MINUTES = 180;

    /**
     * Floor for a sweep's memory, raised only if the ambient limit is lower.
     *
     * A capture is a batch job that pulls whole messages off a mailbox, and PHP's
     * CLI default here is 128M — which is what the SCHEDULER runs under, so it is
     * the unattended path that dies first. Paged fetching keeps a normal sweep
     * around 40M, but one oversized message (a fat PDF, a video) is enough to
     * exhaust 128M inside the IMAP read loop, and a PHP OOM is a fatal error: it
     * cannot be caught, so the run row is orphaned in `running` with no error.
     * Headroom is the only defence available at this layer.
     */
    public const MEMORY_FLOOR = '512M';

    /** Gmail label applied to captured mail. Best-effort; never fails a run. */
    public const PROCESSED_LABEL = 'Claritas/Captured';

    public function __construct(
        private readonly EmailAdapterFactory $emailFactory,
        private readonly DestinationAdapterFactory $destFactory,
        private readonly DetectionEngine $engine,
    ) {}

    /**
     * Execute one capture run. Never throws: a fatal error is recorded on the
     * run (and the workflow) and returned, so callers — job, command, or
     * controller — have a uniform result to report.
     *
     * $catchUp makes the sweep target the whole lookback window instead of the
     * previous run's coverage, and drops the pass/time budget with it. That is
     * for the deliberate, operator-initiated recovery of a backlog a capped
     * sweep left behind (`email-workflows:run --catch-up`), which is inherently
     * one full read of the window — minutes of work nobody should trigger by
     * accident, and which must not be bounded by a budget sized for the nightly
     * case. Ordinary runs never set it.
     */
    public function run(
        EmailWorkflow $workflow,
        string $trigger = EmailWorkflowRun::TRIGGER_MANUAL,
        ?int $userId = null,
        bool $catchUp = false,
    ): EmailWorkflowRun {
        $this->raiseMemoryFloor();
        $this->reapStaleRuns($workflow);

        $run = EmailWorkflowRun::create([
            'email_workflow_id' => $workflow->id,
            'trigger' => $trigger,
            'triggered_by' => $userId,
            'status' => EmailWorkflowRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $this->execute($workflow, $run, $catchUp);
        } catch (Throwable $e) {
            // Fatal: auth, unreachable folder/sheet, misconfiguration.
            //
            // Log the origin, not just the message. Libraries wrap (webklex
            // rethrows everything as GetMessagesFailedException in
            // curate_messages), so the message alone names the wrapper and hides
            // both the real fault and the line that raised it — which cost a long
            // debugging detour on exactly that exception.
            Log::warning('Email Workflow capture run failed', [
                'workflow_id' => $workflow->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'at' => $e->getFile().':'.$e->getLine(),
                'root_cause' => $e->getPrevious()
                    ? $e->getPrevious()::class.': '.$e->getPrevious()->getMessage()
                        .' @ '.$e->getPrevious()->getFile().':'.$e->getPrevious()->getLine()
                    : null,
            ]);

            // The email connection is what a run-level failure is usually about
            // (login refused, mailbox unreachable), so let the diagnosis name it.
            $message = $this->safeMessage($e, $workflow->emailConnection);

            $run->update([
                'status' => EmailWorkflowRun::STATUS_FAILED,
                'error' => $message,
                'finished_at' => now(),
            ]);

            $workflow->forceFill([
                'last_run_at' => now(),
                'last_error' => $message,
            ])->save();

            // An active workflow that can't run is an error state the list must show.
            $this->transitionStatus($workflow, EmailWorkflow::STATUS_ACTIVE, EmailWorkflow::STATUS_ERROR);

            return $run->refresh();
        }

        // Unreadable messages count towards `partial` as much as failed
        // attachments do: both mean the sweep finished without doing its whole
        // job. Leaving them out is what let a run drop documents and still
        // present the green badge the operator reads as "nothing to see".
        //
        // An unclosed coverage gap is the third member of that family, and the
        // one this module spent weeks getting wrong: it means the sweep KNOWS
        // there is mail behind it that no run has read. Green over that is the
        // same lie in a different place. It should also be rare now — the sweep
        // closes the ordinary gap itself — so it stays worth noticing.
        $run->update([
            'status' => ($run->failed_count > 0 || $run->unreadable_count > 0 || $run->coverage_gap_from !== null)
                ? EmailWorkflowRun::STATUS_PARTIAL
                : EmailWorkflowRun::STATUS_SUCCESS,
            'finished_at' => now(),
        ]);

        $workflow->forceFill([
            'last_run_at' => now(),
            'last_error' => null,
            'captured_count' => (int) $workflow->captured_count + $run->captured_count,
        ])->save();

        // Clearing last_error is not enough: `error` is a STATUS, and the list
        // badge and the scheduler both read it. A workflow that has just run
        // green is not in error, and leaving the badge red is the same
        // stale-status lie as a connection claiming `connected` it never earned.
        $this->transitionStatus($workflow, EmailWorkflow::STATUS_ERROR, EmailWorkflow::STATUS_ACTIVE);

        return $run->refresh();
    }

    // ── Pipeline ─────────────────────────────────────────────────────────

    /**
     * Sweep the mailbox in limit-sized passes until coverage is complete.
     *
     * The loop is the fix for the cap silently becoming a coverage bound. One
     * pass reads the newest `limit` messages; if that did not reach back as far
     * as the previous run covered, the next pass asks for the slice behind it,
     * and so on. Each pass is processed and released before the next is
     * fetched, so peak memory is one pass regardless of how many run.
     *
     * Every exit is deliberate, and which one fired decides whether the run
     * reports a gap:
     *  - the window ran out (a short pass, or an empty one) — complete by
     *    definition, since nothing older exists to read;
     *  - coverage reached the target — the normal, quiet exit;
     *  - no target (the first ever run) — one pass, exactly as before, because
     *    walking a whole mailbox on day one is not what anyone asked for;
     *  - budget spent — the only exit that records an unclosed gap, which the
     *    next run inherits and retries.
     */
    private function execute(EmailWorkflow $workflow, EmailWorkflowRun $run, bool $catchUp = false): void
    {
        [$emailConn, $storageConn, $logConn] = $this->connections($workflow);

        $email = $this->emailFactory->for($emailConn);
        $storage = $this->destFactory->storage($storageConn);
        $logger = $this->destFactory->log($logConn);

        $rules = (array) ($workflow->rules_json ?: EmailWorkflow::DEFAULT_RULES);
        $storageCfg = (array) ($workflow->storage_config_json ?: EmailWorkflow::DEFAULT_STORAGE_CONFIG);
        $logCfg = (array) ($workflow->log_config_json ?: EmailWorkflow::DEFAULT_LOG_CONFIG);

        // Fail fast on destinations, before spending mailbox calls.
        $rootFolder = $storage->resolveFolder($storageConn, (string) ($storageCfg['folder_ref'] ?? ''));
        $target = $logger->resolveTarget($logConn, (string) ($logCfg['target_ref'] ?? ''));

        $limit = $this->messageLimit();
        $query = $this->query($rules);
        $reachBackTo = $this->coverageTarget($workflow, $run, $catchUp);
        // Clamped below the pass size: an overlap >= limit would advance the
        // offset by the max(1, …) floor instead, so a sweep would crawl forward
        // one message at a time, burn its pass budget, and report a gap — a
        // config typo turning into apparent data loss.
        $overlap = max(0, (int) config('email-workflow.pass_overlap', self::DEFAULT_PASS_OVERLAP));
        if ($limit > 0) {
            $overlap = min($overlap, $limit - 1);
        }

        // A catch-up is a deliberate one-off read of the whole window; budgeting
        // it would just stop it half way and leave the operator to guess.
        $maxPasses = $catchUp ? PHP_INT_MAX : max(1, (int) config('email-workflow.max_passes', self::DEFAULT_MAX_PASSES));
        $deadline = $catchUp
            ? null
            : now()->addSeconds(max(60, (int) config('email-workflow.max_sweep_seconds', self::DEFAULT_MAX_SWEEP_SECONDS)));

        $headers = $this->headers($logCfg);
        $folders = [];   // partition name → folder ref  (one Drive call per month)
        $partitions = []; // partition name → sheet partition ref (one Sheets call per month)

        $offset = 0;
        $passes = 0;
        $oldest = null;              // oldest message this run has examined (outlier-guarded)
        $cursor = null;              // true oldest seen, for date-based continuation
        $lastCursor = null;          // the cursor the previous pass ended on
        $lastPassWasFull = false;    // did the previous pass fill its quota?
        $windowExhausted = false;
        $unreadable = [];

        $stoppedBecause = null;      // why continuation ended, when it ended badly

        while (true) {
            $passes++;

            try {
                $messages = $email->search($emailConn, $query, [
                    'limit' => $limit,
                    'offset' => $offset,
                    // A date cursor alongside the offset. An offset is a position
                    // and every provider caps how deep one may go; a date is a
                    // filter and has no such ceiling. Adapters that can honour it
                    // exactly (Graph) page by this and ignore the offset; the rest
                    // (Gmail's page tokens, IMAP's positions) have no depth limit
                    // of their own and keep using the offset.
                    'before' => $cursor?->toIso8601String(),
                ]);
            } catch (Throwable $e) {
                // The FIRST pass failing means the mailbox itself is unusable —
                // auth, host, folder — and that must fail the run loudly, exactly
                // as it always has.
                if ($passes === 1) {
                    throw $e;
                }

                // A LATER pass is different in kind: pass one already proved the
                // mailbox works and its documents are already captured. Throwing
                // here would discard a good run's work over a catch-up problem
                // (a provider refusing a deep offset, a transient 5xx), and would
                // report the whole sweep as failed when most of it succeeded. So
                // stop, keep everything captured so far, and let the unclosed gap
                // — which is already how "we did not catch up" is expressed — say
                // what happened. The next run inherits the target and retries.
                Log::warning('Email Workflow could not read a continuation pass — keeping what the sweep captured', [
                    'workflow_id' => $workflow->id,
                    'run_id' => $run->id,
                    'pass' => $passes,
                    'offset' => $offset,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);

                $passes--;   // this pass read nothing; do not count it as work done
                $stoppedBecause = 'the mailbox refused the request for older mail ('
                    .$this->safeMessage($e, $emailConn).')';

                break;
            }

            // A message the adapter could not read never reaches the engine, so it
            // is invisible to scanned_count — which counts what the adapter
            // RETURNED. That is how ~20 documents a day went missing under a green
            // tick. Read per pass and BEFORE any early exit, or an empty pass would
            // throw away the report of what it failed on.
            $passUnreadable = $email->unreadableMessages();
            if ($passUnreadable !== []) {
                $unreadable = array_merge($unreadable, $passUnreadable);
                $run->increment('unreadable_count', count($passUnreadable));
            }

            if ($messages === []) {
                // Empty normally means the window ran out. After a FULL pass it
                // means something else, and reading the two as one is how a
                // provider limit turns into silent data loss: Microsoft Graph
                // stops honouring `$skip` at roughly a thousand messages and
                // answers with an empty page rather than an error, so a sweep
                // deep in a large mailbox was told "no more mail" while July was
                // still sitting underneath it — and recorded full coverage over
                // it, in green. Verified live on admin@claritas.asia.
                //
                // Mid-window, therefore, an empty page is a TRUNCATION. Record it
                // as an unclosed gap so the next run retries and the badge says
                // so. A window whose size happens to land exactly on a pass
                // boundary is misreported as truncated by this, which costs one
                // amber badge and one wasted pass — the opposite mistake costs
                // documents, so the asymmetry is deliberate.
                if ($lastPassWasFull) {
                    $passes--;   // this pass read nothing; not work done
                    $stoppedBecause = 'the mailbox stopped returning older mail before the window ended '
                        .'(a provider paging limit, not the end of the mailbox)';

                    break;
                }

                $windowExhausted = true;
                break;
            }

            $run->increment('scanned_count', count($messages));
            $oldest = $this->earlier($oldest, $this->oldestDate($messages, $overlap));

            // The CONTINUATION cursor is the true oldest of the pass, not the
            // outlier-guarded boundary above: the guard deliberately understates
            // how far back we reached, and paging from an understated point would
            // step over everything between it and the real oldest.
            $cursor = $this->earlier($cursor, $this->oldestDate($messages, 0));

            foreach ($messages as $message) {
                $verdict = $this->engine->evaluate($message, $rules);
                if (! $verdict['matched'] || empty($verdict['attachments'])) {
                    continue;
                }

                $run->increment('matched_count');
                $capturedHere = 0;

                foreach ($verdict['attachments'] as $attachment) {
                    $outcome = $this->captureAttachment(
                        $workflow, $run, $message, $attachment, $verdict['fields'],
                        $emailConn, $storageConn, $logConn,
                        $email, $storage, $logger,
                        $storageCfg, $logCfg, $headers, $rootFolder, $target,
                        $folders, $partitions
                    );

                    if ($outcome === 'captured') {
                        $capturedHere++;
                    }

                    $run->increment(match ($outcome) {
                        'captured' => 'captured_count',
                        'skipped' => 'skipped_count',
                        default => 'failed_count',
                    });
                }

                // Best-effort inbox hygiene — a labelling failure must not fail the run.
                if ($capturedHere > 0) {
                    $this->markProcessed($email, $emailConn, (string) ($message['message_id'] ?? ''));
                }
            }

            // ── Does another pass follow? ────────────────────────────────
            // A short pass normally means the window ran out behind it. Unlimited
            // (0) read the whole window in one go, so it is short by definition.
            //
            // But a pass is ALSO short when the adapter reached its full quota and
            // could not parse some of it: those messages were consumed from the
            // window without being returned. Counting them back in is what stops
            // "some mail in this slice is unreadable" from being reported as
            // "there is no more mail" — the same conflation that let a hole in the
            // middle of a mailbox close a sweep and still show full coverage.
            $lastPassWasFull = $limit > 0 && (count($messages) + count($passUnreadable)) >= $limit;

            if (! $lastPassWasFull) {
                $windowExhausted = true;
                break;
            }

            // A cursor that did not move means the next pass would ask the same
            // question and get the same answer forever — a whole quota of
            // messages sharing one timestamp, or an adapter ignoring the cursor.
            // Stop and record the gap rather than spin.
            if ($cursor !== null && $lastCursor !== null && ! $cursor->lessThan($lastCursor)) {
                $stoppedBecause = 'the mailbox stopped yielding older mail (the paging cursor did not advance)';

                break;
            }
            $lastCursor = $cursor;

            // Nothing to reach back to (first ever run), or nothing datable to
            // judge coverage by — either way, do not spin.
            if ($reachBackTo === null || $oldest === null) {
                break;
            }

            if ($oldest->lessThanOrEqualTo($reachBackTo)) {
                break;  // coverage overlaps the previous run: nothing in between
            }

            if ($passes >= $maxPasses || ($deadline && now()->greaterThanOrEqualTo($deadline))) {
                $stoppedBecause = 'it ran out of budget';

                break;  // records a gap below
            }

            // Step back by what this pass covered, less the overlap that closes
            // the seam against mail moving under us mid-sweep.
            $offset += max(1, count($messages) - $overlap);

            unset($messages);
            gc_collect_cycles();
        }

        $gap = (! $windowExhausted && $reachBackTo && $oldest && $oldest->greaterThan($reachBackTo))
            ? $reachBackTo
            : null;

        $run->update([
            'passes' => $passes,
            'covered_back_to' => $oldest,
            'coverage_gap_from' => $gap,
        ]);

        $notes = [];

        // No silent caps. The engine now closes the ordinary gap itself, so this
        // fires only when it could NOT — which makes it rare and worth reading,
        // rather than the nightly noise it had become.
        if ($warning = $this->coverageWarning($workflow, $run->refresh(), $oldest, $reachBackTo, $windowExhausted, $stoppedBecause)) {
            Log::warning('Email Workflow sweep could not cover everything since the previous run', [
                'workflow_id' => $workflow->id,
                'run_id' => $run->id,
                'limit' => $limit,
                'passes' => $passes,
                'reach_back_to' => (string) $reachBackTo,
                'covered_back_to' => (string) $oldest,
                'window_days' => $this->sinceDays(),
            ]);

            $notes[] = $warning;
        }

        // The other way a sweep silently under-covers: mail it reached but could
        // not parse. Same column, because "this sweep did not see everything" is
        // the same fact to the operator whatever caused it.
        if ($unreadable !== []) {
            Log::warning('Email Workflow could not read some messages — they were skipped', [
                'workflow_id' => $workflow->id,
                'run_id' => $run->id,
                'count' => count($unreadable),
                'skipped' => array_slice($unreadable, 0, 10),
            ]);

            $notes[] = $this->unreadableWarning($unreadable);
        }

        if ($notes !== []) {
            $run->update(['coverage_warning' => implode(' ', $notes)]);
        }

        $run->refresh();
    }

    /**
     * Capture one attachment. Returns 'captured' | 'skipped' | 'failed'.
     *
     * @param  array<string,mixed>  $message
     * @param  array<string,mixed>  $attachment
     * @param  array<string,mixed>  $fields
     * @param  array<int,string>  $headers
     * @param  array<string,mixed>  $rootFolder
     * @param  array<string,mixed>  $target
     * @param  array<string,mixed>  $folders
     * @param  array<string,mixed>  $partitions
     */
    private function captureAttachment(
        EmailWorkflow $workflow,
        EmailWorkflowRun $run,
        array $message,
        array $attachment,
        array $fields,
        EmailWorkflowConnection $emailConn,
        EmailWorkflowConnection $storageConn,
        EmailWorkflowConnection $logConn,
        EmailSourceAdapter $email,
        StorageAdapter $storage,
        LogAdapter $logger,
        array $storageCfg,
        array $logCfg,
        array $headers,
        array $rootFolder,
        array $target,
        array &$folders,
        array &$partitions,
    ): string {
        $messageId = (string) ($message['message_id'] ?? '');
        $originalName = (string) ($attachment['name'] ?? '');

        $capture = $this->claim($workflow, $run, $messageId, $originalName, $fields);

        // Already finished on an earlier run — the common case on re-runs.
        if ($capture === null) {
            return 'skipped';
        }

        try {
            $partitionName = $this->engine->monthlyPartition($fields['date'] ?? null);

            // ── Store ───────────────────────────────────────────────────
            // Gate on the file actually existing, not on status: a capture that
            // died during download is FAILED with no stored_file_id, and must
            // still upload on retry. Keying off status would skip the upload and
            // log a row pointing at nothing.
            if (blank($capture->stored_file_id)) {
                $folder = $rootFolder;
                if (! empty($storageCfg['monthly_subfolders'])) {
                    $folder = $folders[$partitionName]
                        ??= $storage->ensureSubfolder($storageConn, $rootFolder, $partitionName);
                }

                $filename = $this->filename(
                    (string) ($storageCfg['filename_template'] ?? '{{date}}_{{originalName}}'),
                    $fields,
                    $originalName
                );

                // Re-use an identically-named file rather than creating "(1)" copies.
                $stored = $storage->findFile($storageConn, $folder, $filename);

                if ($stored === null) {
                    $bytes = $email->downloadAttachment($emailConn, $messageId, (string) ($attachment['id'] ?? ''));
                    if ($bytes === '') {
                        throw new RuntimeException('Attachment downloaded as 0 bytes.');
                    }

                    $stored = $storage->saveFile(
                        $storageConn, $folder, $bytes, $filename,
                        (string) ($attachment['mime'] ?? 'application/octet-stream')
                    );
                }

                $capture->update([
                    'status' => EmailWorkflowCapture::STATUS_STORED,
                    'stored_file_id' => $stored['id'] ?? null,
                    'stored_file_url' => $stored['url'] ?? null,
                    'stored_file_name' => $stored['name'] ?? $filename,
                    'error' => null,
                ]);
            }

            // ── Log ─────────────────────────────────────────────────────
            $partition = $partitions[$partitionName]
                ??= $logger->ensurePartition(
                    $logConn,
                    $target,
                    ! empty($logCfg['partition_by_month']) ? $partitionName : ($target['title'] ?: 'Log'),
                    $headers
                );

            $logger->appendRow($logConn, $partition, $this->row($logCfg, $fields, $attachment, $capture));

            $capture->update([
                'status' => EmailWorkflowCapture::STATUS_LOGGED,
                'logged_at' => now(),
                'error' => null,
            ]);

            return 'captured';
        } catch (Throwable $e) {
            Log::warning('Email Workflow attachment capture failed', [
                'workflow_id' => $workflow->id,
                'run_id' => $run->id,
                'capture_id' => $capture->id,
                'error' => $e->getMessage(),
            ]);

            // Keep the claimed row so the next run resumes from where it stopped.
            $capture->update([
                'status' => EmailWorkflowCapture::STATUS_FAILED,
                'error' => $this->safeMessage($e),
            ]);

            return 'failed';
        }
    }

    /**
     * Claim an attachment's idempotency key.
     *
     * Returns the capture row to work on, or null when it is already complete.
     * The UNIQUE index — not a read-then-write check — is what makes this safe
     * against two runs racing on the same message.
     *
     * @param  array<string,mixed>  $fields
     */
    private function claim(
        EmailWorkflow $workflow,
        EmailWorkflowRun $run,
        string $messageId,
        string $attachmentName,
        array $fields,
    ): ?EmailWorkflowCapture {
        $key = $this->engine->idempotencyKey($messageId, $attachmentName);
        $hash = EmailWorkflowCapture::hashKey($key);

        $existing = EmailWorkflowCapture::where('email_workflow_id', $workflow->id)
            ->where('key_hash', $hash)
            ->first();

        if ($existing) {
            return $existing->isComplete() ? null : $existing;
        }

        try {
            return EmailWorkflowCapture::create([
                'email_workflow_id' => $workflow->id,
                'email_workflow_run_id' => $run->id,
                'message_id' => mb_substr($messageId, 0, 255),
                'attachment_name' => mb_substr($attachmentName, 0, 500),
                'idempotency_key' => $key,
                'key_hash' => $hash,
                'status' => EmailWorkflowCapture::STATUS_PENDING,
                'amount' => $fields['amount'] ?? null,
                'currency' => $fields['currency'] ?? null,
                'needs_review' => (bool) ($fields['needs_review'] ?? false),
            ]);
        } catch (QueryException $e) {
            // Lost the race — another run claimed it between our read and write.
            $raced = EmailWorkflowCapture::where('email_workflow_id', $workflow->id)
                ->where('key_hash', $hash)
                ->first();

            if (! $raced) {
                throw $e; // a real DB error, not the unique constraint
            }

            return $raced->isComplete() ? null : $raced;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * The three connections a run needs, each verified reachable.
     *
     * @return array{0:EmailWorkflowConnection,1:EmailWorkflowConnection,2:EmailWorkflowConnection}
     */
    private function connections(EmailWorkflow $workflow): array
    {
        $out = [];

        foreach ([
            'email' => $workflow->emailConnection,
            'storage' => $workflow->storageConnection,
            'log' => $workflow->logConnection,
        ] as $label => $conn) {
            if (! $conn) {
                throw new RuntimeException(ucfirst($label).' connection is not configured — finish the wizard first.');
            }
            if (! $conn->isConnected()) {
                throw new RuntimeException(
                    ProviderRegistry::name($conn->provider_id).' ('.$label.') is not connected — reconnect it on the Email Workflow page.'
                );
            }
            $out[] = $conn;
        }

        return $out;
    }

    /**
     * Provider-agnostic search query. `q` is a Gmail hint that Outlook and IMAP
     * ignore; DetectionEngine does the authoritative filtering regardless.
     *
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    private function query(array $rules): array
    {
        $query = ['since_days' => $this->sinceDays()];

        if (! empty($rules['attachment']['required'])) {
            $query['q'] = 'has:attachment';
        }

        return $query;
    }

    /** The lookback window. Mail older than this is never captured. */
    public function sinceDays(): int
    {
        return (int) config('email-workflow.since_days', self::DEFAULT_SINCE_DAYS);
    }

    /** Messages one pass may read; 0 = unlimited. */
    public function messageLimit(): int
    {
        return (int) config('email-workflow.message_limit', self::DEFAULT_MESSAGE_LIMIT);
    }

    /**
     * True when a run examined at least as many messages as one pass allows, so
     * there was probably more behind it. Always false when unlimited (0).
     *
     * On its own this is NOT a problem — see coverageWarning().
     */
    public function hitCeiling(EmailWorkflowRun $run): bool
    {
        $limit = $this->messageLimit();

        return $limit > 0 && $run->scanned_count >= $limit;
    }

    /**
     * How far back this run must read for the chain of sweeps to be unbroken,
     * or null when it has no such obligation.
     *
     * Normally that is where the previous run started: everything older was
     * already this workflow's business on an earlier night. Two refinements
     * matter and both are load-bearing:
     *
     *  - an unmet target is INHERITED (`coverage_gap_from`). Without it, a run
     *    that ran out of budget would hand the next run its own start time as
     *    the target, and the hole behind it would become permanent — the exact
     *    failure this whole change exists to remove.
     *  - the target is clamped to the window. Mail older than `since_days` can
     *    never be captured, so chasing past it is pure work for no documents,
     *    and it bounds the worst case at one window's worth of reading.
     *
     * Null on the first ever run: there is no previous coverage to join up to,
     * and reading an entire mailbox because someone switched a workflow on is
     * not what they asked for. `--catch-up` is how that is asked for.
     */
    private function coverageTarget(EmailWorkflow $workflow, EmailWorkflowRun $run, bool $catchUp): ?\Carbon\CarbonInterface
    {
        $windowStart = now()->subDays($this->sinceDays());

        if ($catchUp) {
            return $windowStart;
        }

        $previous = EmailWorkflowRun::where('email_workflow_id', $workflow->id)
            ->where('id', '<', $run->id)
            ->whereIn('status', [EmailWorkflowRun::STATUS_SUCCESS, EmailWorkflowRun::STATUS_PARTIAL])
            ->latest('id')
            ->first();

        if (! $previous) {
            return null;
        }

        $target = $previous->coverage_gap_from ?: $previous->started_at;

        if (! $target) {
            return null;
        }

        return $target->greaterThan($windowStart) ? $target : $windowStart;
    }

    /**
     * The date of the oldest message in a pass.
     *
     * Newest-first is contractual, so this is normally the last row — but it is
     * computed as a minimum over everything datable rather than trusted blindly,
     * because a single undated or out-of-order message at the end would
     * otherwise decide whether the sweep believes it has caught up.
     *
     * @param  array<int,array<string,mixed>>  $messages
     */
    private function oldestDate(array $messages, int $overlap = 0): ?\Carbon\CarbonInterface
    {
        $dates = [];

        foreach ($messages as $message) {
            $raw = data_get($message, 'date');
            if (! $raw) {
                continue;
            }

            try {
                $date = \Carbon\Carbon::parse($raw);
            } catch (\Throwable) {
                continue;
            }

            // A date OUTSIDE the window this sweep asked for cannot be telling
            // the truth about coverage — the mailbox only returned it because it
            // ARRIVED inside the window, so the header is wrong (a wrong clock, a
            // spoofed date, a document date reused as Date:). Ignoring it rather
            // than folding it in closes two holes at once:
            //
            //  - it cannot decide the stop condition. One message stamped last
            //    year would make $oldest predate the target, the sweep would
            //    break after pass 1, and the run would claim full coverage.
            //  - it cannot reach the database. covered_back_to is a MySQL
            //    TIMESTAMP (1970-01-01 .. 2038-01-19); a `Date: 1 Jan 1969` or a
            //    year-9999 header raises SQLSTATE 22007 in strict mode, which
            //    fails a sweep that had actually captured everything and flips
            //    the workflow to `error`.
            //
            // A day of slack on each side absorbs timezone skew rather than lies.
            if ($date->greaterThan(now()->addDay())
                || $date->lessThan(now()->subDays($this->sinceDays() + 1))) {
                continue;
            }

            $dates[] = $date;
        }

        if ($dates === []) {
            return null;
        }

        // Sort the instants themselves rather than their timestamps: rebuilding a
        // Carbon from an epoch loses the timezone (it comes back UTC, eight hours
        // adrift of Asia/Kuala_Lumpur), and this value is written to a wall-clock
        // MySQL column and compared against now() — both of which would then be
        // wrong by the offset.
        usort($dates, fn ($a, $b) => $a <=> $b);

        // Not the oldest — the k-th oldest. The window filter above throws out
        // dates that are obviously lies, but a header can be wrong and still land
        // INSIDE the window: a forwarded message, a mailing list replaying an old
        // post, a sender whose clock is a fortnight slow. One of those among five
        // hundred would drag the minimum back past the target, stop the sweep
        // after a single pass, and record full coverage over mail nothing read.
        //
        // k is bounded by `pass_overlap` — the messages the NEXT pass re-reads
        // anyway — so this can never cost coverage: understating how far back a
        // pass reached only ever buys an extra pass, while overstating it skips
        // mail, and only one of those is recoverable. It also scales down on
        // small passes, which have no room for outlier resistance.
        $guard = min($overlap, intdiv(count($dates), 10), count($dates) - 1);

        return $dates[max(0, $guard)];
    }

    /** The earlier of two instants, either of which may be null. */
    private function earlier(?\Carbon\CarbonInterface $a, ?\Carbon\CarbonInterface $b): ?\Carbon\CarbonInterface
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $b->lessThan($a) ? $b : $a;
    }

    /**
     * The operator-facing warning when this sweep left mail unread that no run
     * will ever come back for, or null when coverage is sound.
     *
     * The engine closes the ordinary gap by itself now, so reaching this point
     * means it ran out of budget trying — which is a genuine "volume has
     * outgrown the schedule" signal rather than the nightly noise the old
     * cap-based warning became. Silence is the healthy case, and it must stay
     * silent in every case that is actually fine, or the amber badge means
     * nothing again.
     */
    public function coverageWarning(
        EmailWorkflow $workflow,
        EmailWorkflowRun $run,
        ?\Carbon\CarbonInterface $oldest,
        ?\Carbon\CarbonInterface $reachBackTo,
        bool $windowExhausted,
        ?string $stoppedBecause = null,
    ): ?string {
        // Read to the end of the window: there is nothing behind it to miss.
        if ($windowExhausted) {
            return null;
        }

        // No previous coverage to join up to. Whatever sits behind this first
        // sweep's cap has been read by nobody — worth saying once, and only once,
        // because every later run does have a target and closes the gap itself.
        if ($reachBackTo === null) {
            return $this->hitCeiling($run)
                ? 'This first sweep filled its '.$run->scanned_count.'-message pass, so older mail in the '
                    .$this->sinceDays().'-day window was not read. Later runs keep up with new mail on their own; '
                    .'run `php artisan email-workflows:run --workflow='.$workflow->id.' --catch-up --sync` once '
                    .'if you want the existing backlog captured too.'
                : null;
        }

        if ($oldest === null || $oldest->lessThanOrEqualTo($reachBackTo)) {
            return null;
        }

        return 'This sweep could not catch up — '.($stoppedBecause ?? 'it stopped early').': after '.$run->passes
            .' pass(es) ('.$run->scanned_count.' messages) it had only reached back to '.$oldest->format('Y-m-d H:i')
            .', and needed to reach '.$reachBackTo->format('Y-m-d H:i').'. Mail in between has been read by no run '
            .'yet and will fall out of the '.$this->sinceDays().'-day window if this keeps happening. The next run '
            .'will try again from the same point — if it repeats, sweep more often (capture cron) so each run has '
            .'less to catch up on.';
    }

    /**
     * The operator-facing sentence for messages the mail parser gave up on.
     *
     * Names the first cause verbatim, because these are third-party headers we
     * cannot fix and the remedy — if any — depends entirely on what broke. The
     * full list goes to the log; the run carries enough to know it happened and
     * roughly why.
     *
     * @param  array<int,array{ref:string,error:string}>  $unreadable
     */
    public function unreadableWarning(array $unreadable): string
    {
        $count = count($unreadable);
        $one = $count === 1;
        $reason = trim((string) ($unreadable[0]['error'] ?? ''));

        return $count.' message'.($one ? '' : 's').' in the window could not be read by the mail parser and '
            .($one ? 'was' : 'were').' skipped, so any attachments on '.($one ? 'it' : 'them')
            .' were not captured'.($reason !== '' ? ' ('.$reason.')' : '')
            .'. The full list is in the application log.';
    }

    /**
     * Column labels, in sheet order.
     *
     * @param  array<string,mixed>  $logCfg
     * @return array<int,string>
     */
    private function headers(array $logCfg): array
    {
        $columns = (array) ($logCfg['columns'] ?? []);

        return array_values(array_filter(array_map(
            fn ($c) => (string) ($c['label'] ?? ''),
            $columns
        ), fn ($l) => $l !== ''));
    }

    /**
     * Build the sheet row: label => value, resolved from each column's source.
     *
     * @param  array<string,mixed>  $logCfg
     * @param  array<string,mixed>  $fields
     * @param  array<string,mixed>  $attachment
     * @return array<string,mixed>
     */
    private function row(array $logCfg, array $fields, array $attachment, EmailWorkflowCapture $capture): array
    {
        $row = [];

        foreach ((array) ($logCfg['columns'] ?? []) as $column) {
            $label = (string) ($column['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $row[$label] = $this->value((string) ($column['source'] ?? ''), $fields, $attachment, $capture);
        }

        return $row;
    }

    /**
     * Resolve one column source to a cell value.
     *
     * `attachment.name` is deliberately the ORIGINAL filename, not the stored
     * one: it is half of the idempotency key, so the sheet's key must match the
     * captures table's. The stored name is available as `storage.name`.
     *
     * @param  array<string,mixed>  $fields
     * @param  array<string,mixed>  $attachment
     */
    private function value(string $source, array $fields, array $attachment, EmailWorkflowCapture $capture): mixed
    {
        return match ($source) {
            'email.date' => $this->displayDate($fields['date'] ?? null),
            'email.from' => $fields['from'] ?? '',
            'email.subject' => $fields['subject'] ?? '',
            'email.message_id' => $fields['message_id'] ?? '',
            'parsed.amount' => $fields['amount'] ?? '',
            'parsed.currency' => $fields['currency'] ?? '',
            'parsed.description' => $fields['description'] ?? '',
            'attachment.name' => $attachment['name'] ?? '',
            'attachment.size' => $attachment['size'] ?? '',
            'storage.url' => $capture->stored_file_url ?? '',
            'storage.name' => $capture->stored_file_name ?? '',
            default => '',
        };
    }

    /**
     * Render the filename template, then make it filesystem/Drive safe.
     *
     * @param  array<string,mixed>  $fields
     */
    private function filename(string $template, array $fields, string $originalName): string
    {
        $date = $this->displayDate($fields['date'] ?? null, 'Y-m-d');

        $name = strtr($template !== '' ? $template : '{{date}}_{{originalName}}', [
            '{{date}}' => $date,
            '{{originalName}}' => $originalName,
        ]);

        // Strip path separators and control characters; collapse whitespace.
        $name = preg_replace('#[/\\\\]+#', '-', $name) ?? $name;
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return $name !== '' ? mb_substr($name, 0, 250) : $originalName;
    }

    /** Format an ISO date for display; falls back to today when unparseable. */
    private function displayDate(?string $iso, string $format = 'Y-m-d'): string
    {
        if (! $iso) {
            return now()->format($format);
        }

        $ts = strtotime($iso);

        return $ts ? date($format, $ts) : now()->format($format);
    }

    /**
     * Close out runs that were killed without getting to record why.
     *
     * A sweep dies invisibly more often than it fails cleanly — an OOM is a
     * fatal error that no catch block sees, a worker timeout kills the process
     * outright, and a shell hang-up takes a foreground run with it. Each leaves
     * `running` on the row forever, so the list page reports a workflow that
     * looks busy and isn't, and the operator has nothing to read.
     *
     * Only runs older than STALE_RUN_MINUTES are touched: a long unlimited sweep
     * is legitimately slow and must not be declared dead while still working.
     * The captures themselves are unaffected — they resume from their own state.
     */
    private function reapStaleRuns(EmailWorkflow $workflow): void
    {
        $cutoff = now()->subMinutes(self::STALE_RUN_MINUTES);

        $stale = EmailWorkflowRun::where('email_workflow_id', $workflow->id)
            ->where('status', EmailWorkflowRun::STATUS_RUNNING)
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $run) {
            Log::warning('Email Workflow reaping a run that never finished', [
                'workflow_id' => $workflow->id,
                'run_id' => $run->id,
                'started_at' => (string) $run->started_at,
            ]);

            $run->update([
                'status' => EmailWorkflowRun::STATUS_FAILED,
                'error' => 'Interrupted — the sweep stopped without finishing (out of memory, '
                    .'timeout, or the process was killed). Captures resume on the next run.',
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * Raise this process's memory ceiling to MEMORY_FLOOR — never lower it, and
     * never touch an unlimited (-1) or already-generous limit. Applies to every
     * caller (command, job, Run-now) because they all funnel through run().
     */
    private function raiseMemoryFloor(): void
    {
        $current = ini_get('memory_limit');

        // -1 means unlimited: already better than anything we'd set.
        if ($current === false || trim((string) $current) === '-1') {
            return;
        }

        $floor = (string) config('email-workflow.memory_floor', self::MEMORY_FLOOR);

        if ($this->toBytes((string) $current) < $this->toBytes($floor)) {
            @ini_set('memory_limit', $floor);
        }
    }

    /** Parse a php.ini shorthand size ("512M", "1G", "134217728") to bytes. */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function markProcessed(EmailSourceAdapter $email, EmailWorkflowConnection $conn, string $messageId): void
    {
        if ($messageId === '') {
            return;
        }

        try {
            $email->markProcessed($conn, $messageId, self::PROCESSED_LABEL);
        } catch (Throwable $e) {
            Log::info('Email Workflow could not label a captured message', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Move the workflow's status $from → $to, but only if the DB still says
     * $from. Returns whether it moved.
     *
     * A compare-and-swap, not a read-then-write, because `$workflow` was loaded
     * before the sweep and a sweep can run for minutes: writing `status` back
     * from that stale in-memory model would silently clobber whatever the
     * operator did meanwhile. Concretely — pause a workflow while its sweep is
     * running, and the run's own completion would resurrect it. The WHERE clause
     * makes the operator's decision win, and makes the no-op case genuinely a
     * no-op rather than a same-value overwrite.
     *
     * Deliberately raw: no model events, no timestamp churn, no reliance on the
     * in-memory attribute the caller is holding.
     */
    private function transitionStatus(EmailWorkflow $workflow, string $from, string $to): bool
    {
        $moved = EmailWorkflow::whereKey($workflow->getKey())
            ->where('status', $from)
            ->update(['status' => $to]) > 0;

        if ($moved) {
            // Keep the caller's model honest about what is now in the DB.
            $workflow->setAttribute('status', $to)->syncOriginalAttribute('status');
        }

        return $moved;
    }

    /**
     * The operator-facing failure string: explained where we recognise the
     * signature, otherwise a bounded version of the raw message.
     *
     * Bounded because provider errors can echo tokens or PII, and explained
     * because the raw text names the class that noticed the problem rather than
     * the remedy — "ImapServerErrorException: NO [ALERT] You are yet to enable
     * IMAP for your account (Failure)" is a true sentence that helps nobody.
     * Full detail still goes to the log above.
     *
     * $conn names the mailbox in the explanation; pass it only where the error
     * can actually be the mail account's fault.
     */
    private function safeMessage(Throwable $e, ?EmailWorkflowConnection $conn = null): string
    {
        return ConnectionDiagnosis::explain($e, $conn);
    }
}
