<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the per-field OCR columns from the two vendor document tables.
 *
 * The scan that pre-filled the contract and billing forms was removed on 2026-08-11 by
 * operator decision: the fields are typed by hand, and the only wanted machine reading of
 * a vendor document is the whole-document summary + transcription in the `ai_*` columns,
 * which are untouched here.
 *
 * `ocr_raw` held the model's raw reply for a scan that can no longer happen, so nothing
 * downstream reads any of the three. They are dropped rather than left in place (the usual
 * choice for a column whose feature has gone) because they are plain nullable columns —
 * no enum to rewrite, no FK, no index — so the DDL is cheap and cannot half-apply.
 *
 * down() restores the columns but NOT their contents: the raw scan replies are gone for
 * good. That is stated rather than glossed, because a rollback that silently produces
 * empty columns reads as "the scans never ran".
 */
return new class extends Migration
{
    /** table => the three columns, guarded per-column so a partial state still migrates. */
    private const TABLES = ['vendor_contracts', 'vendor_billing_documents'];

    private const COLUMNS = ['ocr_status', 'ocr_raw', 'ocr_at'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter(
                self::COLUMNS,
                fn (string $column) => Schema::hasColumn($table, $column)
            ));

            if ($present === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($present) {
                $t->dropColumn($present);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'ocr_status')) {
                    $t->string('ocr_status', 20)->nullable()->after('original_filename');
                }
                if (! Schema::hasColumn($table, 'ocr_raw')) {
                    $t->json('ocr_raw')->nullable()->after('ocr_status');
                }
                if (! Schema::hasColumn($table, 'ocr_at')) {
                    $t->timestamp('ocr_at')->nullable()->after('ocr_raw');
                }
            });
        }
    }
};
