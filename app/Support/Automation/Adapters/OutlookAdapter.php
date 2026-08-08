<?php

namespace App\Support\Automation\Adapters;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\Contracts\EmailSourceAdapter;
use App\Support\Automation\OAuthService;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Outlook / 365 email source adapter — Microsoft Graph REST API via
 * the Laravel HTTP client (no Graph SDK dependency). Uses the OAuth access
 * token on the connection, refreshing transparently.
 *
 * Endpoints (verified against current Microsoft Graph v1.0):
 *   GET /me/messages                         — list/search ($filter, $search, $top)
 *   GET /me/messages/{id}                     — full message
 *   GET /me/messages/{id}/attachments         — attachment list + content bytes
 */
class OutlookAdapter implements EmailSourceAdapter
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    /** Messages listed per page. Graph caps $top at 1000 for /messages. */
    private const PAGE_SIZE = 100;

    public function __construct(private readonly OAuthService $oauth) {}

    public function providerId(): string
    {
        return 'outlook';
    }

    /**
     * A bearer-auth HTTP client with the sweep timeout applied.
     *
     * Laravel's 30s default is an interactive timeout; a sweep asks Graph for
     * a full page of message bodies filtered + sorted server-side, which can
     * take longer than that against a real mailbox. Every call goes through
     * here so none can silently fall back to the default (which is what died
     * as cURL error 28 on 2026-07-17).
     */
    private function http(string $token): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($token)->timeout((int) config('email-workflow.request_timeout', 120));
    }

    /**
     * Always empty for Graph — see GmailAdapter::unreadableMessages(). Graph
     * hands us parsed JSON, so there is no raw MIME here to be unreadable.
     *
     * @return array<int,array{ref:string,error:string}>
     */
    public function unreadableMessages(): array
    {
        return [];
    }

    /**
     * Refresh the token and read one message header. Throws on any failure.
     *
     * See GmailAdapter::verify() — same reasoning: the cheapest call that
     * proves the grant is still live and the scope still covers reads.
     */
    public function verify(EmailWorkflowConnection $conn): void
    {
        $this->search($conn, ['since_days' => 1], ['limit' => 1]);
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $paging
     * @return array<int,array<string,mixed>>
     */
    public function search(EmailWorkflowConnection $conn, array $query, array $paging = []): array
    {
        $token = $this->oauth->freshAccessToken($conn);
        // 0 = unlimited: follow @odata.nextLink until the window is exhausted.
        $limit = max(0, (int) ($paging['limit'] ?? 25));
        $unlimited = $limit === 0;
        $offset = max(0, (int) ($paging['offset'] ?? 0));
        $sinceDays = (int) ($query['since_days'] ?? 30);
        $since = now()->subDays($sinceDays)->toIso8601ZuluString();

        // $top caps ONE PAGE, not the total. Passing the sweep limit straight
        // through and ignoring @odata.nextLink silently truncated every sweep.
        $url = self::BASE.'/me/messages';
        $params = array_filter([
            '$filter' => "receivedDateTime ge {$since}",
            '$top' => $unlimited ? self::PAGE_SIZE : min($limit, self::PAGE_SIZE),
            // Server-side, so a later pass costs one request rather than
            // re-listing (and re-transferring the bodies of) everything already
            // read. Graph honours $skip alongside $filter + $orderby; it is
            // deliberately NOT emitted at 0 so the ordinary first pass sends the
            // exact request it always did.
            '$skip' => $offset,
            '$orderby' => 'receivedDateTime desc',   // newest first — contract
            '$select' => 'id,subject,from,receivedDateTime,bodyPreview,body,hasAttachments',
        ], fn ($v) => $v !== 0 && $v !== null);

        $out = [];

        do {
            $list = $this->http($token)
                ->get($url, $params)
                ->throw()->json();

            foreach (($list['value'] ?? []) as $msg) {
                $out[] = $this->normalize($conn, $token, $msg);
                if (! $unlimited && count($out) >= $limit) {
                    return $out;
                }
            }

            // nextLink already carries every query param — re-sending ours would
            // duplicate them and Graph rejects that.
            $url = $list['@odata.nextLink'] ?? null;
            $params = [];
        } while ($url);

        return $out;
    }

    /** @return array<string,mixed> */
    public function getMessage(EmailWorkflowConnection $conn, string $messageId): array
    {
        $token = $this->oauth->freshAccessToken($conn);

        $msg = $this->http($token)
            ->get(self::BASE."/me/messages/{$messageId}")
            ->throw()->json();

        return $this->normalize($conn, $token, $msg);
    }

    public function downloadAttachment(EmailWorkflowConnection $conn, string $messageId, string $attachmentId): string
    {
        $token = $this->oauth->freshAccessToken($conn);

        $att = $this->http($token)
            ->get(self::BASE."/me/messages/{$messageId}/attachments/{$attachmentId}")
            ->throw()->json();

        // fileAttachment carries base64 contentBytes.
        return base64_decode($att['contentBytes'] ?? '') ?: '';
    }

    /** Move to a category flag — Graph supports categories; keep it simple here. */
    public function markProcessed(EmailWorkflowConnection $conn, string $messageId, string $label): void
    {
        $token = $this->oauth->freshAccessToken($conn);

        $this->http($token)
            ->patch(self::BASE."/me/messages/{$messageId}", [
                'categories' => [$label],
            ])->throw();
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $msg */
    private function normalize(EmailWorkflowConnection $conn, string $token, array $msg): array
    {
        $attachments = [];
        if (! empty($msg['hasAttachments'])) {
            $list = $this->http($token)
                ->get(self::BASE."/me/messages/{$msg['id']}/attachments", [
                    '$select' => 'id,name,contentType,size',
                ])->throw()->json();

            foreach (($list['value'] ?? []) as $att) {
                $attachments[] = [
                    'id' => (string) ($att['id'] ?? ''),
                    'name' => (string) ($att['name'] ?? ''),
                    'mime' => (string) ($att['contentType'] ?? ''),
                    'size' => (int) ($att['size'] ?? 0),
                ];
            }
        }

        $bodyContent = $msg['body']['content'] ?? ($msg['bodyPreview'] ?? '');
        $contentType = $msg['body']['contentType'] ?? 'text';
        $body = $contentType === 'html' ? strip_tags((string) $bodyContent) : (string) $bodyContent;

        return [
            'message_id' => (string) ($msg['id'] ?? ''),
            'from' => (string) ($msg['from']['emailAddress']['address'] ?? ''),
            'subject' => (string) ($msg['subject'] ?? ''),
            'body' => $body,
            'date' => isset($msg['receivedDateTime']) ? (string) $msg['receivedDateTime'] : null,
            'attachments' => $attachments,
        ];
    }
}
