<?php

namespace App\Support\VendorImport;

use App\Models\Vendor;

/**
 * Turns one spreadsheet row into the attributes of a Vendor — and into the list of
 * judgement calls it had to make on the way.
 *
 * The `notes` a row comes back with are the point of this class, not decoration. An importer
 * that quietly files "Aircond servicing" as Other, or drops a malformed email, produces a
 * vendor master nobody can trust, because the operator has no way to know which rows were
 * interpreted and which were read. Every deviation from what the cell literally said is
 * stated on the preview and survives into the import report.
 *
 * Pure: constants and strings in, an array out. No models are touched (the duplicate check
 * and the writing are the controller's), so the whole thing is unit-testable.
 */
class VendorRowBuilder
{
    /**
     * Column lengths, mirroring the migration. A value longer than its column is TRUNCATED
     * and said so, never silently cut and never allowed to reach the database, where it
     * would abort the whole import over one over-long address.
     */
    private const LIMITS = [
        'name' => 255,
        'company_registration_no' => 100,
        'tin_number' => 100,
        'sst_number' => 60,
        'pic_name' => 255,
        'pic_email' => 255,
        'pic_phone' => 50,
        'technical_person_name' => 255,
        'technical_person_email' => 255,
        'technical_person_phone' => 50,
        'contact_number' => 50,
        'email' => 255,
        'website' => 255,
        'address' => 1000,
        'bank_name' => 255,
        'bank_account_name' => 255,
        'bank_account_number' => 50,
        'bank_branch' => 255,
        'bank_swift' => 20,
        'notes' => 2000,
    ];

    /** Fields copied across as plain text once cleaned. */
    private const TEXT_FIELDS = [
        'name', 'company_registration_no', 'tin_number', 'sst_number',
        'pic_name', 'technical_person_name', 'pic_phone', 'technical_person_phone',
        'contact_number', 'website', 'address', 'bank_name', 'bank_account_name',
        'bank_account_number', 'bank_branch', 'notes',
    ];

    private const EMAIL_FIELDS = ['pic_email', 'email', 'technical_person_email'];

