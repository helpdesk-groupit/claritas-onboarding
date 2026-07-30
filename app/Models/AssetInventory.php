<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class AssetInventory extends Model
{
    use HasFactory;

    /**
     * Section E condition values, keyed value => label. Single source of truth for the
     * validation rule and the edit form's dropdown.
     *   good              → available (unless assigned)
     *   under_maintenance → unavailable
     *   not_good          → unavailable + staged for decommissioning as e-waste
     *   returned          → unavailable + staged for decommissioning as a vendor return
     */
    public const CONDITIONS = [
        'good'              => 'Good',
        'under_maintenance' => 'Under Maintenance',
        'not_good'          => 'Not Good',
        'returned'          => 'Returned',
    ];

    /** How a staged asset is decommissioned. Mirrors DisposedAsset.decommission_type. */
    public const DECOMMISSION_TYPES = [
        'e_waste'       => 'E-waste',
        'vendor_return' => 'Vendor Return',
    ];

    /** Conditions that move an asset out of the active listing into the Decommissioning tab. */
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
        'rental_vendor', 'rental_vendor_contact', 'rental_cost_per_month',
        'rental_start_date', 'rental_end_date', 'rental_contract_reference', 'rental_contract_documents',
        // Section D – Assignment
        'assigned_employee_id', 'asset_assigned_date', 'expected_return_date',
        // Section E – Condition
        'asset_condition', 'maintenance_status', 'last_maintenance_date', 'asset_photos',
        'remarks',
    ];

    protected $casts = [
        'purchase_date'          => 'date',
        'warranty_expiry_date'   => 'date',
        'asset_assigned_date'    => 'date',
        'expected_return_date'   => 'date',
        'last_maintenance_date'  => 'date',
        'rental_start_date'      => 'date',
        'rental_end_date'        => 'date',
        'purchase_cost'          => 'decimal:2',
        'rental_cost_per_month'  => 'decimal:2',
        'asset_photos'           => 'array',
        'invoice_documents'      => 'array',
        'rental_contract_documents' => 'array',
    ];

    public function assignments()      { return $this->hasMany(AssetAssignment::class); }
    public function assignedEmployee() { return $this->belongsTo(Employee::class, 'assigned_employee_id'); }

    /** True when this condition takes the asset out of the active listing. */
    public function isStagedForDecommission(): bool
    {
        return in_array($this->asset_condition, self::DECOMMISSION_CONDITIONS, true);
    }

    public function conditionLabel(): string
    {
        return self::CONDITIONS[$this->asset_condition]
            ?? ucfirst(str_replace('_', ' ', (string) $this->asset_condition));
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
        $timestamp   = Carbon::now()->format('d/m/Y, h:i A');
        $newLine     = "[{$timestamp}] {$entry}";
        $existing    = trim($this->remarks ?? '');
        $this->remarks = $existing ? $existing . "\n" . $newLine : $newLine;
        $this->saveQuietly();
    }
}