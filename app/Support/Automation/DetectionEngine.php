<?php

namespace App\Support\Automation;

/**
 * Provider-independent detection engine.
 *
 * Given a normalized email message + a rule set, decide whether the message
 * matches and which attachments to capture, then best-effort extract fields
 * (date / from / subject + amount / description). Pure logic — no network —
 * so it is heavily unit-testable and shared by both the "Test rules" preview
 * and the capture run (Phase 2).
 *
 * Normalized message shape (what adapters produce):
 *   [
 *     'message_id' => string,
 *     'from'       => string,
 *     'subject'    => string,
 *     'body'       => string,
 *     'date'       => string,                  // ISO-8601
 *     'attachments'=> [['id'=>?, 'name'=>string, 'mime'=>string, 'size'=>int], ...],
 *   ]
 */
class DetectionEngine
{
    /** Currency tokens → ISO code, for amount extraction. */
    private const CURRENCIES = [
        'RM' => 'MYR', 'MYR' => 'MYR',
        '$' => 'USD', 'USD' => 'USD', 'US$' => 'USD',
        'SGD' => 'SGD', 'S$' => 'SGD',
        '€' => 'EUR', 'EUR' => 'EUR',
        '£' => 'GBP', 'GBP' => 'GBP',
    ];

    /**
     * Evaluate a message against a rule set.
     *
     * @param  array<string,mixed>  $message
     * @param  array<string,mixed>  $rules
     * @return array{
     *   matched:bool,
     *   reasons:array<int,string>,
     *   attachments:array<int,array<string,mixed>>,
     *   fields:array<string,mixed>
     * }
     */
    public function evaluate(array $message, array $rules): array
    {
        $reasons = [];

        $subjectMatch = $this->textMatches(
            $message['subject'] ?? '',
            $rules['subject'] ?? []
        );
        $bodyMatch = $this->textMatches(
            $message['body'] ?? '',
            $rules['body'] ?? []
        );

        // Combine subject/body per configured logic (AND/OR).
        $combine = strtolower($rules['combine_subject_body'] ?? 'or');
        $subjectEnabled = (bool) ($rules['subject']['enabled'] ?? false);
        $bodyEnabled = (bool) ($rules['body']['enabled'] ?? false);

        if ($subjectEnabled && $bodyEnabled) {
            $textMatch = $combine === 'and'
                ? ($subjectMatch && $bodyMatch)
                : ($subjectMatch || $bodyMatch);
        } elseif ($subjectEnabled) {
            $textMatch = $subjectMatch;
        } elseif ($bodyEnabled) {
            $textMatch = $bodyMatch;
        } else {
            // No text rule configured → text condition is vacuously true.
            $textMatch = true;
        }

        if ($subjectEnabled && $subjectMatch) {
            $reasons[] = 'Subject matched';
        }
        if ($bodyEnabled && $bodyMatch) {
            $reasons[] = 'Body matched';
        }

        // Sender allow/deny.
        $senderOk = $this->senderAllowed($message['from'] ?? '', $rules['sender'] ?? []);
        if (! $senderOk) {
            $reasons[] = 'Sender excluded';
        }

        // Attachments. Type filtering and filename evidence are separate steps:
        // the type list is a hard filter (we only ever store what was allowed),
        // while the filename keywords say which attachments look like the target
        // document. Keeping them apart is what lets the OR logic below fall back
        // safely — see the fallback comment.
        $attRules = $rules['attachment'] ?? [];
        $allowedByType = $this->attachmentsOfAllowedType($message['attachments'] ?? [], $attRules);
        $captured = $this->filterByFilename($allowedByType, $attRules);
        $attRequired = (bool) ($attRules['required'] ?? true);
        $attachmentMatch = $attRequired ? count($captured) > 0 : true;

        if ($attRequired && count($captured) > 0) {
            $reasons[] = count($captured).' attachment(s) matched';
        }

        // Final capture logic: (attachment) AND (text) per the primary use case.
        $logic = $rules['capture_logic'] ?? 'attachment_and_text';
        $matched = match ($logic) {
            'attachment_only' => $attachmentMatch && $senderOk,
            'text_only' => $textMatch && $senderOk,
            'attachment_or_text' => ($attachmentMatch || $textMatch) && $senderOk,
            default => $attachmentMatch && $textMatch && $senderOk,
        };

        // OR-logic fallback: under `attachment_or_text` a text hit is evidence on
        // its own, so every type-allowed attachment IS the document — a supplier
        // whose subject reads "Rental Invoice" may still name the file anything.
        //
        // This is not cosmetic. CaptureService skips a verdict with an empty
        // attachment list *silently* (neither matched nor captured), so without
        // the fallback a text-only hit would report success and store nothing —
        // the "looks configured, isn't" failure this module keeps producing.
        //
        // Deliberately scoped to the new logic: `text_only` keeps its existing
        // narrowing behaviour so no live workflow starts storing more than it
        // did yesterday.
        $toCapture = $captured;
        if ($logic === 'attachment_or_text' && $captured === [] && $allowedByType !== []) {
            $toCapture = $allowedByType;
            $reasons[] = count($allowedByType).' attachment(s) captured on text evidence';
        }

        return [
            'matched' => $matched,
            'reasons' => $reasons,
            'attachments' => $matched ? $toCapture : [],
            'fields' => $this->extractFields($message),
        ];
    }

