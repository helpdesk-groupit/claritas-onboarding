<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short human-readable note on a company-timeline stint. Set when a back-dated
 * move REWRITES history (removes earlier stints because the effective date is
 * before their start), so the profile timeline explains why an entry replaced
 * an earlier company. Null for ordinary moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_company_histories', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('employee_company_histories', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
