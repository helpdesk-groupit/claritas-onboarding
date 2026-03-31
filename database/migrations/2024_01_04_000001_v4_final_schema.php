<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * V4 Final Schema Migration
 * ─────────────────────────
 * Replaces the two previous migrations (04 + 05) with a single clean migration.
 *
 * Changes:
 * 1. Add AARF acknowledgement tracking columns to aarfs table
 *    (it_manager_acknowledged, it_manager_acknowledged_at, it_manager_user_id,
 *     it_manager_remarks, it_notes)
 *
 * 2. Add full profile + work columns directly to the employees table
 *    (profile data belongs on employees, not users)
 *
 * 3. Expand work_details.role enum — add superadmin, system_admin
 *
 * 4. Expand users.role enum — add superadmin, system_admin
 *
 * NOTE: We deliberately do NOT add profile columns to the users table.
 * Profile data (personal info, work info, AARF) lives on employees.
 * users table stays lean: id, name, work_email, password, role, is_active.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. AARF: Add IT Manager acknowledgement tracking ───────────────
        Schema::table('aarfs', function (Blueprint $table) {
            $table->boolean('it_manager_acknowledged')->default(false)->after('acknowledged');
            $table->timestamp('it_manager_acknowledged_at')->nullable()->after('it_manager_acknowledged');
            $table->unsignedBigInteger('it_manager_user_id')->nullable()->after('it_manager_acknowledged_at');
            $table->text('it_manager_remarks')->nullable()->after('it_manager_user_id');
            $table->text('it_notes')->nullable()->after('it_manager_remarks');
        });

        // ── 2. EMPLOYEES: Add profile + work + AARF columns ───────────────
        Schema::table('employees', function (Blueprint $table) {
            // Personal info — copied from personal_details when start_date arrives
            $table->string('full_name')->nullable()->after('active_until');
            $table->string('official_document_id')->nullable()->after('full_name');
            $table->date('date_of_birth')->nullable()->after('official_document_id');
            $table->enum('sex', ['male', 'female'])->nullable()->after('date_of_birth');
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])->nullable()->after('sex');
            $table->string('religion', 100)->nullable()->after('marital_status');
            $table->string('race', 100)->nullable()->after('religion');
            $table->text('residential_address')->nullable()->after('race');
            $table->string('personal_contact_number', 20)->nullable()->after('residential_address');
            $table->string('personal_email')->nullable()->after('personal_contact_number');
            $table->string('bank_account_number', 50)->nullable()->after('personal_email');
            // Work info — copied from work_details when start_date arrives
            $table->string('designation')->nullable()->after('bank_account_number');
            $table->string('department')->nullable()->after('designation');
            $table->string('company')->nullable()->after('department');
            $table->string('office_location')->nullable()->after('company');
            $table->string('reporting_manager')->nullable()->after('office_location');
            $table->string('company_email')->nullable()->after('reporting_manager');
            $table->date('start_date')->nullable()->after('company_email');
            $table->date('exit_date')->nullable()->after('start_date');
            $table->enum('employment_type', ['permanent', 'intern', 'contract'])->nullable()->after('exit_date');
            $table->string('work_role')->nullable()->after('employment_type');
            // AARF — stored here after upload or generation
            $table->string('aarf_file_path')->nullable()->after('work_role');
            // Google Workspace ID
            $table->string('google_id')->nullable()->after('aarf_file_path');
        });

        // ── 3. WORK_DETAILS: Expand role enum ─────────────────────────────
        DB::statement("ALTER TABLE work_details MODIFY COLUMN role ENUM(
            'manager','senior_executive','executive_associate','director_hod',
            'hr_manager','hr_executive','hr_intern',
            'it_manager','it_executive','it_intern',
            'superadmin','system_admin',
            'others'
        ) NULL");

        // ── 4. USERS: Expand role enum ─────────────────────────────────────
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'hr_manager','hr_executive','hr_intern',
            'it_manager','it_executive','it_intern',
            'superadmin','system_admin',
            'employee'
        ) DEFAULT 'employee'");
    }

    public function down(): void
    {
        // Remove AARF columns
        Schema::table('aarfs', function (Blueprint $table) {
            $table->dropColumn([
                'it_manager_acknowledged', 'it_manager_acknowledged_at',
                'it_manager_user_id', 'it_manager_remarks', 'it_notes',
            ]);
        });

        // Remove employee profile columns
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'full_name', 'official_document_id', 'date_of_birth', 'sex',
                'marital_status', 'religion', 'race', 'residential_address',
                'personal_contact_number', 'personal_email', 'bank_account_number',
                'designation', 'department', 'company', 'office_location',
                'reporting_manager', 'company_email', 'start_date', 'exit_date',
                'employment_type', 'work_role', 'aarf_file_path', 'google_id',
            ]);
        });

        // Revert role enums
        DB::statement("ALTER TABLE work_details MODIFY COLUMN role ENUM(
            'manager','senior_executive','executive_associate','director_hod',
            'hr_manager','hr_executive','hr_intern',
            'it_manager','it_executive','it_intern','others'
        ) NULL");

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'hr_manager','hr_executive','hr_intern',
            'it_manager','it_executive','it_intern','employee'
        ) DEFAULT 'employee'");
    }
};