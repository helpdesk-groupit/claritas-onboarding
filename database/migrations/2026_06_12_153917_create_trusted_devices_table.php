<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trusted devices for risk-based 2FA. A row exists per remembered
     * browser/device. While a valid, non-expired row matches the request's
     * trusted-device cookie (and no risk signal fires), the TOTP challenge
     * is skipped on login. Purely additive — absence of a row falls back to
     * the normal "prompt every login" behaviour.
     */
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Split-token (selector/validator) pattern: selector is the public
            // lookup key, validator_hash is sha256 of the secret half. The raw
            // token is never stored, so a DB leak alone can't forge a cookie.
            $table->string('selector', 32)->unique();
            $table->string('validator_hash', 64);
            $table->string('device_label')->nullable();   // "Chrome on Windows"
            $table->string('user_agent', 1024)->nullable();
            $table->string('last_ip', 45)->nullable();     // IPv6-safe
            $table->string('last_country', 2)->nullable(); // ISO code, e.g. "MY"
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
