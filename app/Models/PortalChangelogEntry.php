<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortalChangelogEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'type', 'status', 'module_area',
        'changed_by_user_id', 'changed_by_name',
        'occurred_at',
        'updated_by_user_id', 'updated_by_name',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public const TYPES = [
        'feature'       => 'Feature',
        'improvement'   => 'Improvement',
        'bug_fix'       => 'Bug Fix',
        'security'      => 'Security',
        'config_change' => 'Config Change',
        'other'         => 'Other',
    ];

    /**
     * The real modules in this portal (mirrors the module breakdown in CLAUDE.md), offered as a
     * dropdown rather than free text so entries stay consistently tagged. module_area itself
     * stays a plain nullable string column — not validated against this list server-side — so an
     * entry logged before this list existed (or before a future module is added here) is never
     * silently rejected or its stored tag lost; see the edit-modal JS for how that's preserved.
     */
    public const MODULE_AREAS = [
        'Onboarding',
        'Offboarding',
        'Employee Records',
        'Leave Management',
        'Payroll & Payslips',
        'Attendance Tracking',
        'Expense Claims (eClaim)',
        'EA Forms',
        'Asset Inventory & Provisioning',
        'Asset Decommissioning (E-Waste / Rental Returns)',
        'AARF (Asset Acknowledgement)',
        'Vendor Management',
        'Ticketing / Helpdesk',
        'Accounting',
        'Company Management',
        'Role & Permission Management',
        'Knowledge Base',
        'Announcements',
        'Reports & Analytics',
        'Security & Authentication',
        'Email Workflow Automation',
        'Social Media AI Strategist',
        'Claude API & Usage Accounting',
        'Notifications',
        'System Settings',
        'Other',
    ];

    public const STATUSES = [
        'planned'     => 'Planned',
        'in_progress' => 'In Progress',
        'completed'   => 'Completed',
        'rolled_back' => 'Rolled Back',
    ];

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'planned'     => 'bg-secondary',
            'in_progress' => 'bg-info text-dark',
            'completed'   => 'bg-success',
            'rolled_back' => 'bg-danger',
            default       => 'bg-light text-dark',
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            'feature'       => 'bg-primary',
            'improvement'   => 'bg-info text-dark',
            'bug_fix'       => 'bg-warning text-dark',
            'security'      => 'bg-danger',
            'config_change' => 'bg-dark',
            default         => 'bg-secondary',
        };
    }
}
