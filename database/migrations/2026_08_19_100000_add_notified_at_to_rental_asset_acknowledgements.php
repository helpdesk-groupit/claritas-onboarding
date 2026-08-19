<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the signed copy was actually emailed out (vendor PIC, IT, Finance, Management).
 *
 * The live signing flow only ever calls distributeSignedCopy() once (finalizeIfComplete()
 * short-circuits on an already-acknowledged form), so it never needed this to stay
 * idempotent. It's added for the historical-backfill tool, which creates already-acknowledged
 * records and sends the notification as a deliberate, separate second step — this is what
 * lets that second step be re-run safely without double-emailing a batch already sent.
 *
 * Nullable and never backfilled: whether an already-signed form's copy went out was never
 * tracked before this column existed, and inventing a value would fabricate the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
