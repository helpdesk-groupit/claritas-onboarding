<?php

namespace App\Support\Automation\Adapters;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\Contracts\LogAdapter;
use App\Support\Automation\OAuthService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Sheets log adapter — Sheets REST v4 via the Laravel HTTP client
 * (no Google SDK dependency, matching GmailAdapter).
 *
 * Endpoints:
 *   GET  v4/spreadsheets/{id}                        — resolve + list tabs
 *   POST v4/spreadsheets/{id}:batchUpdate            — add a monthly tab
 *   GET  v4/spreadsheets/{id}/values/{range}         — read headers / keys
 *   POST v4/spreadsheets/{id}/values/{range}:append  — append a row
 *
 * The `spreadsheets` scope covers any sheet the user can reach, so a pasted
 * link resolves without the per-file scope caveat that applies to Drive.
 */
class GoogleSheetsAdapter implements LogAdapter
{
    private const BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    public function __construct(private readonly OAuthService $oauth) {}

    public function providerId(): string
    {
        return 'gsheets';
    }

    /**
     * Resolve the spreadsheet from a pasted link or raw ID and confirm access.
     *
     * @return array<string,mixed>
     */
    public function resolveTarget(EmailWorkflowConnection $conn, string $urlOrId): array
    {
        $ref = trim($urlOrId);
        if ($ref === '') {
            throw new RuntimeException('No destination sheet configured — set one in step 4 of the wizard.');
        }

        $id = $this->extractId($ref);
        if ($id === null) {
            throw new RuntimeException('That does not look like a Google Sheet link or ID.');
        }

        $token = $this->oauth->freshAccessToken($conn);

        $res = Http::withToken($token)->get(self::BASE.'/'.rawurlencode($id), [
            'fields' => 'spreadsheetId,spreadsheetUrl,properties.title,sheets.properties.title',
        ]);

        if ($res->status() === 404 || $res->status() === 403) {
            throw new RuntimeException(
                'Google Sheet is not accessible (HTTP '.$res->status().'). Check the link, and that the '
                .'connected Google account has edit access to it.'
            );
        }

        $sheet = $res->throw()->json();

        return [
            'id' => (string) ($sheet['spreadsheetId'] ?? $id),
            'title' => (string) data_get($sheet, 'properties.title', ''),
            'url' => (string) ($sheet['spreadsheetUrl'] ?? ''),
            'tabs' => array_values(array_filter(array_map(
                fn ($s) => data_get($s, 'properties.title'),
                $sheet['sheets'] ?? []
            ))),
        ];
    }

    /**
     * Ensure a tab exists with the given headers. Creates the tab when missing
     * and writes the header row when row 1 is empty.
     *
     * @param  array<string,mixed>  $target
     * @param  array<int,string>  $headers
     * @return array<string,mixed>
     */
    public function ensurePartition(EmailWorkflowConnection $conn, array $target, string $name, array $headers): array
    {
        $token = $this->oauth->freshAccessToken($conn);
        $id = (string) ($target['id'] ?? '');

        // Re-read tabs rather than trusting a possibly stale $target['tabs'].
        $tabs = $this->listTabs($token, $id);

        if (! in_array($name, $tabs, true)) {
            Http::withToken($token)->post(self::BASE.'/'.rawurlencode($id).':batchUpdate', [
                'requests' => [
                    ['addSheet' => ['properties' => ['title' => $name]]],
                ],
            ])->throw();
        }

        // Write headers if row 1 is blank (new tab, or a tab the user pre-made).
        $existing = $this->readRange($token, $id, $this->range($name, 'A1:ZZ1'));
        if (empty($existing[0] ?? []) && ! empty($headers)) {
            Http::withToken($token)
                ->put(self::BASE.'/'.rawurlencode($id).'/values/'.rawurlencode($this->range($name, 'A1'))
                    .'?valueInputOption=RAW', [
                        'values' => [array_values($headers)],
                    ])
                ->throw();

            $headerRow = array_values($headers);
        } else {
            // Honour the sheet's real header order — the user may have moved columns.
            $headerRow = ! empty($existing[0] ?? []) ? $existing[0] : array_values($headers);
        }

        return [
            'spreadsheet_id' => $id,
            'title' => $name,
            'headers' => $headerRow,
        ];
    }

