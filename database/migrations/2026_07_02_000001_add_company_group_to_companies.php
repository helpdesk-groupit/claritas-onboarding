<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Affiliated-company grouping. Companies sharing the same (case-insensitive)
     * company_group label pool their reporting managers together — e.g. the Cozzi
     * branches (Batu Pahat / KL / Muar) registered as separate companies but sharing
     * a manager like "Ho Chew Ying".
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('company_group', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('company_group');
        });
    }
};
