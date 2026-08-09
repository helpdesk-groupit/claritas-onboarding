<?php

namespace App\Support\Automation\Adapters;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\Contracts\EmailSourceAdapter;
use App\Support\Automation\ParserWarnings;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Generic IMAP / Yahoo email source adapter (webklex/php-imap, pure PHP — no
 * php-imap C extension required).
 *
 * Authenticates with host + username + app-password from the connection.
 * Normalizes every message into the shape the DetectionEngine consumes:
 *   ['message_id','from','subject','body','date','attachments'=>[...]]
 */
class ImapAdapter implements EmailSourceAdapter
{
    /**
     * Messages fetched per IMAP round-trip.
     *
     * `setFetchBody(true)` downloads each message in full — body AND attachment
     * parts — so asking for the whole sweep at once holds every raw message in
     * memory simultaneously. On a real mailbox that blew the 128M CLI limit
     * (the limit the scheduler runs under) inside ImapProtocol's read loop.
     * Fetching in small pages and releasing each batch keeps peak memory
     * proportional to the batch, not to the sweep. Small enough to be safe on
     * mailboxes with fat PDFs; large enough not to make round-trips dominate.
     */
    private const FETCH_BATCH = 10;

    /**
     * Messages the LAST search() could not read, and therefore did not return.
     *
     * @var array<int,array{ref:string,error:string}>
     */
    private array $unreadable = [];

    /**
     * Parser complaints already logged this sweep, so a sender that emits the
     * same malformed header on every message writes one log line, not hundreds.
     *
     * @var array<string,true>
     */
    private array $warned = [];

    public function __construct(private readonly string $providerId = 'imap') {}

    public function providerId(): string
    {
        return $this->providerId;
    }

    /**
     * Consecutive fully-unreadable pages a sweep will step over before it gives
     * up and stops paging.
     *
     * One bad page is a hole to walk past; several in a row is a connection that
     * has died, and a dead connection never returns the empty page that would
     * otherwise end the loop — so without a bound, stepping over holes spins
     * forever. Three pages (30 messages at the default batch) is comfortably
     * more than the single-page failures seen in production and small enough
     * that a dead socket costs one wasted round-trip per page, briefly.
     */
    private const MAX_CONSECUTIVE_DEAD_PAGES = 3;

    /**
     * Messages the last sweep gave up on. Empty is the healthy case.
     *
     * @return array<int,array{ref:string,error:string}>
     */
    public function unreadableMessages(): array
    {
        return $this->unreadable;
    }

    /**
     * Whether an empty page should be stepped over rather than end the sweep.
     *
     * Pure, and public, so the decision that separates "the window ran out" from
     * "this page is a hole" can be tested without an IMAP server — the same
     * reason pageCursor() is public. Getting it wrong in either direction is
     * invisible: stop too early and the remainder is reported as read; never
     * stop and a dead connection loops.
     */
    public static function shouldStepOverDeadPage(bool $pageHadFailures, int $consecutiveDeadPages): bool
    {
        return $pageHadFailures && $consecutiveDeadPages < self::MAX_CONSECUTIVE_DEAD_PAGES;
    }

    /**
     * Where an offset lands in IMAP's page-and-position addressing.
     *
     * Pure, and public, because it is the whole of this adapter's offset
     * correctness and there is no HTTP surface to assert it on: an error here
     * silently skips messages a later pass believed it had read, and nothing
     * ever comes back for them (the next run starts from the newest). It must
     * honour the offset EXACTLY — rounding to a page boundary would drop up to
     * `perPage` real documents per pass.
     *
     * @return array{page:int,drop:int} 1-based page to start at, and how many of
     *                                  that page belong to the previous pass
     */
    public static function pageCursor(int $offset, int $perPage): array
    {
        $offset = max(0, $offset);
        $perPage = max(1, $perPage);

        return [
            'page' => intdiv($offset, $perPage) + 1,
            'drop' => $offset % $perPage,
        ];
    }

