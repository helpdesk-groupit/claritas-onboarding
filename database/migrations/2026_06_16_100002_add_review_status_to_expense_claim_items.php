<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item approval: an approver (manager / HR) can reject individual line items
 * instead of bouncing the whole claim. Rejected items are excluded from the
 * payable total; their reason is stored in the existing `remarks` column.
 * Default 'approved' so an unreviewed item counts as payable until flagged, and
 * so existing claim items keep their current (already-approved) meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->enum('review_status', ['approved', 'rejected'])->default('approved')->after('is_locked');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->dropColumn('review_status');
        });
    }
};
