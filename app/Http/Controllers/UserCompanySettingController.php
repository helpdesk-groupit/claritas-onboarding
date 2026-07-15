<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
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

        return view('superadmin.user-company-settings', compact('grouped', 'companies'));
    }

    public function bulkAssign(Request $request)
    {
        $this->authorizeSuperadmin();

        $data = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|exists:employees,id',
            'company' => 'required|string|max:255',
            'effective_date' => 'required|date|before_or_equal:today',
        ]);

        // Office follows the company: use the target company's registered address.
        $targetCompany = Company::forName($data['company']);
        if (! $targetCompany) {
            return back()->with('error', 'Unknown company selected.');
        }
        $newCompany = $targetCompany->name;
        $newOffice = $targetCompany->address;
        $effectiveDate = Carbon::parse($data['effective_date'])->startOfDay();
        $actorId = Auth::id();

        $changed = [];
        $skippedSame = [];
        $skippedDate = [];

        DB::transaction(function () use ($data, $newCompany, $newOffice, $effectiveDate, $actorId, &$changed, &$skippedSame, &$skippedDate) {
            $employees = Employee::whereIn('id', $data['employee_ids'])->get();
            foreach ($employees as $emp) {
                $result = $emp->changeCompanyEffective($newCompany, $newOffice, $effectiveDate, $actorId);
                $label = $emp->full_name ?? ('#'.$emp->id);
                match ($result['status']) {
                    'changed' => $changed[] = $label,
                    'skipped_same' => $skippedSame[] = $label,
                    'skipped_date' => $skippedDate[] = $label.' — '.$result['message'],
                    default => null,
                };

                // Keep the onboarding work-detail in sync, like the single-employee edit does.
                if ($result['status'] === 'changed' && $emp->onboarding_id) {
                    \App\Models\Onboarding::with('workDetail')->find($emp->onboarding_id)?->workDetail
                        ?->update(['company' => $newCompany, 'office_location' => $newOffice]);
                }
            }
        });

        Log::info('Bulk company assignment', [
            'actor_id' => $actorId, 'company' => $newCompany,
            'effective_date' => $effectiveDate->toDateString(),
            'changed' => count($changed), 'skipped_same' => count($skippedSame), 'skipped_date' => count($skippedDate),
        ]);

        $summary = count($changed).' employee(s) moved to '.$newCompany.' effective '.$effectiveDate->format('d M Y').'.';
        if ($skippedSame) {
            $summary .= ' '.count($skippedSame).' already there (skipped).';
        }

        return redirect()->route('superadmin.user-company-settings.index')
            ->with('success', $summary)
            ->with('bulk_skipped_date', $skippedDate); // per-employee date conflicts, shown in a details panel
    }
}