    /**
     * Words that identify a service type, checked in THIS ORDER when the value does not
     * match a type's own key or label.
     *
     * Order is load-bearing where one phrase contains another: `cybersecurity` sits above
     * `security` so "Cybersecurity Services" is not filed as a guard company, and `ewaste`
     * above `waste_management` so "E-Waste Disposal" is not filed as general waste.
     */
    private const TYPE_HINTS = [
        'ewaste' => ['ewaste', 'e waste', 'electronic waste', 'scrap', 'recycl'],
        'cybersecurity' => ['cyber', 'penetration test', 'antivirus', 'endpoint security'],
        'cloud_hosting' => ['cloud', 'hosting', 'data centre', 'data center', 'colocation'],
        'it_services' => ['it service', 'it support', 'it solution', 'ict', 'information technology', 'system integrat', 'network support'],
        'software' => ['software', 'saas', 'subscription', 'licence', 'license', 'application'],
        'telco' => ['telco', 'telecommunication', 'broadband', 'fibre', 'fiber', 'internet', 'mobile line', 'data plan'],
        'rental' => ['rent', 'rental', 'sewa', 'hire'],
        'leasing' => ['lease', 'leasing'],
        'repair' => ['repair', 'maintenance', 'servicing', 'aircond', 'upkeep'],
        'purchase' => ['purchase', 'supply', 'supplier', 'trading', 'procurement', 'hardware', 'equipment supply'],
        'legal' => ['legal', 'lawyer', 'solicitor', 'law firm', 'advocate'],
        'audit_accounting' => ['audit', 'accounting', 'accountant', 'bookkeep', 'tax agent', 'company secretar'],
        'banking_financial' => ['banking', 'financial service', 'leasing bank'],
        'insurance' => ['insurance', 'takaful'],
        'recruitment' => ['recruit', 'staffing', 'headhunt', 'manpower', 'outsourc'],
        'payroll_hr' => ['payroll', 'hr service', 'human resource'],
        'training' => ['training', 'course provider', 'certification'],
        'employee_benefits' => ['employee benefit', 'welfare', 'medical card', 'panel clinic'],
        'facilities' => ['facilit', 'building management', 'property management'],
        'cleaning' => ['clean', 'housekeep', 'janitor'],
        'security' => ['security', 'guard', 'cctv', 'alarm'],
        'office_supplies' => ['stationer', 'office supply', 'office supplies', 'pantry'],
        'furniture_equipment' => ['furniture', 'office equipment', 'fitting'],
        'utilities' => ['utilit', 'electricity', 'water supply', 'tnb', 'indah water'],
        'logistics' => ['logistic', 'courier', 'shipping', 'freight', 'forwarding', 'warehous'],
        'transportation' => ['transport', 'vehicle', 'limousine', 'driver'],
        'travel' => ['travel', 'flight', 'hotel', 'accommodation', 'tour'],
        'marketing' => ['marketing', 'advertis', 'branding', 'digital agency'],
        'media_pr' => ['media', 'public relation', 'publicity'],
        'printing_design' => ['printing', 'print', 'design', 'signage', 'banner'],
        'events' => ['event', 'exhibition', 'roadshow'],
        'catering' => ['catering', 'food', 'beverage', 'refreshment'],
        'construction' => ['construction', 'renovation', 'contractor', 'wiring'],
        'waste_management' => ['waste', 'disposal', 'rubbish'],
        // 'consult' rather than 'consultan': the longer stem misses "consultation", which is
        // how these are worded at least as often as "consultancy".
        'professional' => ['consult', 'professional', 'advisory', 'engineering'],
        'other' => ['other', 'miscellaneous', 'general'],
    ];

    /**
     * Same shape, same ordering rule, for the reporting-only industry field.
     *
     * The generic IT wordings sit on `it_software` because the list carries no neutral
     * "IT" industry and a services firm is nearer software than hardware. `it_hardware`
     * still wins on "IT Hardware" / "Computer Hardware", which carry none of these words —
     * the one value this mis-files is "ICT Hardware", and inventing a whole industry to
     * catch it would change the option list every existing vendor is filed under.
     */
    private const INDUSTRY_HINTS = [
        'it_software' => ['software', 'saas', 'application', 'information technology', 'it service', 'it support', 'it solution', 'it consult', 'ict'],
        'it_hardware' => ['hardware', 'computer', 'it equipment'],
        'telecommunications' => ['telco', 'telecommunication', 'broadband'],
        'waste_management' => ['waste', 'recycl', 'scrap'],
        'financial_services' => ['bank', 'financ', 'insurance', 'takaful'],
        'manufacturing' => ['manufactur', 'factory', 'production'],
        'construction' => ['construction', 'renovation', 'contractor'],
        'logistics' => ['logistic', 'courier', 'transport', 'freight'],
        'facilities' => ['facilit', 'property', 'building'],
        'advertising' => ['advertis', 'media', 'marketing', 'creative'],
        'education' => ['education', 'training', 'academy'],
        'healthcare' => ['health', 'medical', 'clinic', 'pharma'],
        'hospitality' => ['hotel', 'restaurant', 'catering', 'hospitality'],
        'retail' => ['retail', 'trading', 'wholesale'],
        'utilities' => ['utilit', 'electricity', 'water'],
        'professional_services' => ['consult', 'professional', 'legal', 'audit', 'accounting'],
        'other' => ['other', 'general'],
    ];

