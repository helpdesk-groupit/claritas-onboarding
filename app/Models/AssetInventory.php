<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class AssetInventory extends Model
{
    protected $fillable = [
        // Section A – Identification
        'asset_tag', 'asset_type', 'brand', 'model', 'serial_number',
        'status', 'notes',
        // Section B – Specification
        'processor', 'ram_size', 'storage', 'operating_system', 'screen_size', 'spec_others',
        // Section C – Procurement
        'purchase_date', 'purchase_vendor', 'purchase_cost', 'warranty_expiry_date', 'invoice_document',
        // Section C – Ownership
        'ownership_type', 'company_name',
        'rental_vendor', 'rental_vendor_contact', 'rental_cost_per_month',
        'rental_start_date', 'rental_end_date', 'rental_contract_reference',
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
    ];

    public function assignments()      { return $this->hasMany(AssetAssignment::class); }
    public function assignedEmployee() { return $this->belongsTo(Employee::class, 'assigned_employee_id'); }

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
        $timestamp   = Carbon::now()->format('d M Y, h:i A');
        $newLine     = "[{$timestamp}] {$entry}";
        $existing    = trim($this->remarks ?? '');
        $this->remarks = $existing ? $existing . "\n" . $newLine : $newLine;
        $this->saveQuietly();
    }
}