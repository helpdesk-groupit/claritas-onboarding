<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetInventory extends Model
{
    use HasFactory;

    /**
     * Section E condition values, keyed value => label. Single source of truth for the
     * validation rule, the edit dropdown, and the Add-Asset modal.
     *   good              → available (unless assigned)
     *   under_maintenance → unavailable
     *   not_good          → unavailable + staged for the e-waste decommission flow
     *   returned          → unavailable + staged for the vendor-return decommission flow
     */
    public const CONDITIONS = [
        'good' => 'Good',
        'under_maintenance' => 'Under Maintenance',
        'not_good' => 'Not Good',
        'returned' => 'Returned',
    ];

    /** How a staged asset is decommissioned. Mirrors DisposedAsset.decommission_type. */
    public const DECOMMISSION_TYPES = [
        'e_waste' => 'E-waste',
        'vendor_return' => 'Vendor Return',
    ];

    /** Conditions that stage an asset into the Decommissioning queue. */
    public const DECOMMISSION_CONDITIONS = ['not_good', 'returned'];

    protected $fillable = [
        // Section A – Identification
        'asset_tag', 'asset_category', 'asset_type', 'brand', 'model', 'serial_number',
        'status', 'notes',
        // Section B – Specification
        'processor', 'ram_size', 'storage', 'operating_system', 'screen_size', 'spec_others',
        // Section C – Procurement
        'purchase_date', 'purchase_vendor', 'purchase_cost', 'warranty_expiry_date', 'invoice_document', 'invoice_documents',
        // Section C – Ownership
        'ownership_type', 'company_name', 'company_supplied_to',
        // vendor_id is the registered-vendor link for BOTH ownership types: rented-from
        // when ownership_type = rental, purchased-from when = company. (Was rental_vendor_id
        // until 2026-08-06, when the vendor master widened past rentals.)
        'vendor_id',
        // The billing document this asset ARRIVED on — see originInvoice().
        'origin_billing_document_id',
        'rental_vendor', 'rental_vendor_contact', 'rental_cost_per_month',
        'rental_start_date', 'rental_end_date', 'rental_contract_reference', 'rental_contract_documents',
        'rental_contract_document_hashes',
        // Section D – Assignment
        'assigned_employee_id', 'asset_assigned_date', 'expected_return_date',
        // Section E – Condition
        'asset_condition', 'maintenance_status', 'last_maintenance_date', 'asset_photos',
        'remarks',
        // Decommissioning (added 2026-07) — soft-archive marker + batch linkage
        'decommissioned_at', 'decommission_batch_id',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expiry_date' => 'date',
        'asset_assigned_date' => 'date',
        'expected_return_date' => 'date',
        'last_maintenance_date' => 'date',
        'rental_start_date' => 'date',
        'rental_end_date' => 'date',
        'decommissioned_at' => 'datetime',
        'purchase_cost' => 'decimal:2',
        'rental_cost_per_month' => 'decimal:2',
        'asset_photos' => 'array',
        'invoice_documents' => 'array',
        'rental_contract_documents' => 'array',
        'rental_contract_document_hashes' => 'array',
    ];

    /**
     * SHA-256 of every file's actual bytes, keyed by its stored path — the fingerprint
     * `VendorContract::matchedAssets()` compares against a contract's own `file_hash`.
     */
    public static function hashUploadedFile(\Illuminate\Http\UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    public function assignments()
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function assignedEmployee()
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    /** The registered vendor this asset is rented from / was purchased from — see ownership_type. */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** True when vendor_id means "we rent this from them" rather than "we bought it from them". */
    public function isRented(): bool
    {
        return $this->ownership_type === 'rental';
    }

    /**
     * The billing document this asset ARRIVED on — the delivery invoice for a rental, the
     * purchase invoice for one we own. Read the meaning off ownership_type, exactly as with
     * vendor(); one column, one meaning, so an asset belongs to exactly one invoice group.
     *
     * NOT "every invoice this asset has ever appeared on". A rental is billed again every
     * month, and when those recurring documents need attaching they get their own pivot
     * beside this column — which is why the FK is named for the origin and not just
     * `billing_document_id`.
     */
    public function originInvoice()
    {
        return $this->belongsTo(VendorBillingDocument::class, 'origin_billing_document_id');
    }

    /**
     * The grouping key for an asset with no registered invoice: its free-text reference,
     * case- and spacing-insensitive.
     *
     * Punctuation is deliberately KEPT — stripping dashes would fold "INV-2025-1" and
     * "INV-20251" into one group and merge two genuinely different invoices. The backfill
     * migration carries its own copy of this rule on purpose: a migration must keep
     * behaving the way it did the day it ran, whatever this method becomes later.
     */
    public static function normaliseInvoiceReference(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    /**
     * Group assets by the invoice they came in on.
     *
     * Two arms, and the second is NOT optional — the same shape as
     * AssetController::applyVendorFilter(). An asset linked to a registered document groups
     * by the FK; one that is not falls back to its free-text reference, which for an
     * unregistered vendor is the only key it will ever have. Drop that arm and every asset
     * not yet linked vanishes off the page built to list them.
     *
     * Every group carries the SAME shape whatever kind it is, so a caller renders one
     * partial rather than branching, and a fourth kind later becomes a new `state` rather
     * than a new code path:
     *
     *   key       stable identity ("doc:12" / "ref:INV-001" / "none")
     *   state     registered | unregistered | none
     *   document  the VendorBillingDocument, or null
     *   reference the free-text ref as first seen (raw, for display)
     *   assets    the assets in this group
     *   count     how many
     *   monthly   live rental commitment in this group (decommissioned assets excluded —
     *             we have stopped paying for those)
     *   purchased total purchase cost in this group
     *   anchor    DOM id, so the billing tab can link straight to the group
     *
     * @param  \Illuminate\Support\Collection<int,self>  $assets
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    public static function groupByOriginInvoice($assets): \Illuminate\Support\Collection
    {
        $groups = [];

        foreach ($assets as $asset) {
            $document = $asset->origin_billing_document_id ? $asset->originInvoice : null;
            $reference = self::normaliseInvoiceReference($asset->rental_contract_reference);

            if ($document) {
                $key = 'doc:'.$document->id;
            } elseif ($reference !== '') {
                $key = 'ref:'.$reference;
            } else {
                $key = 'none';
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'state' => $document ? 'registered' : ($reference !== '' ? 'unregistered' : 'none'),
                    'document' => $document,
                    'reference' => $document ? $document->doc_number : ($asset->rental_contract_reference ?: null),
                    'assets' => collect(),
                    'count' => 0,
                    'monthly' => 0.0,
                    'purchased' => 0.0,
                    'anchor' => $document
                        ? 'inv-doc-'.$document->id
                        : ($reference !== '' ? 'inv-ref-'.substr(md5($reference), 0, 8) : 'inv-none'),
                ];
            }

            $groups[$key]['assets']->push($asset);
            $groups[$key]['count']++;
            if (! $asset->decommissioned_at) {
                $groups[$key]['monthly'] += (float) $asset->rental_cost_per_month;
            }
            $groups[$key]['purchased'] += (float) $asset->purchase_cost;
        }

        // Registered invoices first, newest document first — an invoice with no date sorts
        // after the dated ones rather than jumping to the top on an empty sort key. Then the
        // free-text groups alphabetically, and "no invoice recorded" last: it is the group
        // that needs attention, but it is not a document and does not belong among them.
        // One composite key rather than Collection::sortBy([...]): a callable in that array
        // is treated as a COMPARATOR ($a, $b), not a value extractor, which is a quiet way
        // to get a sort that looks right and isn't.
        return collect($groups)->values()->sortBy(function ($g) {
            $rank = match ($g['state']) {
                'registered' => 0,
                'unregistered' => 1,
                default => 2,
            };

            // Invert the timestamp so newest sorts first; an undated invoice lands at the
            // back of the registered block instead of the front on an empty sort key.
            $recency = $g['state'] === 'registered'
                ? 9999999999 - max(0, (int) ($g['document']->doc_date?->timestamp ?? 0))
                : 0;

            $ref = $g['state'] === 'unregistered' ? self::normaliseInvoiceReference($g['reference']) : '';

            return sprintf('%d|%011d|%s', $rank, $recency, $ref);
        })->values();
    }

    public function decommissionBatch()
    {
        return $this->belongsTo(AssetDecommissionBatch::class, 'decommission_batch_id');
    }

    /**
     * Active inventory = not soft-archived. Once decommissioned_at is set the asset
     * leaves every inventory view (listing + Decommissioning tab) but is never deleted.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('decommissioned_at');
    }

    public function isDecommissioned(): bool
    {
        return $this->decommissioned_at !== null;
    }

    public function conditionLabel(): string
    {
        return self::CONDITIONS[$this->asset_condition] ?? ucfirst(str_replace('_', ' ', (string) $this->asset_condition));
    }

    /** True when this condition takes the asset out of the active listing. */
    public function isStagedForDecommission(): bool
    {
        return in_array($this->asset_condition, self::DECOMMISSION_CONDITIONS, true);
    }

    /**
     * Section B as ONE line, for the listings that give the specification a single column.
     *
     * Ordered widest-to-narrowest (what it runs on, then what it holds, then what it looks
     * like), because a truncated cell should lose the trailing detail rather than the CPU.
     * Empty fields are dropped rather than printed as dashes: six placeholders read as a
     * machine with no specification recorded, when in fact only one field was filled in.
     *
     * Returns '' when nothing is recorded — the caller decides what an empty cell says.
     */
    public function specSummary(): string
    {
        return collect([
            $this->processor,
            $this->ram_size,
            $this->storage,
            $this->operating_system,
            $this->screen_size,
            $this->spec_others,
        ])->map(fn ($v) => trim((string) $v))->filter()->implode(' · ');
    }

    /**
     * Resolve the assigned person's name for display.
     * Covers: direct assignment (assigned_employee_id set),
     * and auto-assigned via onboarding (assigned_employee_id may be null).
     */
    public function resolvedAssigneeName(): string
    {
        // Direct employee assignment
        if ($this->assignedEmployee) {
            return $this->assignedEmployee->onboarding?->personalDetail?->full_name
                ?? $this->assignedEmployee->full_name
                ?? '—';
        }

        // Auto-assigned via onboarding — look up via AssetAssignment
        $assignment = AssetAssignment::where('asset_inventory_id', $this->id)
            ->where('status', 'assigned')
            ->whereNotNull('onboarding_id')
            ->with('onboarding.personalDetail')
            ->first();

        if ($assignment?->onboarding?->personalDetail?->full_name) {
            return $assignment->onboarding->personalDetail->full_name;
        }

        return '—';
    }

    public static function getAvailableByType(string $type): ?self
    {
        return self::where('asset_type', $type)->where('status', 'available')->first();
    }

    /**
     * Append a timestamped entry to the remarks audit log.
     * Saves the model immediately.
     */
    public function appendRemark(string $entry): void
    {
        $timestamp = Carbon::now()->format('d/m/Y, h:i A');
        $newLine = "[{$timestamp}] {$entry}";
        $existing = trim($this->remarks ?? '');
        $this->remarks = $existing ? $existing."\n".$newLine : $newLine;
        $this->saveQuietly();
    }
}
