<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The company-wide operational vendor master.
 *
 * Started life (2026-07-27) as the rental/repair/e-waste list the two decommissioning
 * flows read their vendor + PIC from, and was widened (2026-08-06) to hold EVERY vendor
 * the company deals with — suppliers we buy assets from, service providers, subscriptions,
 * professional services. The three original type tokens are kept verbatim because
 * `whereJsonContains('vendor_types', 'rental'|'ewaste')` is load-bearing for the quarterly
 * e-waste RFQ and the rental-return flow.
 *
 * Deliberately SEPARATE from the finance Accounting\Vendor (acc_vendors), which is an
 * AP ledger row (vendor_code, credit terms, balances) with no PIC, no contracts and no
 * asset linkage. Nothing posts between the two.
 */
class Vendor extends Model
{
    protected $table = 'vendors';

    /**
     * What we engage this vendor FOR — the "type of service" on the registration form.
     *
     * `rental`, `repair` and `ewaste` are the original decommissioning tokens and must
     * keep their exact keys: EwasteSweepService, the batch modal and the asset pickers
     * all filter on them with whereJsonContains. Everything below them was added when
     * the master widened; add freely, never rename.
     *
     * Widened to the operator's full service list on 2026-08-14. Every pre-existing KEY
     * survived — a stored `vendor_types` array never needed rewriting — but three labels
     * NARROWED, because the new list separates what they used to cover and a token must
     * not read as two things at once:
     *
     *   utilities  'Utilities & Telco'   → 'Utilities'                (telco is now `telco`)
     *   marketing  'Marketing & Media'   → 'Marketing & Advertising'  (PR is now `media_pr`)
     *   facilities 'Facilities & Office' → 'Facilities Management'    (supplies/furniture split out)
     *
     * Nothing re-tags existing rows for those three: which half a vendor was tagged under
     * is not derivable from the token, and guessing would file a telco under Utilities or
     * a PR agency under Advertising. Re-tag them by hand when their profile is next opened.
     */
    public const TYPES = [
        'rental' => 'Asset Rental',
        'leasing' => 'Leasing',
        'purchase' => 'Asset Supply / Purchase',
        'repair' => 'Repair & Maintenance',
        'ewaste' => 'E-waste Disposal',
        'it_services' => 'IT Services',
        'software' => 'Software / Subscription',
        'cloud_hosting' => 'Cloud & Hosting Services',
        'cybersecurity' => 'Cybersecurity Services',
        'telco' => 'Telecommunications & Internet',
        'professional' => 'Professional / Consultancy Services',
        'legal' => 'Legal Services',
        'audit_accounting' => 'Audit & Accounting Services',
        'banking_financial' => 'Banking & Financial Services',
        'insurance' => 'Insurance Services',
        'recruitment' => 'Recruitment & Staffing',
        'payroll_hr' => 'Payroll & HR Services',
        'training' => 'Training & Development',
        'employee_benefits' => 'Employee Benefits & Welfare',
        'facilities' => 'Facilities Management',
        'cleaning' => 'Cleaning Services',
        'security' => 'Security Services',
        'office_supplies' => 'Office Supplies & Stationery',
        'furniture_equipment' => 'Furniture & Office Equipment',
        'utilities' => 'Utilities',
        'logistics' => 'Logistics & Courier',
        'transportation' => 'Transportation Services',
        'travel' => 'Travel & Accommodation',
        'marketing' => 'Marketing & Advertising',
        'media_pr' => 'Media & Public Relations',
        'printing_design' => 'Printing & Design Services',
        'events' => 'Event Management',
        'catering' => 'Food & Catering',
        'construction' => 'Construction & Renovation',
        'waste_management' => 'Waste Management / Disposal',
        'other' => 'Other',
    ];

