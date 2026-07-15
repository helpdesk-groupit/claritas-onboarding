<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Re-attributes an employee's employer-stamped records to the company they were
 * under on the record's own date, resolved from the company timeline
 * (Employee::companyAsOf). This is what makes a BACK-DATED company change ripple
 * through already-created records: move someone Claritas→Enlinea effective 1 May
 * (done in July) and their May-onward claims/leave/tickets follow to Enlinea,
 * while anything before 1 May stays Claritas.
 *
 * The timeline (employee_company_histories) is the source of truth; each record's
 * stored company column is a materialised cache we recompute here. Full recompute
 * is idempotent and handles multi-stint histories and later corrections.
 *
 * Anchors (the date each record is tested against the timeline):
 *   - Claims:  the submission cycle (year/month stamp) → 1st of that month
 *   - Leave:   start_date
 *   - Tickets: created_at (re-stamps company_id = the raiser's employer)
 *
 * Deliberately OUT OF SCOPE: payroll/payslips (frozen actual-payment records via
 * pay_runs.company — moving them would misstate the paying entity), attendance,
 * salary and EA forms (no company column; EA needs a per-employer split that is a
 * separate feature). See the v1 scope decision.
 */
class CompanyAttributionService
{
    /**
     * Recompute the stored company on all of an employee's claims, leave and
     * tickets from the timeline. Idempotent.
     *
     * @param  bool  $apply  false = count what WOULD change without writing (dry run)
     * @return array{claims:int, leave:int, tickets:int}
     */
    public function reattributeEmployee(Employee $employee, bool $apply = true): array
    {
        if ($apply) {
            // Make sure there's at least an opening stint so companyAsOf() resolves
            // against a real timeline rather than only the current-company fallback.
            $employee->ensureInitialCompanyStint();
        }
        // Load the timeline once so every companyAsOf() below reuses it (no N+1).
        $employee->load('companyHistories');

        return [
            'claims' => $this->reattributeClaims($employee, $apply),
            'leave' => $this->reattributeLeave($employee, $apply),
            'tickets' => $this->reattributeTickets($employee, $apply),
        ];
    }

    /**
     * The company an already-submitted claim should carry, per the submission-cycle
     * anchor. Public so the submit controllers stamp new claims the same way this
     * service would later re-stamp them (correct even for back-dated submissions).
     */
    public function companyForClaim(ExpenseClaim $claim): ?string
    {
        $employee = $claim->employee;
        if (! $employee) {
            return null;
        }

        return $employee->companyAsOf($this->claimAnchor($claim)) ?? $employee->company;
    }

    private function reattributeClaims(Employee $employee, bool $apply): int
    {
        $changed = 0;
        DB::table('expense_claims')
            ->where('employee_id', $employee->id)
            ->select('id', 'year', 'month', 'submitted_at', 'created_at', 'company')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($employee, $apply, &$changed) {
                foreach ($rows as $row) {
                    $company = $employee->companyAsOf($this->claimAnchor($row));
                    if ($company !== null && $company !== $row->company) {
                        if ($apply) {
                            DB::table('expense_claims')->where('id', $row->id)->update(['company' => $company]);
                        }
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    private function reattributeLeave(Employee $employee, bool $apply): int
    {
        $changed = 0;
        DB::table('leave_applications')
            ->where('employee_id', $employee->id)
            ->select('id', 'start_date', 'created_at', 'company')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($employee, $apply, &$changed) {
                foreach ($rows as $row) {
                    $anchor = $row->start_date ?: $row->created_at;
                    $company = $employee->companyAsOf($anchor);
                    if ($company !== null && $company !== $row->company) {
                        if ($apply) {
                            DB::table('leave_applications')->where('id', $row->id)->update(['company' => $company]);
                        }
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    private function reattributeTickets(Employee $employee, bool $apply): int
    {
        // Tickets link to the employee through their User account (the raiser).
        if (! $employee->user_id) {
            return 0;
        }

        $changed = 0;
        DB::table('tickets')
            ->where('user_id', $employee->user_id)
            ->select('id', 'created_at', 'company_id')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($employee, $apply, &$changed) {
                foreach ($rows as $row) {
                    $name = $employee->companyAsOf($row->created_at);
                    if ($name === null) {
                        continue;
                    }
                    // company_id is a required FK — only re-stamp when the resolved
                    // name maps to a real companies.id, never null it out.
                    $companyId = Ticket::resolveCompanyId($name);
                    if ($companyId && $companyId !== (int) $row->company_id) {
                        if ($apply) {
                            DB::table('tickets')->where('id', $row->id)->update(['company_id' => $companyId]);
                        }
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /**
     * The date a claim is tested against the timeline. Per the v1 decision this is
     * the SUBMISSION CYCLE (the claim's year/month reporting stamp), taken as the
     * first day of that month. Falls back to submitted_at, then created_at, for
     * drafts that have no cycle stamp yet.
     *
     * @param  ExpenseClaim|\stdClass  $claim  a model or a raw DB row
     */
    private function claimAnchor($claim): ?Carbon
    {
        if (! empty($claim->year) && ! empty($claim->month)) {
            return Carbon::create((int) $claim->year, (int) $claim->month, 1);
        }
        if (! empty($claim->submitted_at)) {
            return Carbon::parse($claim->submitted_at);
        }

        return ! empty($claim->created_at) ? Carbon::parse($claim->created_at) : null;
    }
}
