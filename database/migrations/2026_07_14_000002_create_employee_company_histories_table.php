<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee company timeline: one row per "stint" at a company. A stint is open
 * (ended_on = null) while the employee is currently at that company; when a superadmin
 * changes the company, the open stint is closed (ended_on = change date) and a new open
 * stint begins. Returning to a previous company just adds a fresh stint — old ones are kept.
 * Only company changes create stints; office_location is stored as context for the period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_company_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('company');
            $table->string('office_location')->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'started_on']);
            $table->index(['employee_id', 'ended_on']);
        });

        // Backfill: seed one open stint per existing employee so the timeline shows their real
        // tenure immediately. Start from the employee's start_date (fall back to created_at).
        DB::table('employees')->orderBy('id')->chunk(200, function ($emps) {
            $rows = [];
            foreach ($emps as $e) {
                if (empty($e->company)) {
                    continue;
                }
                $started = $e->start_date
                    ?: ($e->created_at ? substr((string) $e->created_at, 0, 10) : now()->toDateString());
                $rows[] = [
                    'employee_id' => $e->id,
                    'company' => $e->company,
                    'office_location' => $e->office_location,
                    'started_on' => $started,
                    'ended_on' => null,
                    'changed_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($rows) {
                DB::table('employee_company_histories')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_company_histories');
    }
};