    /**
     * Types whose vendors can be picked when registering an asset, by ownership.
     *
     * `rental` and `leasing` were one option ("Rental / Leasing") until they were split,
     * and an asset held under either is stored as `ownership_type = 'rental'` — so both
     * tokens belong here, or a leasing-only vendor would vanish from the picker meant to
     * find them. Read this constant rather than testing 'rental' by hand.
     */
    public const RENTAL_ASSET_TYPES = ['rental', 'leasing'];

    /**
     * Types whose vendors are offered when registering a company-OWNED asset.
     *
     * `furniture_equipment` joined them in the 2026-08-14 expansion: office equipment is
     * registered in the asset inventory like any other kit, so a supplier tagged only that
     * would otherwise be missing from the picker built to find who we bought it from. The
     * rest of the new tokens are services, which nothing is purchased as an asset from.
     */
    public const PURCHASE_ASSET_TYPES = ['purchase', 'it_services', 'software', 'furniture_equipment', 'other'];

    /**
     * Bank names offered as autocomplete SUGGESTIONS on the registration form.
     *
     * Deliberately a datalist and not a validated whitelist (the shape the employee bank
     * field uses, with its "Other" companion input): a vendor is regularly a foreign
     * software or logistics supplier banking somewhere that will never appear on a
     * Malaysian list, and refusing their real bank name would push it into the notes field
     * where nothing can read it. Typing beyond the list is expected, not an error.
     */
    public const BANK_SUGGESTIONS = [
        'Maybank', 'CIMB Bank', 'Public Bank', 'RHB Bank', 'Hong Leong Bank', 'AmBank',
        'Bank Islam', 'Bank Rakyat', 'BSN', 'OCBC Bank', 'UOB Malaysia', 'HSBC Bank',
        'Standard Chartered', 'Affin Bank', 'Alliance Bank',
    ];

    /** The industry the vendor operates in — reporting/segmentation, not routing. */
    public const INDUSTRIES = [
        'it_hardware' => 'IT Hardware',
        'it_software' => 'IT Software & SaaS',
        'telecommunications' => 'Telecommunications',
        'professional_services' => 'Professional Services',
        'financial_services' => 'Financial Services',
        'manufacturing' => 'Manufacturing',
        'construction' => 'Construction & Renovation',
        'logistics' => 'Logistics & Transport',
        'waste_management' => 'Waste Management & Recycling',
        'facilities' => 'Facilities & Property',
        'advertising' => 'Advertising & Media',
        'education' => 'Education & Training',
        'healthcare' => 'Healthcare',
        'hospitality' => 'Hospitality & F&B',
        'retail' => 'Retail & Trading',
        'utilities' => 'Utilities',
        'other' => 'Other',
    ];

    /**
     * The taxable-service groups a vendor is registered under, plus the two
     * non-service-tax answers.
     *
     * This is the field the B2B exemption turns on: a registered person acquiring a
     * taxable service from another registered person in the SAME group is exempt from
     * paying service tax on it. A vendor may hold SEVERAL groups at once (a consultancy
     * that also leases equipment is Group G and Group K), which is why the column is a
     * list — see the 2026-08-14 migration.
     *
     * Keys are deliberately DESCRIPTIVE, not the statutory letters, even though the
     * LABELS now carry them at the operator's request. The First Schedule has been
     * re-lettered and extended more than once, so a re-gazette becomes a label edit here
     * instead of a data migration — changing a KEY orphans every vendor row holding it.
     * Override the whole list in config/vendors.php when Finance wants different wording.
     *
     * @return array<string,string>
     */
    public static function sstCategories(): array
    {
        $configured = config('vendors.sst_categories');

        return is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_SST_CATEGORIES;
    }

