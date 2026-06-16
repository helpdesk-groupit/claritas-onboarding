<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the SQL Account GL account code (e.g. "905-000") on each claim
 * category, so categories mirror Finance's chart of accounts and a future
 * accounting/SQL Account export can map each claim line to the right ledger.
 * Multiple claim categories may share a gl_code (e.g. mileage and overtime
 * both posting to Transportation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('gl_code', 20)->nullable()->after('code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('gl_code');
        });
    }
};
