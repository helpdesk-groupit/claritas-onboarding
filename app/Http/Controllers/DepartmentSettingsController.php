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

        // Existing extras keyed by (source_company_id, department) → list of
        // served company ids. The view nests dept rows under a company
        // accordion, so the source company is fixed per row.
        $assignments = []; // [source_company_id][dept] => [served_company_ids]
        DepartmentCompanyAccess::whereNotNull('source_company_id')
            ->get(['source_company_id', 'department', 'company_id'])
            ->each(function ($row) use (&$assignments) {
                $assignments[(int) $row->source_company_id][$row->department][] = (int) $row->company_id;
            });

        // Auto-derived served companies per dept — used to render the
        // "Auto-serves" subtitle on each dept row. Not affected by the source
        // split since auto-derivation always points to the company hosting
        // the team (which is the same as the source).
        $autoServed = [];
        foreach ($departments as $dept) {
            $autoServed[$dept] = Ticket::defaultServedCompanyIdsForDepartmentPublic($dept);
        }

        return view('superadmin.department-settings', [
            'companies'   => $companies,
            'departments' => $departments,
            'assignments' => $assignments,
            'autoServed'  => $autoServed,
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        if (!$user->isSuperadmin() && !$user->isSystemAdmin()) {
            abort(403);
        }

        // Form posts: assignments[<source_company_id>][<department>][] = <served_company_id>
        // The source company is implicit in the accordion (each dept row sits
        // under one company's section); the view encodes it in the input name.
        $assignments = $request->input('assignments', []);
        $validCompanyIds = Company::pluck('id')->map(fn($id) => (int) $id)->all();

        DB::transaction(function () use ($assignments, $validCompanyIds) {
            // Wipe and rebuild — simplest and safest for this small
            // admin-edited table. The unique constraint on
            // (source_company_id, department, company_id) protects against
            // accidental duplicates from a malformed POST.
            DepartmentCompanyAccess::query()->delete();

            foreach ($assignments as $sourceCompanyId => $deptMap) {
                $sourceCompanyId = (int) $sourceCompanyId;
                if (!in_array($sourceCompanyId, $validCompanyIds, true)) continue;
                if (!is_array($deptMap)) continue;

                foreach ($deptMap as $dept => $servedIds) {
                    if (!in_array($dept, Ticket::DEPARTMENTS, true)) continue;
                    if (!is_array($servedIds)) continue;

                    foreach ($servedIds as $servedId) {
                        $servedId = (int) $servedId;
                        if (!in_array($servedId, $validCompanyIds, true)) continue;
                        // Don't store a self-row (source serving itself is
                        // already implied by auto-derive membership).
                        if ($servedId === $sourceCompanyId) continue;

                        DepartmentCompanyAccess::create([
                            'source_company_id' => $sourceCompanyId,
                            'department'        => $dept,
                            'company_id'        => $servedId,
                        ]);
                    }
                }
            }
        });

        return back()->with('success', 'Department-company access updated.');
    }
}
