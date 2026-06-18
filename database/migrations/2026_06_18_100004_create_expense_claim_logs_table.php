<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for the claim lifecycle — who did what, when (submitted, each
 * manager's approve/reject, HR's decision). Shown on the Claim Reports page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claim_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained()->cascadeOnDelete();
            $table->string('action', 50);              // submitted | manager_approved | manager_rejected | hr_approved | hr_rejected | manager_stage_done
            $table->unsignedBigInteger('actor_id')->nullable(); // users.id of the actor
            $table->string('actor_name', 150)->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_logs');
    }
};
