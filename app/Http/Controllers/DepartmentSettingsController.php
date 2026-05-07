<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\DepartmentCompanyAccess;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Superadmin-only configuration page for assigning ticketing departments to
 * companies. Each department can serve one or more companies; a department with
 * no rows in `department_company_access` serves all companies (default).
 */
class DepartmentSettingsController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (!$user->isSuperadmin() && !$user->isSystemAdmin()) {
            abort(403);
        }

        $companies   = Company::orderBy('name')->get(['id', 'name']);
        $departments = Ticket::DEPARTMENTS;

        // Existing extras per dept (the editable pivot rows).
        $assignments = DepartmentCompanyAccess::all()
            ->groupBy('department')
            ->map(fn($rows) => $rows->pluck('company_id')->map(fn($id) => (int) $id)->toArray())
            ->toArray();

        // Auto-derived served companies per dept (computed from member employees).
        // Used to show the per-row "Auto-serves: …" subtitle and to suppress the
        // misleading "(serves all)" badge when a dept actually has auto-coverage.
        $autoServed     = [];
        $autoServedNames = [];
        foreach ($departments as $dept) {
            $allIds = Ticket::companiesServingDepartment($dept);
            // Subtract the explicit-extras to get the truly auto-derived set
            $extraIds  = $assignments[$dept] ?? [];
            $autoIds   = array_values(array_diff($allIds, $extraIds));
            $autoServed[$dept] = $autoIds;
            $autoServedNames[$dept] = $companies->whereIn('id', $autoIds)->pluck('name')->all();
        }

        return view('superadmin.department-settings', [
            'companies'        => $companies,
            'departments'      => $departments,
            'assignments'      => $assignments,
            'autoServed'       => $autoServed,
            'autoServedNames'  => $autoServedNames,
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        if (!$user->isSuperadmin() && !$user->isSystemAdmin()) {
            abort(403);
        }

        // Form posts: assignments[<department>][] = <company_id>
        // Departments with zero checked boxes simply won't appear in the array.
        $assignments = $request->input('assignments', []);
        $validCompanyIds = Company::pluck('id')->map(fn($id) => (int) $id)->all();

        DB::transaction(function () use ($assignments, $validCompanyIds) {
            // Wipe and rebuild — simplest and safest for a small admin-edited table
            DepartmentCompanyAccess::query()->delete();

            foreach ($assignments as $dept => $companyIds) {
                if (!in_array($dept, Ticket::DEPARTMENTS, true)) continue;
                if (!is_array($companyIds)) continue;

                foreach ($companyIds as $companyId) {
                    $companyId = (int) $companyId;
                    if (!in_array($companyId, $validCompanyIds, true)) continue;

                    DepartmentCompanyAccess::create([
                        'department' => $dept,
                        'company_id' => $companyId,
                    ]);
                }
            }
        });

        return back()->with('success', 'Department-company access updated.');
    }
}
