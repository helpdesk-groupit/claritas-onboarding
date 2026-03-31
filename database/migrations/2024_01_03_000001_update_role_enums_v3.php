<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update work_details.role enum - replace it_admin with hr/it role options
        DB::statement("ALTER TABLE work_details MODIFY COLUMN role ENUM(
            'manager','senior_executive','executive_associate','director_hod',
            'hr_manager','hr_executive','hr_intern',
            'it_manager','it_executive','it_intern',
            'others'
        ) NULL");

        // Add hr_email and it_email columns to onboardings table
        Schema::table('onboardings', function (Blueprint $table) {
            if (!Schema::hasColumn('onboardings', 'hr_email')) {
                $table->string('hr_email')->nullable()->after('status');
            }
            if (!Schema::hasColumn('onboardings', 'it_email')) {
                $table->string('it_email')->nullable()->after('hr_email');
            }
        });
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE work_details MODIFY COLUMN role ENUM(
            'manager','senior_executive','executive_associate','director_hod',
            'it_admin','others'
        ) NULL");

        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropColumn(['hr_email', 'it_email']);
        });
    }
};