    /**
     * SST wordings, checked in this order. `not_registered` is tested FIRST because a cell
     * reading "Not registered (Group G previously)" is a statement that they hold no
     * registration, and matching the group in it would invert the answer.
     */
    private const SST_HINTS = [
        'not_registered' => ['not registered', 'not sst registered', 'non sst', 'no sst', 'not applicable', 'nil', 'none', 'n a', 'tidak berdaftar'],
        'sales_tax' => ['sales tax', 'manufacturer', 'importer'],
        'wellness' => ['night club', 'wellness', 'dance hall', 'massage'],
        'golf_clubs' => ['golf', 'driving range'],
        'private_clubs' => ['private club', 'members club', 'club'],
        'betting_gaming' => ['betting', 'gaming', 'casino', 'lottery', 'sweepstake'],
        'accommodation' => ['accommodation', 'hotel', 'lodging', 'homestay', 'inn'],
        'food_beverage' => ['food', 'beverage', 'restaurant', 'cafe', 'catering', 'f b'],
        'credit_card' => ['credit card', 'charge card'],
        'logistics' => ['logistic', 'warehous', 'forwarding', 'courier'],
        'rental_leasing' => ['rental', 'leasing', 'rent'],
        'construction' => ['construction', 'renovation', 'civil', 'mechanical'],
        'other_services' => ['other service', 'advertising', 'insurance', 'motor repair', 'parking', 'telecommunication'],
        'professional' => ['professional', 'legal', 'accounting', 'engineering', 'architecture', 'consultancy', 'consulting', 'it', 'digital'],
    ];

    private const INACTIVE_WORDS = [
        'inactive', 'not active', 'no', 'n', '0', 'false', 'terminated', 'blacklisted',
        'blacklist', 'closed', 'discontinued', 'dormant', 'expired', 'suspended', 'tidak aktif',
    ];

    private const ACTIVE_WORDS = ['active', 'yes', 'y', '1', 'true', 'ok', 'current', 'aktif'];

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $byField  field => column index
     * @return array{attributes: array<string, mixed>, notes: list<string>, error: ?string, defaulted: list<string>}
     */
    public static function build(array $cells, array $byField): array
    {
        $notes = [];
        $attributes = [];

        // Attributes this row FILLED IN rather than read. They are correct for a new vendor
        // (vendor_types cannot be empty) but must never be written over an existing one —
        // "the sheet has no type column" is not a reason to re-tag a vendor as Other.
        $defaulted = [];

        $value = static function (string $field) use ($cells, $byField): string {
            $index = $byField[$field] ?? null;

            return $index === null ? '' : SpreadsheetReader::clean($cells[$index] ?? '');
        };

        // ── Identity ──────────────────────────────────────────────────────────
        $name = $value('name');

        if ($name === '') {
            return [
                'attributes' => [],
                'notes' => [],
                'defaulted' => [],
                'error' => isset($byField['name'])
                    ? 'No vendor name in this row.'
                    : 'No column is mapped to "Vendor name", so this row cannot be imported.',
            ];
        }

        foreach (self::TEXT_FIELDS as $field) {
            $raw = $value($field);

            if ($raw === '') {
                continue;
            }

            $attributes[$field] = self::limit($raw, $field, $notes);
        }

        // ── Emails ────────────────────────────────────────────────────────────
        // An address the mail layer would refuse is worse than a blank one: the e-waste RFQ
        // and the signed AARF copy both go to pic_email, and a silent failure there looks
        // like the feature not working.
        foreach (self::EMAIL_FIELDS as $field) {
            $raw = $value($field);

            if ($raw === '') {
                continue;
            }

            $email = self::firstEmail($raw);

            if ($email === null) {
                $notes[] = ColumnMapper::FIELDS[$field].' "'.$raw.'" is not a valid email address — left blank.';

                continue;
            }

            if ($email !== $raw) {
                $notes[] = ColumnMapper::FIELDS[$field].' read as "'.$email.'" from "'.$raw.'".';
            }

            $attributes[$field] = self::limit($email, $field, $notes);
        }

        // ── Service types ─────────────────────────────────────────────────────
        $rawTypes = $value('vendor_types');
        [$types, $typeNotes] = self::resolveTypes($rawTypes);
        $attributes['vendor_types'] = $types;
        $notes = array_merge($notes, $typeNotes);

        if ($types === ['other'] && trim($rawTypes) === '') {
            $defaulted[] = 'vendor_types';
        }

        // ── Industry ──────────────────────────────────────────────────────────
        $rawIndustry = $value('industry');

        if ($rawIndustry !== '') {
            $industry = self::matchOne($rawIndustry, Vendor::INDUSTRIES, self::INDUSTRY_HINTS);

            if ($industry === null) {
                $notes[] = 'Industry "'.$rawIndustry.'" is not one of the industries this system holds — left blank. Set it on the vendor\'s profile after importing.';
            }

            $attributes['industry'] = $industry;
        }

        // ── SST categories ────────────────────────────────────────────────────
        [$categories, $sstNotes] = self::resolveSstCategories($value('sst_categories'));
        $notes = array_merge($notes, $sstNotes);

        if ($categories !== null) {
            $attributes['sst_categories'] = $categories;
        }

        // ── Status ────────────────────────────────────────────────────────────
        $rawStatus = $value('is_active');

        if ($rawStatus !== '') {
            $normalised = ColumnMapper::normalise($rawStatus);

            if (in_array($normalised, self::INACTIVE_WORDS, true)) {
                $attributes['is_active'] = false;
            } elseif (in_array($normalised, self::ACTIVE_WORDS, true)) {
                $attributes['is_active'] = true;
            } else {
                $attributes['is_active'] = true;
                $notes[] = 'Status "'.$rawStatus.'" was not recognised — imported as Active.';
            }
        }

        return ['attributes' => $attributes, 'notes' => $notes, 'error' => null, 'defaulted' => $defaulted];
    }

