<?php

namespace App\Services;

use App\Jobs\SummariseVendorDocument;
use App\Models\AssetDecommissionQuotation;
use App\Models\VendorContract;
use App\Models\VendorDocumentScan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File an e-waste quotation onto the sending vendor's Contracts tab.
 *
 * A quotation IT uploads to a disposal cycle is also a document that vendor sent us, and the
 * vendor master is where the company's record of their documents lives. Filing it here means
 * the same PDF is not uploaded twice, and a vendor's record shows every offer they made —
 * including the ones we did not take up, which is the only way their profile can show they
 * were ever asked.
 *
 * EVERY revision from every vendor is filed. Keeping only the winner would leave a losing
 * vendor's record empty on a cycle they did quote for, and keeping only the latest revision
 * would drop the offer a re-quote replaced — which is the document a later question about a
 * price change is actually about.
 *
 * The filed row is a COPY, and it is read-only on the Contracts tab: its figure and its state
 * belong to the cycle. See VendorContract::TYPE_EWASTE_QUOTATION.
 */
class EwasteQuotationFilingService
{
    /**
     * File one revision. Returns the contract created, or null when there was nothing to do.
     *
     * Idempotent: a revision already filed returns its existing row rather than a second copy.
     * The UNIQUE index on the column is the real guarantee — this check only avoids provoking it.
     */
    public static function file(AssetDecommissionQuotation $quotation, ?string $originalFilename = null): ?VendorContract
    {
        if ($existing = VendorContract::where('asset_decommission_quotation_id', $quotation->id)->first()) {
            return $existing;
        }

        // No vendor means nothing to file it against. Legacy revisions from a cycle whose RFQ
        // was skipped carry a null vendor_id, and inventing one would attribute a real
        // financial document to a company that may never have quoted.
        if (! $quotation->vendor_id) {
            return null;
        }

        $batch = $quotation->batch;

        $contract = new VendorContract([
            'contract_type' => VendorContract::TYPE_EWASTE_QUOTATION,
            'title' => self::title($quotation, $batch?->batch_number),
            'contract_reference' => $batch?->batch_number,
            // The figure the cycle holds. Kept in step by AssetDecommissionBatch::setQuotationAmount()
            // when somebody corrects a mis-read amount; the document itself is never touched.
            'contract_value' => $quotation->amount,
            'currency' => VendorContract::DEFAULT_CURRENCY,
            // No term: a scrap offer is not an agreement with a start and an end. Leaving these
            // null is what stops the Contracts tab computing an expiry for something that
            // cannot expire.
            'start_date' => null,
            'end_date' => null,
        ]);

        $contract->vendor_id = $quotation->vendor_id;
        $contract->asset_decommission_quotation_id = $quotation->id;
        $contract->created_by = $quotation->uploaded_by;

        // Both parties are known facts here, not a reading — the entity disposing of the assets
        // and the vendor who quoted for them. The Contracts tab's first column would otherwise
        // read "not recorded" on a document whose counterparties we know exactly.
        $contract->companies_involved = array_values(array_filter([
            $batch?->issuingCompany(),
            $quotation->vendor?->name,
        ]));

        self::attachCopy($contract, $quotation, $originalFilename);

        $contract->save();

        // Queued, like registerFromAssets(): nobody is watching a modal here, and reading a
        // multi-page PDF inline would push the quotation upload past the edge timeout.
        if ($contract->file_path) {
            SummariseVendorDocument::dispatchFor($contract);
        }

        return $contract;
    }

    /**
     * Copy the document into the vendor's own directory.
     *
     * COPIED, never referenced. `ewaste_quotations` and `vendor_contracts` carry different role
     * lists in SecureFileController::DIRECTORY_PERMISSIONS, so a filed row pointing at the
     * e-waste path would 403 for somebody who may legitimately read the vendor's documents —
     * and the cycle's report renderer still merges the original into the final PDF, so it
     * cannot be moved. Same decision as VendorBillingController::createDocumentFromAssets().
     */
    private static function attachCopy(VendorContract $contract, AssetDecommissionQuotation $quotation, ?string $originalFilename): void
    {
        $source = $quotation->path;

        if (blank($source) || ! Storage::disk('local')->exists($source)) {
            return;
        }

        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $target = VendorDocumentScan::directoryFor(VendorDocumentScan::KIND_CONTRACT, (int) $quotation->vendor_id)
            .'/'.Str::random(40).($extension ? '.'.$extension : '');

        if (! Storage::disk('local')->copy($source, $target)) {
            Log::warning('E-waste quotation copy failed', ['quotation' => $quotation->id, 'source' => $source]);

            return;
        }

        $contract->file_path = $target;
        // The uploader's own filename when the caller still has it. Uploads are given a random
        // name on the way to the private disk, so basename() is a fallback that names the
        // stored file rather than inventing one the vendor never sent.
        $contract->original_filename = $originalFilename ?: basename($source);
    }

    private static function title(AssetDecommissionQuotation $quotation, ?string $batchNumber): string
    {
        $title = 'E-waste quotation — '.($batchNumber ?: 'disposal cycle');

        // Only numbered once there IS a second revision to tell it apart from — a lone
        // quotation reading "(revision 1)" implies a history that does not exist.
        return (int) $quotation->revision > 1
            ? $title.' (revision '.$quotation->revision.')'
            : $title;
    }
}
