<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 20)->unique();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')
                  ->comment('Creator of the ticket');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null')
                  ->comment('PIC handling the ticket');
            $table->enum('department', ['HR', 'IT', 'Finance', 'Admin']);
            $table->enum('priority', ['Low', 'Medium', 'High', 'Urgent'])->default('Medium');
            $table->enum('status', ['Open', 'Assigned', 'In Progress', 'Resolved', 'Archived'])->default('Open');
            $table->string('subject', 255);
            $table->text('description');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['department', 'status']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
