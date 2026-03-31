<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained()->cascadeOnDelete();

            // Who made the edit
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('edited_by_name');
            $table->string('edited_by_role')->nullable();

            // What was changed
            $table->json('sections_changed')->nullable(); // e.g. ["Section A","Section F"]
            $table->text('change_notes')->nullable();

            // Consent request
            $table->boolean('consent_required')->default(false);
            $table->string('consent_token', 100)->nullable()->unique();
            $table->timestamp('consent_token_expires_at')->nullable();
            $table->timestamp('consent_requested_at')->nullable();
            $table->string('consent_sent_to_email')->nullable();

            // Acknowledgement
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acknowledged_by_name')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_edit_logs');
    }
};
