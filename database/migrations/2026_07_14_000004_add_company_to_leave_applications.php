<?php

use App\Models\LeaveApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the company on each leave application so it stays attributed to the company the
 * employee was under when they applied — even after the employee is later moved. Reads fall
 * back to the owner's current company when null. Backfilled from the company timeline using
 * each application's creation date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->string('company')->nullable()->after('employee_id');
            $table->index('company');
        });

        LeaveApplication::whereNull('company')
            ->with('employee')
            ->chunkById(200, function ($apps) {
                foreach ($apps as $app) {
                    $company = $app->employee?->companyAsOf($app->created_at) ?? $app->employee?->company;
                    if ($company) {
                        $app->newQuery()->whereKey($app->getKey())->update(['company' => $company]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropIndex(['company']);
            $table->dropColumn('company');
        });
    }
};