    /**
     * Types are a LIST in one cell — "Rental; Repair & Maintenance" — so each fragment is
     * resolved on its own and an unrecognised one is named rather than dropped.
     *
     * A row with no recognisable type is filed as "Other" and says so. Refusing it instead
     * would block the import over a field the sheet may not even have, and guessing a type
     * from the vendor's NAME is exactly the sort of inference that files a telco under
     * Utilities.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function resolveTypes(string $raw): array
    {
        $notes = [];

        if (trim($raw) === '') {
            return [['other'], ['No service type in the sheet — filed as "Other". Re-tag the vendor on its profile.']];
        }

        $matched = [];
        $unmatched = [];

        foreach (self::fragments($raw) as $fragment) {
            $type = self::matchOne($fragment, Vendor::TYPES, self::TYPE_HINTS);

            if ($type === null) {
                $unmatched[] = $fragment;

                continue;
            }

            $matched[$type] = true;
        }

        $types = array_keys($matched);

        if ($unmatched !== []) {
            $notes[] = 'Service type '.self::quoteList($unmatched).' not recognised'
                .($types === [] ? ' — filed as "Other" and kept in the notes.' : ' — kept in the notes.');
        }

        if ($types === []) {
            $types = ['other'];
        }

        return [$types, $notes];
    }

    /**
     * SST is the one field where a wrong answer costs money — sstVerdict() decides whether an
     * invoice's SST line gets flagged — so an ambiguous cell is recorded as NOT RECORDED
     * rather than resolved. A cell claiming both "not registered" and a taxable group is
     * self-contradictory, and picking either half would state something the sheet did not.
     *
     * Null means "leave the column alone"; an empty result and a contradiction both land
     * there, because "not recorded" and "not registered" are different answers.
     *
     * @return array{0: ?list<string>, 1: list<string>}
     */
    private static function resolveSstCategories(string $raw): array
    {
        if (trim($raw) === '') {
            return [null, []];
        }

        $matched = [];
        $unmatched = [];
        $clashes = [];

        foreach (self::fragments($raw) as $fragment) {
            [$key, $clash] = self::resolveSstFragment($fragment);

            if ($key !== null) {
                $matched[$key] = true;

                continue;
            }

            if ($clash !== null) {
                $clashes[] = $clash;

                continue;
            }

            $unmatched[] = $fragment;
        }

        $keys = array_keys($matched);

        // A clash is reported whatever else the cell held, but its CONSEQUENCE depends on
        // whether anything else in the cell resolved — so the diagnosis is built where the
        // clash is found and the outcome sentence is added here, where that is known.
        $outcome = $keys === []
            ? ' Left blank rather than guessing which group is meant.'
            : ' This group was left out rather than guessed.';

        $notes = array_map(static fn (string $clash): string => $clash.$outcome, $clashes);

        if ($keys === []) {
            if ($unmatched !== []) {
                $notes[] = 'SST category '.self::quoteList($unmatched).' was not recognised — left blank ("not recorded", which is not the same as "not registered").';
            }

            return [null, $notes];
        }

        if (in_array('not_registered', $keys, true) && count($keys) > 1) {
            return [null, ['SST category "'.$raw.'" says both "not SST-registered" and a taxable group — left blank rather than guessing which is meant.']];
        }

        if ($unmatched !== []) {
            $notes[] = 'Part of the SST category ('.self::quoteList($unmatched).') was not recognised and was ignored.';
        }

        return [$keys, $notes];
    }

