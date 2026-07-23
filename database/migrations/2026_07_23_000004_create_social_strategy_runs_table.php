<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One generation run of a social strategy — the observability record the wizard
 * polls while the background job (RunStrategyGeneration) works through the six
 * sections. Mirrors email_workflow_runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_strategy_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_strategy_id')->constrained('social_strategies')->cascadeOnDelete();
            $table->string('trigger', 20);               // manual | regenerate
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            // running | success | partial | failed
            $table->string('status', 20)->default('running');
            // null = all six sections; else the subset of section_keys targeted.
            $table->json('target_sections_json')->nullable();
            $table->unsignedInteger('total_sections')->default(0);
            $table->unsignedInteger('completed_sections')->default(0);
            $table->unsignedInteger('failed_sections')->default(0);
            $table->string('current_section', 20)->nullable(); // drives the spinner label while running
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['social_strategy_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_strategy_runs');
    }
};
