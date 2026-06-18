<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            // Starting point for a mileage claim. Previously a fixed config origin
            // (Jaya One); now the employee chooses it per claim, so it must persist
            // for the printed form and the distance re-verification.
            $table->string('mileage_origin', 255)->nullable()->after('mileage_destination');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->dropColumn('mileage_origin');
        });
    }
};
