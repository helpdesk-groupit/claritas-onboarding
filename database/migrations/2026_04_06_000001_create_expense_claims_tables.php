<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Expense Categories ───────────────────────────────────────────
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->decimal('monthly_limit', 10, 2)->nullable();
            $table->boolean('requires_receipt')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('keywords')->nullable();
            $table->timestamps();
        });

        // ── Expense Claims (monthly grouping per employee) ───────────────
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('claim_number')->unique();
            $table->string('title');
            $table->year('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('total_gst', 12, 2)->default(0);
            $table->decimal('total_with_gst', 12, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->enum('status', [
                'draft',
                'submitted',
                'manager_approved',
                'manager_rejected',
                'hr_approved',
                'hr_rejected',
                'paid',
                'cancelled',
            ])->default('draft');
            $table->date('submitted_at')->nullable();
            $table->date('submission_deadline')->nullable();

            // Manager approval
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('manager_approved_by')->nullable();
            $table->timestamp('manager_approved_at')->nullable();
            $table->text('manager_remarks')->nullable();

            // HR approval
            $table->unsignedBigInteger('hr_approved_by')->nullable();
            $table->timestamp('hr_approved_at')->nullable();
            $table->text('hr_remarks')->nullable();

            // Payroll linkage
            $table->unsignedBigInteger('payslip_id')->nullable();
            $table->unsignedBigInteger('pay_run_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('manager_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('manager_approved_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('hr_approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('payslip_id')->references('id')->on('payslips')->nullOnDelete();
            $table->foreign('pay_run_id')->references('id')->on('pay_runs')->nullOnDelete();
            $table->unique(['employee_id', 'year', 'month']);
        });

        // ── Expense Claim Items (individual line items) ──────────────────
        Schema::create('expense_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained();
            $table->date('expense_date');
            $table->string('description');
            $table->string('project_client')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('gst_amount', 10, 2)->default(0);
            $table->decimal('total_with_gst', 10, 2);
            $table->string('receipt_path')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        // ── Expense Claim Policies (company-level settings) ──────────────
        Schema::create('expense_claim_policies', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->unsignedTinyInteger('submission_deadline_day')->default(20);
            $table->boolean('require_manager_approval')->default(true);
            $table->boolean('require_hr_approval')->default(true);
            $table->decimal('auto_approve_below', 10, 2)->nullable();
            $table->unsignedTinyInteger('reminder_days_before')->default(3);
            $table->boolean('gst_enabled')->default(true);
            $table->decimal('gst_rate', 5, 2)->default(8.00);
            $table->text('general_rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_policies');
        Schema::dropIfExists('expense_claim_items');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('expense_categories');
    }
};
