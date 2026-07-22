<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The claim submission deadline is now ALWAYS the policy day (the 20th) and no longer rolls
 * back off weekends / public holidays (see ClaimRulesService::submissionDeadline). Correct the
 * stored policy rule text so it no longer promises a roll-back.
 *
 * Surgical, idempotent REPLACE: it swaps only that one sentence and leaves every other rule
 * line (and any per-company customisations) untouched. Rows without the sentence are skipped.
 */
return new class extends Migration
{
    private string $old = 'If the 20th falls on a weekend or public holiday, the deadline moves to the preceding working day.';

    private string $new = 'The deadline is always the 20th, including weekends and public holidays.';

    public function up(): void
    {
        DB::statement(
            'UPDATE expense_claim_policies SET general_rules = REPLACE(general_rules, ?, ?) WHERE general_rules LIKE ?',
            [$this->old, $this->new, '%'.$this->old.'%']
        );
    }

    public function down(): void
    {
        DB::statement(
            'UPDATE expense_claim_policies SET general_rules = REPLACE(general_rules, ?, ?) WHERE general_rules LIKE ?',
            [$this->new, $this->old, '%'.$this->new.'%']
        );
    }
};
