<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users table (for login)
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('work_email')->unique();
            $table->string('password');
            $table->enum('role', ['hr_manager', 'hr_executive', 'hr_intern', 'employee', 'it_admin'])->default('employee');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        // Main onboarding table (parent)
        Schema::create('onboardings', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending'); // pending, active, offboarded
            $table->timestamps();
        });

        // Personal details (Section A + D)
        Schema::create('personal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained('onboardings')->onDelete('cascade');
            $table->string('full_name');
            $table->string('official_document_id'); // NRIC or Passport
            $table->date('date_of_birth');
            $table->enum('sex', ['male', 'female']);
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed']);
            $table->string('religion');
            $table->string('race');
            $table->text('residential_address');
            $table->string('personal_contact_number');
            $table->string('personal_email');
            $table->string('bank_account_number');
            $table->enum('role', ['manager', 'senior_executive', 'executive_associate', 'director_hod', 'it_admin', 'others'])->nullable();
            $table->timestamps();
        });

        // Work details (Section B)
        Schema::create('work_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained('onboardings')->onDelete('cascade');
            $table->enum('employee_status', ['active', 'resigned'])->default('active');
            $table->enum('staff_status', ['existing', 'new'])->default('new');
            $table->enum('employment_type', ['permanent', 'intern', 'contract']);
            $table->string('designation');
            $table->string('company');
            $table->string('office_location');
            $table->string('reporting_manager');
            $table->date('start_date');
            $table->date('exit_date')->nullable();
            $table->string('company_email')->nullable();
            $table->string('google_id')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });

        // Asset provisioning (Section C)
        Schema::create('asset_provisionings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained('onboardings')->onDelete('cascade');
            $table->boolean('laptop_provision')->default(false);
            $table->boolean('monitor_set')->default(false);
            $table->boolean('converter')->default(false);
            $table->boolean('company_phone')->default(false);
            $table->boolean('sim_card')->default(false);
            $table->boolean('access_card_request')->default(false);
            $table->string('office_keys')->nullable();
            $table->text('others')->nullable();
            $table->timestamps();
        });

        // Asset inventory
        Schema::create('asset_inventories', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag')->unique();
            $table->string('asset_name');
            $table->enum('asset_type', ['laptop', 'monitor', 'converter', 'phone', 'sim_card', 'access_card', 'other']);
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->enum('status', ['available', 'unavailable'])->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Asset assignments
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained('onboardings')->onDelete('cascade');
            $table->foreignId('asset_inventory_id')->constrained('asset_inventories');
            $table->date('assigned_date');
            $table->date('returned_date')->nullable();
            $table->enum('status', ['assigned', 'returned'])->default('assigned');
            $table->timestamps();
        });

        // AARF table
        Schema::create('aarfs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained('onboardings')->onDelete('cascade');
            $table->string('aarf_reference')->unique();
            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_token')->unique()->nullable();
            $table->timestamps();
        });

        // Employees (active employees)
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->nullable()->constrained('onboardings')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->date('active_from');
            $table->date('active_until')->nullable();
            $table->timestamps();
        });

        // Offboarding
        Schema::create('offboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained('onboardings')->onDelete('cascade');
            $table->date('exit_date');
            $table->string('reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        // Password reset tokens
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboardings');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('aarfs');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('asset_inventories');
        Schema::dropIfExists('asset_provisionings');
        Schema::dropIfExists('work_details');
        Schema::dropIfExists('personal_details');
        Schema::dropIfExists('onboardings');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};