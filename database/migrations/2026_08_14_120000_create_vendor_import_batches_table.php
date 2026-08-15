<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor list that has been uploaded and read, but not yet filed.
 *
 * Same shape and same reasoning as `vendor_document_scans`: the operator has to SEE what the
 * importer made of their spreadsheet before a single vendor row is created, and that preview
 * spans two requests. Holding the file here (rather than creating inactive vendor rows and
 * tidying up afterwards) means an abandoned import leaves nothing on the vendor directory
 * that reads like a real vendor.
 *
 * The parsed rows are deliberately NOT stored — the confirm step re-reads the file with the
 * mapping the operator approved, so what gets imported is always derived from the document
 * itself and never from a payload the browser could have altered in between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Travels in a URL, so it is a lookup key and never a capability: every read is
            // additionally scoped to the uploader, exactly as VendorDocumentScan is.
            $table->string('token', 64)->unique();
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_import_batches');
    }
};
