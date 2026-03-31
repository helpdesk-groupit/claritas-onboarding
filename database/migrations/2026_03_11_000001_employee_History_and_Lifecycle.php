<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe migration — every change is guarded with hasTable() / hasColumn().
 * Running this on a DB that already has these columns/tables will simply skip
 * those steps instead of throwing a "column already exists" error.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── employee_histories — permanent exit archive ────────────────────
        if (!Schema::hasTable('employee_histories')) {
            Schema::create('employee_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->unsignedBigInteger('onboarding_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                // Personal snapshot
                $table->string('full_name')->nullable();
                $table->string('official_document_id')->nullable();
                $table->date('date_of_birth')->nullable();
                $table->enum('sex', ['male', 'female'])->nullable();
                $table->enum('marital_status', ['single','married','divorced','widowed'])->nullable();
                $table->string('religion', 100)->nullable();
                $table->string('race', 100)->nullable();
                $table->text('residential_address')->nullable();
                $table->string('personal_contact_number', 20)->nullable();
                $table->string('personal_email')->nullable();
                $table->string('bank_account_number', 50)->nullable();
                // Work snapshot
                $table->string('designation')->nullable();
                $table->string('department')->nullable();
                $table->string('company')->nullable();
                $table->string('office_location')->nullable();
                $table->string('reporting_manager')->nullable();
                $table->string('company_email')->nullable();
                $table->date('start_date')->nullable();
                $table->date('exit_date')->nullable();
                $table->string('employment_type')->nullable();
                $table->string('work_role')->nullable();
                // Exit metadata
                $table->string('exit_reason')->nullable();
                $table->text('exit_remarks')->nullable();
                $table->date('archived_at')->nullable();
                $table->timestamps();
            });
        }

        // ── offboardings: is_completed flag (safe add) ────────────────────
        if (!Schema::hasColumn('offboardings', 'is_completed')) {
            Schema::table('offboardings', function (Blueprint $table) {
                $table->boolean('is_completed')->default(false)->after('remarks');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_histories');

        if (Schema::hasColumn('offboardings', 'is_completed')) {
            Schema::table('offboardings', function (Blueprint $table) {
                $table->dropColumn('is_completed');
            });
        }
    }
};