    /**
     * Resolve one SST fragment to a category key, or explain why it was left out.
     *
     * Our own label is authoritative and is tried first. Failing that, a fragment carrying a
     * statutory GROUP LETTER is judged on the letter — but only when the wording after it
     * does not contradict the letter. "Group K — Other taxable services" names a letter we
     * hold under different wording ("Rental or leasing services"), and the two cannot both be
     * right: taking the letter files the vendor under a group the sheet does not describe,
     * and taking the description ignores the one part of the cell that is a statutory
     * identity. Neither is guessed. This is the field the B2B exemption turns on, so the
     * clash is reported and the group left out.
     *
     * A bare "Group K" carries no description to disagree with, so it still resolves — as do
     * our own labels, which arrive comma-split into fragments that each confirm their letter.
     *
     * @return array{0: ?string, 1: ?string} [category key, the clash to report]
     */
    private static function resolveSstFragment(string $fragment): array
    {
        $options = Vendor::sstCategories() + Vendor::LEGACY_SST_CATEGORIES;
        $normalised = ColumnMapper::normalise($fragment);

        foreach ($options as $key => $label) {
            if ($normalised === ColumnMapper::normalise($key) || $normalised === ColumnMapper::normalise($label)) {
                return [$key, null];
            }
        }

        $group = self::sstGroupLetter($fragment);

        if ($group !== null) {
            if ($group['rest'] === '' || self::matchOne($group['rest'], $options, self::SST_HINTS) === $group['key']) {
                return [$group['key'], null];
            }

            return [null, 'SST category "'.trim($fragment).'" does not match this system: Group '
                .strtoupper($group['letter']).' here is "'.self::sstGroupDescription($group['label']).'".'];
        }

        return [self::matchOne($fragment, $options, self::SST_HINTS), null];
    }

    /**
     * The category a fragment's leading "Group X" names, with whatever followed it.
     *
     * `\b` after the letter is what stops "Group Kitchen services" being read as Group K.
     *
     * @return array{key: string, label: string, letter: string, rest: string}|null
     */
    private static function sstGroupLetter(string $fragment): ?array
    {
        if (! preg_match('/^\s*group\s*([a-l])\b[\s\p{Pd}:.]*(.*)$/iu', $fragment, $match)) {
            return null;
        }

        $letter = strtolower($match[1]);

        foreach (Vendor::sstCategories() as $key => $label) {
            if (str_starts_with(ColumnMapper::normalise($label), 'group '.$letter.' ')) {
                return ['key' => $key, 'label' => $label, 'letter' => $letter, 'rest' => trim($match[2])];
            }
        }

        return null;
    }

