<?php

use App\Models\ExpenseClaim;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the company on each expense claim so a claim stays attributed to the company the
 * employee was under when they SUBMITTED it — even after the employee is later moved to another
 * company. Reads fall back to the owner's current company when this is null (drafts / legacy).
 * Backfilled from the company timeline (employee_company_histories) using each claim's
 * submission date, so historical multi-company claims are labelled correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->string('company')->nullable()->after('employee_id');
            $table->index('company');
        });

        // Backfill submitted claims (those with a submission date) from the timeline. Drafts stay
        // null — they get stamped when they are submitted.
        ExpenseClaim::whereNull('company')
            ->whereNotNull('submitted_at')
            ->with('employee')
            ->chunkById(200, function ($claims) {
                foreach ($claims as $claim) {
                    $company = $claim->employee?->companyAsOf($claim->submitted_at) ?? $claim->employee?->company;
                    if ($company) {
                        $claim->newQuery()->whereKey($claim->getKey())->update(['company' => $company]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropIndex(['company']);
            $table->dropColumn('company');
        });
    }
};
