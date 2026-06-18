<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly claim carries an "Event" label (per the paper EXPENSES CLAIMS FORM
 * header — e.g. "Office Equipment Claim"). One event per month's claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->string('event', 255)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
};
