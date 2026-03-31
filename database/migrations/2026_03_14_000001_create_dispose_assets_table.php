<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispose_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_inventory_id')->constrained('asset_inventories')->onDelete('cascade');
            $table->string('asset_tag');
            $table->string('asset_type');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('asset_condition')->default('not_good'); // the condition that triggered disposal
            $table->string('disposed_by')->nullable();             // actor name
            $table->timestamp('disposed_at')->useCurrent();
            $table->text('remarks')->nullable();                   // carried over from asset
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispose_assets');
    }
};
