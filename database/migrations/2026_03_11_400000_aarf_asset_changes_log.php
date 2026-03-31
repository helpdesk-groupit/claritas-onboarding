<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aarfs', function (Blueprint $table) {
            // Append-only log of reassign / return events for this AARF
            $table->text('asset_changes')->nullable()->after('it_notes');
        });
    }

    public function down(): void
    {
        Schema::table('aarfs', function (Blueprint $table) {
            $table->dropColumn('asset_changes');
        });
    }
};