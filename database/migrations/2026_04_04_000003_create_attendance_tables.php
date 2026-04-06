<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Work Schedules (templates per company/department) ───────────
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('name');                         // e.g. Standard Office Hours
            $table->time('start_time');                     // e.g. 09:00
            $table->time('end_time');                       // e.g. 18:00
            $table->time('break_start')->nullable();        // e.g. 12:00
            $table->time('break_end')->nullable();          // e.g. 13:00
            $table->decimal('work_hours_per_day', 4, 2);    // e.g. 8.00
            $table->json('working_days')->nullable();       // e.g. [1,2,3,4,5] (Mon-Fri)
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Attendance Records (clock in/out entries) ───────────────────
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('clock_in')->nullable();
            $table->timestamp('clock_out')->nullable();
            $table->decimal('work_hours', 5, 2)->nullable();
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->decimal('break_duration', 5, 2)->default(0);   // hours
            $table->enum('status', ['present', 'absent', 'late', 'half_day', 'on_leave', 'holiday'])->default('present');
            $table->string('clock_in_ip', 45)->nullable();
            $table->string('clock_out_ip', 45)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('work_schedule_id')->nullable();
            $table->timestamps();

            $table->foreign('work_schedule_id')->references('id')->on('work_schedules')->nullOnDelete();
            $table->unique(['employee_id', 'date']);
        });

        // ── Overtime Requests (approval workflow) ───────────────────────
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('hours', 5, 2);
            $table->decimal('multiplier', 3, 1)->default(1.5);  // OT rate (1.5x, 2.0x, etc.)
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('work_schedules');
    }
};