    /**
     * Read composite idempotency keys already present across all tabs.
     *
     * The contract passes no column spec, so CaptureService puts the relevant
     * header labels on the target as `key_columns` (in order). Without them we
     * can't know which cells form the key, so we return nothing and let the
     * captures table be the sole dedupe gate.
     *
     * @param  array<string,mixed>  $target
     * @return array<int,string>
     */
    public function listKeys(EmailWorkflowConnection $conn, array $target): array
    {
        $keyColumns = array_values(array_filter((array) ($target['key_columns'] ?? [])));
        if (empty($keyColumns)) {
            return [];
        }

        $token = $this->oauth->freshAccessToken($conn);
        $id = (string) ($target['id'] ?? '');

        $keys = [];
        foreach ($this->listTabs($token, $id) as $tab) {
            $rows = $this->readRange($token, $id, $this->range($tab, 'A1:ZZ10000'));
            if (count($rows) < 2) {
                continue; // headers only, or empty
            }

            $headers = array_map('strval', array_shift($rows));
            $idx = [];
            foreach ($keyColumns as $label) {
                $pos = array_search($label, $headers, true);
                if ($pos === false) {
                    continue 2; // this tab isn't shaped like our log — skip it
                }
                $idx[] = $pos;
            }

            foreach ($rows as $row) {
                $parts = array_map(fn ($i) => (string) ($row[$i] ?? ''), $idx);
                if (implode('', $parts) !== '') {
                    $keys[] = implode('|', $parts);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Append one row, ordered to match the partition's real header row.
     *
     * @param  array<string,mixed>  $partition
     * @param  array<string,mixed>  $row  label => value
     */
    public function appendRow(EmailWorkflowConnection $conn, array $partition, array $row): void
    {
        $token = $this->oauth->freshAccessToken($conn);
        $id = (string) ($partition['spreadsheet_id'] ?? '');
        $title = (string) ($partition['title'] ?? '');
        $headers = (array) ($partition['headers'] ?? []);

        $values = [];
        foreach ($headers as $label) {
            $values[] = $this->scalar($row[$label] ?? '');
        }

        // The range is encoded but the `:append` verb must stay literal — it is
        // part of the method name, not the range.
        Http::withToken($token)
            ->post(self::BASE.'/'.rawurlencode($id).'/values/'.rawurlencode($this->range($title, 'A1'))
                .':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS', [
                    'values' => [$values],
                ])
            ->throw();
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @return array<int,string> */
    private function listTabs(string $token, string $spreadsheetId): array
    {
        $res = Http::withToken($token)->get(self::BASE.'/'.rawurlencode($spreadsheetId), [
            'fields' => 'sheets.properties.title',
        ])->throw()->json();

        return array_values(array_filter(array_map(
            fn ($s) => data_get($s, 'properties.title'),
            $res['sheets'] ?? []
        )));
    }

    /** @return array<int,array<int,string>> */
    private function readRange(string $token, string $spreadsheetId, string $range): array
    {
        $res = Http::withToken($token)
            ->get(self::BASE.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range));

        if ($res->failed()) {
            return []; // absent range → treat as empty rather than fatal
        }

        return $res->json('values', []) ?: [];
    }

    /** Quote a tab title for an A1 range ("It's" → 'It''s'). */
    private function range(string $tab, string $cells): string
    {
        return "'".str_replace("'", "''", $tab)."'!".$cells;
    }

    /** Sheets values must be scalars — flatten anything else. */
    private function scalar(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }

    /** Pull a spreadsheet ID out of a pasted link, or recognise a raw ID. */
    private function extractId(string $ref): ?string
    {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $ref, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $ref, $m)) {
            return $m[1];
        }
        if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $ref)) {
            return $ref;
        }

        return null;
    }
}
