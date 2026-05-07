<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-message attachment table — supports multi-file uploads on chat messages.
 * The legacy single-attachment columns on ticket_messages (attachment_path,
 * attachment_original_name, attachment_mime) are kept untouched for backward
 * compatibility — old messages still render via those columns. New messages
 * use this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('ticket_messages')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size'); // bytes (post-compression)
            $table->boolean('is_image')->default(false);
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_message_attachments');
    }
};
