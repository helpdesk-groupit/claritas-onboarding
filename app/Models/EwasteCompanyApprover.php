<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Who in management may authorise an e-waste disposal, per company.
 *
 * Explicit rather than derived, because the real approvers span companies — one person is CEO
 * of one entity and CTO of another, and an employee record carries exactly one company. A role
 * would not work either: it would approve every company's cycles at once.
 *
 * Several per company is normal, and the FIRST decision counts (see
 * AssetDecommissionBatch::recordManagementDecision) — waiting for every named approver would
 * stall a cycle behind whoever is on leave.
 *
 * TWO READERS, and the coupling is deliberate (2026-08-14). Besides e-waste approval, this is
 * also the list the signed AARF is copied to for the company on the form — see
 * notificationEmailsFor() and RentalAssetAcknowledgementController::distributeSignedCopy().
 * It was reused rather than duplicated so the CEO/CTO of each entity are named in ONE place;
 * the price is that the two meanings move together, which the settings screen states in as
 * many words: naming somebody here BOTH grants them disposal authority AND puts them on the
 * AARF copy list, and removing them takes both away. If those ever need to diverge, split this
 * into a purpose-scoped mapping rather than filtering one meaning out of the other silently.
 */
class EwasteCompanyApprover extends Model
{
    protected $table = 'ewaste_company_approvers';

    protected $fillable = ['company', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The management approvers for a company.
     *
     * Falls back to superadmins when none are configured, so a cycle can never become
     * un-approvable by a company simply having been missed on the settings screen — a stuck
     * cycle holds assets in a queue indefinitely with nobody able to release them. Whether the
     * fallback is in play is visible via configuredFor(), so the UI can say so rather than
     * quietly presenting a superadmin as the named authority.
     *
     * @return Collection<int, User>
     */
    public static function approversFor(?string $company): Collection
    {
        $configured = self::configuredFor($company);

        if ($configured->isNotEmpty()) {
            return $configured;
        }

        return User::where('role', 'superadmin')->where('is_active', true)->get();
    }

    /** The approvers actually configured for a company — empty when nobody has been named. */
    public static function configuredFor(?string $company): Collection
    {
        if (blank($company)) {
            return collect();
        }

        return User::whereIn('id', self::where('company', $company)->pluck('user_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Work-email addresses of the management people named for a company.
     *
     * WORK EMAIL ONLY, and deduped — the same rules User::roleEmailRecipients() applies to the
     * IT and Finance lines, so the four legs of an AARF distribution cannot drift apart on who
     * counts as reachable. Users authenticate on work_email; there is no personal fallback,
     * and a named approver without one is simply not addressable.
     *
     * Goes through approversFor(), so an unnamed company falls back to superadmins rather than
     * silently sending a signed document to nobody. An EMPTY result therefore means neither a
     * named approver nor an active superadmin exists — a broken configuration, which the
     * caller reports rather than passes over.
     *
     * @return string[]
     */
    public static function notificationEmailsFor(?string $company): array
    {
        return self::approversFor($company)
            ->pluck('work_email')
            ->map(fn ($email) => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The companies this user is a NAMED approver for.
     *
     * Reads the configured rows only, never approversFor()'s superadmin fallback: this scopes
     * what an approver may see on the Decommissioning page, and a superadmin already reaches it
     * by role. Widening it through the fallback would hand every company's disposals to every
     * superadmin twice over, by a route nobody configured.
     *
     * @return Collection<int, string>
     */
    public static function companiesFor(?int $userId): Collection
    {
        if (! $userId) {
            return collect();
        }

        return self::where('user_id', $userId)->pluck('company')->filter()->unique()->values();
    }

    /** Every mapping, grouped by company — for the settings screen. */
    public static function map(): array
    {
        return self::query()->get()->groupBy('company')
            ->map(fn ($rows) => $rows->pluck('user_id')->all())
            ->all();
    }
}
