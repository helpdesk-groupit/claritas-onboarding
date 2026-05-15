<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Data-fix: backfill `employees.manager_id` from the free-text
     * `employees.reporting_manager` column.
     *
     * Background: `manager_id` (the FK that powers reporting-chain features —
     * leave approval, and now ticket routing) is NULL on essentially every
     * row, because `reporting_manager` was captured as free text and the old
     * resolver did an exact `full_name` match that almost never hit. The names
     * are mostly in "PreferredName FullName" shape (e.g. "Petrina Goh Shze
     * Yinn" for full_name "Goh Shze Yinn" preferred "Petrina").
     *
     * Employee::resolveManagerId() now understands that pattern and is STRICT
     * — it returns a match only when exactly one active employee resolves.
     * This migration applies it to every employee with a reporting_manager
     * set but no manager_id.
     *
     * Rows whose reporting_manager cannot be resolved unambiguously (typo,
     * genuine duplicate employee records, or a manager no longer active) are
     * LEFT UNTOUCHED and logged. A superadmin re-selects the correct employee
     * for those via the reporting-manager dropdown on the Employee edit page,
     * which sets manager_id directly. We never guess an identity here.
     *
     * Also normalises reporting_manager to the matched employee's canonical
     * full_name so the stored text and the FK agree going forward.
     *
     * Idempotent: only touches rows where manager_id IS NULL.
     */
    public function up(): void
    {
        if (! class_exists(\App\Models\Employee::class)) {
            return;
        }

        $resolved = 0;
        $unresolved = [];

        $rows = \App\Models\Employee::whereNull('active_until')
            ->whereNull('manager_id')
            ->whereNotNull('reporting_manager')
            ->where('reporting_manager', '!=', '')
            ->get(['id', 'full_name', 'reporting_manager']);

        foreach ($rows as $emp) {
            $managerId = \App\Models\Employee::resolveManagerId($emp->reporting_manager);

            // No unambiguous match, or it resolved to the employee themselves
            // (self-reference) — skip and record for manual fixing.
            if ($managerId === null || $managerId === (int) $emp->id) {
                $unresolved[] = [
                    'employee_id' => $emp->id,
                    'employee' => $emp->full_name,
                    'reporting_manager' => $emp->reporting_manager,
                ];

                continue;
            }

            // Canonical full_name of the resolved manager, so the stored
            // free-text and the FK stay consistent.
            $canonical = \App\Models\Employee::whereKey($managerId)->value('full_name');

            \App\Models\Employee::whereKey($emp->id)->update([
                'manager_id' => $managerId,
                'reporting_manager' => $canonical ?: $emp->reporting_manager,
            ]);
            $resolved++;
        }

        if (function_exists('logger')) {
            logger()->info('[migration] backfill_employee_manager_id', [
                'resolved' => $resolved,
                'unresolved_count' => count($unresolved),
                'unresolved' => $unresolved,
            ]);
        }
    }

    /**
     * Irreversible — re-derived FK data, nothing meaningful to roll back to
     * (the pre-fix state was NULL by definition).
     */
    public function down(): void
    {
        // no-op
    }
};
