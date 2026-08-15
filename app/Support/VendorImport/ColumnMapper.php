<?php

namespace App\Support\VendorImport;

/**
 * Works out which spreadsheet column holds which vendor field.
 *
 * Pure and network-free (same rule as DetectionEngine): headers and sample values in, a
 * mapping out. Every decision it makes is REPORTED with how it was reached — `header`,
 * `header-partial` or `values` — because the operator confirms the mapping on screen before
 * anything is written, and a guess they cannot see is a guess they cannot correct.
 *
 * It deliberately never invents a value. Its whole job is picking columns; turning the cells
 * in those columns into vendor attributes is VendorRowBuilder's, and that is where an
 * unrecognised service type or SST category is recorded as unrecognised rather than guessed.
 */
class ColumnMapper
{
    /**
     * The fields a spreadsheet column may be mapped onto, in the order the mapping table
     * shows them.
     *
     * This list is also the WHITELIST — sanitiseSubmitted() drops any field not in it, so a
     * hand-posted mapping cannot reach a column the operator was never shown. Keep anything
     * whose value steers a downstream decision (rather than merely describing the vendor) out
     * of it: an import is a bulk write nobody reviews field by field.
     */
    public const FIELDS = [
        'name' => 'Vendor name',
        'company_registration_no' => 'Company registration no.',
        'tin_number' => 'TIN',
        'sst_number' => 'SST no.',
        'sst_categories' => 'SST category',
        'vendor_types' => 'Type of service',
        'industry' => 'Industry',
        'pic_name' => 'PIC name',
        'pic_email' => 'PIC email',
        'pic_phone' => 'PIC phone',
        'technical_person_name' => 'Technical person',
        'technical_person_email' => 'Technical person email',
        'technical_person_phone' => 'Technical person phone',
        'contact_number' => 'Office contact no.',
        'email' => 'Company email',
        'website' => 'Website',
        'address' => 'Address',
        'bank_name' => 'Bank',
        'bank_account_name' => 'Bank account name',
        'bank_account_number' => 'Bank account no.',
        'bank_branch' => 'Bank branch',
        'bank_swift' => 'SWIFT / BIC',
        'notes' => 'Notes / remarks',
        'is_active' => 'Status (active / inactive)',
    ];

