<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');

            // Who made the edit
            $table->unsignedBigInteger('edited_by_user_id')->nullable();
            $table->foreign('edited_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->string('edited_by_name')->nullable();
            $table->string('edited_by_role')->nullable();

            // What changed
            $table->json('sections_changed')->nullable();
            $table->text('change_notes')->nullable();

            // Consent request
            $table->boolean('consent_required')->default(false);
            $table->string('consent_token', 100)->nullable()->unique();
            $table->timestamp('consent_token_expires_at')->nullable();
            $table->timestamp('consent_requested_at')->nullable();
            $table->string('consent_sent_to_email')->nullable();

            // Acknowledgement
            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $table->foreign('acknowledged_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->string('acknowledged_by_name')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_edit_logs');
    }
};
