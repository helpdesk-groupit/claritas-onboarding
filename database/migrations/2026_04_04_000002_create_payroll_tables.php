<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Payroll Items (configurable allowance/deduction types) ──────
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('name');                     // e.g. Transport Allowance, Parking Deduction
            $table->string('code', 30)->unique();       // e.g. TRANSPORT_ALW, PARKING_DED
            $table->enum('type', ['earning', 'deduction']);
            $table->boolean('is_statutory')->default(false);  // EPF, SOCSO, EIS, PCB
            $table->boolean('is_recurring')->default(false);  // recurring vs ad-hoc
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Employee Salaries (salary structure per employee) ───────────
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('basic_salary', 12, 2);
            $table->string('payment_method')->default('bank_transfer');  // bank_transfer, cheque, cash
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Employee Salary Items (recurring allowances/deductions) ─────
        Schema::create('employee_salary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_salary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_item_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        // ── Pay Runs (monthly payroll batches) ──────────────────────────
        Schema::create('pay_runs', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('reference')->unique();       // e.g. PR-2026-04-001
            $table->string('title');                     // e.g. April 2026 Payroll
            $table->year('year');
            $table->unsignedTinyInteger('month');        // 1-12
            $table->date('pay_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'processing', 'approved', 'paid', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->decimal('total_employer_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['company', 'year', 'month']);
        });

        // ── Payslips (individual employee record per pay run) ───────────
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('payslip_number')->unique();

            // Summary amounts
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('total_earnings', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);

            // Malaysian statutory (employee portion)
            $table->decimal('epf_employee', 10, 2)->default(0);
            $table->decimal('socso_employee', 10, 2)->default(0);
            $table->decimal('eis_employee', 10, 2)->default(0);
            $table->decimal('pcb_amount', 10, 2)->default(0);          // income tax

            // Malaysian statutory (employer portion)
            $table->decimal('epf_employer', 10, 2)->default(0);
            $table->decimal('socso_employer', 10, 2)->default(0);
            $table->decimal('eis_employer', 10, 2)->default(0);
            $table->decimal('hrdf_amount', 10, 2)->default(0);

            // Leave deductions
            $table->decimal('unpaid_leave_days', 5, 1)->default(0);
            $table->decimal('unpaid_leave_amount', 10, 2)->default(0);

            // Overtime
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->decimal('overtime_amount', 10, 2)->default(0);

            $table->enum('status', ['draft', 'finalized', 'paid'])->default('draft');
            $table->timestamps();

            $table->unique(['pay_run_id', 'employee_id']);
        });

        // ── Payslip Items (line-item earnings/deductions per payslip) ───
        Schema::create('payslip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->enum('type', ['earning', 'deduction']);
            $table->decimal('amount', 12, 2);
            $table->boolean('is_statutory')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_items');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('pay_runs');
        Schema::dropIfExists('employee_salary_items');
        Schema::dropIfExists('employee_salaries');
        Schema::dropIfExists('payroll_items');
    }
};
