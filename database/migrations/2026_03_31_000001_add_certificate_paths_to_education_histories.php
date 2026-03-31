<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_education_histories', function (Blueprint $table) {
            $table->json('certificate_paths')->nullable()->after('certificate_path');
        });
    }

    public function down(): void
    {
        Schema::table('employee_education_histories', function (Blueprint $table) {
            $table->dropColumn('certificate_paths');
        });
    }
};
