<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Run now" becomes a REQUEST, not a synchronous sweep.
 *
 * WHY: Run now ran the full sweep inline in the browser request. On a slow
 * mailbox (the real Zoho IMAP is ~2-3s per message) that takes minutes, but
 * Cloudflare's edge returns a 504 at ~100s while PHP is still reading mail —
 * the operator sees a Gateway Time-out, twice now, and bounding the sweep only
 * moved the cliff without removing it. No synchronous request can reliably beat
 * a fixed edge timeout against a mailbox that slow.
 *
 * So Run now sets a marker and returns immediately; the every-minute scheduler
 * (CLI, no edge timeout) picks it up and runs the FULL sweep out of band. The
 * result lands in the run history. `run_requested_by` records who asked so the
 * run stays attributable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_workflows', function (Blueprint $table) {
            $table->timestamp('run_requested_at')->nullable()->after('next_run_at');
            $table->foreignId('run_requested_by')->nullable()->after('run_requested_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('run_requested_by');
            $table->dropColumn('run_requested_at');
        });
    }
};
