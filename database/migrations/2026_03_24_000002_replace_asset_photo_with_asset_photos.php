<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            // Add new JSON column for multiple photos
            $table->json('asset_photos')->nullable()->after('asset_photo');
        });

        // Migrate existing single photo into the new array format
        DB::table('asset_inventories')
            ->whereNotNull('asset_photo')
            ->where('asset_photo', '!=', '')
            ->chunkById(100, function ($assets) {
                foreach ($assets as $asset) {
                    DB::table('asset_inventories')
                        ->where('id', $asset->id)
                        ->update(['asset_photos' => json_encode([$asset->asset_photo])]);
                }
            });

        // Drop old single-photo column
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->dropColumn('asset_photo');
        });
    }

    public function down(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->string('asset_photo')->nullable()->after('asset_photos');
        });

        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->dropColumn('asset_photos');
        });
    }
};