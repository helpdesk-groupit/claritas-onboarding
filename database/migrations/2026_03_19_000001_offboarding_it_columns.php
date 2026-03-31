<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Make exit_date nullable (was NOT NULL, blocking inserts for resigned-without-date)
        Schema::table('offboardings', function (Blueprint $table) {
            $table->date('exit_date')->nullable()->change();
            // IT task tracking columns for offboarding
            $table->enum('asset_cleaning_status', ['pending','in_progress','done'])
                  ->default('pending')->after('aarf_status');
            $table->enum('deactivation_status', ['pending','in_progress','done'])
                  ->default('pending')->after('asset_cleaning_status');
        });

        // 2. Add offboarding_id to it_tasks so tasks can be linked to offboarding records
        Schema::table('it_tasks', function (Blueprint $table) {
            $table->foreignId('offboarding_id')->nullable()
                  ->after('onboarding_id')
                  ->constrained('offboardings')->onDelete('cascade');
        });

        // 3. Make onboarding_id nullable in it_tasks (it was constrained not null before)
        Schema::table('it_tasks', function (Blueprint $table) {
            $table->foreignId('onboarding_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('it_tasks', function (Blueprint $table) {
            $table->dropForeign(['offboarding_id']);
            $table->dropColumn('offboarding_id');
        });

        Schema::table('offboardings', function (Blueprint $table) {
            $table->dropColumn(['asset_cleaning_status', 'deactivation_status']);
            $table->date('exit_date')->nullable(false)->change();
        });
    }
};