<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'work_email', 'password', 'role', 'is_active', 'profile_picture', 'login_attempts', 'deactivation_reason', 'deactivated_at', 'session_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $casts = ['password' => 'hashed', 'is_active' => 'boolean', 'deactivated_at' => 'datetime', 'two_factor_confirmed_at' => 'datetime'];

    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /** Roles that must have 2FA enabled to access the application. */
    private const TWO_FACTOR_REQUIRED_ROLES = [
        'superadmin', 'hr_manager', 'hr_executive', 'finance_manager', 'it_manager', 'it_executive',
    ];

    public function requiresTwoFactor(): bool
    {
        return in_array($this->role, self::TWO_FACTOR_REQUIRED_ROLES);
    }

    public function mustSetupTwoFactor(): bool
    {
        return $this->requiresTwoFactor() && ! $this->hasTwoFactorEnabled();
    }

    // Tell Laravel password broker to use work_email
    public function getEmailForPasswordReset(): string
    {
        return $this->work_email;
    }

    public function routeNotificationForMail(): string
    {
        return $this->work_email;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    public function getProfilePictureUrlAttribute(): string
    {
        if ($this->profile_picture) {
            return asset('storage/'.$this->profile_picture);
        }

        return self::defaultAvatarUrl($this->employee?->sex);
    }

    public static function defaultAvatarUrl(?string $sex = null): string
    {
        $file = ($sex === 'female') ? 'default-avatar-female.svg' : 'default-avatar-male.svg';

        return asset('images/'.$file);
    }

    // ── Role checks ───────────────────────────────────────────────────────
    public function isHrManager(): bool
    {
        return $this->role === 'hr_manager';
    }

    public function isHrExecutive(): bool
    {
        return $this->role === 'hr_executive';
    }

    public function isHrIntern(): bool
    {
        return $this->role === 'hr_intern';
    }

    public function isHr(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'hr_intern']);
    }

    public function isItManager(): bool
    {
        return $this->role === 'it_manager';
    }

    public function isItExecutive(): bool
    {
        return $this->role === 'it_executive';
    }

    public function isItIntern(): bool
    {
        return $this->role === 'it_intern';
    }

    public function isIt(): bool
    {
        return in_array($this->role, ['it_manager', 'it_executive', 'it_intern']);
    }

    public function isSuperadmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function isSystemAdmin(): bool
    {
        return $this->role === 'system_admin';
    }

    public function isHrOrIt(): bool
    {
        return $this->isHr() || $this->isIt() || $this->isSuperadmin() || $this->isSystemAdmin();
    }

    public function canViewOnboarding(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'hr_intern', 'superadmin', 'system_admin']);
    }

    public function canAddOnboarding(): bool
    {
        return in_array($this->role, ['hr_manager', 'superadmin', 'system_admin']);
    }

    public function canEditOnboarding(): bool
    {
        return in_array($this->role, ['hr_manager', 'superadmin', 'system_admin']);
    }

    public function canEditAllOnboardingSections(): bool
    {
        return in_array($this->role, ['hr_manager', 'superadmin']);
    }

    public function canViewAssets(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'it_manager', 'it_executive', 'it_intern', 'superadmin', 'system_admin']);
    }

    public function canAddAsset(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin']);
    }

    public function canEditAsset(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'it_manager', 'it_executive', 'superadmin']);
    }

    public function canEditAllAssetSections(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'it_manager', 'superadmin']);
    }

    public function canEditAarf(Aarf $aarf): bool
    {
        $allowed = in_array($this->role, ['it_manager', 'superadmin']);

        return $allowed && ! $aarf->acknowledged;
    }

    public function canAcknowledgeAarf(): bool
    {
        return in_array($this->role, ['it_manager', 'superadmin', 'employee']);
    }

    // ── Leave capability checks ───────────────────────────────────────
    public function canViewLeaveAdmin(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'superadmin', 'system_admin']);
    }

    public function canManageLeave(): bool
    {
        return in_array($this->role, ['hr_manager', 'superadmin', 'system_admin']);
    }

    // ── Payroll capability checks ─────────────────────────────────────
    public function canViewPayroll(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'superadmin', 'system_admin']);
    }

    public function canManagePayroll(): bool
    {
        return in_array($this->role, ['hr_manager', 'superadmin', 'system_admin']);
    }

    public function canApprovePayRun(): bool
    {
        return in_array($this->role, ['hr_manager', 'superadmin']);
    }

    public function canManageEaForms(): bool
    {
        return in_array($this->role, ['hr_manager', 'superadmin', 'system_admin']);
    }

    // ── Claims capability checks ──────────────────────────────────────
    public function canViewAllClaims(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive', 'superadmin', 'system_admin']);
    }

    public function canManageClaims(): bool
    {
        // In the eClaim module, HR Executive has the same control as HR Manager
        // (manage categories, view & edit policy). Superadmin/system_admin keep this for
        // configuration oversight, but NOT the approve/reject action — see canApproveRejectClaims().
        return in_array($this->role, ['hr_manager', 'hr_executive', 'superadmin', 'system_admin']);
    }

    /**
     * May the user APPROVE or REJECT a claim on the HR Claims page?
     * Deliberately narrower than canManageClaims(): only HR Manager and HR Executive.
     * Superadmin/system_admin may VIEW all claims (canViewAllClaims) and manage categories/
     * policy (canManageClaims), but must never act as the HR approver on a claim.
     */
    public function canApproveRejectClaims(): bool
    {
        return in_array($this->role, ['hr_manager', 'hr_executive']);
    }

    /**
     * May the user see the "Claim Reports" page — the fully-approved (manager + HR) claims,
     * grouped Year > Month > Company > Employee for posting into the accounting system?
     * Finance team, HR Manager/Executive, and superadmin/system_admin.
     */
    public function canViewClaimReports(): bool
    {
        return in_array($this->role, ['finance_manager', 'finance_executive', 'hr_manager', 'hr_executive', 'superadmin', 'system_admin']);
    }

    /**
     * Roles that should be notified when a claim reaches the HR stage: the HR approvers
     * (hr_manager, hr_executive) plus superadmin for oversight. Single source of truth so
     * the submission email and the weekly sweep stay in sync — HR Executives must not be
     * dropped, since they can approve/reject claims (canApproveRejectClaims).
     */
    public function scopeClaimHrRole($query)
    {
        return $query->whereIn('role', ['hr_manager', 'hr_executive', 'superadmin']);
    }

    /**
     * May the user open the Team Claims page (the approver's inbox)? True for an approving
     * manager (has direct reports) OR anyone chosen as an approver on a claim item — and for
     * superadmin, who gets oversight of ALL team claims (see ExpenseClaimController::teamClaims()).
     */
    public function canViewTeamClaims(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }
        if (! $this->employee) {
            return false;
        }

        return \App\Models\Employee::where('manager_id', $this->employee->id)->exists()
            || \App\Models\ExpenseClaimItem::where('approver_id', $this->employee->id)->exists();
    }

    public function employee()
    {
        return $this->hasOne(Employee::class)->whereNull('active_until');
    }

    public function permissions()
    {
        return $this->hasMany(UserPermission::class);
    }

    public function trustedDevices()
    {
        return $this->hasMany(TrustedDevice::class);
    }

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

    // ── Accounting Module ──────────────────────────────────────────────

    public function isFinanceManager(): bool
    {
        return $this->role === 'finance_manager';
    }

    public function isFinanceExecutive(): bool
    {
        return $this->role === 'finance_executive';
    }

    public function isFinance(): bool
    {
        return in_array($this->role, ['finance_manager', 'finance_executive']);
    }

    public function canViewAccounting(): bool
    {
        return in_array($this->role, ['finance_manager', 'finance_executive', 'hr_manager', 'superadmin', 'system_admin']);
    }

    public function canManageAccounting(): bool
    {
        return in_array($this->role, ['finance_manager', 'superadmin', 'system_admin']);
    }

    public function canApproveTransactions(): bool
    {
        return in_array($this->role, ['finance_manager', 'superadmin']);
    }

    public function canUseAiChat(): bool
    {
        return in_array($this->role, ['finance_manager', 'superadmin']);
    }

    // ── Tickets capability checks ─────────────────────────────────────
    public function canViewAllTickets(): bool
    {
        return in_array($this->role, ['superadmin', 'system_admin']);
    }

    public function canManageTicketsForDepartment(string $department): bool
    {
        return \App\Models\Ticket::isManagerOf($this, $department);
    }

    /** True if the user manages tickets for at least one department. */
    public function isTicketManager(): bool
    {
        return ! empty(\App\Models\Ticket::departmentsManagedBy($this));
    }

    /**
     * True if the user can access the Ticket Management page (/tickets/manage).
     * - Superadmin / system_admin: always
     * - True department managers (manager-suffixed app roles or work_role='manager'): always
     *
     * Non-managers — executives, interns, regular employees — do NOT get the
     * Ticket Management page even if they're PIC-assignable. They see their
     * assigned tickets via the "Assigned to Me" tab on My Tickets (/tickets).
     *
     * Note: this is stricter than `isTicketManager()` / `Ticket::isManagerOf()`,
     * which intentionally include executives in the broader manager set so
     * executives still get new-ticket notifications and dept-team visibility.
     * The Ticket Management *page* is reserved for the narrower "true manager"
     * audience.
     */
    public function canAccessTicketManagement(): bool
    {
        if ($this->isSuperadmin() || $this->isSystemAdmin()) {
            return true;
        }
        // App-role-gated true managers
        if (in_array($this->role, ['hr_manager', 'it_manager', 'finance_manager'], true)) {
            return true;
        }

        // Work-role-gated true managers
        return $this->employee && $this->employee->work_role === 'manager';
    }
}
