<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ea_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('employer_name')->nullable();
            $table->string('employer_address')->nullable();
            $table->string('employer_tax_no')->nullable();          // LHDN E-number

            // Section A — Employee particulars (snapshot at generation time)
            $table->string('employee_name');
            $table->string('employee_tax_no')->nullable();          // Income tax number
            $table->string('employee_ic_no')->nullable();           // NRIC / Passport
            $table->string('employee_epf_no')->nullable();
            $table->string('employee_socso_no')->nullable();
            $table->string('designation')->nullable();
            $table->date('employment_start_date')->nullable();
            $table->date('employment_end_date')->nullable();

            // Section B — Remuneration details
            $table->decimal('gross_salary', 14, 2)->default(0);             // B1: Salary, wages, etc.
            $table->decimal('overtime_pay', 14, 2)->default(0);             // B1: Overtime
            $table->decimal('commission', 14, 2)->default(0);               // B1: Commission/bonus
            $table->decimal('allowances', 14, 2)->default(0);               // B1: Other allowances
            $table->decimal('gross_remuneration', 14, 2)->default(0);       // B1: Total
            $table->decimal('benefits_in_kind', 14, 2)->default(0);         // B2: BIK
            $table->decimal('value_of_living_accommodation', 14, 2)->default(0); // B3: VOLA
            $table->decimal('pension_or_annuity', 14, 2)->default(0);       // B4
            $table->decimal('gratuity', 14, 2)->default(0);                 // B5: Gratuity/compensation
            $table->decimal('total_remuneration', 14, 2)->default(0);       // B total

            // Section C — Deductions
            $table->decimal('epf_employee', 14, 2)->default(0);             // C1: EPF (KWSP)
            $table->decimal('socso_employee', 14, 2)->default(0);           // C2: SOCSO (PERKESO)
            $table->decimal('eis_employee', 14, 2)->default(0);             // C3: EIS (SIP)
            $table->decimal('pcb_paid', 14, 2)->default(0);                 // C4: PCB / MTD / CP38
            $table->decimal('zakat', 14, 2)->default(0);                    // C5: Zakat
            $table->decimal('total_deductions', 14, 2)->default(0);         // C total

            // Employer contributions (info section)
            $table->decimal('epf_employer', 14, 2)->default(0);
            $table->decimal('socso_employer', 14, 2)->default(0);
            $table->decimal('eis_employer', 14, 2)->default(0);
            $table->decimal('hrdf_employer', 14, 2)->default(0);

            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ea_forms');
    }
};
