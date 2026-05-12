<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_edit_logs')) return;

        Schema::create('ticket_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('edited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('changes');  // { "department": {"from": "KOL", "to": "Group IT"}, ... }
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_edit_logs');
    }
};
