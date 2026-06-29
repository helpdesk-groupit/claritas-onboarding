<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Category C" — the read-only receipt details the OCR reads from each item's attachment
 * (company name, item description as printed, receipt date, who paid, and total paid).
 * Stored as JSON so it travels with the item and is shown beside its attachment in the
 * report. Distinct from the user-entered Expense Description / Date / claimed amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->json('ocr_details')->nullable()->after('receipt_paths');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->dropColumn('ocr_details');
        });
    }
};
