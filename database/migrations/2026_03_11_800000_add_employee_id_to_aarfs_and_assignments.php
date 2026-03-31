<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // aarfs: make onboarding_id nullable, add employee_id as alternative
        Schema::table('aarfs', function (Blueprint $table) {
            $table->foreignId('onboarding_id')->nullable()->change();
            if (!Schema::hasColumn('aarfs', 'employee_id')) {
                $table->foreignId('employee_id')
                      ->nullable()
                      ->after('onboarding_id')
                      ->constrained('employees')
                      ->onDelete('cascade');
            }
            // Extra fields for IT notes / manager sign-off used in import context
            if (!Schema::hasColumn('aarfs', 'it_notes')) {
                $table->text('it_notes')->nullable()->after('asset_changes');
            }
        });

        // asset_assignments: make onboarding_id nullable, add employee_id
        Schema::table('asset_assignments', function (Blueprint $table) {
            $table->foreignId('onboarding_id')->nullable()->change();
            if (!Schema::hasColumn('asset_assignments', 'employee_id')) {
                $table->foreignId('employee_id')
                      ->nullable()
                      ->after('onboarding_id')
                      ->constrained('employees')
                      ->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aarfs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });
        Schema::table('asset_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};