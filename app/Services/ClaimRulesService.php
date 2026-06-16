<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaimItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Central rules engine for the expense-claim module: which categories an
 * employee may use, how computed (per-km / per-day / per-hour) amounts are
 * derived, and period-aware (monthly/annual) + role-based spending caps.
 *
 * Pure business logic, no HTTP — so it's directly unit-testable.
 */
class ClaimRulesService
{
    /** Categories this employee may file under (entity + role scoped). */
    public static function categoriesFor(Employee $employee): Collection
    {
        $company = $employee->company;

        return ExpenseCategory::query()
            ->where('is_active', true)
            ->where(function ($q) use ($company) {
                $q->whereNull('company');
                if ($company) {
                    $q->orWhere('company', $company);
                }
            })
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (ExpenseCategory $c) => self::roleAllows($employee, $c->applies_to_role))
            ->values();
    }

    /** Is the employee an intern or still on probation? */
    public static function isInternOrProbationer(Employee $employee): bool
    {
        $type = strtolower((string) $employee->employment_type);
        if (in_array($type, array_map('strtolower', config('claims.intern_employment_types', ['intern'])), true)) {
            return true;
        }

        if (strtolower((string) $employee->employment_status) === 'probation') {
            return true;
        }

        // A confirmation date set in the future means probation not yet passed.
        if (! empty($employee->confirmation_date)) {
            return Carbon::parse($employee->confirmation_date)->isFuture();
        }

        return false;
    }

    /** Does an employee satisfy a category's applies_to_role restriction? */
    public static function roleAllows(Employee $employee, ?string $appliesToRole): bool
    {
        if (empty($appliesToRole)) {
            return true;
        }

        return match ($appliesToRole) {
            'intern' => self::isInternOrProbationer($employee),
            default => true,
        };
    }

    // ── Computed amounts ─────────────────────────────────────────────────────

    /**
     * Compute the line amount for a non-receipt category from its quantity.
     * Returns null for receipt-based categories (caller keeps the entered amount)
     * or when the required quantity is missing.
     */
    public static function computeAmount(ExpenseCategory $category, array $input): ?float
    {
        $qty = isset($input['quantity']) && $input['quantity'] !== '' ? (float) $input['quantity'] : null;

        return match ($category->rate_type) {
            'per_km' => $qty === null ? null : round($qty * self::mileageRate($input['vehicle'] ?? 'car'), 2),
            'per_day' => $qty === null ? null : round($qty * (float) ($category->rate_amount ?? 0), 2),
            'per_hour' => $qty === null ? null : self::otBandAmount($qty),
            default => null,
        };
    }

    /** RM per km for the given vehicle (falls back to the car rate). */
    public static function mileageRate(string $vehicle): float
    {
        $rates = config('claims.mileage.rates', ['car' => 0.70, 'motorcycle' => 0.35]);

        return (float) ($rates[$vehicle] ?? $rates['car']);
    }

    /** OT payout for the hours worked — highest band whose threshold is met. */
    public static function otBandAmount(float $hours): float
    {
        $bands = config('claims.ot_bands', [4 => 50, 8 => 100]);
        krsort($bands); // highest threshold first
        foreach ($bands as $threshold => $amount) {
            if ($hours >= $threshold) {
                return (float) $amount;
            }
        }

        return 0.0;
    }

    /** Unit label for a category's rate type (for display / storage). */
    public static function unitFor(ExpenseCategory $category): ?string
    {
        return match ($category->rate_type) {
            'per_km' => 'km',
            'per_day' => 'day',
            'per_hour' => 'hour',
            default => null,
        };
    }

    // ── Spending caps ────────────────────────────────────────────────────────

    /**
     * Effective cap for this employee+category, or null if uncapped.
     *
     * @return array{amount: float, period: string}|null
     */
    public static function effectiveLimit(ExpenseCategory $category, Employee $employee): ?array
    {
        // Interns/probationers have a medical cap even though Medical Fees is otherwise uncapped.
        $medicalGl = config('claims.medical_gl_code', '932-000');
        if (($category->code === 'MEDICAL' || $category->gl_code === $medicalGl) && self::isInternOrProbationer($employee)) {
            return ['amount' => (float) config('claims.intern_medical_cap', 100), 'period' => 'monthly'];
        }

        if ($category->monthly_limit !== null) {
            return [
                'amount' => (float) $category->monthly_limit,
                'period' => $category->limit_period ?: 'monthly',
            ];
        }

        return null;
    }

    /** Amount already used by this employee for the category in the cap's period. */
    public static function usedInPeriod(Employee $employee, ExpenseCategory $category, Carbon $date, string $period): float
    {
        $query = ExpenseClaimItem::where('expense_category_id', $category->id)
            ->whereHas('claim', function ($c) use ($employee) {
                $c->where('employee_id', $employee->id)
                    ->whereNotIn('status', ['manager_rejected', 'hr_rejected', 'cancelled']);
            })
            ->whereYear('expense_date', $date->year);

        if ($period !== 'annual') {
            $query->whereMonth('expense_date', $date->month);
        }

        return (float) $query->sum('amount');
    }

    /**
     * Check whether adding $amount keeps the employee within the category cap.
     * Returns null when OK, or a human-readable error string when it would exceed.
     */
    public static function capError(Employee $employee, ExpenseCategory $category, float $amount, Carbon $date): ?string
    {
        $limit = self::effectiveLimit($category, $employee);
        if ($limit === null) {
            return null;
        }

        $used = self::usedInPeriod($employee, $category, $date, $limit['period']);
        if (($used + $amount) > $limit['amount'] + 0.001) {
            $periodLabel = $limit['period'] === 'annual' ? 'annual' : 'monthly';
            $remaining = max(0, $limit['amount'] - $used);

            return sprintf(
                'Exceeds the %s limit of RM %s for %s (RM %s already used; RM %s remaining).',
                $periodLabel,
                number_format($limit['amount'], 2),
                $category->name,
                number_format($used, 2),
                number_format($remaining, 2)
            );
        }

        return null;
    }

    // ── Submission deadline (working-day aware) ──────────────────────────────

    /** Roll a date back to the preceding working day (skips weekends + holidays). */
    public static function precedingWorkingDay(Carbon $date): Carbon
    {
        $holidays = config('claims.public_holidays', []);
        $d = $date->copy()->startOfDay();
        while ($d->isWeekend() || in_array($d->toDateString(), $holidays, true)) {
            $d->subDay();
        }

        return $d;
    }

    /**
     * Effective submission deadline for a month: the policy day (e.g. the 20th),
     * pulled back to the preceding working day when it lands on a weekend/holiday.
     */
    public static function submissionDeadline(int $deadlineDay, ?Carbon $monthRef = null): Carbon
    {
        $monthRef = $monthRef ? $monthRef->copy() : now();
        $day = min(max(1, $deadlineDay), $monthRef->daysInMonth);

        return self::precedingWorkingDay($monthRef->copy()->setDay($day));
    }
}
