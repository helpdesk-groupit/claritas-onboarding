<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge-base uploads for a social strategy.
 *
 * Files are stored on the private `local` disk via AttachmentProcessor (EXIF
 * stripped, images downscaled) and scanned by the global ScanUploadsForMalware
 * middleware. Only rows with scan_status = 'clean' are ever read back into an AI
 * call (see SocialMediaStrategistService::binaryBlocks / contextBlock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_strategy_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_strategy_id')->constrained('social_strategies')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('file_path');                 // private-disk relative path
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->boolean('is_image')->default(false);
            $table->string('kind', 10);                  // pdf | image | text | csv | json
            $table->longText('extracted_text')->nullable(); // inline text for text/csv/json/md
            $table->string('scan_status', 10)->default('pending'); // pending | clean | infected
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index('social_strategy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_strategy_files');
    }
};
