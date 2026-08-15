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

    /**
     * Creative / GTM departments (employees.department) whose members may use the
     * Social Media AI Strategist, in addition to the admin roles below. Operations
     * / finance / kitchen etc. are deliberately excluded. "Video" is listed for
     * forward-compat — there is no such department in the data yet, so it matches
     * nobody today; video staff currently sit under Production/Media.
     */
    public const SOCIAL_STRATEGIST_DEPARTMENTS = [
        'Digital', 'Projects', 'Marketing', 'Sales', 'Production',
        'Media', 'KOL', 'Content', 'Design', 'Video',
    ];

    /**
     * Who may use the Social Media AI Strategist (IT > Automation). The admin
     * roles (IT, superadmin, system_admin, HR manager) always qualify; beyond
     * them, any active employee in one of SOCIAL_STRATEGIST_DEPARTMENTS. This one
     * helper is the single place access is decided — the sidebar and the
     * controller both read it.
     */
    public function canUseSocialStrategist(): bool
    {
        if ($this->isIt() || $this->isSuperadmin() || $this->isSystemAdmin() || $this->isHrManager()) {
            return true;
        }

        // employees.department is free-text — match case-insensitively so a stray
        // casing difference doesn't silently lock a whole team out.
        $dept = trim((string) ($this->employee?->department ?? ''));
        if ($dept === '') {
            return false;
        }

        return in_array(
            mb_strtolower($dept),
            array_map('mb_strtolower', self::SOCIAL_STRATEGIST_DEPARTMENTS),
            true
        );
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

    // ── Asset Decommissioning / Vendor Management ──────────────────────
    /**
     * Roles that reach Vendor Management. Finance + IT + admin oversight.
     *
     * Finance was excluded on 2026-07-29 while the module was only the rental/repair/
     * e-waste list the decommissioning flows read a PIC from — at that scope it really
     * was IT-only master data. Reversed on 2026-08-06: the table is now the company-wide
     * vendor master holding contracts, quotations, invoices and SST registration, which
     * is Finance's own subject matter, so locking them out would mean the people who own
     * the commercial relationship couldn't see it.
     *
     * Interns (it_intern / hr_intern) stay out of BOTH gates: the profile carries
     * contract values, billing and tax identity, and there is no read-only slice of it
     * worth the blast radius of getting the split wrong.
     */
    private const VENDOR_ROLES = [
        'it_manager', 'it_executive',
        'finance_manager', 'finance_executive',
        'superadmin', 'system_admin',
    ];

    /**
     * May the user create/edit vendors, contracts and billing documents?
     *
     * Same set as canViewVendors(): everyone admitted to the vendor master maintains it.
     * A viewer/editor split was considered and dropped — Finance corrects tax identity
     * and payment terms, IT corrects PICs and technical contacts, and neither group is
     * a passive audience for the other's fields.
     */
    public function canManageVendors(): bool
    {
        return in_array($this->role, self::VENDOR_ROLES);
    }

    /** May the user VIEW Vendor Management? See VENDOR_ROLES. */
    public function canViewVendors(): bool
    {
        return in_array($this->role, self::VENDOR_ROLES);
    }

    /**
     * May the user drive the IT-side decommission flows (create batches, sweep e-waste,
     * upload quotation/receipt, finalize)? IT managers + IT executives + admins.
     *
     * it_executive was added 2026-07-30 so the day-to-day IT operator — not just the
     * manager — can run a collection batch and work the e-waste cycle. This is the ONE
     * gate for the whole IT side of both flows, so it necessarily also grants finalize,
     * cancel and resend; the batch is IT's own operational record, and Finance still
     * holds the only approval step (canApproveEwasteQuotation). Interns stay out.
     * Note this does NOT grant the C-Suite report archive (canViewDecommissionReports)
     * or the vendor master (canManageVendors) — both remain it_manager+admin.
     */
    public function canManageDecommission(): bool
    {
        return in_array($this->role, ['it_manager', 'it_executive', 'superadmin', 'system_admin']);
    }

    /**
     * May the user approve/reject an e-waste quotation in-app (mirrors the eClaim
     * hrApprove gate, for Finance)? Finance + superadmin oversight.
     */
    public function canApproveEwasteQuotation(): bool
    {
        return in_array($this->role, ['finance_manager', 'finance_executive', 'superadmin']);
    }

    /**
     * May this user cast the MANAGEMENT decision on a cycle for $company?
     *
     * Deliberately per-company and identity-based, not role-based: the approvers span
     * companies (CEO of one entity, CTO of another), so nothing derivable from this user's own
     * employer or role can express it. See EwasteCompanyApprover.
     *
     * Management's decision is the one that advances a cycle, so this is a strictly narrower
     * gate than canApproveEwasteQuotation() — being in Finance does not make you management.
     */
    public function canApproveEwasteAsManagement(?string $company): bool
    {
        return EwasteCompanyApprover::approversFor($company)->contains('id', $this->id);
    }

    /** Is this user a named management approver for any company at all? Drives UI visibility. */
    public function isEwasteManagementApprover(): bool
    {
        return EwasteCompanyApprover::where('user_id', $this->id)->exists()
            || $this->role === 'superadmin';
    }

    /** May this user configure who approves disposals? Superadmin only — it grants authority. */
    public function canManageEwasteApprovers(): bool
    {
        return in_array($this->role, ['superadmin', 'system_admin']);
    }

    /**
     * May the user open the Decommissioning page? The existing reports set
     * (superadmin/hr_manager/system_admin) widened with it_manager + Finance — plus anybody
     * NAMED as a management approver.
     *
     * The named-approver arm is not a convenience. Since the page became the single review
     * surface, it is where a disposal is authorised and it is the link the approval email
     * carries; a CEO named in ewaste_company_approvers holds none of the six roles above and
     * would have 403'd on the page they were just asked to act on. What they may SEE there is
     * narrowed per-company by reachableDecommissionCompanies().
     */
    public function canViewDecommissionReports(): bool
    {
        return in_array($this->role, ['superadmin', 'system_admin', 'hr_manager', 'it_manager', 'finance_manager', 'finance_executive'])
            || $this->isEwasteManagementApprover();
    }

    /**
     * Which companies' cycles this user may see on the Decommissioning page.
     *
     * Null means "every company" — the role-holders above, who own the process or the money
     * across the group. A user who reaches the page ONLY by being a named approver is scoped
     * to their own companies: another entity's disposal is not theirs to read, the same rule
     * that decides who a signed AARF is copied to. Their authority is per-company by design
     * (canApproveEwasteAsManagement), so the list they see matches the list they can act on.
     *
     * @return \Illuminate\Support\Collection<int, string>|null
     */
    public function reachableDecommissionCompanies(): ?\Illuminate\Support\Collection
    {
        if (in_array($this->role, ['superadmin', 'system_admin', 'hr_manager', 'it_manager', 'finance_manager', 'finance_executive'])) {
            return null;
        }

        return EwasteCompanyApprover::companiesFor($this->id);
    }

    /**
     * Finance recipients for decommission notifications/mail — the finance approvers plus
     * superadmin oversight. No finance-targeted scope existed before this module; mirrors
     * scopeClaimHrRole. Single source of truth for finance notify targeting.
     */
    public function scopeFinanceRole($query)
    {
        return $query->whereIn('role', ['finance_manager', 'finance_executive', 'superadmin']);
    }

    /**
     * Recipients for the IT-team emails. Same shape and same rules as
     * financeEmailRecipients(): TO the IT Manager(s), CC the IT Executive(s), work email
     * only, superadmins taking the TO line when no it_manager exists so a report is never
     * silently dropped.
     *
     * @return array{to: string[], cc: string[]}
     */
    public static function itEmailRecipients(): array
    {
        return static::roleEmailRecipients(['it_manager'], ['it_executive']);
    }

    /**
     * Recipients for the e-waste "finance team" emails. The report is addressed TO the
     * Finance Manager(s) and CC'd to the Finance Executive(s) — WORK EMAIL ONLY (users
     * authenticate on work_email; there is no personal-email fallback). If no dedicated
     * finance_manager exists, superadmins take the TO line so a report is never silently
     * dropped. An address never appears on both the TO and CC lines.
     *
     * @return array{to: string[], cc: string[]}
     */
    public static function financeEmailRecipients(): array
    {
        return static::roleEmailRecipients(['finance_manager'], ['finance_executive']);
    }

    /**
     * The shared body of the two helpers above — extracted so the IT and Finance lines can
     * never drift apart on the rules that matter (active only, work email only, superadmin
     * fallback, no address on both TO and CC).
     *
     * @param  string[]  $toRoles
     * @param  string[]  $ccRoles
     * @return array{to: string[], cc: string[]}
     */
    protected static function roleEmailRecipients(array $toRoles, array $ccRoles): array
    {
        $workEmails = fn (array $roles) => static::query()
            ->whereIn('role', $roles)
            ->where('is_active', true)
            ->whereNotNull('work_email')
            ->where('work_email', '!=', '')
            ->pluck('work_email')
            ->map(fn ($e) => trim((string) $e))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $to = $workEmails($toRoles);
        if (empty($to)) {
            // No dedicated manager for that function — fall back to superadmin oversight
            // so a report is never silently dropped.
            $to = $workEmails(['superadmin']);
        }

        $cc = array_values(array_diff($workEmails($ccRoles), $to));

        return ['to' => $to, 'cc' => $cc];
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
