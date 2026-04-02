<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'work_email', 'password', 'role', 'is_active', 'profile_picture'];
    protected $hidden   = ['password', 'remember_token'];
    protected $casts    = ['password' => 'hashed', 'is_active' => 'boolean'];

    // Tell Laravel password broker to use work_email
    public function getEmailForPasswordReset(): string { return $this->work_email; }
    public function routeNotificationForMail(): string { return $this->work_email; }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    public function getProfilePictureUrlAttribute(): string
    {
        if ($this->profile_picture) {
            return asset('storage/' . $this->profile_picture);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=2563eb&color=fff&size=128';
    }

    // ── Role checks ───────────────────────────────────────────────────────
    public function isHrManager(): bool   { return $this->role === 'hr_manager'; }
    public function isHrExecutive(): bool  { return $this->role === 'hr_executive'; }
    public function isHrIntern(): bool     { return $this->role === 'hr_intern'; }
    public function isHr(): bool           { return in_array($this->role, ['hr_manager','hr_executive','hr_intern']); }
    public function isItManager(): bool    { return $this->role === 'it_manager'; }
    public function isItExecutive(): bool  { return $this->role === 'it_executive'; }
    public function isItIntern(): bool     { return $this->role === 'it_intern'; }
    public function isIt(): bool           { return in_array($this->role, ['it_manager','it_executive','it_intern']); }
    public function isSuperadmin(): bool   { return $this->role === 'superadmin'; }
    public function isSystemAdmin(): bool  { return $this->role === 'system_admin'; }

    public function isHrOrIt(): bool
    {
        return $this->isHr() || $this->isIt() || $this->isSuperadmin() || $this->isSystemAdmin();
    }

    public function canViewOnboarding(): bool
    {
        return in_array($this->role, ['hr_manager','hr_executive','hr_intern','superadmin','system_admin']);
    }

    public function canAddOnboarding(): bool
    {
        return in_array($this->role, ['hr_manager','superadmin','system_admin']);
    }

    public function canEditOnboarding(): bool
    {
        return in_array($this->role, ['hr_manager','superadmin','system_admin']);
    }

    public function canEditAllOnboardingSections(): bool
    {
        return in_array($this->role, ['hr_manager', 'superadmin']);
    }

    public function canViewAssets(): bool
    {
        return in_array($this->role, ['hr_manager','hr_executive','it_manager','it_executive','it_intern','superadmin','system_admin']);
    }

    public function canAddAsset(): bool
    {
        return in_array($this->role, ['hr_manager','hr_executive','it_manager','it_executive','superadmin','system_admin']);
    }

    public function canEditAsset(): bool
    {
        return in_array($this->role, ['hr_manager','hr_executive','it_manager','it_executive','superadmin']);
    }

    public function canEditAllAssetSections(): bool
    {
        return in_array($this->role, ['hr_manager','hr_executive','it_manager', 'superadmin']);
    }

    public function canEditAarf(Aarf $aarf): bool
    {
        $allowed = in_array($this->role, ['it_manager', 'superadmin']);
        return $allowed && !$aarf->acknowledged && !$aarf->it_manager_acknowledged;
    }

    public function canAcknowledgeAarf(): bool
    {
        return in_array($this->role, ['it_manager', 'superadmin', 'employee']);
    }

    public function employee() { return $this->hasOne(Employee::class); }

    public function permissions() { return $this->hasMany(UserPermission::class); }

    /**
     * Return the custom access level for a resource, or null if none is set.
     * Access levels: 'full', 'view', 'edit', 'none'
     */
    public function customPermission(string $resource): ?string
    {
        if ($this->relationLoaded('permissions')) {
            return $this->permissions->where('resource', $resource)->first()?->access_level;
        }
        return $this->permissions()->where('resource', $resource)->value('access_level');
    }

    /** Custom permission grants view access (full / view / edit all imply view). */
    public function canViewResource(string $resource): bool
    {
        $p = $this->customPermission($resource);
        return $p !== null && $p !== 'none';
    }

    /** Custom permission grants edit access (full / edit). */
    public function canEditResource(string $resource): bool
    {
        $p = $this->customPermission($resource);
        return in_array($p, ['full', 'edit']);
    }
}