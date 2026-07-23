<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One generated section of a social strategy (market · competitor · compliance ·
 * strategy · measure · handoff). Each is written by its own AI call, is editable
 * in place, and can be regenerated individually.
 *
 * UNIQUE(social_strategy_id, section_key) makes the generation job idempotent —
 * a re-run upserts the same six rows rather than duplicating them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_strategy_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_strategy_id')->constrained('social_strategies')->cascadeOnDelete();
            $table->string('section_key', 20);           // market|competitor|compliance|strategy|measure|handoff
            $table->unsignedTinyInteger('position');     // 1..6 render order
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->boolean('is_live_sourced')->default(false); // drives the "LIVE-SOURCED" badge
            $table->string('status', 10)->default('wait'); // wait | running | ok | error
            $table->text('error')->nullable();
            $table->unsignedInteger('tokens_input')->nullable();
            $table->unsignedInteger('tokens_output')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['social_strategy_id', 'section_key'], 'sms_sections_strategy_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_strategy_sections');
    }
};
