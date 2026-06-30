<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-employee category restriction. NULL = no restriction (every existing category keeps
     * its company/role scoping unchanged). When set to a JSON array of employee IDs, only those
     * employees may see/file the category — e.g. a personal allowance granted to one person.
     */
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->json('applies_to_employee_ids')->nullable()->after('applies_to_role');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('applies_to_employee_ids');
        });
    }
};
