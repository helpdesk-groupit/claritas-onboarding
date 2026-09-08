<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let one export produce SEVERAL archives instead of one.
 *
 * Why, measured on production 2026-09-08: after the resumable-download fix and the font
 * subsetting that took the August archive from 227.7 MiB to 127.5 MiB, HR still could not
 * download it. Nineteen attempts, every one dying between 42 and 67 MB. The origin was ruled
 * out by direct test — a 250 MB probe through the identical chain completed at 20 MB/s, and a
 * 140 MB probe completed at 1 MB/s, HR's own observed rate — so what fails is their browser's
 * connection partway through any large transfer, and nothing on our side can stop that.
 *
 * Resumability was the right mechanism and is proven end to end (Cloudflare forwards Range and
 * If-Range to the origin), but it only helps if the browser actually resumes; all six post-fix
 * attempts were fresh 200s from byte 0, i.e. the Download button being pressed again. So the
 * export is split into parts small enough to finish well inside their failure band, which is
 * the one fix that depends on neither their network surviving a long transfer nor anyone
 * knowing to press Resume.
 *
 * `parts` is the source of truth; `file_path`/`file_size` stay as a materialised cache of part
 * one so a row written before this migration, and anything still reading those columns, keeps
 * answering exactly as it did. See ExpenseClaimZipExport::partList().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_zip_exports', function (Blueprint $table) {
            // [{path, size, claims}, ...] oldest first. Null on a legacy single-file row.
            $table->json('parts')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_zip_exports', function (Blueprint $table) {
            $table->dropColumn('parts');
        });
    }
};
