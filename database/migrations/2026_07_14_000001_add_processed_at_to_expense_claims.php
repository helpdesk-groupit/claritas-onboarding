<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "processed_at" marks an expense claim as processed for the month-end approved-PDF export.
 * It is stamped when HR approves a claim (the claim module's terminal approval state — there
 * is no "paid" transition). The approved-PDF ZIP filters on this + the per-company submission
 * cutoff cycle, so it is the authoritative "this claim is done and exportable" signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('hr_approved_at');
        });

        // Backfill: existing HR-approved claims are already processed. Stamp the approval time
        // (fall back to updated_at if somehow null) so historical claims don't vanish from the
        // approved-PDF ZIP the moment the export starts filtering on processed_at.
        DB::table('expense_claims')
            ->where('status', 'hr_approved')
            ->whereNull('processed_at')
            ->update(['processed_at' => DB::raw('COALESCE(hr_approved_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn('processed_at');
        });
    }
};
