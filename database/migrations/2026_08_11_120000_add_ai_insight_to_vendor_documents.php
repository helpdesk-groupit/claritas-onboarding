<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the uploaded document SAYS, as opposed to what fields were read off it.
 *
 * The existing ocr_* trio pre-fills form fields; these five hold the readable summary
 * shown on the row and the faithful transcription the vendor Q&A assistant answers from.
 * Duplicated across both document tables rather than pulled into a shared table, exactly
 * as ocr_status/ocr_raw/ocr_at already are — a contract and an invoice are read by the
 * same service but they are not the same record.
 *
 * `ai_status` carries the same "we read it" vs "we never read it" distinction as
 * ocr_status, plus one state of its own: `partial`. The transcription is bounded by
 * max_tokens, and a truncated transcript that reads as complete would make the assistant
 * answer "clause 32 is not in this contract" when it simply never received page 20.
 */
return new class extends Migration
{
    /** vendor_contracts and vendor_billing_documents take the identical block. */
    private const TABLES = ['vendor_contracts', 'vendor_billing_documents'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                // pending | ok | partial | empty | skipped | disabled | failed
                $t->string('ai_status', 20)->nullable()->after('ocr_at');
                $t->longText('ai_summary')->nullable()->after('ai_status');
                $t->json('ai_key_points')->nullable()->after('ai_summary');
                // The grounding for the assistant. longText because a 40-page contract
                // transcribes to well past TEXT's 64 KB.
                $t->longText('ai_text')->nullable()->after('ai_key_points');
                $t->timestamp('ai_at')->nullable()->after('ai_text');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['ai_status', 'ai_summary', 'ai_key_points', 'ai_text', 'ai_at']);
            });
        }
    }
};
