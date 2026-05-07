<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Nullable for backward compat with tickets created before this column existed.
            // Set on create via the new Company dropdown on the Raise Ticket form.
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('user_id')
                  ->constrained('companies')
                  ->nullOnDelete();

            $table->index('company_id');
        });

        // Backfill existing tickets from creator's employee.company.
        // For tickets where the creator's company doesn't match any registered
        // company name, leave company_id NULL — analytics will fall back to the
        // creator-employee-company subselect for those rows.
        DB::statement("
            UPDATE tickets t
            JOIN employees e ON e.user_id = t.user_id
            JOIN companies c ON c.name = e.company
            SET t.company_id = c.id
            WHERE t.company_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
