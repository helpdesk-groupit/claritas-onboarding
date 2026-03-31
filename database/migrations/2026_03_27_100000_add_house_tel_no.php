<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_details', function (Blueprint $table) {
            $table->string('house_tel_no')->nullable()->after('personal_contact_number');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('house_tel_no')->nullable()->after('personal_contact_number');
        });
    }

    public function down(): void
    {
        Schema::table('personal_details', function (Blueprint $table) {
            $table->dropColumn('house_tel_no');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('house_tel_no');
        });
    }
};