    /**
     * Log in and open the INBOX, then hang up. Throws on any failure.
     *
     * Deliberately does NOT fetch a message: the question is only "will this
     * host accept these credentials and let us read mail", and selecting the
     * folder answers it in one round-trip on an empty mailbox too. This is the
     * probe that separates a mailbox with IMAP switched off (Zoho rejects at
     * LOGIN) from one that works — the two are otherwise indistinguishable
     * until a capture run fails.
     */
    public function verify(EmailWorkflowConnection $conn): void
    {
        $client = $this->client($conn);
        $client->connect();

        try {
            $client->getFolderByName('INBOX');
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Search the INBOX. Supports a `since_days` window (default 30) so the
     * "Test rules" preview and capture run stay bounded.
     *
     * NEWEST FIRST — do not remove setFetchOrderDesc(). webklex defaults
     * `fetch_order` to 'asc' (its src/config/imap.php), and no config/imap.php is
     * published here, so `->since(30 days)->limit(100)` silently returned the
     * OLDEST 100 messages in the window. Once a mailbox exceeds the limit inside
     * the window, the run scans ever-older mail and never sees a new invoice —
     * the automation looks healthy (status success) while capturing nothing.
     * Gmail's API and OutlookAdapter's `$orderby` are both newest-first; this
     * keeps the contract consistent across providers.
     *
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $paging
     * @return array<int,array<string,mixed>>
     */
    public function search(EmailWorkflowConnection $conn, array $query, array $paging = []): array
    {
        // Per-sweep state: what this run could not read, and what it has already
        // complained about. Reset here so unreadableMessages() always describes
        // the sweep the caller just asked for.
        $this->unreadable = [];
        $this->warned = [];

        // 0 = unlimited: keep paging until the window is exhausted.
        $limit = max(0, (int) ($paging['limit'] ?? 25));
        $unlimited = $limit === 0;
        $offset = max(0, (int) ($paging['offset'] ?? 0));
        $sinceDays = (int) ($query['since_days'] ?? 30);
        $since = now()->subDays($sinceDays);

        $client = $this->client($conn);
        $client->connect();

        try {
            $inbox = $client->getFolderByName('INBOX');

            $out = [];
            $perPage = max(1, (int) config('email-workflow.fetch_batch', self::FETCH_BATCH));

            // IMAP pages by position in the ordered result, so an offset is just
            // a later starting page plus a partial first batch.
            ['page' => $startPage, 'drop' => $dropFromFirstPage] = self::pageCursor($offset, $perPage);

            // How many messages the window actually holds. One IMAP SEARCH, no
            // fetch — and it is the difference between a truthful sweep and an
            // invented one.
            //
            // Asking webklex for a page BEYOND the result set does not come back
            // empty. Query::fetch() slices an empty UID array, and
            // ImapProtocol::fetch() then matches neither of its array branches
            // (count > 1, count === 1) and falls through to string-concatenating
            // it — emitting "Array to string conversion" and sending the literal
            // command `UID FETCH Array:Array`, which the server rejects. That is
            // indistinguishable from a page of genuinely unreadable messages, so
            // every sweep whose last real page came back FULL went on to invent
            // one: tech@careplusx.com holds 23 messages and was reporting 40
            // unreadable ones at offsets 31-70, none of which exist.
            $total = $this->read('counting the window', fn () => $inbox->messages()->since($since)->count());

            $consecutiveDeadPages = 0;

            for ($page = $startPage; ($page - 1) * $perPage < $total; $page++) {
                $failedBefore = count($this->unreadable);

                try {
                    $batch = $this->fetchPage($inbox, $since, $perPage, $page);
                } catch (Throwable $e) {
                    // One unparseable message must not cost the whole sweep. webklex
                    // raises GetMessagesFailedException for the entire page, so fall
                    // back to fetching this page one message at a time and skip only
                    // the offender.
                    // webklex rethrows everything as GetMessagesFailedException
                    // (Query::curate_messages), so record the wrapped cause or
                    // the log names the wrapper and hides the actual fault.
                    Log::warning('Email Workflow IMAP page failed — salvaging it message by message', [
                        'page' => $page,
                        'per_page' => $perPage,
                        'in_window' => $total,
                        'error' => $e->getMessage(),
                        'root_cause' => $e->getPrevious()
                            ? $e->getPrevious()::class.': '.$e->getPrevious()->getMessage()
                                .' @ '.$e->getPrevious()->getFile().':'.$e->getPrevious()->getLine()
                            : null,
                    ]);

                    $batch = $this->salvagePage($inbox, $since, $perPage, $page, $total);
                }

                if ($batch === []) {
                    // In-range and still empty means every message on this page
                    // failed to parse — a HOLE, with real messages still behind
                    // it. Breaking here would report the remainder as read.
                    // Step over it instead, bounded so a genuinely dead
                    // connection (which fails every page rather than returning
                    // empty) cannot spin.
                    //
                    // Only reachable for pages inside the window now: the loop
                    // is bounded by $total, so "past the end" — which used to
                    // arrive here disguised as a failed page — cannot occur.
                    if (self::shouldStepOverDeadPage(count($this->unreadable) > $failedBefore, $consecutiveDeadPages)) {
                        $consecutiveDeadPages++;

                        continue;
                    }

                    break;
                }

                $consecutiveDeadPages = 0;

                // The offset's remainder lands mid-page; drop what belongs to the
                // previous pass. A full page always leaves at least one behind
                // (the remainder is < perPage), so an empty result here means the
                // page was short — i.e. the window ended inside it.
                if ($page === $startPage && $dropFromFirstPage > 0) {
                    $batch = array_slice($batch, $dropFromFirstPage);

                    if ($batch === []) {
                        break;
                    }
                }

                foreach ($batch as $message) {
                    $out[] = $message;
                    if (! $unlimited && count($out) >= $limit) {
                        break;
                    }
                }

                // Drop the raw messages before fetching the next page. The
                // normalized rows we keep are small (text + attachment metadata);
                // the raw ones are not.
                unset($batch);
                gc_collect_cycles();

                if (! $unlimited && count($out) >= $limit) {
                    break;
                }
            }

            return $out;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Look up by IMAP UID (the stable per-message handle this adapter emits).
     *
     * @return array<string,mixed>
     */
    public function getMessage(EmailWorkflowConnection $conn, string $messageId): array
    {
        $client = $this->client($conn);
        $client->connect();

        try {
            $message = $this->read('message '.$messageId, fn () => $this->findByUid($client, $messageId));

            return $message ? $this->normalize($message) : [];
        } finally {
            $client->disconnect();
        }
    }

    public function downloadAttachment(EmailWorkflowConnection $conn, string $messageId, string $attachmentId): string
    {
        $client = $this->client($conn);
        $client->connect();

        try {
            // Tolerated here too, and not only in search(): the download re-parses
            // the whole message, so a header the sweep already forgave would
            // otherwise resurface as a failed lookup and the attachment would
            // arrive as 0 bytes — detected, matched, and still not captured.
            //
            // The tolerance stops at the parse. Reading the bytes out is done
            // outside it, because a diagnostic there is about the CONTENT we are
            // about to file, and a truncated attachment stored as a good one is
            // never retried (ParserWarnings::NEVER_TOLERATED covers the transport
            // side of the same hazard).
            $message = $this->read('attachment '.$attachmentId.' of message '.$messageId,
                fn () => $this->findByUid($client, $messageId));

            if (! $message) {
                return '';
            }

            foreach ($message->getAttachments() as $att) {
                if ((string) $att->getName() === $attachmentId) {
                    return (string) $att->getContent();
                }
            }

            return '';
        } finally {
            $client->disconnect();
        }
    }

    /** Resolve a message by its UID within the INBOX. */
    private function findByUid($client, string $uid): ?Message
    {
        $inbox = $client->getFolderByName('INBOX');
        try {
            return $inbox->query()->setFetchBody(true)->getMessageByUid((int) $uid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** IMAP has no app-managed labels in this MVP — no-op (idempotency is DB-side). */
    public function markProcessed(EmailWorkflowConnection $conn, string $messageId, string $label): void
    {
        // Intentionally a no-op for generic IMAP; the captured_docs idempotency
        // key is the source of truth. A provider that supports flags can override.
    }

    /**
     * Fetch one page of normalized messages, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchPage($inbox, \Carbon\Carbon $since, int $perPage, int $page): array
    {
        // Tolerate ONLY the fetch-and-parse. That is where the malformed-header
        // notice fires (Message::make → Header::parse → imap_rfc822_parse_headers)
        // and where losing it costs the whole page.
        $batch = $this->read('page '.$page, fn () => $inbox->messages()
            ->since($since)
            ->setFetchBody(true)
            ->setFetchOrderDesc()
            ->limit($perPage, $page)
            ->get());

        // Normalize OUTSIDE the tolerant scope, deliberately. A diagnostic raised
        // here does not cost us the message — it costs us the TRUTH about it: a
        // tolerated "Array to string conversion" would make (string) $subject the
        // literal "Array", which detection then quietly fails to match, and
        // nothing counts it. A counted skip beats an uncounted wrong answer, so
        // let it escalate: the caller re-reads this page one message at a time
        // and records exactly which one it was.
        $out = [];
        foreach ($batch as $message) {
            $out[] = $this->normalize($message);
        }

        return $out;
    }

    /**
     * Re-fetch a failed page one message at a time, keeping what parses.
     *
     * webklex fails a whole page when any single message in it trips its parser,
     * so without this a lone malformed message silently costs up to `perPage`
     * documents — or, before the caller caught it, the entire sweep. Each skip is
     * logged: a document dropped without a trace is exactly the silent-success
     * failure this module keeps producing.
     *
     * `limit(1, $n)` selects the nth message of the ordered result, so the page's
     * offsets map to n = (page-1)*perPage + i. $total bounds those probes to
     * positions that exist — see the note in search() on what webklex does with
     * an out-of-range one.
     *
     * @return array<int,array<string,mixed>>
     */
    private function salvagePage($inbox, \Carbon\Carbon $since, int $perPage, int $page, int $total): array
    {
        $out = [];
        $base = ($page - 1) * $perPage;

        for ($i = 1; $i <= $perPage; $i++) {
            $offset = $base + $i;

            // Never probe past the end of the result set. webklex answers an
            // out-of-range position with a failure, not an empty page (see
            // search()), so probing one would be recorded as an unreadable
            // MESSAGE that does not exist — inventing exactly the loss this
            // module reports on.
            if ($offset > $total) {
                break;
            }

            try {
                // Same split as fetchPage: tolerate the parse, not the shaping.
                $one = $this->read('page '.$page.' offset '.$offset, fn () => $inbox->messages()
                    ->since($since)
                    ->setFetchBody(true)
                    ->setFetchOrderDesc()
                    ->limit(1, $offset)
                    ->get());

                if ($one->isEmpty()) {
                    break; // ran off the end of the window
                }

                foreach ($one as $message) {
                    $out[] = $this->normalize($message);
                }
            } catch (Throwable $e) {
                $this->recordUnreadable($offset, $e);
            }
        }

        return $out;
    }

    /**
     * Note a message this sweep could not read, in the log AND on the adapter.
     *
     * The log alone was not enough: a skipped message left NO trace in any run
     * counter, so a sweep that dropped twenty documents still reported success
     * with a green tick. CaptureService reads unreadableMessages() and turns the
     * count into a run counter and an operator-visible note.
     */
    private function recordUnreadable(int $offset, Throwable $e): void
    {
        $cause = $e->getPrevious() ?: $e;

        $this->unreadable[] = [
            // The IMAP UID is not available here (the message is precisely the
            // thing that would not construct), so identify it by its position in
            // the newest-first window — enough to locate it in the mailbox.
            'ref' => 'message #'.$offset.' in the '.$this->providerId.' window',
            'error' => mb_substr($cause::class.': '.$cause->getMessage(), 0, 300),
        ];

        Log::warning('Email Workflow skipped an unreadable IMAP message', [
            'provider' => $this->providerId,
            'offset' => $offset,
            'error' => $e->getMessage(),
            'root_cause' => $e->getPrevious()
                ? $e->getPrevious()::class.': '.$e->getPrevious()->getMessage()
                    .' @ '.$e->getPrevious()->getFile().':'.$e->getPrevious()->getLine()
                : null,
        ]);
    }

    /**
     * Perform an IMAP read with the mail parser's non-fatal complaints tolerated.
     *
     * Every path that turns raw mail into our shape goes through here, because
     * every one of them can trip a diagnostic on a header written by somebody
     * else. Without this, Laravel's error handler promotes that diagnostic to an
     * ErrorException, webklex loses the whole page it was building, and the
     * documents on those messages are never captured — see ParserWarnings.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $fn
     * @return TReturn
     */
    private function read(string $context, callable $fn): mixed
    {
        $warnings = [];

        try {
            return ParserWarnings::tolerate($fn, $warnings);
        } finally {
            $fresh = array_values(array_filter($warnings, fn (string $w) => ! isset($this->warned[$w])));

            if ($fresh !== []) {
                foreach ($fresh as $w) {
                    $this->warned[$w] = true;
                }

                Log::info('Email Workflow tolerated a mail-parser complaint (message kept)', [
                    'provider' => $this->providerId,
                    'context' => $context,
                    'warnings' => $fresh,
                ]);
            }
        }
    }

    /** Build a webklex client straight from the connection's stored config. */
    private function client(EmailWorkflowConnection $conn)
    {
        return (new ClientManager)->make($conn->imapConfig());
    }

    /**
     * Normalize a webklex Message into the engine's message shape.
     *
     * @return array<string,mixed>
     */
    private function normalize(Message $message): array
    {
        // webklex getX() returns an Attribute; ->first() yields the value.
        $fromAttr = $message->getFrom();
        $first = is_object($fromAttr) && method_exists($fromAttr, 'first') ? $fromAttr->first() : null;
        $fromAddr = $first ? (string) ($first->mail ?? '') : '';

        $dateAttr = $message->getDate();
        $dateVal = is_object($dateAttr) && method_exists($dateAttr, 'first') ? $dateAttr->first() : $dateAttr;
        $iso = $dateVal instanceof \Carbon\Carbon ? $dateVal->toIso8601String()
            : ($dateVal ? (string) $dateVal : null);

        $attachments = [];
        foreach ($message->getAttachments() as $att) {
            $name = (string) $att->getName();
            if ($name === '') {
                continue;
            }
            $attachments[] = [
                'id' => $name,                  // name is the per-message attachment handle
                'name' => $name,
                'mime' => (string) $att->getMimeType(),
                // getSize() reads the MIME part's declared byte count (webklex
                // sets it from $part->bytes). NEVER measure with
                // strlen($att->getContent()) — that downloads every attachment in
                // the mailbox just to weigh it, then discards it, and
                // downloadAttachment() re-fetches the matching ones anyway. On a
                // real inbox it exhausted the 128M CLI limit (which is what the
                // scheduler runs under) and made every sweep minutes slower.
                'size' => (int) ($att->getSize() ?? 0),
            ];
        }

        $uidAttr = $message->getUid();
        $uid = is_object($uidAttr) && method_exists($uidAttr, 'first') ? $uidAttr->first() : $uidAttr;

        return [
            'message_id' => (string) $uid,      // IMAP UID — stable handle for lookups
            'from' => $fromAddr,
            'subject' => (string) $this->attr($message->getSubject()),
            'body' => (string) ($message->getTextBody() ?: strip_tags((string) $message->getHTMLBody())),
            'date' => $iso,
            'attachments' => $attachments,
        ];
    }

    /** Unwrap a webklex Attribute (or scalar) to a plain string-ish value. */
    private function attr($value): mixed
    {
        if (is_object($value) && method_exists($value, 'first')) {
            return $value->first();
        }

        return $value;
    }
}