    public const DEFAULT_SST_CATEGORIES = [
        'accommodation' => 'Group A — Accommodation (hotels, inns, lodging houses, homestays)',
        'food_beverage' => 'Group B — Food & beverage (restaurants, cafés, bars, food courts, catering)',
        'wellness' => 'Group C — Night clubs, dance halls & wellness centres',
        'private_clubs' => 'Group D — Private clubs (members\' clubs run on subscription)',
        'golf_clubs' => 'Group E — Golf clubs & driving ranges',
        'betting_gaming' => 'Group F — Betting & gaming (casinos, sweepstakes, lotteries, racing)',
        'professional' => 'Group G — Professional services (legal, accounting, engineering, architecture, consultancy, IT & digital)',
        'credit_card' => 'Group H — Credit cards, charge cards & financial services',
        'other_services' => 'Group I — Other service providers (advertising, insurance/takaful, motor repair, parking, telecommunications)',
        'logistics' => 'Group J — Logistics services (logistics, warehousing, forwarding, courier)',
        'rental_leasing' => 'Group K — Rental or leasing services (commercial property, equipment)',
        'construction' => 'Group L — Construction works (renovation, civil, electrical, mechanical)',
        'sales_tax' => 'Sales Tax registrant (manufacturer / importer)',
        'not_registered' => 'Not SST-registered',
    ];

    /**
     * Categories the form no longer offers, kept solely so a row that still holds one
     * renders as words rather than a raw slug.
     *
     * They were guesses at the 2025 expansion made before the statutory list was to hand;
     * the groups above are the operator's own. Nothing rewrites a stored value to a new
     * group — where `healthcare` now belongs is a tax question, and answering it silently
     * in a migration would change what the B2B exemption says about that vendor's bills.
     * The registration form shows such a value ticked, so an ordinary save cannot drop it
     * and whoever knows the answer can re-tick it deliberately.
     */
    public const LEGACY_SST_CATEGORIES = [
        'financial' => 'Financial services (no longer offered — see Group H)',
        'healthcare' => 'Private healthcare services (no longer offered)',
        'education' => 'Education services (no longer offered)',
        'beauty' => 'Beauty & personal care services (no longer offered)',
    ];

    /**
     * The vendor is telling us they hold no SERVICE tax registration.
     *
     * `not_registered` is exclusive — it cannot be combined with anything, which
     * VendorController enforces. `sales_tax` deliberately is not: a manufacturer can also
     * be registered for a taxable service, and the two facts are both worth recording.
     */
    public const NON_SERVICE_TAX_CATEGORIES = ['sales_tax', 'not_registered'];

    protected $fillable = [
        'name', 'vendor_types', 'industry',
        'pic_name', 'pic_email', 'pic_phone',
        'technical_person_name', 'technical_person_phone', 'technical_person_email',
        'company_registration_no', 'sst_number', 'sst_categories', 'tin_number',
        'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift',
        'address', 'contact_number', 'email', 'website',
        'notes', 'is_active',
    ];

    protected $casts = [
        'vendor_types' => 'array',
        'sst_categories' => 'array',
        'is_active' => 'boolean',
    ];

