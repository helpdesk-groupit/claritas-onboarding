<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'birthday_email_sent_year')) {
                $table->smallInteger('birthday_email_sent_year')->nullable()->after('date_of_birth');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'birthday_email_sent_year')) {
                $table->dropColumn('birthday_email_sent_year');
            }
        });
    }
};
