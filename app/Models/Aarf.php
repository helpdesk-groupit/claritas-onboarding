<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Aarf extends Model
{
    protected $table = 'aarfs';

    protected $fillable = [
        'onboarding_id', 'employee_id', 'aarf_reference', 'acknowledged', 'acknowledged_at',
        'acknowledgement_token', 'it_manager_acknowledged', 'it_manager_acknowledged_at',
        'it_manager_user_id', 'it_manager_remarks',
        'it_notes', 'asset_changes',
    ];

    protected $casts = [
        'acknowledged'               => 'boolean',
        'acknowledged_at'            => 'datetime',
        'it_manager_acknowledged'    => 'boolean',
        'it_manager_acknowledged_at' => 'datetime',
    ];

    public function isFullyAcknowledged(): bool
    {
        return $this->acknowledged && $this->it_manager_acknowledged;
    }

    public function isLocked(): bool
    {
        return $this->acknowledged || $this->it_manager_acknowledged;
    }

    public function onboarding() { return $this->belongsTo(Onboarding::class); }
    public function itManager()  { return $this->belongsTo(User::class, 'it_manager_user_id'); }
    public function employee()   { return $this->belongsTo(Employee::class); }

    /**
     * Append a timestamped asset event to the AARF asset_changes log.
     */
    public function appendAssetChange(string $entry): void
    {
        $timestamp  = Carbon::now()->format('d M Y, h:i A');
        $newLine    = "[{$timestamp}] {$entry}";
        $existing   = trim($this->asset_changes ?? '');
        $this->asset_changes = $existing ? $existing . "\n" . $newLine : $newLine;
        $this->saveQuietly();
    }
}