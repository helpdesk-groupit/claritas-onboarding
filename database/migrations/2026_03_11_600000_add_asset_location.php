<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('asset_inventories', 'asset_location')) {
            Schema::table('asset_inventories', function (Blueprint $table) {
                $table->string('asset_location')->nullable()->after('remarks');
            });
        }
    }
    public function down(): void
    {
        if (Schema::hasColumn('asset_inventories', 'asset_location')) {
            Schema::table('asset_inventories', function (Blueprint $table) {
                $table->dropColumn('asset_location');
            });
        }
    }
};