<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns behind "which assets does this contract cover" on the Contracts tab.
 *
 * There is no `contract_id` on assets, and none is being added — a rental contract only
 * ever describes assets in prose (quantities, models, commencement dates), never a serial
 * number or asset tag, so a manual picker would just be a second place to type the same
 * mistake. Instead: a rental asset already carries its own uploaded copy of the contract
 * in `rental_contract_documents`, and the Contracts tab already carries its own copy in
 * `file_path`. When those are the SAME file, a byte-for-byte hash match is a certain
 * answer, not a guess — two different files cannot share a SHA-256 digest.
 *
 * `rental_contract_document_hashes` is a map keyed by path (mirroring the existing
 * keep/remove-by-path handling for `rental_contract_documents`) rather than an array
 * aligned by index, so removing one file can never desynchronise which hash belongs to
 * which of the others that remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_contracts', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('file_path')->index();
        });

        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->json('rental_contract_document_hashes')->nullable()->after('rental_contract_documents');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_contracts', function (Blueprint $table) {
            $table->dropColumn('file_hash');
        });

        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->dropColumn('rental_contract_document_hashes');
        });
    }
};
