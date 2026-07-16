<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A future-dated company move that has NOT taken effect yet. Unlike a past/present change
 * (which mutates the company timeline immediately via Employee::changeCompanyEffective),
 * a scheduled change is stored here as intent and applied on its effective_date by the
 * `company:apply-scheduled` command — mirroring how employees:activate flips employees to
 * active on their start date. Applying it then runs the normal timeline + re-attribution path.
 *
 * status: pending → applied (on the date) | cancelled (superadmin cancels) | superseded
 * (a newer schedule replaced it, or the employee was already moved/offboarded before it ran).
 * At most ONE pending row per employee — scheduling a new one supersedes any prior pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_company_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('company');
            $table->string('office_location')->nullable();
            $table->date('effective_date');
            $table->enum('status', ['pending', 'applied', 'cancelled', 'superseded'])->default('pending');
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            // Cron scan: pending rows due on/before today.
            $table->index(['status', 'effective_date']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_company_changes');
    }
};
