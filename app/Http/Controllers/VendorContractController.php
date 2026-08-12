<?php

namespace App\Http\Controllers;

use App\Jobs\SummariseVendorDocument;
use App\Models\Vendor;
use App\Models\VendorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Contracts held with a vendor, on the vendor profile page.
 *
 * The uploaded document is the record; the stored fields are a human's transcription of
 * it, typed by hand. Saving is therefore a plain upload — store the file, write the fields
 * as submitted, nothing else.
 *
 * The per-field OCR that used to pre-fill this form was REMOVED on 2026-08-11 by operator
 * decision: the only wanted reading of a vendor document is the whole-document summary,
 * which is a separate, queued pass (`SummariseVendorDocument`) whose output lives on the
 * row and feeds the Ask AI tab. Don't reintroduce a scan-into-fields path here — the two
 * readings were deliberately never merged, and the summary half does not depend on it.
 */
class VendorContractController extends Controller
{
    private const DIR = 'vendor_contracts';

    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageVendors()) {
            abort(403, 'No permission to manage vendors.');
        }
    }

    public function store(Request $request, Vendor $vendor)
    {
        $this->authorizeManage();

        $data = $this->validateContract($request, fileRequired: false);
        $doc = $this->resolveDocument($request, $vendor, $data);

        $contract = new VendorContract($doc['data']);
        $contract->vendor_id = $vendor->id;
        $contract->file_path = $doc['path'];
        $contract->original_filename = $doc['name'];
        $contract->created_by = Auth::id();
        if ($doc['path']) {
            $contract->resetDocumentInsight();
        }
        $contract->save();

        $summary = $this->queueSummary($contract, (bool) $doc['path']);

        return redirect()->route('vendors.show', [$vendor, 'tab' => 'contracts'])
            ->with('success', 'Contract added.'.$summary);
    }

    public function update(Request $request, Vendor $vendor, VendorContract $contract)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $contract);

        $data = $this->validateContract($request, fileRequired: false);
        $doc = $this->resolveDocument($request, $vendor, $data);

        // The old file is removed only after the new one is safely in place, so a failed
        // upload never leaves the record pointing at nothing.
        if ($doc['replaced']) {
            $this->deleteFile($contract->file_path);

            $contract->file_path = $doc['path'];
            $contract->original_filename = $doc['name'];
            // The stored summary and transcription describe the file just deleted. Cleared
            // in the SAME write that points the record at the new one — leaving them would
            // show the old PDF's summary under the new name and, worse, have the assistant
            // answer questions about this contract out of a document that is gone.
            $contract->resetDocumentInsight();
        }

        $contract->fill($doc['data'])->save();

        $summary = $this->queueSummary($contract, $doc['replaced']);

        return redirect()->route('vendors.show', [$vendor, 'tab' => 'contracts'])
            ->with('success', 'Contract updated.'.$summary);
    }

    public function destroy(Vendor $vendor, VendorContract $contract)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $contract);

        $this->deleteFile($contract->file_path);
        $contract->delete();

        return redirect()->route('vendors.show', [$vendor, 'tab' => 'contracts'])
            ->with('success', 'Contract removed.');
    }

    // ── Internals ─────────────────────────────────────────────────────────────
    private function assertBelongs(Vendor $vendor, VendorContract $contract): void
    {
        // Both ids come from the URL, so without this a contract could be edited through
        // any vendor's route and would then redirect to — and appear under — the wrong one.
        abort_unless($contract->vendor_id === $vendor->id, 404);
    }

    private function validateContract(Request $request, bool $fileRequired): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'contract_reference' => 'nullable|string|max:255',
            'contract_type' => ['nullable', 'string', Rule::in(array_keys(VendorContract::TYPES))],
            'status' => ['required', 'string', Rule::in(array_keys(VendorContract::STATUSES))],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'auto_renew' => 'nullable|boolean',
            'notice_period_days' => 'nullable|integer|min:0|max:3650',
            'contract_value' => 'nullable|numeric|min:0|max:999999999.99',
            'currency' => 'nullable|string|size:3',
            'billing_cycle' => ['nullable', 'string', Rule::in(array_keys(VendorContract::BILLING_CYCLES))],
            'payment_terms' => 'nullable|string|max:255',
            'scope_summary' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'document' => ($fileRequired ? 'required' : 'nullable').'|file|mimes:pdf,jpg,jpeg,png|max:10240|valid_file_content',
        ]);

        // Both columns are NOT NULL with a DB default, but a CLEARED text input arrives as
        // '' which ConvertEmptyStringsToNull turns into null — and an explicit null defeats
        // a column default, so the write dies on an integrity violation instead of falling
        // back. Both forms now post auto_renew's hidden "0" companion, but the coercion
        // stays: array_key_exists still has to distinguish absent from an explicit null.
        $data['currency'] = $data['currency'] ?? VendorContract::DEFAULT_CURRENCY;
        if (array_key_exists('auto_renew', $data) && $data['auto_renew'] === null) {
            $data['auto_renew'] = false;
        }

        return $data;
    }

    /** @return array{0:string,1:string} stored path, original filename */
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
     * `replaced` is what drives both the old file's deletion and the re-reading of the
     * summary, so it must mean "the record now points at a DIFFERENT file" and nothing
     * looser — a save that only edits the typed fields must not throw away the reading of
     * a document that has not changed.
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
     * Queue the summary + transcription pass, and say so on the way out.
     *
     * Deliberately out of band: transcribing a long PDF inside the save request is what
     * pushes it past the edge timeout on live. The flash says it is happening because the
     * row comes back showing no summary, which otherwise reads as a document that had
     * nothing in it.
     */
    private function queueSummary(VendorContract $contract, bool $documentChanged): string
    {
        if (! $documentChanged || blank($contract->file_path)) {
            return '';
        }

        SummariseVendorDocument::dispatchFor($contract);

        return ' The document is being read for its summary — it appears on this row shortly.';
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
