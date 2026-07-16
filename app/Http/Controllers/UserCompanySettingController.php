<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ScheduledCompanyChange;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Superadmin-only: bulk-assign employees to a company effective from a chosen date (which may be
 * in the past). Each change is recorded on the company timeline (employee_company_histories), so
 * it shows on the Employee listing and the user profile automatically. Historical claims/leave
 * keep their own company snapshots and are not relabelled.
 */
class UserCompanySettingController extends Controller
{
    private function authorizeSuperadmin(): void
    {
        // Strictly superadmin — NOT system_admin.
        if (! Auth::user()->isSuperadmin()) {
            abort(403);
        }
    }

    public function index()
    {
        $this->authorizeSuperadmin();

        // Active employees, grouped by their current company for the company-first listing.
        $employees = Employee::query()
            ->whereNull('active_until')
            ->with(['companyHistories' => fn ($q) => $q->whereNull('ended_on')])
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'preferred_name', 'department', 'company', 'office_location', 'start_date']);

        $grouped = $employees
            ->groupBy(fn ($e) => $e->company ?: '— No company —')
            ->sortKeys();

        $companies = Company::orderBy('name')->get(['name', 'address']);

        // Upcoming future-dated moves awaiting their effective date, newest schedule first.
        $scheduled = ScheduledCompanyChange::with(['employee:id,full_name,company', 'scheduledBy:id,name'])
            ->pending()
            ->orderBy('effective_date')
            ->get();

        return view('superadmin.user-company-settings', compact('grouped', 'companies', 'scheduled'));
    }

    public function bulkAssign(Request $request)
    {
        $this->authorizeSuperadmin();

        $data = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|exists:employees,id',
            'company' => 'required|string|max:255',
            // Past/today moves apply immediately; a future date is deferred and scheduled below.
            'effective_date' => 'required|date',
        ]);

        // Office follows the company: use the target company's registered address.
        $targetCompany = Company::forName($data['company']);
        if (! $targetCompany) {
            return back()->withInput()->with('error', 'Unknown company selected.');
        }
        $newCompany = $targetCompany->name;
        $newOffice = $targetCompany->address;
        $effectiveDate = Carbon::parse($data['effective_date'])->startOfDay();
        $actorId = Auth::id();

        // Future-dated → don't touch the timeline now; store the intent and let
        // `company:apply-scheduled` apply it on the day (see scheduleFutureMove).
        if ($effectiveDate->gt(Carbon::today())) {
            return $this->scheduleFutureMove($data['employee_ids'], $newCompany, $newOffice, $effectiveDate, $actorId);
        }
        $confirmed = $request->boolean('confirmed');

        $employees = Employee::whereIn('id', $data['employee_ids'])->get();

        // ── Preview: which selected employees would REWRITE (remove) timeline history? ──
        // That happens when the effective date is on/before their current company's
        // start — instead of silently skipping (old behaviour) we hold the whole batch
        // for a detailed confirmation the first time round.
        $rewrites = [];
        foreach ($employees as $emp) {
            $preview = $emp->previewCompanyChange($newCompany, $effectiveDate);
            if ($preview['mode'] === 'rewrite') {
                $rewrites[$emp->id] = $preview['removes'];
            }
        }

        if (! empty($rewrites) && ! $confirmed) {
            $details = [];
            foreach ($rewrites as $empId => $removes) {
                $emp = $employees->firstWhere('id', $empId);
                $details[] = [
                    'name' => $emp->full_name ?? ('#'.$emp->id),
                    'removes' => $removes->map(fn ($s) => Employee::stintLabel($s))->values()->all(),
                ];
            }

            return back()->withInput()->with('rewrite_confirm', [
                'company' => $newCompany,
                'effective' => $effectiveDate->format('d M Y'),
                'total' => $employees->count(),
                'employees' => $details,
            ]);
        }

        // ── Apply ──
        $changed = [];
        $rewritten = [];
        $skippedSame = [];

        DB::transaction(function () use ($employees, $rewrites, $newCompany, $newOffice, $effectiveDate, $actorId, &$changed, &$rewritten, &$skippedSame) {
            foreach ($employees as $emp) {
                $result = isset($rewrites[$emp->id])
                    ? $emp->rewriteCompanyFrom($newCompany, $newOffice, $effectiveDate, $actorId)
                    : $emp->changeCompanyEffective($newCompany, $newOffice, $effectiveDate, $actorId);

                $label = $emp->full_name ?? ('#'.$emp->id);
                match ($result['status']) {
                    'changed' => $changed[] = $label,
                    'rewritten' => $rewritten[] = $label,
                    'skipped_same' => $skippedSame[] = $label,
                    default => null,
                };

                if (in_array($result['status'], ['changed', 'rewritten'], true)) {
                    // Ripple the move through the employee's already-created records:
                    // claims/leave/tickets dated on/after the effective date follow the
                    // new company; earlier ones stay put.
                    app(\App\Services\CompanyAttributionService::class)->reattributeEmployee($emp);

                    // Keep the onboarding work-detail in sync, like the single-employee edit does.
                    if ($emp->onboarding_id) {
                        \App\Models\Onboarding::with('workDetail')->find($emp->onboarding_id)?->workDetail
                            ?->update(['company' => $emp->company, 'office_location' => $emp->office_location]);
                    }
                }
            }
        });

        Log::info('Bulk company assignment', [
            'actor_id' => $actorId, 'company' => $newCompany,
            'effective_date' => $effectiveDate->toDateString(),
            'changed' => count($changed), 'rewritten' => count($rewritten), 'skipped_same' => count($skippedSame),
        ]);

        $done = count($changed) + count($rewritten);
        $summary = $done.' employee(s) moved to '.$newCompany.' effective '.$effectiveDate->format('d M Y').'.';
        if ($rewritten) {
            $summary .= ' '.count($rewritten).' rewound over existing timeline history.';
        }
        if ($skippedSame) {
            $summary .= ' '.count($skippedSame).' already there (skipped).';
        }

        return redirect()->route('superadmin.user-company-settings.index')->with('success', $summary);
    }

    /**
     * Record a future-dated company move as intent (scheduled_company_changes). The move is NOT
     * applied now — `company:apply-scheduled` runs it on the effective date. At most one pending
     * change per employee: scheduling a new one supersedes any prior pending change for them.
     * Employees already at the target company are skipped (nothing to schedule).
     */
    private function scheduleFutureMove(array $employeeIds, string $newCompany, ?string $newOffice, Carbon $effectiveDate, ?int $actorId)
    {
        $employees = Employee::whereIn('id', $employeeIds)->whereNull('active_until')->get();
        $scheduled = [];
        $skippedSame = [];

        DB::transaction(function () use ($employees, $newCompany, $newOffice, $effectiveDate, $actorId, &$scheduled, &$skippedSame) {
            foreach ($employees as $emp) {
                $label = $emp->full_name ?? ('#'.$emp->id);

                if ($emp->company === $newCompany) {
                    $skippedSame[] = $label;

                    continue;
                }

                // Supersede any existing pending change for this employee (one pending per person).
                ScheduledCompanyChange::where('employee_id', $emp->id)->where('status', 'pending')->update([
                    'status' => 'superseded',
                    'note' => 'Replaced by a newer scheduled change.',
                ]);

                ScheduledCompanyChange::create([
                    'employee_id' => $emp->id,
                    'company' => $newCompany,
                    'office_location' => $newOffice,
                    'effective_date' => $effectiveDate->toDateString(),
                    'status' => 'pending',
                    'scheduled_by' => $actorId,
                ]);
                $scheduled[] = $label;
            }
        });

        Log::info('Scheduled future company change', [
            'actor_id' => $actorId, 'company' => $newCompany,
            'effective_date' => $effectiveDate->toDateString(),
            'scheduled' => count($scheduled), 'skipped_same' => count($skippedSame),
        ]);

        $summary = count($scheduled).' employee(s) scheduled to move to '.$newCompany.' on '.$effectiveDate->format('d M Y').'. It will apply automatically that day.';
        if ($skippedSame) {
            $summary .= ' '.count($skippedSame).' already there (skipped).';
        }

        return redirect()->route('superadmin.user-company-settings.index')->with('success', $summary);
    }

    /** Cancel a still-pending scheduled company change before it applies. */
    public function cancelScheduled(ScheduledCompanyChange $change)
    {
        $this->authorizeSuperadmin();

        if ($change->status !== 'pending') {
            return back()->with('error', 'That scheduled change is no longer pending.');
        }

        $change->update([
            'status' => 'cancelled',
            'cancelled_by' => Auth::id(),
            'cancelled_at' => now(),
        ]);

        return back()->with('success', 'Scheduled company change cancelled.');
    }
}