    /** The label without its "Group X — " prefix, which the sentence carrying it already said. */
    private static function sstGroupDescription(string $label): string
    {
        return trim(preg_replace('/^\s*group\s*[a-l]\s*\p{Pd}\s*/iu', '', $label) ?? $label);
    }

    /**
     * Resolve one value against a key => label list, then against ordered hint words.
     *
     * The exact key and the exact label are tried first so a value that IS one of our own
     * labels can never be re-routed by a hint word buried in it.
     *
     * @param  array<string, string>  $options
     * @param  array<string, list<string>>  $hints
     */
    private static function matchOne(string $value, array $options, array $hints): ?string
    {
        $normalised = ColumnMapper::normalise($value);

        if ($normalised === '') {
            return null;
        }

        foreach ($options as $key => $label) {
            if ($normalised === ColumnMapper::normalise($key) || $normalised === ColumnMapper::normalise($label)) {
                return $key;
            }
        }

        foreach ($hints as $key => $words) {
            // A hint may name a key that a config override removed; skip rather than store
            // a category the form cannot display.
            if (! isset($options[$key])) {
                continue;
            }

            foreach ($words as $word) {
                if (self::hintMatches($normalised, $word)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Does a hint word appear in an already-normalised value?
     *
     * A SHORT hint has to be a whole word, and that is not a refinement — it is a bug fix.
     * `professional` carries the hint `it` (its label ends "IT & digital"), and as a plain
     * substring that matches inside "fac-IT-ies", "secur-IT-y", "k-IT-chen" and
     * "recru-IT-ment", so any of those cells filed the vendor under Group G — the group that
     * decides whether their invoices get an SST flag. `ict` and `n a` had the same reach.
     *
     * Longer hints stay substrings, which is what lets `rent` match "rental" and `club` match
     * "clubs". Both sides are needed; neither generalises to the other.
     *
     * Normalisation has already collapsed everything to single-space-separated words, so
     * padding both sides is a whole-word test that also works for multi-word hints ("f b").
     */
    private static function hintMatches(string $normalised, string $word): bool
    {
        if (mb_strlen($word) >= 4) {
            return str_contains($normalised, $word);
        }

        return str_contains(' '.$normalised.' ', ' '.$word.' ');
    }

    /**
     * Split a multi-value cell. Newlines, commas, semicolons, slashes and bullets are all
     * used as separators in hand-kept lists; "&" and "and" are NOT, because they appear
     * inside our own labels ("Repair & Maintenance").
     *
     * @return list<string>
     */
    private static function fragments(string $raw): array
    {
        $parts = preg_split('/[\r\n,;|•]+|\s\/\s|\/(?=\s)/u', $raw) ?: [];
        $parts = array_map('trim', $parts);
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));

        return $parts === [] ? [trim($raw)] : $parts;
    }

    /**
     * The first address in a cell that may hold several, or a label and an address.
     * Returns null when there is nothing a mail server would accept.
     */
    private static function firstEmail(string $raw): ?string
    {
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return $raw;
        }

        foreach (preg_split('/[\s,;\/|]+/', $raw) ?: [] as $candidate) {
            $candidate = trim($candidate, '<>()[]"\'');

            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $notes
     */
    private static function limit(string $value, string $field, array &$notes): string
    {
        $max = self::LIMITS[$field] ?? 255;

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $notes[] = ColumnMapper::FIELDS[$field].' was longer than the '.$max.'-character limit and was shortened.';

        return mb_substr($value, 0, $max);
    }

    /**
     * @param  list<string>  $values
     */
    private static function quoteList(array $values): string
    {
        return implode(', ', array_map(static fn ($v) => '"'.$v.'"', array_slice($values, 0, 4)))
            .(count($values) > 4 ? ' and '.(count($values) - 4).' more' : '');
    }
}
