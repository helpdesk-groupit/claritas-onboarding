<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `service_company_id` to tickets — the company whose team actually
     * handles the ticket (service provider). This is distinct from
     * `company_id`, which remains the RAISER (client) company. Routing,
     * manager visibility, and the PIC pool all key off the service company.
     *
     * Backfill rule: set service_company_id = company_id (raiser's company).
     * Historically all tickets defaulted to broad-cluster visibility, so the
     * raiser's own company is the closest analogue. Mis-routed historical
     * tickets can be corrected via the Edit feature.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('service_company_id')
                  ->nullable()
                  ->after('company_id')
                  ->constrained('companies')
                  ->nullOnDelete();

            $table->index('service_company_id');
        });

        DB::statement('UPDATE tickets SET service_company_id = company_id WHERE service_company_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['service_company_id']);
            $table->dropColumn('service_company_id');
        });
    }
};
