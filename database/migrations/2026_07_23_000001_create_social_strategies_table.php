<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social Media AI Strategist — the aggregate row for one strategy engagement.
 *
 * Ports the client-side `S` object of the "Strategist OS" browser agent into a
 * server-owned record: the 6-step intake, the knowledge base (notes/links; files
 * live in social_strategy_files), the gap-check gate (factbase + gaps + answers),
 * and the run metadata. Lives under IT > Automation > Social Media AI Strategist,
 * owned by its creator (app-layer tenant scoping via created_by + scopeVisibleTo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // Frozen at creation for usage attribution + reporting, exactly like
            // AiAccountingService reads Auth::user()->employee->company.
            $table->string('company')->nullable();
            $table->string('name');
            // draft | generating | ready | error
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('wizard_step')->default(1);

            // The 19 intake fields (client, industry, offering, goal[], success,
            // juris[], audience, salesmotion, competitors, budget, team, approval,
            // assets, licenses, strikes, redlines, timeline, seasonal, history).
            $table->json('intake_json')->nullable();

            // Knowledge base (uploaded files are a child table).
            $table->longText('kb_notes')->nullable();
            $table->json('kb_links_json')->nullable();          // [{url, note}]
            $table->json('integrations_json')->nullable();       // MCP names — stored, unused v1

            // Gap-check gate.
            $table->longText('factbase')->nullable();
            $table->json('gaps_json')->nullable();               // [{q, why, suggestion}]
            $table->json('gap_answers_json')->nullable();        // {index: answer}

            // Output metadata + AI config.
            $table->json('meta_json')->nullable();               // {client, date, industry}
            $table->string('model')->nullable();                 // per-strategy Claude model override
            $table->boolean('use_web_search')->default(true);

            $table->text('last_error')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_strategies');
    }
};
