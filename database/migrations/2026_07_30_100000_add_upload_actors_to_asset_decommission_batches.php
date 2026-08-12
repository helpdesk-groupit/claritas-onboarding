<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHO uploaded the quotation and the payment receipt.
 *
 * The report reproduces both documents in full, so each needs the same
 * accountability the Finance approval already carries (`finance_reviewed_by`):
 * a named person against a timestamp. Only the timestamp was captured before,
 * which left the two money documents as the only unattributed steps in the cycle.
 *
 * Nullable and NOT backfilled — the actor was never recorded for existing rows and
 * inventing one would fabricate an audit trail. Those report pages state the
 * timestamp alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_decommission_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('quotation_uploaded_by')->nullable()->after('quotation_uploaded_at');
            $table->unsignedBigInteger('receipt_uploaded_by')->nullable()->after('receipt_uploaded_at');

            $table->foreign('quotation_uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('receipt_uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_decommission_batches', function (Blueprint $table) {
            $table->dropForeign(['quotation_uploaded_by']);
            $table->dropForeign(['receipt_uploaded_by']);
            $table->dropColumn(['quotation_uploaded_by', 'receipt_uploaded_by']);
        });
    }
};
