<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    $assets = DB::table('asset_inventories')
        ->where('asset_condition', 'not_good')
        ->whereNotIn('id', DB::table('dispose_assets')->pluck('asset_inventory_id'))
        ->get();

    foreach ($assets as $asset) {
        DB::table('dispose_assets')->insert([
            'asset_inventory_id' => $asset->id,
            'asset_tag'          => $asset->asset_tag,
            'asset_type'         => $asset->asset_type,
            'brand'              => $asset->brand,
            'model'              => $asset->model,
            'serial_number'      => $asset->serial_number,
            'asset_condition'    => 'not_good',
            'disposed_by'        => 'System (backfill)',
            'disposed_at'        => $asset->updated_at ?? now(),
            'remarks'            => $asset->remarks,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dispose', function (Blueprint $table) {
            //
        });
    }
};
