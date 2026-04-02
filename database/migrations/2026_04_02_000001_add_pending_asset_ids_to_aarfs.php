<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aarfs', function (Blueprint $table) {
            $table->json('pending_asset_ids')->nullable()->after('asset_changes');
        });
    }

    public function down(): void
    {
        Schema::table('aarfs', function (Blueprint $table) {
            $table->dropColumn('pending_asset_ids');
        });
    }
};
