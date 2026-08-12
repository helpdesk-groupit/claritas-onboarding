<?php

namespace App\Http\Controllers;

use App\Jobs\SummariseVendorDocument;
use App\Models\AssetInventory;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Quotations and invoices received from a vendor — a DOCUMENT REGISTER on the vendor
 * profile, deliberately not an AP ledger. Nothing here posts a journal entry or touches
 * the Accounting module; it mirrors the "Finance stays lightweight" decision already made
 * for the e-waste cycle.
 *
 * Same shape as vendor contracts: the figures are typed by hand and saving is a plain
 * upload. The per-field OCR was removed on 2026-08-11; the only AI reading of a billing
 * document is the queued whole-document summary (`SummariseVendorDocument`).
 */
class VendorBillingController extends Controller
{
    private const DIR = 'vendor_billing';

    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageVendors()) {
            abort(403, 'No permission to manage vendors.');
        }
    }

    public function store(Request $request, Vendor $vendor)
    {
        $this->authorizeManage();

        $data = $this->validateDocument($request, $vendor);
        $file = $this->resolveDocument($request, $vendor, $data);

        $doc = new VendorBillingDocument($file['data']);
        $doc->vendor_id = $vendor->id;
        $doc->file_path = $file['path'];
        $doc->original_filename = $file['name'];
        $doc->created_by = Auth::id();
        if ($file['path']) {
            $doc->resetDocumentInsight();
        }
        $doc->save();

        $summary = $this->queueSummary($doc, (bool) $file['path']);

        return redirect()->route('vendors.show', [$vendor, 'tab' => 'billing'])
            ->with('success', $doc->typeLabel().' added.'.$summary.$this->sstFlash($doc));
    }

    public function update(Request $request, Vendor $vendor, VendorBillingDocument $document)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $document);

        $data = $this->validateDocument($request, $vendor);
        $file = $this->resolveDocument($request, $vendor, $data);

        if ($file['replaced']) {
            $this->deleteFile($document->file_path);

            $document->file_path = $file['path'];
            $document->original_filename = $file['name'];
            // Same rule as the contract side: the stored summary and transcription describe
            // the file just deleted, so they go in the SAME write that repoints the record.
            $document->resetDocumentInsight();
        }

        $document->fill($file['data'])->save();

        $summary = $this->queueSummary($document, $file['replaced']);

        return redirect()->route('vendors.show', [$vendor, 'tab' => 'billing'])
            ->with('success', $document->typeLabel().' updated.'.$summary.$this->sstFlash($document));
    }

    /**
     * File a free-text invoice reference typed on the assets as a real document in this
     * vendor's register, and link every asset grouped under it.
     *
     * The bridge between the two worlds, and the reason the free-text arm of the grouping
     * is not just tolerated but useful: most existing assets carry a reference and their own
     * re-uploaded copy of the PDF, with nothing in the register. Registering by hand would
     * mean re-typing the number and re-uploading the file for a document already on disk.
     *
     * NO AMOUNTS are invented. The figures are typed by hand (the 2026-08-11 decision that
     * removed the per-field OCR), and a total guessed from an inventory row would be a
     * finance figure nobody entered.
     */
    public function registerFromAssets(Request $request, Vendor $vendor)
    {
        $this->authorizeManage();

        $reference = $request->validate([
            'reference' => 'required|string|max:255',
        ])['reference'];

        $normalised = AssetInventory::normaliseInvoiceReference($reference);

        // The vendor's own unlinked assets carrying this reference — matched through the
        // relation rather than by id list, so an asset belonging to another vendor can never
        // be swept onto this document by a hand-posted reference.
        $assets = $vendor->assets()
            ->whereNull('origin_billing_document_id')
            ->whereNotNull('rental_contract_reference')
            ->get()
            ->filter(fn ($a) => AssetInventory::normaliseInvoiceReference($a->rental_contract_reference) === $normalised)
            ->values();

        if ($assets->isEmpty()) {
            return redirect()->route('vendors.show', [$vendor, 'tab' => 'assets'])
                ->with('error', 'No unlinked asset carries the reference "'.$reference.'" for this vendor — it may already have been registered.');
        }

        // Already in the register? LINK to it rather than filing a second document for the
        // same invoice — two rows for one bill is exactly what the register exists to stop,
        // and the operator's intent ("these assets came in on this invoice") is served
        // either way. Matched on the same normalised key the grouping uses.
        $existing = VendorBillingDocument::invoiceOptions($vendor->id)
            ->first(fn ($d) => AssetInventory::normaliseInvoiceReference($d->doc_number) === $normalised);

        $document = $existing ?: $this->createDocumentFromAssets($vendor, $reference, $assets);

        AssetInventory::whereIn('id', $assets->pluck('id'))
            ->update(['origin_billing_document_id' => $document->id]);

        $count = $assets->count();
        $noun = $count === 1 ? 'asset' : 'assets';

        if ($existing) {
            return redirect()->route('vendors.show', [$vendor, 'tab' => 'assets'])
                ->with('success', "Invoice {$reference} was already in the register — {$count} {$noun} linked to it.");
        }

        $summary = $this->queueSummary($document, (bool) $document->file_path);
        $file = $document->file_path
            ? ' The invoice document was copied from the asset record.'
            : ' No invoice document was uploaded on those assets, so the record has no file — attach one on the Billing tab.';

        return redirect()->route('vendors.show', [$vendor, 'tab' => 'assets'])
            ->with('success', "Invoice {$reference} filed in the billing register and linked to {$count} {$noun}.".$file.$summary
                .' Its dates and amounts are blank — fill them in on the Billing tab.');
    }

    public function destroy(Vendor $vendor, VendorBillingDocument $document)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $document);

        $label = $document->typeLabel();
        $this->deleteFile($document->file_path);
        $document->delete();

        return redirect()->route('vendors.show', [$vendor, 'tab' => 'billing'])
            ->with('success', $label.' removed.');
    }

    /**
     * Build the register entry for a reference that only existed on the assets.
     *
     * The file is COPIED into vendor_billing/ rather than referenced where it lies. Asset
     * invoices live under `invoices/`, which SecureFileController gates to HR + IT — so a
     * document filed on the vendor profile but pointing there would 403 for exactly the
     * Finance readers this register exists for. The asset keeps its own copy: it is the
     * evidence attached to that asset, and legacy assets hold files no register row knows
     * about.
     */
    private function createDocumentFromAssets(Vendor $vendor, string $reference, $assets): VendorBillingDocument
    {
        $document = new VendorBillingDocument([
            'doc_type' => 'invoice',
            'doc_number' => mb_substr($reference, 0, 255),
            'status' => 'received',
            'currency' => VendorBillingDocument::DEFAULT_CURRENCY,
            'description' => 'Registered from '.$assets->count().' linked asset'.($assets->count() === 1 ? '' : 's').'.',
        ]);
        $document->vendor_id = $vendor->id;
        $document->created_by = Auth::id();

        // The first copy anyone uploaded against these assets. They are meant to be the same
        // document, but the asset record is the only place that can be checked — so take one
        // and say where it came from, rather than merging copies that may differ.
        $source = $assets
            ->map(fn ($a) => collect($a->invoice_documents ?? [])->first())
            ->filter()
            ->first();

        if ($source && Storage::disk('local')->exists($source)) {
            $extension = pathinfo($source, PATHINFO_EXTENSION);
            $target = self::DIR.'/'.$vendor->id.'/'.Str::random(40).($extension ? '.'.$extension : '');

            if (Storage::disk('local')->copy($source, $target)) {
                $document->file_path = $target;
                // The stored name is all the asset record ever kept — uploads are given a
                // random filename there, so there is no original to carry across. Naming it
                // after the reference would invent a filename the vendor never sent.
                $document->original_filename = basename($source);
            }
        }

        $document->save();

        return $document;
    }

    // ── Internals ─────────────────────────────────────────────────────────────
    private function assertBelongs(Vendor $vendor, VendorBillingDocument $document): void
    {
        abort_unless($document->vendor_id === $vendor->id, 404);
    }

    private function validateDocument(Request $request, Vendor $vendor): array
    {
        $data = $request->validate([
            'doc_type' => ['required', 'string', Rule::in(array_keys(VendorBillingDocument::TYPES))],
            'doc_number' => 'nullable|string|max:255',
            'status' => ['required', 'string', Rule::in(array_keys(VendorBillingDocument::STATUSES))],
            // Scoped to THIS vendor: a contract id from another vendor would silently file
            // the document under a relationship it has nothing to do with.
            'vendor_contract_id' => [
                'nullable',
                Rule::exists('vendor_contracts', 'id')->where('vendor_id', $vendor->id),
            ],
            'doc_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'subtotal' => 'nullable|numeric|min:0|max:999999999.99',
            'sst_amount' => 'nullable|numeric|min:0|max:999999999.99',
            'total' => 'nullable|numeric|min:0|max:999999999.99',
            'currency' => 'nullable|string|size:3',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240|valid_file_content',
        ]);

        // `currency` is NOT NULL with a DB default, but a CLEARED text input arrives as ''
        // which ConvertEmptyStringsToNull turns into null — and an explicit null defeats a
        // column default, so the insert dies on an integrity violation instead of falling
        // back. Coalesce here rather than making the column nullable: an amount with no
        // currency beside it is not a meaningful record.
        $data['currency'] = $data['currency'] ?? VendorBillingDocument::DEFAULT_CURRENCY;

        return $data;
    }

    /** @return array{0:string,1:string} */
    private function storeDocument(Request $request, Vendor $vendor): array
    {
        $file = $request->file('document');

        return [
            $file->store(self::DIR.'/'.$vendor->id, 'local'),
            mb_substr((string) $file->getClientOriginalName(), 0, 255),
        ];
    }

    /**
     * Which document this submit carries: a new upload, or nothing.
     *
     * `replaced` drives both the old file's deletion and the re-reading of the summary, so
     * it must mean "the record now points at a DIFFERENT file" — a save that only edits the
     * typed figures must not discard the reading of a document that has not changed.
     *
     * @return array{path:?string,name:?string,data:array,replaced:bool}
     */
    private function resolveDocument(Request $request, Vendor $vendor, array $data): array
    {
        if ($request->hasFile('document')) {
            [$path, $name] = $this->storeDocument($request, $vendor);

            return ['path' => $path, 'name' => $name, 'data' => $data, 'replaced' => true];
        }

        return ['path' => null, 'name' => null, 'data' => $data, 'replaced' => false];
    }

    /**
     * Queue the summary + transcription pass. Out of band for the same reason as the
     * contract side: a long PDF read inside the save request is what times out on live.
     */
    private function queueSummary(VendorBillingDocument $document, bool $documentChanged): string
    {
        if (! $documentChanged || blank($document->file_path)) {
            return '';
        }

        SummariseVendorDocument::dispatchFor($document);

        return ' The document is being read for its summary — it appears on this row shortly.';
    }

    /**
     * Say it on the way out, not just on the page: an SST line from a vendor in our own
     * taxable group is money we should not be paying, and it is easiest to challenge on
     * the day the document is filed.
     */
    private function sstFlash(VendorBillingDocument $document): string
    {
        $flag = $document->sstFlag();

        return $flag ? ' ⚠ '.$flag : '';
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
