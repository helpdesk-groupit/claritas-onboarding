<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorDocumentScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Contracts held with a vendor, on the vendor profile page.
 *
 * The document is the record, and as of 2026-08-13 it is also what fills the record in.
 * Adding a contract is: upload it, let it be read (VendorDocumentScanController), correct
 * the SUMMARY the reading produced, save. The dates, references and figures come off the
 * same reading and are never asked for on the Add form — they are shown and editable here
 * only on Edit, where the operator is looking at a record rather than filing one.
 *
 * The 2026-08-11 rule this replaced said not to reintroduce a scan-into-fields path. Its
 * REASON is intact and must stay intact: the fields do not ride inside the transcription
 * reply, where a max_tokens truncation would destroy them. They come from a separate small
 * call over the transcript. Fold the two back into one JSON reply and a long contract
 * starts losing its dates.
 *
 * Every AI-supplied value here is a suggestion that a person saw before it was stored.
 * Nothing on this path is written from a reading the operator was not shown.
 */
class VendorContractController extends Controller
{
    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageVendors()) {
            abort(403, 'No permission to manage vendors.');
        }
    }

    /**
     * File a scanned contract.
     *
     * Requires a completed scan: the Add form is an upload form now, and a contract with no
     * document has no summary, no parties and no dates — nothing the listing is built to
     * show. A contract we hold no copy of is recorded by uploading whatever copy exists.
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

        $fields = is_array($scan->fields) ? $scan->fields : [];

        $contract = new VendorContract($this->fieldsFromScan($fields, $scan));
        $contract->vendor_id = $vendor->id;
        $contract->file_path = $scan->file_path;
        $contract->file_hash = VendorContract::hashStoredFile($scan->file_path);
        $contract->original_filename = $scan->original_filename;
        $contract->created_by = Auth::id();

        // The reading is carried across whole rather than re-run: it has already been paid
        // for, and re-reading would replace what the operator just reviewed with a second
        // opinion they never saw.
        $contract->forceFill([
            'ai_status' => $scan->status,
            'ai_key_points' => $scan->key_points ?: null,
            'ai_text' => $scan->text,
            'ai_at' => now(),
        ]);
        $contract->ai_summary = $scan->summary;
        $contract->companies_involved = VendorContract::parseCompaniesInput($data['companies_involved'] ?? null) ?: null;

        // Stamped only if the operator actually changed the wording, so an untouched
        // summary keeps reading as the machine's and a corrected one carries its author.
        $contract->applySummaryEdit($data['ai_summary'] ?? null, Auth::id());

        $contract->save();

        // The row now owns the file; the staging row must go WITHOUT deleting it.
        $scan->delete();

        return $this->back($vendor)->with('success', 'Contract added from the scanned document.'.$this->readingNote($contract));
    }

    /**
     * Correct the READING of a filed contract — its summary and its parties — or replace
     * the document behind it.
     *
     * There is deliberately nothing else here. The terms are read off the document and
     * stored, never typed: the operator's instruction is that the scan produces the summary
     * and the form asks for no fields, on Add or on Edit. A term the scan read wrong is
     * fixed by re-reading or replacing the document, which is why both controls sit in this
     * same modal.
     */
    public function update(Request $request, Vendor $vendor, VendorContract $contract)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $contract);
        $this->assertEditableHere($contract);

        $data = $this->validateContract($request);

        $scan = filled($data['scan_token'] ?? null)
            ? $this->claimScan($vendor, $data['scan_token'])
            : null;

        if (filled($data['scan_token'] ?? null) && ! $scan) {
            return $this->back($vendor)->with('error',
                'The replacement upload is no longer available — it may have expired. Nothing was changed; please attach it again.');
        }

        if ($scan) {
            // The old file goes only once the new one is safely in place, so a failure never
            // leaves the record pointing at nothing.
            $this->deleteFile($contract->file_path);

            $contract->file_path = $scan->file_path;
            $contract->file_hash = VendorContract::hashStoredFile($scan->file_path);
            $contract->original_filename = $scan->original_filename;

            // Clears the reading of the file just deleted — including its parties and any
            // edit stamp — in the SAME write that repoints the record, then takes the new
            // document's reading as reviewed in the modal.
            $contract->resetDocumentInsight();
            $contract->forceFill([
                'ai_status' => $scan->status,
                'ai_key_points' => $scan->key_points ?: null,
                'ai_text' => $scan->text,
                'ai_at' => now(),
            ]);
            $contract->ai_summary = $scan->summary;
        }

        $contract->companies_involved = VendorContract::parseCompaniesInput($data['companies_involved'] ?? null) ?: null;
        $contract->applySummaryEdit($data['ai_summary'] ?? null, Auth::id());

        $contract->save();

        if ($scan) {
            $scan->delete();
        }

        return $this->back($vendor)->with('success', 'Contract updated.'.($scan ? $this->readingNote($contract) : ''));
    }

    public function destroy(Vendor $vendor, VendorContract $contract)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $contract);
        $this->assertEditableHere($contract);

        $this->deleteFile($contract->file_path);
        $contract->delete();

        return $this->back($vendor)->with('success', 'Contract removed.');
    }

    // ── Internals ─────────────────────────────────────────────────────────────
    private function assertBelongs(Vendor $vendor, VendorContract $contract): void
    {
        // Both ids come from the URL, so without this a contract could be edited through
        // any vendor's route and would then redirect to — and appear under — the wrong one.
        abort_unless($contract->vendor_id === $vendor->id, 404);
    }

    /**
     * A quotation filed automatically from a disposal cycle is not editable here.
     *
     * The tab hides its Edit and Delete controls, but a hidden button is a courtesy and not a
     * rule: the document is evidence of what a vendor offered on a disposal that may already
     * have been approved on the strength of it, and its figure and state are the cycle's.
     * Correcting either is done on the cycle, which is what the row links to.
     */
    private function assertEditableHere(VendorContract $contract): void
    {
        abort_if($contract->isEwasteQuotation(), 403,
            'This quotation was filed from a disposal cycle — correct it on the cycle, not on the vendor record.');
    }

    private function back(Vendor $vendor)
    {
        return redirect()->route('vendors.show', [$vendor, 'tab' => 'contracts']);
    }

    /**
     * This operator's completed scan for this vendor, of the right kind.
     *
     * Scoped by vendor, uploader AND kind: the token arrives in the request, so without all
     * three a contract could be filed from a billing upload — putting an invoice's due date
     * into a contract's term — or from a file somebody else uploaded.
     */
    private function claimScan(Vendor $vendor, string $token): ?VendorDocumentScan
    {
        return VendorDocumentScan::where('vendor_id', $vendor->id)
            ->where('user_id', Auth::id())
            ->where('kind', VendorDocumentScan::KIND_CONTRACT)
            ->where('token', $token)
            ->first();
    }

    /**
     * The record values the reading produced, with the two the column layer insists on.
     *
     * `title` is NOT NULL and is no longer typed, so it always resolves to something a
     * human can recognise the row by — what the document calls itself, else the filename
     * they chose, else a stated placeholder. `status` seeds the lifecycle only; what the
     * listing shows is derived from the dates by stateBadge(), which is why an expired
     * contract cannot be made to look current by this default.
     */
    private function fieldsFromScan(array $fields, VendorDocumentScan $scan): array
    {
        $title = $fields['title']
            ?? ($scan->original_filename ? pathinfo($scan->original_filename, PATHINFO_FILENAME) : null);

        return array_merge($fields, [
            'title' => mb_substr(trim((string) $title) ?: 'Untitled contract', 0, 255),
            'status' => 'active',
            'currency' => $fields['currency'] ?? VendorContract::DEFAULT_CURRENCY,
        ]);
    }

    /**
     * The Edit form's whole surface: the reading, and optionally a replacement document.
     *
     * Every term the record carries is absent on purpose. Accepting them here would give a
     * crafted submit a way to set a contract value, a term or a status that no form ever
     * displayed — and there is no form left that displays them.
     */
    private function validateContract(Request $request): array
    {
        return $request->validate([
            'scan_token' => 'nullable|string|max:64',
            'ai_summary' => 'nullable|string|max:6000',
            'companies_involved' => 'nullable|string|max:2000',
        ]);
    }

    /** Says what the reading managed, so a blank summary never reads as an empty document. */
    private function readingNote(VendorContract $contract): string
    {
        if (in_array($contract->ai_status, ['ok', 'partial'], true)) {
            return '';
        }

        return ' '.(VendorContract::aiNoteFor($contract->ai_status) ?: 'The document was not read.');
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