    /**
     * Header wordings that identify a field, already normalised (lowercase, punctuation to
     * single spaces) — so "SSM No." is listed as "ssm no" and "A/C Number" as "a c number".
     *
     * Two rules hold this list together:
     *
     *  - The MORE SPECIFIC wording wins, because matching is longest-first. "Technical
     *    Contact Email" must not be taken by `email`'s "email".
     *  - A BARE "email" / "phone" belongs to the PIC, not to the company-level column. Those
     *    are the fields the system actually uses — the e-waste RFQ and the signed AARF copy
     *    both go to `pic_email` — so mapping a lone email column to the decorative
     *    company-level field would leave every automated notification with nowhere to go.
     */
    public const SYNONYMS = [
        'name' => [
            'vendor', 'vendor name', 'supplier', 'supplier name', 'company', 'company name',
            'name', 'nama', 'nama syarikat', 'syarikat', 'business name', 'trading name',
            'vendor supplier', 'supplier vendor', 'payee', 'payee name', 'beneficiary',
            'registered name', 'legal name', 'entity', 'entity name', 'firm', 'contractor',
        ],
        'company_registration_no' => [
            'ssm', 'ssm no', 'ssm number', 'ssm registration no', 'registration no',
            'registration number', 'reg no', 'reg number', 'company registration no',
            'company registration number', 'company reg no', 'company no', 'roc', 'roc no',
            'business registration no', 'business registration number', 'no pendaftaran',
            'no ssm', 'company registration', 'brn', 'coy no',
        ],
        'tin_number' => [
            'tin', 'tin no', 'tin number', 'tax identification no', 'tax identification number',
            'income tax no', 'income tax number', 'lhdn no', 'lhdn number', 'tax id',
            'e invoice tin', 'no cukai pendapatan',
        ],
        'sst_number' => [
            'sst', 'sst no', 'sst number', 'sst registration no', 'sst reg no',
            'service tax no', 'service tax number', 'sst id', 'gst no', 'gst number',
            'no sst', 'sst registration number',
        ],
        'sst_categories' => [
            'sst category', 'sst categories', 'sst group', 'sst type', 'tax category',
            'taxable service group', 'service tax group', 'service tax category',
            'sst service group', 'sst status',
        ],
        'vendor_types' => [
            'type of service', 'service type', 'type of services', 'types of service',
            'vendor type', 'supplier type', 'vendor category', 'supplier category',
            'service category', 'services provided', 'service provided', 'services',
            'service', 'type', 'scope', 'scope of work', 'scope of service',
            'nature of service', 'product service', 'category', 'services rendered',
            'goods services', 'jenis perkhidmatan',
        ],
        'industry' => [
            'industry', 'sector', 'nature of business', 'business nature', 'business type',
            'line of business', 'industry sector', 'bidang',
        ],
        'pic_name' => [
            'pic', 'pic name', 'person in charge', 'contact person', 'contact person name',
            'contact name', 'attention', 'attn', 'attention to', 'sales person',
            'salesperson', 'representative', 'rep', 'sales rep', 'account manager',
            'liaison', 'contact', 'pegawai', 'pic contact person',
        ],
        'pic_email' => [
            'pic email', 'contact person email', 'contact email', 'email', 'e mail',
            'email address', 'emel', 'pic e mail', 'sales email', 'person in charge email',
        ],
        'pic_phone' => [
            'pic phone', 'pic contact', 'pic contact no', 'contact person phone',
            'contact person no', 'contact no', 'contact number', 'phone', 'phone no',
            'phone number', 'mobile', 'mobile no', 'mobile number', 'handphone',
            'hand phone', 'h p', 'hp', 'hp no', 'tel', 'tel no', 'telephone',
            'telephone no', 'no telefon', 'no tel', 'contact details',
        ],
        'technical_person_name' => [
            'technical person', 'technical contact', 'technical pic', 'tech contact',
            'tech person', 'support contact', 'support person', 'engineer',
            'technical support', 'service engineer', 'technician',
        ],
        'technical_person_email' => [
            'technical email', 'technical person email', 'tech email', 'support email',
            'technical contact email', 'helpdesk email',
        ],
        'technical_person_phone' => [
            'technical phone', 'technical person phone', 'technical contact no', 'tech phone',
            'support phone', 'support no', 'hotline', 'support hotline', 'helpdesk no',
        ],
        'contact_number' => [
            'office no', 'office number', 'office tel', 'office phone', 'office contact',
            'company phone', 'company tel', 'company contact no', 'general line',
            'main line', 'landline', 'office line', 'no pejabat',
        ],
        'email' => [
            'company email', 'general email', 'main email', 'official email', 'info email',
            'office email', 'corporate email', 'billing email', 'accounts email',
        ],
        'website' => [
            'website', 'web site', 'web', 'url', 'web address', 'homepage', 'site', 'www',
        ],
        'address' => [
            'address', 'addr', 'office address', 'business address', 'registered address',
            'mailing address', 'correspondence address', 'company address', 'alamat',
            'location', 'full address', 'address line 1',
        ],
        'bank_name' => [
            'bank', 'bank name', 'banker', 'name of bank', 'nama bank',
        ],
        'bank_account_name' => [
            'account name', 'acc name', 'a c name', 'bank account name', 'account holder',
            'account holder name', 'beneficiary name', 'payee name', 'nama akaun',
        ],
        'bank_account_number' => [
            'account no', 'account number', 'acc no', 'acc number', 'a c no', 'a c number',
            'ac no', 'bank account', 'bank account no', 'bank account number', 'bank acc',
            'bank acc no', 'no akaun', 'account', 'acct no',
        ],
        'bank_branch' => [
            'branch', 'bank branch', 'branch name', 'cawangan',
        ],
        'bank_swift' => [
            'swift', 'swift code', 'bic', 'bic code', 'swift bic', 'iban',
        ],
        'notes' => [
            'notes', 'note', 'remarks', 'remark', 'comment', 'comments', 'description',
            'catatan', 'additional info', 'other info',
        ],
        'is_active' => [
            'status', 'active', 'is active', 'vendor status', 'supplier status',
            'active inactive', 'aktif',
        ],
    ];

