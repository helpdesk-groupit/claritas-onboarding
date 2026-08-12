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
     */
    public const TYPES = [
        'rental' => 'Asset Rental',
        'leasing' => 'Leasing',
        'repair' => 'Repair & Maintenance',
        'ewaste' => 'E-waste Disposal',
        'purchase' => 'Asset Supply / Purchase',
        'it_services' => 'IT Services',
        'software' => 'Software / Subscription',
        'professional' => 'Professional Services',
        'facilities' => 'Facilities & Office',
        'logistics' => 'Logistics & Courier',
        'marketing' => 'Marketing & Media',
        'training' => 'Training',
        'utilities' => 'Utilities & Telco',
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

    public const PURCHASE_ASSET_TYPES = ['purchase', 'it_services', 'software', 'other'];

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
     * The taxable-service category a vendor is registered under, plus the two
     * non-service-tax answers.
     *
     * This is the field the B2B exemption turns on: a registered person acquiring a
     * taxable service from another registered person in the SAME category is exempt
     * from paying service tax on it.
     *
     * Keys are deliberately DESCRIPTIVE rather than the statutory group letters. The
     * First Schedule has been re-lettered and extended more than once (logistics in
     * 2024; rental/leasing, construction, financial, healthcare, education and beauty
     * in the 2025 expansion), so hard-coding "Group G" here would date badly and the
     * rule only needs the two sides to match, not the legal letter. Override the whole
     * list in config/vendors.php when Finance wants the statutory wording.
     *
     * @return array<string,string>
     */
    public static function sstCategories(): array
    {
        $configured = config('vendors.sst_categories');

        return is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_SST_CATEGORIES;
    }

    public const DEFAULT_SST_CATEGORIES = [
        'accommodation' => 'Accommodation',
        'food_beverage' => 'Food & Beverage',
        'wellness' => 'Health, wellness & recreation clubs',
        'betting_gaming' => 'Betting & gaming',
        'professional' => 'Professional services (legal, accounting, engineering, consultancy, IT)',
        'credit_card' => 'Credit & charge cards',
        'other_services' => 'Other service providers (telco, insurance, advertising, parking, cleaning…)',
        'logistics' => 'Logistics & delivery services',
        'rental_leasing' => 'Rental or leasing services',
        'construction' => 'Construction work services',
        'financial' => 'Financial services',
        'healthcare' => 'Private healthcare services',
        'education' => 'Education services',
        'beauty' => 'Beauty & personal care services',
        'sales_tax' => 'Sales Tax registrant (manufacturer / importer)',
        'not_registered' => 'Not SST-registered',
    ];

    /** The vendor is telling us they cannot charge service tax at all. */
    public const NON_SERVICE_TAX_CATEGORIES = ['sales_tax', 'not_registered'];

    protected $fillable = [
        'name', 'vendor_types', 'industry',
        'pic_name', 'pic_email', 'pic_phone',
        'technical_person_name', 'technical_person_phone', 'technical_person_email',
        'company_registration_no', 'sst_number', 'sst_category', 'tin_number',
        'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift',
        'address', 'contact_number', 'email', 'website',
        'is_primary_ewaste', 'notes', 'is_active',
    ];

    protected $casts = [
        'vendor_types' => 'array',
        'is_primary_ewaste' => 'boolean',
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
    /** The single active vendor flagged to receive the quarterly e-waste RFQ. */
    public static function primaryEwaste(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where('is_primary_ewaste', true)
            ->whereJsonContains('vendor_types', 'ewaste')
            ->first();
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

    public function sstCategoryLabel(): string
    {
        return self::sstCategories()[$this->sst_category] ?? ($this->sst_category ?: '—');
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
     * Our own registered taxable-service group, or null when it has not been set.
     *
     * Deliberately read from config rather than a column: where our SST identity should
     * live (per legal entity on `companies`, or once for the group) is an open decision,
     * and guessing it here would bake the wrong answer into a rule that decides money.
     * Set VENDOR_OWN_SST_CATEGORY (or config/vendors.php) and the verdict goes live
     * everywhere at once; leave it unset and every vendor honestly reads "not determined"
     * instead of quietly asserting that SST is chargeable.
     */
    public static function ownSstCategory(): ?string
    {
        $own = config('vendors.own_sst_category');

        return is_string($own) && $own !== '' ? $own : null;
    }

    /**
     * Can this vendor charge us service tax?
     *
     *   'not_registered' — they hold no service-tax registration, so no SST on their bill
     *   'exempt'         — same taxable group as us ⇒ B2B exemption applies
     *   'chargeable'     — different group ⇒ SST is properly charged
     *   'unknown'        — we don't know one side of the comparison yet
     *
     * @return array{state:string,label:string,reason:string}
     */
    public function sstVerdict(): array
    {
        $theirs = $this->sst_category;
        $ours = self::ownSstCategory();

        if ($theirs && in_array($theirs, self::NON_SERVICE_TAX_CATEGORIES, true)) {
            return [
                'state' => 'not_registered',
                'label' => 'No SST chargeable',
                'reason' => 'Vendor is not registered for service tax ('.$this->sstCategoryLabel().').',
            ];
        }

        if (! $theirs) {
            return [
                'state' => 'unknown',
                'label' => 'Not determined',
                'reason' => "This vendor's SST category has not been recorded.",
            ];
        }

        if (! $ours) {
            return [
                'state' => 'unknown',
                'label' => 'Not determined',
                'reason' => 'Our own SST category is not configured, so the B2B exemption cannot be evaluated.',
            ];
        }

        if ($theirs === $ours) {
            return [
                'state' => 'exempt',
                'label' => 'Cannot charge us SST',
                'reason' => 'Same taxable category as ours ('.$this->sstCategoryLabel().') — the B2B exemption applies.',
            ];
        }

        return [
            'state' => 'chargeable',
            'label' => 'May charge SST',
            'reason' => 'Their category ('.$this->sstCategoryLabel().') differs from ours ('
                .(self::sstCategories()[$ours] ?? $ours).').',
        ];
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
