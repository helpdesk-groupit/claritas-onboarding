<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The asset ↔ invoice link: which billing document an asset ARRIVED on.
 *
 * Until now the two sides had nothing joining them. The asset carried
 * `rental_contract_reference` — free text, and in practice the number of the first
 * invoice the kit was delivered under — plus its own re-uploaded copy of that PDF in
 * `invoice_documents`, while the real document sat in `vendor_billing_documents` with its
 * number, dates, figures and summary. Grouping a vendor's assets "by invoice" could only
 * ever be done on the string, which cannot show the amount, the document or the contract.
 *
 * NAMING IS DELIBERATE — `origin_billing_document_id`, not `billing_document_id`. It says
 * WHICH invoice: the one the asset came in on. A rental is billed again every month, and
 * when those recurring documents need attaching an `asset_billing_document` pivot goes in
 * beside this column with nothing renamed, re-backfilled or made ambiguous. One column,
 * one meaning, one group per asset — which is what makes the grouping a clean list.
 *
 * It points at a BILLING DOCUMENT, not "an invoice", so grouping by a quotation or a
 * delivery order later is a change to what the picker offers, not another migration.
 *
 * Like `vendor_id`, one column serves both ownership types with its meaning read off
 * `ownership_type`: rented-in on that invoice, or purchased on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            // nullOnDelete, not cascade: deleting a filed invoice must never delete the
            // asset it paid for. The asset simply falls back to its free-text reference.
            $table->foreignId('origin_billing_document_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('vendor_billing_documents')
                ->nullOnDelete();
        });

        $this->backfillFromReference();
    }

    public function down(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->dropForeign(['origin_billing_document_id']);
            $table->dropColumn('origin_billing_document_id');
        });
    }

    /**
     * Link the assets whose free-text reference unambiguously names an invoice already in
     * the vendor's register.
     *
     * STRICT, on purpose — an asset is linked only when EXACTLY ONE of that vendor's
     * invoices matches its normalised reference. `vendor_billing_documents.doc_number` has
     * no unique constraint, so a tie is a real possibility, and guessing on one is how an
     * asset gets filed under the wrong bill on a page whose whole job is to say which bill
     * it came in on. Ambiguous and unmatched assets stay null and fall into the page's
     * free-text group, where they can be registered in one click.
     *
     * This NEVER creates a billing document. Inventing finance records out of an inventory
     * free-text field is not something a migration gets to do silently.
     */
    private function backfillFromReference(): void
    {
        // vendor id => normalised doc_number => list of document ids.
        $index = [];

        DB::table('vendor_billing_documents')
            ->where('doc_type', 'invoice')
            ->whereNotNull('doc_number')
            ->where('doc_number', '!=', '')
            ->orderBy('id')
            ->select('id', 'vendor_id', 'doc_number')
            ->each(function ($doc) use (&$index) {
                $key = $this->normalise($doc->doc_number);
                if ($key !== '') {
                    $index[$doc->vendor_id][$key][] = $doc->id;
                }
            });

        if ($index === []) {
            return;
        }

        DB::table('asset_inventories')
            ->whereNotNull('vendor_id')
            ->whereNull('origin_billing_document_id')
            ->whereNotNull('rental_contract_reference')
            ->where('rental_contract_reference', '!=', '')
            ->orderBy('id')
            ->select('id', 'vendor_id', 'rental_contract_reference')
            ->each(function ($asset) use ($index) {
                $key = $this->normalise($asset->rental_contract_reference);
                $matches = $index[$asset->vendor_id][$key] ?? [];

                if (count($matches) !== 1) {
                    return;
                }

                DB::table('asset_inventories')
                    ->where('id', $asset->id)
                    ->update(['origin_billing_document_id' => $matches[0]]);
            });
    }

    /**
     * Case- and spacing-insensitive, and nothing more.
     *
     * Punctuation is deliberately KEPT: stripping dashes would fold "INV-2025-1" and
     * "INV-20251" into one key and merge two genuinely different invoices.
     */
    private function normalise(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)));
    }
};
