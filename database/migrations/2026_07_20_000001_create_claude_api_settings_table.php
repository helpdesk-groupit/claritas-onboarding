<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row settings for the superadmin "Claude API" page (Settings menu).
 * Holds the Anthropic API key used for claim-receipt OCR. When `enabled` is true
 * and `api_key` is set, ClaimReceiptOcrService uses Claude for OCR — taking
 * precedence over the env-based CLAIMS_OCR_* config. The key is encrypted at rest
 * (see ClaudeApiSetting's cast) and never returned to the browser in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_api_settings', function (Blueprint $table) {
            $table->id();
            $table->text('api_key')->nullable();          // encrypted at rest (model cast)
            $table->string('model')->default('claude-haiku-4-5');
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_api_settings');
    }
};
