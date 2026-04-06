<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Leave Types (company-configurable) ─────────────────────────
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('name');                     // e.g. Annual Leave, Sick Leave
            $table->string('code', 20)->unique();       // e.g. AL, SL, ML
            $table->text('description')->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Leave Entitlements (tenure-based) ──────────────────────────
        Schema::create('leave_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->string('company')->nullable();
            $table->integer('min_tenure_months')->default(0);   // e.g. 0 = from start
            $table->integer('max_tenure_months')->nullable();   // null = no upper limit
            $table->decimal('entitled_days', 5, 1);             // e.g. 14.0
            $table->decimal('carry_forward_limit', 5, 1)->default(0); // max days carried forward
            $table->timestamps();
        });

        // ── Leave Balances (per-employee per-year) ─────────────────────
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->year('year');
            $table->decimal('entitled', 5, 1)->default(0);
            $table->decimal('taken', 5, 1)->default(0);
            $table->decimal('carry_forward', 5, 1)->default(0);
            $table->decimal('adjustment', 5, 1)->default(0);   // manual HR adjustments
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        // ── Leave Applications ─────────────────────────────────────────
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_days', 5, 1);
            $table->boolean('is_half_day')->default(false);
            $table->enum('half_day_period', ['morning', 'afternoon'])->nullable();
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        // ── Public Holidays ────────────────────────────────────────────
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();   // null = applies to all companies
            $table->string('name');
            $table->date('date');
            $table->year('year');
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();

            $table->unique(['company', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('leave_applications');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_entitlements');
        Schema::dropIfExists('leave_types');
    }
};