    /** How many rows from the top may hold the header. */
    private const HEADER_SEARCH_DEPTH = 15;

    /**
     * Which row is the header?
     *
     * A maintained vendor list rarely starts at A1 — there is a title, a company name, a
     * blank spacer, sometimes a legend — so the header is found by SCORING: the row that
     * matches the most known field names wins. Position is only the tie-breaker.
     *
     * @param  array<int, list<string>>  $rows
     * @return array{line: ?int, score: int, confident: bool}
     */
    public static function detectHeaderRow(array $rows): array
    {
        $bestLine = null;
        $bestScore = 0;
        $checked = 0;

        foreach ($rows as $line => $cells) {
            if ($checked++ >= self::HEADER_SEARCH_DEPTH) {
                break;
            }

            $score = 0;

            foreach ($cells as $cell) {
                if (self::exactField(self::normalise($cell)) !== null) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLine = $line;
            }
        }

        // Nothing recognisable anywhere: fall back to the first row so the mapping table
        // still renders and the operator can map it by hand. Saying "confident: false" is
        // what makes the page warn instead of quietly presenting data as headers.
        if ($bestLine === null) {
            $bestLine = array_key_first($rows);
        }

        return [
            'line' => $bestLine,
            'score' => $bestScore,
            // Two recognised headers is the threshold: one is as easily a data cell that
            // happens to read like a field name ("Status") as it is a real header row.
            'confident' => $bestScore >= 2,
        ];
    }

