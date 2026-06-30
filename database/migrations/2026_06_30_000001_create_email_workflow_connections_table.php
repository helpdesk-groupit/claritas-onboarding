<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-provider OAuth/credential connections for the Email Workflow
 * automation module (IT > Automation > Email Workflow).
 *
 * Mirrors DocFlow's `connections` table. The user supplies their own
 * Google Cloud OAuth client id/secret and runs the consent flow; tokens
 * + client secret are encrypted at rest via Laravel `encrypted` casts
 * (never stored or logged in plaintext — see EmailWorkflowConnection model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_workflow_connections', function (Blueprint $table) {
            $table->id();

            // App-layer tenant scoping: who owns this connection.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // 'email' | 'storage' | 'log'
            $table->string('category', 20);
            // 'gmail' | 'gdrive' | 'gsheets' | 'outlook' | ... (registry id)
            $table->string('provider_id', 40);

            // Human label, e.g. the connected account email once known.
            $table->string('account_label')->nullable();

            // Encrypted at rest (see model $casts): OAuth client secret +
            // access/refresh tokens. Plaintext NEVER persisted.
            $table->text('client_id')->nullable();          // OAuth client id (not secret, but kept together)
            $table->text('client_secret')->nullable();      // encrypted
            $table->text('access_token')->nullable();       // encrypted
            $table->text('refresh_token')->nullable();      // encrypted

            $table->json('scopes')->nullable();             // granted/requested scopes
            // 'unconfigured' | 'pending' | 'connected' | 'needs_reconnect' | 'error'
            $table->string('status', 30)->default('unconfigured');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'category']);
            $table->index('provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_workflow_connections');
    }
};
