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
     * Messages per sweep; 0 = unlimited. See config/email-workflow.php for the
     * reasoning behind 500 (re-read the newest N daily, let the dedupe skip the
     * overlap, accept the old backlog) and for why unlimited needs a streaming
     * redesign before it is safe on a large mailbox.
     */
    public const DEFAULT_MESSAGE_LIMIT = 500;

    /** Seconds a synchronous "Run now" may take before PHP kills it. */
    public const REQUEST_TIME_LIMIT = 900;

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
     */
    public function run(EmailWorkflow $workflow, string $trigger = EmailWorkflowRun::TRIGGER_MANUAL, ?int $userId = null): EmailWorkflowRun
    {
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
            $this->execute($workflow, $run);
        } catch (Throwable $e) {
            // Fatal: auth, unreachable folder/sheet, misconfiguration.
            Log::warning('Email Workflow capture run failed', [
                'workflow_id' => $workflow->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => EmailWorkflowRun::STATUS_FAILED,
                'error' => $this->safeMessage($e),
                'finished_at' => now(),
            ]);

            $workflow->forceFill([
                'last_run_at' => now(),
                'last_error' => $this->safeMessage($e),
                // An active workflow that can't run is an error state the list must show.
                'status' => $workflow->isActive() ? EmailWorkflow::STATUS_ERROR : $workflow->status,
            ])->save();

            return $run->refresh();
        }

        $run->update([
            'status' => $run->failed_count > 0
                ? EmailWorkflowRun::STATUS_PARTIAL
                : EmailWorkflowRun::STATUS_SUCCESS,
            'finished_at' => now(),
        ]);

        $workflow->forceFill([
            'last_run_at' => now(),
            'last_error' => null,
            'captured_count' => (int) $workflow->captured_count + $run->captured_count,
        ])->save();

        return $run->refresh();
    }

    // ── Pipeline ─────────────────────────────────────────────────────────

    private function execute(EmailWorkflow $workflow, EmailWorkflowRun $run): void
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

        $limit = (int) config('email-workflow.message_limit', self::DEFAULT_MESSAGE_LIMIT);

        $messages = $email->search($emailConn, $this->query($rules), ['limit' => $limit]);

        $run->update(['scanned_count' => count($messages)]);

        // No silent caps — but warn about the case that actually costs documents.
        //
        // A cap is designed to leave the old backlog unread: each sweep re-reads
        // the newest N, the dedupe skips what it already has, and the window
        // slides forward with the mailbox. Nothing NEW is lost while daily volume
        // stays below the cap, so "we hit the cap" on its own is expected and
        // warning about it every run would be noise nobody reads.
        //
        // The real failure is the cap hiding mail the PREVIOUS sweep hadn't
        // covered either — that mail is seen by no run, ever, and slides out of
        // the window. See coverageWarning().
        if ($warning = $this->coverageWarning($workflow, $run->refresh(), $messages)) {
            Log::warning('Email Workflow sweep may have missed new mail — raise the message cap', [
                'workflow_id' => $workflow->id,
                'run_id' => $run->id,
                'limit' => $limit,
                'window_days' => $this->sinceDays(),
            ]);

            $run->update(['coverage_warning' => $warning]);
        }

        $headers = $this->headers($logCfg);
        $folders = [];   // partition name → folder ref  (one Drive call per month)
        $partitions = []; // partition name → sheet partition ref (one Sheets call per month)

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

    /**
     * True when a run examined exactly as many messages as its cap allows, so
     * there was probably more behind it. Always false when unlimited (0).
     *
     * On its own this is NOT a problem — see coverageWarning().
     */
    public function hitCeiling(EmailWorkflowRun $run): bool
    {
        $limit = (int) config('email-workflow.message_limit', self::DEFAULT_MESSAGE_LIMIT);

        return $limit > 0 && $run->scanned_count >= $limit;
    }

    /**
     * The operator-facing warning when a cap may have cost NEW documents, or
     * null when coverage is sound.
     *
     * A capped sweep reads the newest N and re-reads them daily; the dedupe
     * makes the overlap free. While each day's volume stays under the cap, every
     * arriving message appears in at least one sweep before sliding past N, so
     * nothing new is missed and the untouched backlog is a deliberate choice.
     *
     * It only goes wrong when the cap fills with mail NEWER than the previous
     * sweep: the messages between the previous sweep and the oldest one this
     * sweep looked at were examined by neither, and the window will carry them
     * away. That is the condition worth interrupting someone for — and it is
     * self-correcting information, because it means volume has outgrown the cap.
     *
     * @param  array<int,array<string,mixed>>  $messages  newest-first
     */
    public function coverageWarning(EmailWorkflow $workflow, EmailWorkflowRun $run, array $messages): ?string
    {
        if (! $this->hitCeiling($run) || $messages === []) {
            return null;
        }

        // Newest-first is contractual, so the last row is the oldest we saw.
        $oldestSeen = data_get($messages[array_key_last($messages)], 'date');

        $previous = EmailWorkflowRun::where('email_workflow_id', $workflow->id)
            ->where('id', '<', $run->id)
            ->whereIn('status', [EmailWorkflowRun::STATUS_SUCCESS, EmailWorkflowRun::STATUS_PARTIAL])
            ->latest('id')
            ->first();

        // No earlier sweep to have covered the remainder: the backlog beyond the
        // cap is unread by definition. Worth saying once, on the first run.
        if (! $previous) {
            return 'This first sweep filled its '.$run->scanned_count.'-message cap, so older mail in the '
                .$this->sinceDays().'-day window was not read. Ongoing runs will keep up with new mail; '
                .'raise EWF_MESSAGE_LIMIT once if you want the existing backlog captured too.';
        }

        if (! $oldestSeen) {
            return null; // can't date the messages — don't cry wolf
        }

        try {
            $reachedBack = \Carbon\Carbon::parse($oldestSeen);
        } catch (\Throwable) {
            return null;
        }

        // Reached back past the previous sweep ⇒ the two runs' coverage overlaps
        // ⇒ nothing in between was skipped. This is the healthy, quiet case.
        if ($reachedBack->lessThanOrEqualTo($previous->started_at)) {
            return null;
        }

        return 'Mail volume has outgrown the '.$run->scanned_count.'-message cap: this sweep only reached back to '
            .$reachedBack->format('Y-m-d H:i').', but the previous one ran at '
            .$previous->started_at->format('Y-m-d H:i').'. Messages in between were read by neither run and will '
            .'fall out of the '.$this->sinceDays().'-day window. Raise EWF_MESSAGE_LIMIT or sweep more often.';
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
     * Provider errors can echo tokens or PII — keep the operator-facing string
     * short and bounded. Full detail goes to the log.
     */
    private function safeMessage(Throwable $e): string
    {
        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : class_basename($e).': '.$e->getMessage();

        return mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 500);
    }
}