    /**
     * Decide what each column holds.
     *
     * @param  list<string>  $headers  the header row's cells
     * @param  array<int, list<string>>  $samples  column index => a handful of its data values
     * @return array<int, array{field: ?string, via: ?string, header: string}>
     */
    public static function map(array $headers, array $samples = []): array
    {
        $mapping = [];
        $claimed = [];

        foreach ($headers as $index => $header) {
            $mapping[$index] = ['field' => null, 'via' => null, 'header' => $header];
        }

        // Pass 1 — an exact header match. Strongest signal there is, so it claims the field
        // outright and nothing later can take it.
        foreach ($mapping as $index => $column) {
            $field = self::exactField(self::normalise($column['header']));

            if ($field !== null && ! isset($claimed[$field])) {
                $mapping[$index] = ['field' => $field, 'via' => 'header', 'header' => $column['header']];
                $claimed[$field] = true;
            }
        }

        // Pass 2 — the header CONTAINS a known wording ("Vendor Name (as per SSM)"). Weaker,
        // and flagged as such on screen, but this is what a real-world sheet looks like.
        foreach ($mapping as $index => $column) {
            if ($column['field'] !== null) {
                continue;
            }

            $field = self::partialField(self::normalise($column['header']), $claimed);

            if ($field !== null) {
                $mapping[$index] = ['field' => $field, 'via' => 'header-partial', 'header' => $column['header']];
                $claimed[$field] = true;
            }
        }

        // Pass 3 — nothing in the header, so read the DATA. A column of addresses is an
        // address column whatever it is captioned, and plenty of hand-kept lists caption
        // nothing at all.
        foreach ($mapping as $index => $column) {
            if ($column['field'] !== null) {
                continue;
            }

            $field = self::sniffField($samples[$index] ?? [], $claimed);

            if ($field !== null) {
                $mapping[$index] = ['field' => $field, 'via' => 'values', 'header' => $column['header']];
                $claimed[$field] = true;
            }
        }

        // Last resort — with no name column found, the import cannot do anything at all, so
        // take the leftmost unmapped column of mostly-filled text. Flagged as a guess from
        // the values, which is exactly what it is.
        if (! isset($claimed['name'])) {
            foreach ($mapping as $index => $column) {
                if ($column['field'] !== null) {
                    continue;
                }

                if (self::looksLikeNames($samples[$index] ?? [])) {
                    $mapping[$index] = ['field' => 'name', 'via' => 'values', 'header' => $column['header']];
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Collect a few data values per column, for the value sniffers.
     *
     * @param  array<int, list<string>>  $rows
     * @return array<int, list<string>>
     */
    public static function samples(array $rows, ?int $headerLine, int $limit = 12): array
    {
        $samples = [];
        $seen = 0;
        $past = $headerLine === null;

        foreach ($rows as $line => $cells) {
            if (! $past) {
                $past = $line === $headerLine;

                continue;
            }

            foreach ($cells as $index => $value) {
                if ($value !== '') {
                    $samples[$index][] = $value;
                }
            }

            if (++$seen >= $limit) {
                break;
            }
        }

        return $samples;
    }

    // ── Matching ──────────────────────────────────────────────────────────────
    public static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function exactField(string $normalised): ?string
    {
        if ($normalised === '') {
            return null;
        }

        foreach (self::SYNONYMS as $field => $synonyms) {
            if (in_array($normalised, $synonyms, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The longest synonym contained in the header wins.
     *
     * Longest-first is load-bearing: "Technical Contact Email" contains both "email" and
     * "technical contact email", and picking the shorter one files the support address as
     * the PIC's.
     *
     * @param  array<string, bool>  $claimed
     */
    private static function partialField(string $normalised, array $claimed): ?string
    {
        if ($normalised === '') {
            return null;
        }

        $best = null;
        $bestLength = 0;

        foreach (self::SYNONYMS as $field => $synonyms) {
            if (isset($claimed[$field])) {
                continue;
            }

            foreach ($synonyms as $synonym) {
                // Short synonyms are excluded from substring matching entirely — "web" would
                // otherwise match "Web of trust", and "hp" matches almost anything.
                if (mb_strlen($synonym) < 4 || mb_strlen($synonym) <= $bestLength) {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($synonym, '/').'\b/', $normalised)) {
                    $best = $field;
                    $bestLength = mb_strlen($synonym);
                }
            }
        }

        return $best;
    }

    /**
     * Identify a column from what is IN it.
     *
     * Each test needs a clear majority of the column's non-empty values, so one stray email
     * in a notes column does not turn it into the email column.
     *
     * @param  list<string>  $values
     * @param  array<string, bool>  $claimed
     */
    private static function sniffField(array $values, array $claimed): ?string
    {
        if ($values === []) {
            return null;
        }

        if (self::majority($values, static fn ($v) => (bool) filter_var($v, FILTER_VALIDATE_EMAIL))) {
            foreach (['pic_email', 'email', 'technical_person_email'] as $field) {
                if (! isset($claimed[$field])) {
                    return $field;
                }
            }

            return null;
        }

        if (self::majority($values, static fn ($v) => self::looksLikeUrl($v))) {
            return isset($claimed['website']) ? null : 'website';
        }

        if (self::majority($values, static fn ($v) => self::looksLikePhone($v))) {
            foreach (['pic_phone', 'contact_number', 'technical_person_phone'] as $field) {
                if (! isset($claimed[$field])) {
                    return $field;
                }
            }

            return null;
        }

        if (self::majority($values, static fn ($v) => self::looksLikeAddress($v))) {
            return isset($claimed['address']) ? null : 'address';
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     */
    private static function majority(array $values, callable $test, float $threshold = 0.6): bool
    {
        $hits = 0;

        foreach ($values as $value) {
            if ($test($value)) {
                $hits++;
            }
        }

        return $values !== [] && ($hits / count($values)) >= $threshold;
    }

    private static function looksLikeUrl(string $value): bool
    {
        if (str_contains($value, '@') || str_contains($value, ' ')) {
            return false;
        }

        return (bool) preg_match('#^(https?://|www\.)#i', $value)
            || (bool) preg_match('/^[a-z0-9.-]+\.(com|net|org|my|co|io|biz|asia)(\.[a-z]{2})?$/i', $value);
    }

    /**
     * Phone-ish: mostly digits, with the punctuation a typed number carries and nothing else.
     * The digit count range covers a 7-digit landline through a country-coded mobile, and
     * excludes the long unbroken runs that are bank accounts and registration numbers.
     */
    private static function looksLikePhone(string $value): bool
    {
        if (! preg_match('/^[+(]?[\d][\d\s\-()+\/.,]*$/', $value)) {
            return false;
        }

        $digits = strlen(preg_replace('/\D/', '', $value) ?? '');

        return $digits >= 7 && $digits <= 15;
    }

    /**
     * Addresses are long, contain a number and a letter, and usually a comma or a newline.
     * Deliberately conservative — this is the loosest of the sniffers and the one most able
     * to steal a notes column.
     */
    private static function looksLikeAddress(string $value): bool
    {
        if (mb_strlen($value) < 20) {
            return false;
        }

        if (! preg_match('/\d/', $value) || ! preg_match('/[A-Za-z]{3}/', $value)) {
            return false;
        }

        return str_contains($value, ',') || str_contains($value, "\n")
            || (bool) preg_match('/\b\d{5}\b/', $value); // Malaysian postcode
    }

    /**
     * @param  list<string>  $values
     */
    private static function looksLikeNames(array $values): bool
    {
        if (count($values) < 2) {
            return false;
        }

        return self::majority($values, static fn ($v) => mb_strlen($v) >= 3
            && preg_match('/[A-Za-z]{3}/', $v) === 1
            && ! filter_var($v, FILTER_VALIDATE_EMAIL)
            && ! self::looksLikePhone($v));
    }

    /**
     * The mapping an operator submitted, cleaned: unknown fields dropped, and a field claimed
     * twice kept only on its first column.
     *
     * Both matter — the selects are rendered per column, so nothing stops someone picking
     * "PIC email" twice, and silently letting the second column win would file whichever
     * value happened to be rightmost.
     *
     * @param  array<int|string, string|null>  $submitted
     * @param  list<string>  $headers
     * @return array<int, array{field: ?string, via: ?string, header: string}>
     */
    public static function sanitiseSubmitted(array $submitted, array $headers): array
    {
        $mapping = [];
        $claimed = [];

        foreach ($headers as $index => $header) {
            $field = $submitted[$index] ?? null;
            $field = is_string($field) && $field !== '' ? $field : null;

            if ($field !== null && (! isset(self::FIELDS[$field]) || isset($claimed[$field]))) {
                $field = null;
            }

            if ($field !== null) {
                $claimed[$field] = true;
            }

            $mapping[$index] = [
                'field' => $field,
                'via' => $field === null ? null : 'operator',
                'header' => $header,
            ];
        }

        return $mapping;
    }

    /**
     * Field => column index, for the row builder.
     *
     * @param  array<int, array{field: ?string, via: ?string, header: string}>  $mapping
     * @return array<string, int>
     */
    public static function byField(array $mapping): array
    {
        $byField = [];

        foreach ($mapping as $index => $column) {
            if ($column['field'] !== null && ! isset($byField[$column['field']])) {
                $byField[$column['field']] = $index;
            }
        }

        return $byField;
    }
}
