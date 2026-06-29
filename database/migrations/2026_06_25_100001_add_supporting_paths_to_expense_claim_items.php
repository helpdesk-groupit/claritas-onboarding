<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            // Optional, non-OCR supporting documents (approval letters, MC, cost breakdowns,
            // etc.) — kept SEPARATE from the receipt/proof attachments so they don't get
            // scanned and aren't treated as the receipt of record.
            $table->json('supporting_paths')->nullable()->after('receipt_paths');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->dropColumn('supporting_paths');
        });
    }
};