    /**
     * Best-effort field extraction. Amount/description flagged needs_review
     * when not confidently found.
     *
     * @param  array<string,mixed>  $message
     * @return array<string,mixed>
     */
    public function extractFields(array $message): array
    {
        $subject = (string) ($message['subject'] ?? '');
        $body = (string) ($message['body'] ?? '');
        $haystack = trim($subject."\n".$body);

        $amount = $this->extractAmount($haystack);

        return [
            'date' => $message['date'] ?? null,
            'from' => $message['from'] ?? null,
            'subject' => $subject,
            'message_id' => $message['message_id'] ?? null,
            'amount' => $amount['value'],
            'currency' => $amount['currency'],
            'description' => $this->extractDescription($subject),
            'needs_review' => $amount['value'] === null,
        ];
    }

    /**
     * Idempotency key: message_id|attachment_name (source-of-truth dedupe).
     */
    public function idempotencyKey(string $messageId, string $attachmentName): string
    {
        return $messageId.'|'.$attachmentName;
    }

    /** Monthly partition name, e.g. "2026-06", from an ISO date (UTC-safe). */
    public function monthlyPartition(?string $isoDate): string
    {
        if (! $isoDate) {
            return date('Y-m');
        }
        $ts = strtotime($isoDate);

        return $ts ? date('Y-m', $ts) : date('Y-m');
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $rule */
    private function textMatches(string $text, array $rule): bool
    {
        if (! ($rule['enabled'] ?? false)) {
            return false;
        }
        $keywords = array_filter((array) ($rule['keywords'] ?? []));
        if (empty($keywords)) {
            return false;
        }

        return $this->anyKeywordMatches($text, $keywords, (string) ($rule['mode'] ?? 'contains'));
    }

    /**
     * Shared matcher for subject / body / filename rules, so all three agree on
     * what "contains" and "regex" mean.
     *
     * @param  array<int,mixed>  $keywords
     */
    private function anyKeywordMatches(string $haystack, array $keywords, string $mode): bool
    {
        if (strtolower($mode) === 'regex') {
            foreach ($keywords as $pattern) {
                // Invalid patterns are ignored here rather than thrown — the
                // save path rejects them up front (EmailWorkflowController), so
                // a dead pattern can't reach a live run unnoticed.
                if (@preg_match(self::compilePattern((string) $pattern), $haystack) === 1) {
                    return true;
                }
            }

            return false;
        }

        // contains (case-insensitive)
        $lower = mb_strtolower($haystack);
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_strpos($lower, mb_strtolower((string) $kw)) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Wrap an operator-entered pattern as a case-insensitive PCRE. */
    public static function compilePattern(string $pattern): string
    {
        return '/'.str_replace('/', '\/', $pattern).'/i';
    }

    /** Would this operator-entered pattern compile? Used to reject dead rules on save. */
    public static function isValidPattern(string $pattern): bool
    {
        return @preg_match(self::compilePattern($pattern), '') !== false;
    }

    /**
     * Split an operator-entered comma/newline list into trimmed keywords.
     *
     * The comma is brace-aware. A regex quantifier is written `\d{6,}`, and a
     * naive split on every comma turns that single pattern into `\d{6` and `}` —
     * two patterns, both uncompilable, both silently ignored at match time. The
     * step-2 form round-trips its fields through here on every save, so a plain
     * split would quietly shred a working rule the next time anyone opened the
     * wizard and pressed Save.
     *
     * `,(?![^{}]*\})` keeps a comma that sits inside `{...}` and splits on every
     * other one, so `invoice, \d{3,}` still yields two keywords.
     *
     * @return array<int,string>
     */
    public static function splitKeywords(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n]+|,(?![^{}]*\})/', $raw) ?: []),
            fn ($v) => $v !== ''
        ));
    }

    /** @param array<string,mixed> $senderRules */
    private function senderAllowed(string $from, array $senderRules): bool
    {
        $from = mb_strtolower($from);

        foreach (array_filter((array) ($senderRules['denylist'] ?? [])) as $bad) {
            if ($bad !== '' && mb_strpos($from, mb_strtolower((string) $bad)) !== false) {
                return false;
            }
        }

        $allow = array_filter((array) ($senderRules['allowlist'] ?? []));
        if (empty($allow)) {
            return true; // no allowlist → everyone permitted (after denylist)
        }
        foreach ($allow as $good) {
            if ($good !== '' && mb_strpos($from, mb_strtolower((string) $good)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hard filter: attachments whose extension is on the allow-list (an empty
     * list allows everything). Nothing outside this set is ever stored.
     *
     * @param  array<int,array<string,mixed>>  $attachments
     * @param  array<string,mixed>  $rules
     * @return array<int,array<string,mixed>>
     */
    private function attachmentsOfAllowedType(array $attachments, array $rules): array
    {
        $types = array_map('strtolower', array_filter((array) ($rules['types'] ?? [])));

        $out = [];
        foreach ($attachments as $att) {
            $name = (string) ($att['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (! empty($types)
                && ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $types, true)) {
                continue;
            }

            $out[] = $att;
        }

        return $out;
    }

    /**
     * Filename evidence: which of the already type-allowed attachments look like
     * the target document. No keywords configured ⇒ every one of them does.
     *
     * `filename_mode` mirrors the subject/body `mode`. Regex earns its place on
     * filenames because supplier document codes need precision a substring can't
     * express: "(?<![a-z0-9])I-\d{6}" catches I-001068 without also catching any
     * filename that happens to contain "i-0".
     *
     * @param  array<int,array<string,mixed>>  $attachments
     * @param  array<string,mixed>  $rules
     * @return array<int,array<string,mixed>>
     */
    private function filterByFilename(array $attachments, array $rules): array
    {
        $kw = array_filter((array) ($rules['filename_keywords'] ?? []));
        if (empty($kw)) {
            return $attachments;
        }

        $mode = (string) ($rules['filename_mode'] ?? 'contains');

        $out = [];
        foreach ($attachments as $att) {
            if ($this->anyKeywordMatches((string) ($att['name'] ?? ''), $kw, $mode)) {
                $out[] = $att;
            }
        }

        return $out;
    }

    /**
     * Currency-aware amount extraction (RM/MYR, USD/$, SGD, EUR/€, GBP/£).
     *
     * @return array{value:?float, currency:?string}
     */
    private function extractAmount(string $text): array
    {
        // Symbol/code immediately before the number: "RM 1,250.00", "$99", "€1.234,56"
        $pattern = '/(RM|MYR|US\$|USD|SGD|S\$|EUR|GBP|[$€£])\s?([0-9][0-9.,\s]*[0-9]|[0-9])/iu';
        if (preg_match($pattern, $text, $m)) {
            $token = strtoupper(trim($m[1]));
            $currency = self::CURRENCIES[$token] ?? self::CURRENCIES[$m[1]] ?? null;
            $value = $this->normalizeNumber($m[2]);
            if ($value !== null) {
                return ['value' => $value, 'currency' => $currency];
            }
        }

        return ['value' => null, 'currency' => null];
    }

    /** Normalize "1,250.00" / "1.234,56" / "1 250" → float. */
    private function normalizeNumber(string $raw): ?float
    {
        $raw = trim(str_replace(' ', '', $raw));
        if ($raw === '') {
            return null;
        }

        $lastDot = strrpos($raw, '.');
        $lastComma = strrpos($raw, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Both present: the right-most is the decimal separator.
            if ($lastComma > $lastDot) {
                // European: 1.234,56
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                // US: 1,234.56
                $raw = str_replace(',', '', $raw);
            }
        } elseif ($lastComma !== false) {
            // Only commas — decimal comma if exactly 2 trailing digits, else thousands.
            if (preg_match('/,\d{2}$/', $raw)) {
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        }
        // Only dots, or none: leave as-is (PHP treats dot as decimal).

        return is_numeric($raw) ? (float) $raw : null;
    }

    /** Naive description: the subject with common noise words trimmed. */
    private function extractDescription(string $subject): string
    {
        $s = trim($subject);
        $s = preg_replace('/\b(re|fwd?):/i', '', $s) ?? $s;

        return trim($s);
    }
}
