<?php

namespace App\Support\Automation\Adapters;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\Contracts\EmailSourceAdapter;
use App\Support\Automation\OAuthService;
use Illuminate\Support\Facades\Http;

/**
 * Gmail email source adapter — Gmail REST API via the Laravel HTTP client
 * (no Google SDK dependency). Uses the OAuth access token on the connection,
 * refreshing it transparently when expired.
 *
 * Endpoints (verified against current Gmail API):
 *   GET users/me/messages            — list/search (q = Gmail query syntax)
 *   GET users/me/messages/{id}       — full message (format=full)
 *   GET users/me/messages/{id}/attachments/{attId}
 */
class GmailAdapter implements EmailSourceAdapter
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1';

    public function __construct(private readonly OAuthService $oauth) {}

    public function providerId(): string
    {
        return 'gmail';
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $paging
     * @return array<int,array<string,mixed>>
     */
    public function search(EmailWorkflowConnection $conn, array $query, array $paging = []): array
    {
        $token = $this->oauth->freshAccessToken($conn);
        $limit = (int) ($paging['limit'] ?? 25);
        $sinceDays = (int) ($query['since_days'] ?? 30);

        // Gmail query syntax — bound the window; has:attachment keeps it relevant.
        $q = trim(($query['q'] ?? '').' newer_than:'.$sinceDays.'d');

        $list = Http::withToken($token)
            ->get(self::BASE.'/users/me/messages', ['q' => $q, 'maxResults' => $limit])
            ->throw()->json();

        $out = [];
        foreach (($list['messages'] ?? []) as $stub) {
            $out[] = $this->getMessage($conn, $stub['id']);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function getMessage(EmailWorkflowConnection $conn, string $messageId): array
    {
        $token = $this->oauth->freshAccessToken($conn);

        $msg = Http::withToken($token)
            ->get(self::BASE."/users/me/messages/{$messageId}", ['format' => 'full'])
            ->throw()->json();

        return $this->normalize($msg);
    }

    public function downloadAttachment(EmailWorkflowConnection $conn, string $messageId, string $attachmentId): string
    {
        $token = $this->oauth->freshAccessToken($conn);

        $res = Http::withToken($token)
            ->get(self::BASE."/users/me/messages/{$messageId}/attachments/{$attachmentId}")
            ->throw()->json();

        // Gmail returns base64url-encoded data.
        return $this->b64urlDecode($res['data'] ?? '');
    }

    /** Apply a Gmail label (creating it if needed) to mark a message processed. */
    public function markProcessed(EmailWorkflowConnection $conn, string $messageId, string $label): void
    {
        $token = $this->oauth->freshAccessToken($conn);
        $labelId = $this->ensureLabel($token, $label);
        if (! $labelId) {
            return;
        }

        Http::withToken($token)
            ->post(self::BASE."/users/me/messages/{$messageId}/modify", [
                'addLabelIds' => [$labelId],
            ])->throw();
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $msg */
    private function normalize(array $msg): array
    {
        $headers = collect($msg['payload']['headers'] ?? [])
            ->mapWithKeys(fn ($h) => [strtolower($h['name'] ?? '') => $h['value'] ?? '']);

        [$body, $attachments] = $this->walkParts($msg['payload'] ?? []);

        $internalMs = (int) ($msg['internalDate'] ?? 0);
        $iso = $internalMs > 0 ? \Carbon\Carbon::createFromTimestampMs($internalMs)->toIso8601String() : null;

        return [
            'message_id' => (string) ($msg['id'] ?? ''),
            'from' => (string) $headers->get('from', ''),
            'subject' => (string) $headers->get('subject', ''),
            'body' => $body,
            'date' => $iso,
            'attachments' => $attachments,
        ];
    }

    /**
     * Recursively walk MIME parts: collect text body + attachment metadata.
     *
     * @param  array<string,mixed>  $part
     * @return array{0:string, 1:array<int,array<string,mixed>>}
     */
    private function walkParts(array $part, string $body = '', array $attachments = []): array
    {
        $mime = $part['mimeType'] ?? '';
        $filename = $part['filename'] ?? '';
        $bodyPart = $part['body'] ?? [];

        if ($filename) {
            $attachments[] = [
                'id' => (string) ($bodyPart['attachmentId'] ?? ''),
                'name' => $filename,
                'mime' => $mime,
                'size' => (int) ($bodyPart['size'] ?? 0),
            ];
        } elseif ($mime === 'text/plain' && ! empty($bodyPart['data'])) {
            $body .= $this->b64urlDecode($bodyPart['data']);
        }

        foreach (($part['parts'] ?? []) as $child) {
            [$body, $attachments] = $this->walkParts($child, $body, $attachments);
        }

        return [$body, $attachments];
    }

    private function ensureLabel(string $token, string $label): ?string
    {
        $labels = Http::withToken($token)->get(self::BASE.'/users/me/labels')->throw()->json();
        foreach (($labels['labels'] ?? []) as $l) {
            if (($l['name'] ?? '') === $label) {
                return $l['id'] ?? null;
            }
        }
        $created = Http::withToken($token)->post(self::BASE.'/users/me/labels', [
            'name' => $label,
            'labelListVisibility' => 'labelShow',
            'messageListVisibility' => 'show',
        ])->throw()->json();

        return $created['id'] ?? null;
    }

    private function b64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
