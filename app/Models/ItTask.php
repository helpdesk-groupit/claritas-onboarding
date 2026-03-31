<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItTask extends Model
{
    protected $fillable = [
        'onboarding_id', 'offboarding_id', 'assigned_to', 'assigned_by',
        'task_type', 'title', 'description', 'status', 'completed_at',
    ];

    protected $casts = ['completed_at' => 'datetime'];

    public function onboarding()  { return $this->belongsTo(Onboarding::class); }
    public function offboarding() { return $this->belongsTo(Offboarding::class); }
    public function assignedTo()  { return $this->belongsTo(User::class, 'assigned_to'); }
    public function assignedBy()  { return $this->belongsTo(User::class, 'assigned_by'); }

    // Status badge color helper
    public function statusColor(): string
    {
        return match($this->status) {
            'done'        => 'success',
            'in_progress' => 'warning text-dark',
            default       => 'secondary',
        };
    }

    // Sync this task's status back to the onboarding record
    public function syncToOnboarding(): void
    {
        $onboarding = $this->onboarding;
        if (!$onboarding) return;

        $column = match($this->task_type) {
            'asset_preparation' => 'asset_preparation_status',
            'work_email'        => 'work_email_status',
            default             => null,
        };

        if ($column) {
            $onboarding->update([$column => $this->status]);
        }
    }

    // Sync this task's status back to the offboarding record
    public function syncToOffboarding(): void
    {
        $offboarding = $this->offboarding;
        if (!$offboarding) return;

        $column = match($this->task_type) {
            'asset_cleaning' => 'asset_cleaning_status',
            'deactivation'   => 'deactivation_status',
            default          => null,
        };

        if ($column) {
            $offboarding->update([$column => $this->status]);
        }
    }
}