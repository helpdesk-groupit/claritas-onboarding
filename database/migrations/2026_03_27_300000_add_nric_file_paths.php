<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_details', function (Blueprint $table) {
            $table->json('nric_file_paths')->nullable()->after('nric_file_path');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->json('nric_file_paths')->nullable()->after('nric_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('personal_details', function (Blueprint $table) {
            $table->dropColumn('nric_file_paths');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('nric_file_paths');
        });
    }
};
