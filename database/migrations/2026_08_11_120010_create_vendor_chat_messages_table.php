<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor profile's document Q&A thread.
 *
 * ONE thread per vendor, shared by everyone who can see the vendor — deliberately no
 * sessions table. The profile is a shared Finance+IT workspace, and the answer one person
 * got about a contract is exactly what the next person needs to see; a per-user thread
 * would hide it and would make the record useless as a trail of what the assistant was
 * told and what it said back.
 *
 * There is no delete path, for the same reason a signed AARF has none: an AI answer about
 * a contract can drive a commercial decision. What bounds the CONTEXT sent to the model is
 * not deletion but the `divider` role — a "Start new topic" marker the context builder
 * stops at — plus the last-N-turns cap in config('vendors.ai.chat_history_turns').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            // Null once the asker's account is removed — the question and answer stay.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // user | assistant | divider
            $table->string('role', 16);
            $table->longText('content');

            // Which documents were in scope for this turn, and which were EXCLUDED with the
            // reason. Stored per message because the scope is chosen per question, and an
            // answer read a year later has to say what it was actually looking at.
            $table->json('context_json')->nullable();
            $table->string('model', 60)->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_chat_messages');
    }
};
