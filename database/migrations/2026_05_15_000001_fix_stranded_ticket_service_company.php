<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data-fix: repair "stranded" tickets whose `service_company_id` points at
     * a company that is NOT a valid service provider for the ticket's
     * department.
     *
     * Cause: a ticket raised or re-routed by a superadmin (who has no employee
     * record / no company) hit the admin fallback in TicketController::store()
     * — `$serviceCompanyId = $sourcePool[0]` — and landed on an arbitrary
     * company; OR a pre-fix Edit-Department re-route changed `department`
     * without recomputing `service_company_id`. Either way the ticket carries
     * a service company that doesn't run its department.
     *
     * Effect of the bug: Ticket::scopeVisibleTo() matches a manager's own
     * company against `service_company_id`, so a stranded ticket is invisible
     * to EVERY department manager — it only ever showed in the superadmin's
     * own view (which bypasses the scope). Resolving it then merely moved it
     * into the superadmin's archive, never the department managers' inbox.
     *
     * Repair rule ("same company, new dept"): re-resolve the service company
     * via Ticket::resolveServiceCompanyIdForDepartmentChange() — keep the
     * ticket with the raiser's own company when that company runs the
     * department, otherwise auto-resolve to the single configured provider.
     * Tickets that cannot be resolved (genuinely ambiguous, or no provider
     * exists) are left untouched and logged — they need a manual re-route via
     * the Edit feature.
     *
     * Idempotent: re-running only touches rows still mismatched; already-valid
     * rows are skipped by isValidServiceCompanyForDepartment().
     */
    public function up(): void
    {
        if (! class_exists(\App\Models\Ticket::class)) {
            return;
        }

        $repaired = 0;
        $unresolved = [];

        $tickets = DB::table('tickets')->get(['id', 'ticket_number', 'company_id', 'service_company_id', 'department']);

        foreach ($tickets as $row) {
            // Skip tickets whose routing is already valid for their department.
            if (\App\Models\Ticket::isValidServiceCompanyForDepartment($row->service_company_id, $row->department)) {
                continue;
            }

            // NULL service_company_id is handled by the legacy fallback in
            // scopeVisibleTo() — leave those alone; this fix targets rows that
            // are SET but wrong. (A separate earlier migration already
            // backfilled NULLs to company_id.)
            if ($row->service_company_id === null) {
                continue;
            }

            $resolved = \App\Models\Ticket::resolveServiceCompanyIdForDepartmentChange(
                $row->company_id !== null ? (int) $row->company_id : null,
                $row->department
            );

            if ($resolved === null || $resolved === (int) $row->service_company_id) {
                // Cannot improve it automatically — record for manual review.
                $unresolved[] = $row->ticket_number ?? ('#'.$row->id);

                continue;
            }

            DB::table('tickets')->where('id', $row->id)->update([
                'service_company_id' => $resolved,
            ]);
            $repaired++;
        }

        if (function_exists('logger')) {
            logger()->info('[migration] fix_stranded_ticket_service_company', [
                'repaired' => $repaired,
                'unresolved_count' => count($unresolved),
                'unresolved_tickets' => $unresolved,
            ]);
        }
    }

    /**
     * Irreversible — this is a data correction, not a schema change. The
     * pre-fix `service_company_id` values were wrong by definition, so there
     * is nothing meaningful to roll back to.
     */
    public function down(): void
    {
        // no-op
    }
};
