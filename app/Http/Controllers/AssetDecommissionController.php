<?php

namespace App\Http\Controllers;

use App\Mail\EwasteFinalReportMail;
use App\Mail\EwasteQuotationApprovalMail;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Notifications\DecommissionNotification;
use App\Services\DecommissionReportRenderer;
use App\Services\EwasteDocumentOcrService;
use App\Services\EwasteSweepService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * E-WASTE ONLY.
 *
 * Vendor returns used to live here too, as a second `type` on AssetDecommissionBatch with a
 * tokenized public page the collector opened from an email. They no longer do: a rental
 * return is an Asset Acceptance & Return Form signed in-app by the collector standing at
 * our desk, so it is a RentalAssetAcknowledgement of type `return` and lives in
 * RentalAssetAcknowledgementController. The one thing this controller still shares with it
 * is the Decommissioning queue itself — `dispose_assets.decommission_type` is still
 * `vendor_return` for a returned asset, because that names the STAGING reason, not a batch.
 */
class AssetDecommissionController extends Controller
{
    // ── Authorization gates ──────────────────────────────────────────────────
    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageDecommission()) {
            abort(403, 'No permission to manage decommissioning.');
        }
    }

    private function authorizeFinance(): void
    {
        if (! Auth::user()->canApproveEwasteQuotation()) {
            abort(403, 'Only Finance may approve or reject e-waste quotations.');
        }
    }

    /** IT-side cycle detail. Finance may view it too. */
    public function show(AssetDecommissionBatch $batch)
    {
        $user = Auth::user();
        $canManage = $user->canManageDecommission();
        $financeViewingEwaste = $batch->isEwaste() && $user->canViewDecommissionReports();
        if (! $canManage && ! $financeViewingEwaste) {
            abort(403);
        }

        // `quotations` carries the re-quote loop (every offer + the Finance decision on it);
        // the timeline walks it rather than the batch's single-revision cache columns.
        $batch->load([
            'vendor', 'items.asset', 'creator', 'financeReviewer',
            'quotations.uploader', 'quotations.financeReviewer',
        ]);

        return view('it.decommission.show', [
            'batch' => $batch,
            'canManage' => $canManage,
        ]);
    }

    /** Cancel an in-flight cycle and release its assets back to the queue. */
    public function cancel(AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        if ($batch->isFinalized() || in_array($batch->status, ['completed', 'collected', 'cancelled'])) {
            return back()->with('error', 'This cycle can no longer be cancelled.');
        }

        // Release the assets/staging rows so they reappear in the Decommissioning tab.
        AssetInventory::where('decommission_batch_id', $batch->id)->update(['decommission_batch_id' => null]);
        DisposedAsset::where('decommission_batch_id', $batch->id)->update(['decommission_batch_id' => null]);
        $batch->update(['status' => 'cancelled']);

        return redirect()->route('assets.index', ['tab' => 'damaged'])
            ->with('success', "Cycle {$batch->batch_number} cancelled — its assets are back in the Decommissioning queue.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  The e-waste cycle (quarterly, finance-gated)
    // ═══════════════════════════════════════════════════════════════════════

    /** Throttled "Run sweep now" — synchronous, mirrors EmailWorkflowController::runNow. */
    public function runSweep(Request $request)
    {
        $this->authorizeManage();

        $result = EwasteSweepService::sweep(Auth::id());

        if (! $result['batch']) {
            return back()->with('info', $result['message']);
        }

        return redirect()->route('decommission.show', $result['batch'])->with('success', $result['message']);
    }

    /** Stage 2 — IT uploads the vendor's quotation (RM they will pay us). */
    public function uploadQuotation(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        if (! $batch->isEwaste() || ! in_array($batch->status, ['awaiting_quotation', 'finance_rejected'])) {
            return back()->with('error', 'A quotation cannot be uploaded for this cycle right now.');
        }

        // OCR on a multi-page PDF can run to the 45s client timeout — past PHP's 30s default.
        @set_time_limit(180);

        $request->validate([
            'quotation_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120|valid_file_content',
            'quotation_amount' => 'nullable|numeric|min:0.01|max:99999999.99',
        ]);

        $file = $request->file('quotation_file');
        $path = $file->store('ewaste_quotations/'.$batch->batch_number, 'local');

        // A re-quote is a new REVISION, not a replacement: the rejected offer and Finance's
        // reason for refusing it stay on their own row. addQuotationRevision() also re-points
        // the batch's cache columns, which is all every other screen reads.
        $revision = $batch->addQuotationRevision([
            'path' => $path,
            'amount' => $this->resolveAmount($request->input('quotation_amount'), $path, 'quotation'),
            'uploaded_at' => now(),
            'uploaded_by' => Auth::id(),
        ]);

        $isRequote = $revision->revision > 1;
        $rejected = $batch->lastRejectedQuotation();

        $this->notifyFinance(
            new EwasteQuotationApprovalMail($batch->fresh(['vendor', 'quotations.financeReviewer'])),
            new DecommissionNotification(
                event: 'ewaste.quotation_pending',
                batchNumber: $batch->batch_number,
                subject: $isRequote ? 'Revised e-waste quotation awaiting approval' : 'E-waste quotation awaiting approval',
                message: $isRequote
                    ? "Cycle {$batch->batch_number} has a REVISED quotation (revision {$revision->revision}) awaiting your approval — the offer you rejected is kept in the cycle log."
                    : "Cycle {$batch->batch_number} has a quotation awaiting your approval — the offer amount is in the attached quote.",
                url: route('accounting.fixed-assets.index', ['status' => 'disposed']),
                icon: 'bi-cash-coin',
                color: 'warning',
            )
        );

        return redirect()->route('decommission.show', $batch)
            ->with('success', $isRequote
                ? "Revised quotation uploaded as revision {$revision->revision} — the rejected revision "
                    .($rejected?->revision ?? $revision->revision - 1)
                    .' and its reason stay on the cycle log. Finance has been notified to approve it.'
                : 'Quotation uploaded — Finance has been notified to approve it.');
    }

    /** Stage 3 — Finance approves the quotation (mirrors eClaim hrApprove). */
    public function financeApprove(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeFinance();

        if (! $batch->isEwaste() || $batch->finance_status !== 'pending') {
            return back()->with('error', 'This quotation is not pending approval.');
        }

        $remarks = mb_substr(strip_tags((string) $request->input('remarks')), 0, 1000);

        // Stamped on the quotation revision under review AND the batch cache, so the log keeps
        // which offer was approved even after a later re-quote.
        $batch->recordFinanceDecision('approved', Auth::id(), $remarks ?: null);

        Log::info('E-waste quotation approved', [
            'batch' => $batch->batch_number,
            'revision' => $batch->currentQuotation()?->revision,
            'actor_id' => Auth::id(),
        ]);

        EwasteSweepService::notifyIt(new DecommissionNotification(
            event: 'ewaste.quotation_approved',
            batchNumber: $batch->batch_number,
            subject: 'E-waste quotation approved',
            message: "Finance approved the quotation for {$batch->batch_number}. Proceed with collection, then upload the payment receipt.",
            url: route('decommission.show', $batch),
            icon: 'bi-check-circle',
            color: 'success',
        ));

        return redirect()->route('accounting.fixed-assets.index', ['status' => 'disposed'])->with('success', "Quotation for {$batch->batch_number} approved.");
    }

    /** Stage 3 — Finance rejects the quotation (reason required). */
    public function financeReject(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeFinance();

        if (! $batch->isEwaste() || $batch->finance_status !== 'pending') {
            return back()->with('error', 'This quotation is not pending approval.');
        }

        $request->validate(['remarks' => 'required|string|max:1000']);
        $remarks = mb_substr(strip_tags((string) $request->input('remarks')), 0, 1000);

        // The rejection is recorded ON the rejected revision, so IT's re-quote cannot erase it.
        $batch->recordFinanceDecision('rejected', Auth::id(), $remarks);

        EwasteSweepService::notifyIt(new DecommissionNotification(
            event: 'ewaste.quotation_rejected',
            batchNumber: $batch->batch_number,
            subject: 'E-waste quotation rejected',
            message: "Finance rejected the quotation for {$batch->batch_number}: {$remarks}. Re-quote or cancel the cycle.",
            url: route('decommission.show', $batch),
            icon: 'bi-x-circle',
            color: 'danger',
        ));

        return redirect()->route('accounting.fixed-assets.index', ['status' => 'disposed'])->with('success', "Quotation for {$batch->batch_number} rejected — IT notified.");
    }

    /** Stage 4 — IT uploads the vendor's payment receipt (only after finance approval). */
    public function uploadReceipt(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        if (! $batch->isEwaste() || $batch->finance_status !== 'approved') {
            return back()->with('error', 'A receipt can only be uploaded after Finance has approved the quotation.');
        }

        // This one request chains the most expensive work in the module: an OCR call (up to 45s),
        // then finalization — which renders the merged report and sends Finance a multi-MB
        // attachment over SMTP. Past PHP's 30s default the request dies mid-finalize and strands
        // the batch at `collected`, needing the manual "Finalize cycle" fallback.
        @set_time_limit(300);

        $request->validate([
            'receipt_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120|valid_file_content',
            'receipt_amount' => 'nullable|numeric|min:0.01|max:99999999.99',
        ]);

        $path = $request->file('receipt_file')->store('ewaste_receipts/'.$batch->batch_number, 'local');

        $batch->update([
            'receipt_path' => $path,
            'receipt_amount' => $this->resolveAmount($request->input('receipt_amount'), $path, 'receipt'),
            'receipt_uploaded_at' => now(),
            'receipt_uploaded_by' => Auth::id(),
            'status' => 'collected',
        ]);

        // Auto-complete: uploading the receipt is the final step — no manual button.
        // Archive the assets, render + store the report PDF, email Finance the final report.
        try {
            $this->finalizeEwasteCycle($batch->fresh(['vendor', 'items.asset']));
        } catch (\Throwable $e) {
            Log::error('E-waste auto-finalize failed for '.$batch->batch_number.': '.$e->getMessage());

            // The batch stays at "collected"; the fallback "Finalize cycle" button appears
            // on the detail page so IT can retry without re-uploading.
            return redirect()->route('decommission.show', $batch)
                ->with('error', 'Receipt uploaded, but finalizing the cycle failed — please click "Finalize cycle" to retry.');
        }

        return redirect()->route('reports.decommission')
            ->with('success', "Receipt uploaded — cycle {$batch->batch_number} completed, assets archived, and the final report sent to Finance.");
    }

    /**
     * Manual fallback for the auto-finalize on receipt upload — the only such fallback left
     * in the module now the vendor-return arm is gone. Only reachable when a batch is stuck at "collected" because the
     * automatic finalize threw — the normal flow completes without any button.
     */
    public function completeCycle(AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        if (! $batch->isEwaste() || $batch->status !== 'collected') {
            return back()->with('error', 'This cycle is not ready to complete.');
        }

        $this->finalizeEwasteCycle($batch->fresh(['vendor', 'items.asset']));

        return redirect()->route('reports.decommission')
            ->with('success', "Cycle {$batch->batch_number} completed — assets archived and the final report sent to Finance.");
    }

    /**
     * Finalize an e-waste cycle: soft-archive the assets, render + store the report PDF,
     * email Finance the final report. Idempotent — a re-run on a finalized batch is a no-op.
     */
    private function finalizeEwasteCycle(AssetDecommissionBatch $batch): void
    {
        if ($batch->isFinalized()) {
            return;
        }

        // Archive the collected assets out of every inventory view.
        AssetInventory::where('decommission_batch_id', $batch->id)
            ->whereNull('decommissioned_at')
            ->update(['decommissioned_at' => now()]);

        $path = $this->storeBatchReportPdf($batch);
        $batch->update([
            'report_pdf_path' => $path,
            'status' => 'completed',
            'finalized_at' => now(),
        ]);

        // Final report (assets + quotation + receipt) to Finance:
        // TO the Finance Manager(s), CC the Finance Executive(s) — work email only.
        EwasteSweepService::mailFinance(new EwasteFinalReportMail($batch->fresh(['vendor', 'items.asset'])));
    }

    /**
     * The e-waste quotations awaiting a Finance decision. There is no standalone screen for
     * these any more — Accounting → Assets → status "Disposed" renders them inline, so this
     * is the shared query behind that page (see Accounting\FixedAssetController::index).
     */
    public static function pendingQuotationsQuery()
    {
        // `quotations` is eager-loaded so the row can say "this is revision 2, you rejected
        // revision 1 because …" — a reviewer looking at a second quote needs to know why
        // without opening the cycle. Handful of rows per batch, so the cost is negligible.
        return AssetDecommissionBatch::where('type', AssetDecommissionBatch::TYPE_EWASTE)
            ->where('finance_status', 'pending')
            ->with(['vendor', 'quotations.financeReviewer'])
            ->withCount('items')
            ->latest();
    }

    /**
     * Correct the amount read off a quotation/receipt.
     *
     * OCR pre-fills, a human owns the number: the figure feeds the Finance report, so it must
     * always be fixable without re-uploading the document. Blank clears it back to "see the
     * attached document" rather than storing 0.00.
     */
    public function updateAmount(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        if (! $batch->isEwaste()) {
            return back()->with('error', 'Amounts only apply to e-waste cycles.');
        }

        $data = $request->validate([
            'field' => 'required|in:quotation,receipt',
            'amount' => 'nullable|numeric|min:0.01|max:99999999.99',
        ]);

        $column = $data['field'].'_amount';
        $amount = $data['amount'] === null || $data['amount'] === '' ? null : round((float) $data['amount'], 2);

        // A quotation amount belongs to a REVISION as well as the cache — correcting only the
        // cache would leave the cycle log quoting a figure the report contradicts.
        $data['field'] === 'quotation'
            ? $batch->setQuotationAmount($amount)
            : $batch->update(['receipt_amount' => $amount]);

        Log::info('E-waste amount corrected', [
            'batch' => $batch->batch_number, 'field' => $column,
            'amount' => $amount, 'actor_id' => Auth::id(),
        ]);

        return back()->with('success', $amount === null
            ? ucfirst($data['field']).' amount cleared — the report will point at the attached document.'
            : ucfirst($data['field']).' amount updated to RM '.number_format($amount, 2).'.');
    }

    // ── Shared helpers ────────────────────────────────────────────────────────
    /**
     * The amount to store: what the uploader typed, else whatever OCR can read off the
     * document. Never throws and never blocks the upload — a null simply means the report
     * points at the reproduced document instead of stating a figure.
     */
    private function resolveAmount($submitted, string $storedPath, string $kind): ?float
    {
        if ($submitted !== null && $submitted !== '') {
            return round((float) $submitted, 2);
        }

        try {
            return EwasteDocumentOcrService::readAmount(
                Storage::disk('local')->path($storedPath),
                (string) Storage::disk('local')->mimeType($storedPath),
                $kind
            );
        } catch (\Throwable $e) {
            Log::warning('E-waste amount OCR failed', ['kind' => $kind, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Render the batch report PDF and store it privately; returns the storage path. */
    private function storeBatchReportPdf(AssetDecommissionBatch $batch): string
    {
        $path = 'decommission_reports/'.$batch->batch_number.'.pdf';
        Storage::disk('local')->put($path, DecommissionReportRenderer::render($batch));

        return $path;
    }

    /** Email + bell every finance recipient. */
    private function notifyFinance($mailable, DecommissionNotification $notification): void
    {
        // Email: TO the Finance Manager(s), CC the Finance Executive(s) — work email only.
        EwasteSweepService::mailFinance($mailable);

        // In-app bell still reaches every finance recipient (managers, executives, superadmin).
        $financeUsers = User::query()->financeRole()->where('is_active', true)->get();
        if ($financeUsers->isNotEmpty()) {
            \Illuminate\Support\Facades\Notification::send($financeUsers, $notification);
        }
    }
}
