<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the FK constraint first, then alter to nullable, then re-add FK
        Schema::table('offboardings', function (Blueprint $table) {
            // Drop existing foreign key on onboarding_id
            $table->dropForeign(['onboarding_id']);
        });

        // Alter column to nullable
        DB::statement('ALTER TABLE offboardings MODIFY onboarding_id BIGINT UNSIGNED NULL');

        Schema::table('offboardings', function (Blueprint $table) {
            // Re-add FK as nullable
            $table->foreign('onboarding_id')
                  ->references('id')->on('onboardings')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        // Revert to NOT NULL (only safe if no nulls exist)
        Schema::table('offboardings', function (Blueprint $table) {
            $table->dropForeign(['onboarding_id']);
        });
        DB::statement('ALTER TABLE offboardings MODIFY onboarding_id BIGINT UNSIGNED NOT NULL');
        Schema::table('offboardings', function (Blueprint $table) {
            $table->foreign('onboarding_id')
                  ->references('id')->on('onboardings')
                  ->onDelete('cascade');
        });
    }
};