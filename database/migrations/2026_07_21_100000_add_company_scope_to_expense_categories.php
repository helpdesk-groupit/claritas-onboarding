<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a company-scoped category (non-null `company` token) resolves eligibility:
 *   - 'current' (default) — only employees whose CURRENT company matches the token.
 *   - 'ever'              — employees who are OR were ever at a matching company, read from
 *                           the EmployeeCompanyHistory timeline. For benefits that follow the
 *                           person after a company move — e.g. the Claritas Optical & Dental
 *                           benefit kept by ex-Claritas staff now at another entity (Enlinea).
 * Null-company categories (all entities) ignore this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('company_scope', 20)->default('current')->after('company');
        });

        // The Claritas Optical & Dental benefit follows the person: staff who moved off
        // Claritas (e.g. to Enlinea) keep it. Every other category stays 'current'.
        DB::table('expense_categories')
            ->where('code', 'CLARITAS_OPTICAL_DENTAL')
            ->update(['company_scope' => 'ever']);
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('company_scope');
        });
    }
};
