<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An explicit approval-date window for a ZIP export request.
 *
 * Until now the only period a request could carry was a cutoff cycle (year + month, the 21st
 * to the 20th). That is the right default for the monthly pack but it is the ONLY thing HR
 * could ask for, so an audit window, a quarter, or "everything approved since we last ran
 * this" had no way to be expressed at all.
 *
 * Nullable, and the existing `year`/`month` columns are untouched: a request carries EITHER a
 * range or a cycle, and a row created before this migration keeps answering exactly as it did.
 * Stored as DATE, not DATETIME — the operator picks calendar days, and the end day is treated
 * as inclusive when the window is applied (see ClaimZipExportService::claimsApprovedBetween),
 * so persisting a spurious 00:00:00 boundary here would invite it being read back as exclusive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_zip_exports', function (Blueprint $table) {
            $table->date('from_date')->nullable()->after('month');
            $table->date('to_date')->nullable()->after('from_date');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_zip_exports', function (Blueprint $table) {
            $table->dropColumn(['from_date', 'to_date']);
        });
    }
};
