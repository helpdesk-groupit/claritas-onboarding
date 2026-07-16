<?php

namespace App\Support\Automation\Adapters;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\Contracts\EmailSourceAdapter;
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
    public function __construct(private readonly string $providerId = 'imap') {}

    public function providerId(): string
    {
        return $this->providerId;
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
        $limit = (int) ($paging['limit'] ?? 25);
        $sinceDays = (int) ($query['since_days'] ?? 30);

        $client = $this->client($conn);
        $client->connect();

        try {
            $inbox = $client->getFolderByName('INBOX');
            $q = $inbox->messages()
                ->since(now()->subDays($sinceDays))
                ->setFetchBody(true)
                ->setFetchOrderDesc();

            $messages = $q->limit(max(1, $limit))->get();

            $out = [];
            foreach ($messages as $message) {
                $out[] = $this->normalize($message);
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
            $message = $this->findByUid($client, $messageId);

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
            $message = $this->findByUid($client, $messageId);
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
