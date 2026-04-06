<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Employee statutory fields ──────────────────────────────────────
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'epf_category')) {
                $table->string('epf_category', 10)->nullable()->default('1')->after('socso_no');
            }
            if (!Schema::hasColumn('employees', 'is_resident')) {
                $table->boolean('is_resident')->default(true)->after('epf_category');
            }
            if (!Schema::hasColumn('employees', 'nationality')) {
                $table->string('nationality', 60)->nullable()->after('is_resident');
            }
        });

        // ── Employee salary enhancements ───────────────────────────────────
        Schema::table('employee_salaries', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_salaries', 'working_days_per_month')) {
                $table->unsignedSmallInteger('working_days_per_month')->default(26)->after('is_active');
            }
        });

        // ── Payroll configuration per company ──────────────────────────────
        if (!Schema::hasTable('payroll_configs')) {
            Schema::create('payroll_configs', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->decimal('epf_employee_rate', 5, 2)->default(11.00);
            $table->decimal('epf_employer_rate', 5, 2)->default(13.00);
            $table->decimal('epf_employee_rate_senior', 5, 2)->default(5.50); // 60+ years
            $table->decimal('epf_employer_rate_senior', 5, 2)->default(6.50); // 60+ years
            $table->decimal('socso_employee_rate', 5, 4)->default(0.50);
            $table->decimal('socso_employer_rate', 5, 4)->default(1.75);
            $table->decimal('socso_wage_ceiling', 10, 2)->default(5000.00);
            $table->decimal('eis_rate', 5, 4)->default(0.20);
            $table->decimal('eis_wage_ceiling', 10, 2)->default(5000.00);
            $table->decimal('hrdf_rate', 5, 2)->default(1.00);
            $table->boolean('hrdf_enabled')->default(true);
            $table->unsignedSmallInteger('default_working_days')->default(26);
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('lhdn_employer_no')->nullable();
            $table->string('epf_employer_no')->nullable();
            $table->string('socso_employer_no')->nullable();
            $table->string('eis_employer_no')->nullable();
            $table->timestamps();
            $table->unique('company');
        });
        }

        // ── Salary adjustment history (audit trail) ────────────────────────
        if (!Schema::hasTable('salary_adjustments')) {
            Schema::create('salary_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30); // increment, bonus, adjustment, promotion
            $table->decimal('previous_salary', 12, 2);
            $table->decimal('new_salary', 12, 2);
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
        }

        // ── Pay run enhancements (skip if columns already exist) ───────────
        // Original migration already includes total_employer_cost, notes, created_by

        // ── Payslip column fixes (rename for consistency) ──────────────────
        // Views reference pcb, unpaid_leave, overtime — add accessors instead
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustments');
        Schema::dropIfExists('payroll_configs');
        if (Schema::hasColumn('employee_salaries', 'working_days_per_month')) {
            Schema::table('employee_salaries', function (Blueprint $table) {
                $table->dropColumn('working_days_per_month');
            });
        }
        Schema::table('employees', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('employees', 'epf_category')) $cols[] = 'epf_category';
            if (Schema::hasColumn('employees', 'is_resident')) $cols[] = 'is_resident';
            if (Schema::hasColumn('employees', 'nationality')) $cols[] = 'nationality';
            if ($cols) $table->dropColumn($cols);
        });
    }
};