    // ── Scopes ──────────────────────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->whereJsonContains('vendor_types', $type);
    }

    /** Vendors carrying ANY of the given type tokens. */
    public function scopeOfAnyType($query, array $types)
    {
        return $query->where(function ($q) use ($types) {
            foreach ($types as $type) {
                $q->orWhereJsonContains('vendor_types', $type);
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    /**
     * Every vendor the quarterly e-waste sweep can send an RFQ to: active, tagged
     * `ewaste`, and carrying a PIC email to send it to.
     *
     * There is deliberately no "primary" among them — the sweep asks the whole market so
     * the offers can be compared, and singling one out is what made a cycle able to show
     * only ever one price. This is the single definition of "can be asked to quote":
     * EwasteSweepService reads it to send, and the vendor directory reads it to warn when
     * the answer is nobody. Two copies of the query is how the banner starts disagreeing
     * with what the sweep actually does — which is the bug this replaced.
     */
    public static function ewasteRfqRecipients()
    {
        return static::query()
            ->where('is_active', true)
            ->whereJsonContains('vendor_types', 'ewaste')
            ->whereNotNull('pic_email')
            ->where('pic_email', '!=', '');
    }

    public function hasType(string $type): bool
    {
        return in_array($type, $this->vendor_types ?? [], true);
    }

    /** Do we hold assets from them under a rental or a lease? */
    public function isRental(): bool
    {
        foreach (self::RENTAL_ASSET_TYPES as $type) {
            if ($this->hasType($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Do we BUY assets from them? True for any of the purchase-side tokens, not just
     * `purchase` — a supplier may be registered as IT Services or Software and still be
     * who the kit was bought from. Reads the constant for the same reason isRental() does:
     * testing one token by hand is how a vendor silently drops out of the surface built
     * to find it.
     */
    public function isAssetSupplier(): bool
    {
        foreach (self::PURCHASE_ASSET_TYPES as $type) {
            if ($this->hasType($type)) {
                return true;
            }
        }

        return false;
    }

    public function isRepair(): bool
    {
        return $this->hasType('repair');
    }

    public function isEwaste(): bool
    {
        return $this->hasType('ewaste');
    }

    /** Human-readable list of the vendor's types, e.g. "Asset Rental, E-waste Disposal". */
    public function typeLabels(): string
    {
        $labels = array_map(fn ($t) => self::TYPES[$t] ?? ucfirst($t), $this->vendor_types ?? []);

        return implode(', ', $labels) ?: '—';
    }

    public function industryLabel(): string
    {
        return self::INDUSTRIES[$this->industry] ?? ($this->industry ?: '—');
    }

    /**
     * The categories this vendor is registered under, as a clean list of keys.
     * Every read goes through here so a null column, an empty array and a legacy
     * single string all answer the same question the same way.
     *
     * @return list<string>
     */
    public function sstCategoryList(): array
    {
        $stored = $this->sst_categories;

        if (is_string($stored)) {
            $stored = [$stored];
        }

        return array_values(array_filter(array_map('strval', (array) $stored), 'strlen'));
    }

    public function hasSstCategory(string $key): bool
    {
        return in_array($key, $this->sstCategoryList(), true);
    }

    /**
     * Their taxable-SERVICE groups — the categories minus the two answers that say they
     * hold no service tax registration. This, not the raw list, is what the B2B exemption
     * compares: "Sales Tax registrant" is a real answer but it is not a group we can match.
     *
     * @return list<string>
     */
    public function sstServiceGroups(): array
    {
        return array_values(array_diff($this->sstCategoryList(), self::NON_SERVICE_TAX_CATEGORIES));
    }

    /** @return list<string> */
    public function sstCategoryLabels(): array
    {
        return array_map([self::class, 'sstLabelFor'], $this->sstCategoryList());
    }

    /** Every category as one readable string, e.g. "Group G — …, Group K — …". */
    public function sstCategoryLabel(): string
    {
        return implode(', ', $this->sstCategoryLabels()) ?: '—';
    }

    /**
     * One category's label. Falls back through the retired list to the raw key, so a value
     * stored before the list changed is still readable instead of surfacing as a slug.
     */
    public static function sstLabelFor(string $key): string
    {
        return self::sstCategories()[$key] ?? self::LEGACY_SST_CATEGORIES[$key] ?? $key;
    }

    /**
     * Do we hold enough to pay them?
     *
     * The bank NAME alone is not a payment instruction, so it is deliberately not enough to
     * make the profile's banking block read as recorded — an account number with no bank, or
     * a bank with no account number, is a half-entered record that must keep prompting for
     * the missing half rather than looking complete.
     */
    public function hasBankDetails(): bool
    {
        return filled($this->bank_account_number) && filled($this->bank_name);
    }

    // ── SST / B2B exemption ───────────────────────────────────────────────────
    /**
     * Our own registered taxable-service group(s), or [] when nothing has been set.
     *
     * Deliberately read from config rather than a column: where our SST identity should
     * live (per legal entity on `companies`, or once for the group) is an open decision,
     * and guessing it here would bake the wrong answer into a rule that decides money.
     * Set VENDOR_OWN_SST_CATEGORY (or config/vendors.php) and the verdict goes live
     * everywhere at once; leave it unset and every vendor honestly reads "not determined"
     * instead of quietly asserting that SST is chargeable.
     *
     * Accepts a single key or a list, because we can be registered under more than one
     * group for the same reason a vendor can.
     *
     * @return list<string>
     */
    public static function ownSstCategories(): array
    {
        $own = config('vendors.own_sst_category');

        return array_values(array_filter(array_map('strval', (array) $own), 'strlen'));
    }

    /**
     * Can this vendor charge us service tax?
     *
     *   'not_registered' — they hold no service-tax registration, so no SST on their bill
     *   'exempt'         — they share a taxable group with us ⇒ B2B exemption applies
     *   'chargeable'     — no group in common ⇒ SST is properly charged
     *   'unknown'        — we don't know one side of the comparison yet
     *
     * With several groups on either side the comparison is an INTERSECTION, and `exempt`
     * consequently means "may not charge us on services in the group we share" — not that
     * every line they bill is exempt. The reason says so whenever they hold groups outside
     * the overlap, because VendorBillingDocument::sstFlag() quotes it verbatim onto an
     * invoice, and a flag that overstates its case is one an operator learns to dismiss.
     *
     * @return array{state:string,label:string,reason:string}
     */
    public function sstVerdict(): array
    {
        $theirs = $this->sstCategoryList();
        $theirGroups = $this->sstServiceGroups();
        $ours = self::ownSstCategories();

        if ($theirs === []) {
            return [
                'state' => 'unknown',
                'label' => 'Not determined',
                'reason' => "This vendor's SST category has not been recorded.",
            ];
        }

        if ($theirGroups === []) {
            return [
                'state' => 'not_registered',
                'label' => 'No SST chargeable',
                'reason' => 'Vendor is not registered for service tax ('.$this->sstCategoryLabel().').',
            ];
        }

        if ($ours === []) {
            return [
                'state' => 'unknown',
                'label' => 'Not determined',
                'reason' => 'Our own SST category is not configured, so the B2B exemption cannot be evaluated.',
            ];
        }

        $shared = array_values(array_intersect($theirGroups, $ours));

        if ($shared !== []) {
            $others = array_values(array_diff($theirGroups, $shared));

            return [
                'state' => 'exempt',
                'label' => 'Cannot charge us SST',
                'reason' => 'Same taxable category as ours ('.self::labelList($shared).') — the B2B exemption applies'
                    .($others === []
                        ? '.'
                        : ' to services in that category. They are also registered under '
                            .self::labelList($others).', which they may charge SST on.'),
            ];
        }

        return [
            'state' => 'chargeable',
            'label' => 'May charge SST',
            'reason' => 'Their '.(count($theirGroups) === 1 ? 'category' : 'categories').' ('
                .self::labelList($theirGroups).') '.(count($theirGroups) === 1 ? 'differs' : 'differ')
                .' from ours ('.self::labelList($ours).').',
        ];
    }

    /** @param  list<string>  $keys */
    private static function labelList(array $keys): string
    {
        return implode(', ', array_map([self::class, 'sstLabelFor'], $keys));
    }

    /** True only when we are certain SST must not appear on their bill. */
    public function isSstExemptToUs(): bool
    {
        return in_array($this->sstVerdict()['state'], ['exempt', 'not_registered'], true);
    }

    // ── Relations ───────────────────────────────────────────────────────────
    public function batches()
    {
        return $this->hasMany(AssetDecommissionBatch::class, 'vendor_id');
    }

    /** Every asset linked to this vendor, rented or purchased. */
    public function assets()
    {
        return $this->hasMany(AssetInventory::class, 'vendor_id');
    }

    /** Assets we are RENTING from them (ownership_type = rental). */
    public function rentedAssets()
    {
        return $this->assets()->where('ownership_type', 'rental');
    }

    /** Assets we BOUGHT from them and own (ownership_type = company). */
    public function purchasedAssets()
    {
        return $this->assets()->where('ownership_type', 'company');
    }

    public function contracts()
    {
        return $this->hasMany(VendorContract::class)->orderByDesc('start_date')->orderByDesc('id');
    }

    public function billingDocuments()
    {
        return $this->hasMany(VendorBillingDocument::class)->orderByDesc('doc_date')->orderByDesc('id');
    }

    /**
     * This vendor's invoices, as offered when naming the document an asset arrived on.
     * Delegates so there is exactly one definition of "which documents can be picked".
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,VendorBillingDocument>
     */
    public function invoiceOptions()
    {
        return VendorBillingDocument::invoiceOptions($this->id);
    }

    /** AARFs — rental assets received from, or returned to, this vendor. */
    public function rentalAcknowledgements()
    {
        return $this->hasMany(RentalAssetAcknowledgement::class)->orderByDesc('id');
    }

    /**
     * The document Q&A thread for this vendor, oldest first (reading order).
     *
     * Deliberately NOT a DELETE_BLOCKER: a conversation is a by-product of reading the
     * filed documents, not a filed record itself, and a vendor row created by mistake
     * should not become undeletable because somebody asked it a question.
     */
    public function chatMessages()
    {
        return $this->hasMany(VendorChatMessage::class)->orderBy('id');
    }

    /**
     * Every document the assistant could be asked about — contracts and billing documents
     * as one list, which is how the vendor page presents them and how the scope is chosen.
     *
     * @return \Illuminate\Support\Collection<int,VendorContract|VendorBillingDocument>
     */
    public function askableDocuments(): \Illuminate\Support\Collection
    {
        return $this->contracts->concat($this->billingDocuments)->values();
    }

    // ── Deletion ──────────────────────────────────────────────────────────────
    /**
     * Everything that makes a vendor row un-deletable, as `relation => [count attribute,
     * singular, plural]`.
     *
     * A vendor is normally retired with `toggleActive`, not deleted, so that assets,
     * batches, contracts and signed acknowledgements keep the reference they were filed
     * under. Delete exists for the one case that is not history — the duplicate or typo'd
     * row somebody just created — and the guard is what keeps the two apart.
     *
     * It is NOT decoration. `vendor_contracts`, `vendor_billing_documents` and
     * `rental_asset_acknowledgements` all declare `cascadeOnDelete`, so an unguarded
     * `$vendor->delete()` silently destroys every contract, every filed invoice and every
     * SIGNED AARF (with its stored PDF) belonging to that vendor, and nulls the vendor link
     * on assets and e-waste batches — irreversibly, from one button.
     */
    public const DELETE_BLOCKERS = [
        'assets' => ['assets_count', 'linked asset', 'linked assets'],
        'contracts' => ['contracts_count', 'contract', 'contracts'],
        'billingDocuments' => ['billing_documents_count', 'billing document', 'billing documents'],
        'rentalAcknowledgements' => ['rental_acknowledgements_count', 'AARF', 'AARFs'],
        'batches' => ['batches_count', 'e-waste cycle', 'e-waste cycles'],
    ];

    /**
     * Human-readable list of what is attached to this vendor, e.g. ['4 linked assets', '1 contract'].
     * Empty means the row references nothing and can be deleted.
     *
     * Reads a `withCount`/`loadCount` attribute when one is present so the directory stays
     * one query, and falls back to counting per relation when it isn't.
     *
     * @return list<string>
     */
    public function deletionBlockers(): array
    {
        $blockers = [];

        foreach (self::DELETE_BLOCKERS as $relation => [$countKey, $one, $many]) {
            $n = array_key_exists($countKey, $this->attributes)
                ? (int) $this->attributes[$countKey]
                : $this->{$relation}()->count();

            if ($n > 0) {
                $blockers[] = $n.' '.($n === 1 ? $one : $many);
            }
        }

        return $blockers;
    }

    public function canBeDeleted(): bool
    {
        return $this->deletionBlockers() === [];
    }
}
