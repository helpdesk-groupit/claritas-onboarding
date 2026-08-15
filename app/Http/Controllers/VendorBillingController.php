<?php

namespace App\Http\Controllers;

use App\Jobs\SummariseVendorDocument;
use App\Models\AssetInventory;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorDocumentScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Quotations and invoices received from a vendor — a DOCUMENT REGISTER on the vendor
 * profile, deliberately not an AP ledger. Nothing here posts a journal entry or touches
 * the Accounting module; it mirrors the "Finance stays lightweight" decision already made
 * for the e-waste cycle.
 *
 * Same shape as vendor contracts as of 2026-08-13: filing a document is upload → read →
 * correct the SUMMARY → save. The number, dates and figures come off that same reading and
 * are never asked for on the Add form; they are shown and owned on Edit. See
 * VendorContractController for why the field values must keep coming from a call SEPARATE
 * from the transcription.
 *
 * `registerFromAssets` is the one path that still files a document nobody scanned — it
 * copies an invoice already uploaded against an asset — so it keeps the queued background
 * read (`SummariseVendorDocument`).
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

    /**
     * File a scanned quotation or invoice.
     *
     * Requires a completed scan, like the contract side: the Add form is an upload form,
     * and a billing document with no file has no summary, no parties and no figures.
     * Whether it is a quotation or an invoice comes from the reading — the form no longer
     * asks, and the document says so on its face.
     */
    public function store(Request $request, Vendor $vendor)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'scan_token' => 'required|string|max:64',
            'ai_summary' => 'nullable|string|max:6000',
            'companies_involved' => 'nullable|string|max:2000',
        ]);

        $scan = $this->claimScan($vendor, $data['scan_token']);

        if (! $scan) {
            return $this->back($vendor)->with('error',
                'That upload is no longer available — it may have expired. Please attach the document again.');
        }

        $doc = new VendorBillingDocument($this->fieldsFromScan(is_array($scan->fields) ? $scan->fields : []));
        $doc->vendor_id = $vendor->id;
        $doc->file_path = $scan->file_path;
        $doc->original_filename = $scan->original_filename;
        $doc->created_by = Auth::id();

        // Carried across whole rather than re-run: it has been paid for, and a second
        // reading would replace the summary the operator just reviewed with one they
        // never saw.
        $doc->forceFill([
            'ai_status' => $scan->status,
            'ai_key_points' => $scan->key_points ?: null,
            'ai_text' => $scan->text,
            'ai_at' => now(),
        ]);
        $doc->ai_summary = $scan->summary;
        $doc->companies_involved = VendorBillingDocument::parseCompaniesInput($data['companies_involved'] ?? null) ?: null;
        $doc->applySummaryEdit($data['ai_summary'] ?? null, Auth::id());

        $doc->save();

        // The record owns the file now; the staging row goes WITHOUT deleting it.
        $scan->delete();

        return $this->back($vendor)->with('success',
            $doc->typeLabel().' added from the scanned document.'.$this->readingNote($doc).$this->sstFlash($doc));
    }

    /**
     * Correct the READING of a filed document — its summary and its parties — move it
     * along its lifecycle, or replace the document behind it.
     *
     * No figures. They are read off the document and stored, never typed: the operator's
     * instruction is that the scan produces the summary and the form asks for no fields. A
     * total the scan read wrong is fixed by re-reading or replacing the document, both of
     * which are in this same modal.
     *
     * `status` is the one exception and is NOT a document field — it is our own workflow
     * state (received → paid / disputed), it is a column on the listing, and no reading of
     * the document could ever set it.
     */
    public function update(Request $request, Vendor $vendor, VendorBillingDocument $document)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $document);

        $data = $this->validateDocument($request, $vendor);

        $scan = filled($data['scan_token'] ?? null)
            ? $this->claimScan($vendor, $data['scan_token'])
            : null;

        if (filled($data['scan_token'] ?? null) && ! $scan) {
            return $this->back($vendor)->with('error',
                'The replacement upload is no longer available — it may have expired. Nothing was changed; please attach it again.');
        }

        if ($scan) {
            $this->deleteFile($document->file_path);

            $document->file_path = $scan->file_path;
            $document->original_filename = $scan->original_filename;

            // Same rule as the contract side: the stored summary, transcription, parties and
            // edit stamp describe the file just deleted, so they are cleared in the SAME
            // write that repoints the record.
            $document->resetDocumentInsight();
            $document->forceFill([
                'ai_status' => $scan->status,
                'ai_key_points' => $scan->key_points ?: null,
                'ai_text' => $scan->text,
                'ai_at' => now(),
            ]);
            $document->ai_summary = $scan->summary;
        }

        $document->companies_involved = VendorBillingDocument::parseCompaniesInput($data['companies_involved'] ?? null) ?: null;
        $document->applySummaryEdit($data['ai_summary'] ?? null, Auth::id());

        $document->save();

        if ($scan) {
            $scan->delete();
        }

        return $this->back($vendor)->with('success',
            $document->typeLabel().' updated.'.($scan ? $this->readingNote($document) : '').$this->sstFlash($document));
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
        // The slip ROW cascades with the invoice; its FILE does not. Deleting the record and
        // leaving the proof of payment on the private disk would accumulate documents nothing
        // can ever reach or account for.
        $this->deleteFile($document->paymentSlip?->file_path);
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

    private function back(Vendor $vendor)
    {
        return redirect()->route('vendors.show', [$vendor, 'tab' => 'billing']);
    }

    /**
     * This operator's completed scan for this vendor, of the billing kind.
     *
     * Scoped by vendor, uploader AND kind — the token arrives in the request, so without
     * all three a contract upload could be filed as an invoice (putting a contract's term
     * into a due date) or somebody else's upload could be claimed.
     */
    private function claimScan(Vendor $vendor, string $token): ?VendorDocumentScan
    {
        return VendorDocumentScan::where('vendor_id', $vendor->id)
            ->where('user_id', Auth::id())
            ->where('kind', VendorDocumentScan::KIND_BILLING)
            ->where('token', $token)
            ->first();
    }

    /**
     * The figures the reading produced, with the two the column layer insists on.
     *
     * `doc_type` is already resolved to a valid type by the reading (it is what decides
     * quotation vs invoice now that the form does not ask), and `status` seeds the
     * lifecycle at the same 'received' every document used to start on.
     */
    private function fieldsFromScan(array $fields): array
    {
        return array_merge($fields, [
            'doc_type' => $fields['doc_type'] ?? 'invoice',
            'status' => 'received',
            'currency' => $fields['currency'] ?? VendorBillingDocument::DEFAULT_CURRENCY,
        ]);
    }

    /** Says what the reading managed, so a blank summary never reads as an empty document. */
    private function readingNote(VendorBillingDocument $document): string
    {
        if (in_array($document->ai_status, ['ok', 'partial'], true)) {
            return '';
        }

        return ' '.(VendorBillingDocument::aiNoteFor($document->ai_status) ?: 'The document was not read.');
    }

    /**
     * The Edit form's whole surface: the reading, and optionally a replacement document.
     *
     * Every figure the record carries is absent on purpose. Accepting them here would give
     * a crafted submit a way to set a total, a due date or a document number that no form
     * ever displayed — and there is no form left that displays them.
     *
     * `status` was the one exception until 2026-08-13 and is now gone with the rest. Whether
     * an invoice is Paid is derived from the payment slip filed against it, so accepting a
     * status here would let a submit assert a bill was settled with no document behind it —
     * on the one register whose entire value is provenance.
     */
    private function validateDocument(Request $request, Vendor $vendor): array
    {
        return $request->validate([
            'scan_token' => 'nullable|string|max:64',
            'ai_summary' => 'nullable|string|max:6000',
            'companies_involved' => 'nullable|string|max:2000',
        ]);
    }

    /**
     * Queue a background reading for a document nobody scanned.
     *
     * The ONE remaining caller is registerFromAssets(), which files an invoice copied off
     * an asset record — there is no modal, no operator watching, and nothing to review
     * before saving. Every document that arrives through the Add form is read inline
     * instead, because the whole point there is that its summary is on screen before the
     * record exists.
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
