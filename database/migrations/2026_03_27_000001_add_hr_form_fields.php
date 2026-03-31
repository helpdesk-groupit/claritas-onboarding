<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. personal_details — new Section A fields + consent ─────────
        Schema::table('personal_details', function (Blueprint $table) {
            $table->boolean('is_disabled')->default(false)->after('race');
            $table->string('bank_name')->nullable()->after('bank_account_number');
            $table->string('epf_no')->nullable()->after('bank_name');
            $table->string('income_tax_no')->nullable()->after('epf_no');
            $table->string('socso_no')->nullable()->after('income_tax_no');
            $table->string('nric_file_path')->nullable()->after('socso_no');
            $table->timestamp('consent_given_at')->nullable()->after('nric_file_path');
            $table->string('consent_ip', 45)->nullable()->after('consent_given_at');
            // Temporary staging for invite-submitted education/spouse/emergency/children
            // until the employee record is created and activated
            $table->text('invite_staging_json')->nullable()->after('consent_ip');
        });

        // ── 2. employees — mirror Section A new fields ───────────────────
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_disabled')->default(false)->after('race');
            $table->string('bank_name')->nullable()->after('bank_account_number');
            $table->string('epf_no')->nullable()->after('bank_name');
            $table->string('income_tax_no')->nullable()->after('epf_no');
            $table->string('socso_no')->nullable()->after('income_tax_no');
            $table->string('nric_file_path')->nullable()->after('socso_no');
            $table->timestamp('consent_given_at')->nullable()->after('nric_file_path');
            $table->string('consent_ip', 45)->nullable()->after('consent_given_at');
        });

        // ── 3. employee_education_histories ──────────────────────────────
        Schema::create('employee_education_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('qualification');           // Full name of qualification
            $table->string('institution')->nullable(); // Name of university / college
            $table->year('year_graduated')->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable(); // Years of work experience
            $table->string('certificate_path')->nullable(); // Uploaded cert file
            $table->timestamps();
        });

        // ── 4. employee_spouse_details ───────────────────────────────────
        Schema::create('employee_spouse_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->onDelete('cascade');
            $table->string('name')->nullable();
            $table->text('address')->nullable();
            $table->string('nric_no')->nullable();
            $table->string('tel_no')->nullable();
            $table->string('occupation')->nullable();
            $table->string('income_tax_no')->nullable();
            $table->boolean('is_working')->default(false);
            $table->boolean('is_disabled')->default(false);
            $table->timestamps();
        });

        // ── 5. employee_emergency_contacts ───────────────────────────────
        Schema::create('employee_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->unsignedTinyInteger('contact_order')->default(1); // 1 or 2 — enforces 2-person requirement
            $table->string('name')->nullable();
            $table->string('tel_no')->nullable();
            $table->string('relationship')->nullable();
            $table->timestamps();
        });

        // ── 6. employee_child_registrations ──────────────────────────────
        Schema::create('employee_child_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->onDelete('cascade');
            // Each category stores count_100 (self) and count_50 (shared with spouse)
            $table->unsignedTinyInteger('cat_a_100')->default(0); // Children under 18
            $table->unsignedTinyInteger('cat_a_50')->default(0);
            $table->unsignedTinyInteger('cat_b_100')->default(0); // 18+ cert/matric
            $table->unsignedTinyInteger('cat_b_50')->default(0);
            $table->unsignedTinyInteger('cat_c_100')->default(0); // 18+ Diploma+
            $table->unsignedTinyInteger('cat_c_50')->default(0);
            $table->unsignedTinyInteger('cat_d_100')->default(0); // Disabled under 18
            $table->unsignedTinyInteger('cat_d_50')->default(0);
            $table->unsignedTinyInteger('cat_e_100')->default(0); // Disabled Diploma+
            $table->unsignedTinyInteger('cat_e_50')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_child_registrations');
        Schema::dropIfExists('employee_emergency_contacts');
        Schema::dropIfExists('employee_spouse_details');
        Schema::dropIfExists('employee_education_histories');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'is_disabled','bank_name','epf_no','income_tax_no',
                'socso_no','nric_file_path','consent_given_at','consent_ip',
            ]);
        });

        Schema::table('personal_details', function (Blueprint $table) {
            $table->dropColumn([
                'is_disabled','bank_name','epf_no','income_tax_no',
                'socso_no','nric_file_path','consent_given_at','consent_ip',
                'invite_staging_json',
            ]);
        });
    }
};
