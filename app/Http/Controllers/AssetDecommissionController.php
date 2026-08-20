<?php

namespace App\Http\Controllers;

use App\Mail\EwasteAwardMail;
use App\Mail\EwasteFinalReportMail;
use App\Mail\EwasteManagementApprovalMail;
use App\Mail\EwasteQuotationApprovalMail;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetDecommissionQuotation;
use App\Models\AssetInventory;
use App\Models\Company;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\DecommissionNotification;
use App\Services\DecommissionReportRenderer;
use App\Services\EwasteDocumentOcrService;
use App\Services\EwasteQuotationComparisonService;
use App\Services\EwasteQuotationFilingService;
use App\Services\EwasteSweepService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

    /**
     * IT's working page for one cycle. Finance and management may READ it; neither decides here.
     *
     * The management approve/reject controls lived on this page until 2026-08-14 and were moved
     * to Management → Decommissioning, which is now the single review surface for both Finance
     * and management. This page is IT's: gather the offers, recommend one, upload the receipt.
     * Do not put a decision control back on it — two places to approve one disposal is the
     * arrangement that move was undoing.
     */
    public function show(AssetDecommissionBatch $batch)
    {
        $user = Auth::user();
        $canManage = $user->canManageDecommission();
        // A named approver still reaches the page to READ the cycle their decision concerns —
        // they may hold none of the other decommission permissions, since a CEO is not IT and
        // not Finance. They decide on the Decommissioning page.
        $canRead = $batch->isEwaste()
            && ($user->canViewDecommissionReports() || $user->canApproveEwasteAsManagement($batch->company));
        if (! $canManage && ! $canRead) {
            abort(403);
        }

        // `quotations` carries every vendor's offers and the decision made on each; the
        // comparison and the timeline both walk it rather than the batch's cache columns.
        $batch->load([
            'vendor', 'items.asset', 'creator', 'financeReviewer', 'managementReviewer',
            'quotations.uploader', 'quotations.financeReviewer', 'quotations.vendor',
            'recommendedQuotation.vendor', 'selectedQuotation.vendor',
        ]);

        return view('it.decommission.show', [
            'batch' => $batch,
            'canManage' => $canManage,
            // Always false: the decision moved to the Decommissioning page. Passed rather than
            // dropped because the comparison partial is shared with that page, where it is
            // per-company true.
            'canDecide' => false,
            // Who may be named as the sender of a filed quotation — the same set the RFQ went to.
            'ewasteVendors' => Vendor::where('is_active', true)
                ->whereJsonContains('vendor_types', 'ewaste')
                ->orderBy('name')->get(['id', 'name']),
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
    //  Phase 2 — Inspection
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Record an inspection against a queued e-waste asset: its completeness, the parts taken
     * off it, and the company that owns it.
     *
     * A separate act from marking the asset Not Good, deliberately. The completeness is what
     * the vendor prices against, so it has to be decided by someone who physically opened the
     * machine — and the quarterly cycle refuses to run while anything in the queue is still
     * unexamined, which only means something if "unexamined" is a state the data can hold.
     *
     * Re-inspectable until the asset is swept into a cycle: a correction before the RFQ costs
     * nothing, and afterwards the vendor has already quoted against what was recorded.
     */
    public function inspect(Request $request, DisposedAsset $disposedAsset)
    {
        $this->authorizeManage();

        // A vendor return is not inspected here — the collector examines it and signs the
        // return AARF, which is the document that records its condition.
        if (! $disposedAsset->isEwaste()) {
            return back()->with('error', 'Only assets staged for e-waste are inspected here — a returned rental asset is examined on its return form.');
        }

        if ($disposedAsset->decommission_batch_id) {
            return back()->with('error', 'This asset is already in cycle '.($disposedAsset->batch?->batch_number ?? 'a collection cycle').' — the vendor has been asked to quote against what was recorded, so its inspection can no longer be changed.');
        }

        $companies = Company::orderBy('name')->pluck('name');
        if ($companies->isEmpty()) {
            return back()->with('error', 'No companies are registered, so the owning company cannot be confirmed. Register the company first.');
        }

        $data = $request->validate([
            'ewaste_completeness' => ['required', Rule::in(array_keys(DisposedAsset::COMPLETENESS))],
            // Recording WHICH parts came off is the whole substance of an "Incomplete"
            // verdict — without it the vendor is told the machine is short of something,
            // but not what, and cannot price it.
            'ewaste_parts_removed' => ['nullable', 'string', 'max:500', Rule::requiredIf(
                fn () => $request->input('ewaste_completeness') === 'incomplete'
            )],
            // Confirmed against the registered companies, never the asset's own free-text
            // company_name: from Phase 4 this decides which management approver may authorise
            // the disposal, and a fuzzy match is not a good enough basis for that.
            'company' => ['required', 'string', Rule::in($companies->all())],
            // Rows staged before the reason became mandatory have none, and the queue is
            // where that gap is closed — required only for those, so an inspection of a
            // properly-marked asset is not made to re-type what is already on file.
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(
                fn () => blank($disposedAsset->reason)
            )],
        ], [
            'ewaste_parts_removed.required' => 'List the parts removed — an "Incomplete" asset the vendor cannot itemise cannot be priced.',
            'company.required' => 'Confirm which company owns this asset.',
            'company.in' => 'Pick one of the registered companies — the owner decides who approves the disposal.',
            'reason.required' => 'This asset was queued before a reason was required. State why it is being written off.',
        ]);

        $parts = $data['ewaste_completeness'] === 'incomplete'
            ? (trim((string) ($data['ewaste_parts_removed'] ?? '')) ?: null)
            : null;   // a "Complete" verdict must not keep a parts list from an earlier one

        $disposedAsset->update([
            'ewaste_completeness' => $data['ewaste_completeness'],
            'ewaste_parts_removed' => $parts,
            'company' => $data['company'],
            'inspected_at' => now(),
            'inspected_by' => Auth::id(),
            'reason' => filled($data['reason'] ?? null) ? $data['reason'] : $disposedAsset->reason,
        ]);

        $actor = Auth::user();
        $verdict = DisposedAsset::COMPLETENESS[$data['ewaste_completeness']];
        $note = $parts ? "{$verdict} — parts removed: {$parts}" : $verdict;
        $disposedAsset->asset?->appendRemark(
            "E-waste inspection: {$note}. Owner confirmed as {$data['company']}. Inspected by ".($actor->name ?? 'IT').'.'
        );

        return back()->with('success', "{$disposedAsset->asset_tag} inspected — {$note}.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  The e-waste cycle (quarterly, finance-gated)
    // ═══════════════════════════════════════════════════════════════════════

    /** Throttled "Run sweep now" — synchronous, mirrors EmailWorkflowController::runNow. */
    public function runSweep(Request $request)
    {
        $this->authorizeManage();

        $result = EwasteSweepService::sweep(Auth::id());

        // "Run sweep now" bypasses the quarterly DATE gate only — never the inspection gate.
        // Letting it through here would make the gate decorative: the one button an operator
        // reaches for when the cycle did not run is this one.
        if ($result['blocked']) {
            return back()->with('error', $result['message']);
        }

        if ($result['batches']->isEmpty()) {
            return back()->with('info', $result['message']);
        }

        // A sweep now produces one cycle per company. Land on it when there is only one;
        // otherwise go back to the queue, where all of them are listed.
        if ($result['batches']->count() === 1) {
            return redirect()->route('decommission.show', $result['batches']->first())
                ->with('success', $result['message']);
        }

        return redirect()->route('assets.index', ['tab' => 'damaged'])->with('success', $result['message']);
    }

    /** Stage 2 — IT uploads the vendor's quotation (RM they will pay us). */
    public function uploadQuotation(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        // Quotations are collected while the cycle is gathering them, and again after a
        // rejection sends everyone back for revised offers. Not while a decision is pending —
        // an offer arriving mid-review would change the comparison under the approvers.
        if (! $batch->isEwaste() || ! in_array($batch->status, ['awaiting_quotation', 'quotation_uploaded', 'rejected', 'finance_rejected'])) {
            return back()->with('error', 'A quotation cannot be uploaded for this cycle right now.');
        }

        // OCR on a multi-page PDF can run to the 45s client timeout — past PHP's 30s default.
        @set_time_limit(180);

        $request->validate([
            'quotation_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120|valid_file_content',
            'quotation_amount' => 'nullable|numeric|min:0.01|max:99999999.99',
            // WHOSE offer this is. Required: the cycle RFQs every active e-waste vendor, so a
            // document filed against no vendor cannot be compared, cannot be attributed in the
            // report, and would leave the winner's PIC unidentifiable at collection.
            'vendor_id' => ['required', Rule::exists('vendors', 'id')],
        ], [
            'vendor_id.required' => 'Say which vendor sent this quotation — the offers are compared against each other.',
        ]);

        $file = $request->file('quotation_file');
        $path = $file->store('ewaste_quotations/'.$batch->batch_number, 'local');

        // A re-quote is a new REVISION, not a replacement: the rejected offer and the reason it
        // was refused stay on their own row. Revisions run per vendor.
        $revision = $batch->addQuotationRevision([
            'vendor_id' => (int) $request->input('vendor_id'),
            'path' => $path,
            'amount' => $this->resolveAmount($request->input('quotation_amount'), $path, 'quotation'),
            'uploaded_at' => now(),
            'uploaded_by' => Auth::id(),
        ]);

        // Uploading no longer notifies anybody: nothing is under review until IT has the offers
        // in and submits the comparison. Telling Finance on every upload would ask them to
        // decide on a field of one, repeatedly.
        $vendorName = $revision->vendorName();
        $note = $revision->revision > 1
            ? "Revised quotation from {$vendorName} filed as their revision {$revision->revision} — the offer they replaced stays on the cycle log."
            : "Quotation from {$vendorName} filed.";

        return redirect()->route('decommission.show', $batch)
            ->with('success', $note.$this->fileToVendorRecord($revision, $file->getClientOriginalName())
                .' Submit the comparison for approval once every vendor has replied.');
    }

    /**
     * Undo an upload mistake — remove a quotation before the comparison has ever been
     * submitted for approval. Not a way to withdraw an offer that has already been submitted,
     * recommended or decided on; see AssetDecommissionQuotation::isDeletable().
     */
    public function deleteQuotation(AssetDecommissionBatch $batch, AssetDecommissionQuotation $quotation)
    {
        $this->authorizeManage();
        $this->assertQuotationBelongs($batch, $quotation);

        if (! $quotation->isDeletable()) {
            return back()->with('error', 'This quotation can no longer be deleted — it has already been submitted or reviewed. Upload a revised offer instead.');
        }

        $vendorName = $quotation->vendorName();
        $path = $quotation->path;
        $revertedToAwaitingQuotation = false;

        DB::transaction(function () use ($batch, $quotation, &$revertedToAwaitingQuotation) {
            // A suggestion the AI made off this document no longer applies once it's gone.
            if ($batch->ai_recommended_quotation_id === $quotation->id) {
                $batch->update([
                    'ai_recommended_quotation_id' => null,
                    'ai_recommendation_note' => null,
                    'ai_recommended_at' => null,
                    'ai_compare_status' => null,
                ]);
            }

            $quotation->delete();
            $batch->unsetRelation('quotations');

            // No quotations left at all means the cycle is back to gathering offers — leaving
            // it at "quotation_uploaded" would assert an offer is on file when none is.
            if ($batch->status === 'quotation_uploaded' && $batch->quotations()->doesntExist()) {
                $batch->update(['status' => 'awaiting_quotation']);
                $revertedToAwaitingQuotation = true;
            }
        });

        // The ORIGINAL upload only — the copy filed on the vendor's Contracts tab is left in
        // place (see the nullOnDelete note on vendor_contracts.asset_decommission_quotation_id):
        // it is still a real document the vendor sent, even though the cycle no longer holds it.
        if ($path) {
            Storage::disk('local')->delete($path);
        }

        Log::info('E-waste quotation deleted', [
            'batch' => $batch->batch_number, 'vendor' => $vendorName, 'actor_id' => Auth::id(),
        ]);

        return redirect()->route('decommission.show', $batch)->with('success',
            "Quotation from {$vendorName} deleted."
            .($revertedToAwaitingQuotation ? ' No quotations remain — the cycle is back to awaiting offers.' : ''));
    }

    /**
     * IT put the collected offers up for approval, naming the one they recommend.
     *
     * This is the step that makes the cycle reviewable. Before it, quotations are still coming
     * in — showing an approver a half-collected comparison invites a decision on a field of one,
     * which is exactly what asking several vendors was meant to avoid.
     */
    public function submitForApproval(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        if (! $batch->isEwaste() || ! in_array($batch->status, ['quotation_uploaded', 'rejected', 'finance_rejected'])) {
            return back()->with('error', 'This cycle has no quotations waiting to be submitted.');
        }

        // Every offer needs a figure or there is nothing to compare them ON, and the
        // recommendation would be an assertion nobody can check. OCR fills these where it can;
        // the rest are typed on the cycle page.
        if (! $batch->everyQuotationHasAnAmount()) {
            return back()->with('error', 'Every quotation needs an amount before the comparison can be submitted — fill in the missing figures on this page.');
        }

        $data = $request->validate([
            'recommended_quotation_id' => [
                'required',
                Rule::exists('asset_decommission_quotations', 'id')
                    ->where('asset_decommission_batch_id', $batch->id),
            ],
            'recommendation_note' => 'nullable|string|max:1000',
        ], [
            'recommended_quotation_id.required' => 'Pick the quotation you are recommending.',
            'recommended_quotation_id.exists' => 'That quotation does not belong to this cycle.',
        ]);

        $recommended = $batch->quotations()->findOrFail($data['recommended_quotation_id']);
        $batch->submitForApproval($recommended, $data['recommendation_note'] ?? null, Auth::id());

        // Finance AND management are asked at the same time; either may answer first, and only
        // management's answer moves the cycle.
        $fresh = $batch->fresh(['vendor', 'quotations.vendor', 'quotations.financeReviewer', 'recommendedQuotation.vendor']);

        $this->notifyFinance(
            new EwasteQuotationApprovalMail($fresh),
            new DecommissionNotification(
                event: 'ewaste.quotation_pending',
                batchNumber: $batch->batch_number,
                subject: 'E-waste quotation comparison awaiting your review',
                message: "Cycle {$batch->batch_number} has ".$batch->quotationsForComparison()->count()
                    .' vendor quotation(s) for review. Your position is recorded alongside management\'s, who authorise the disposal.',
                url: route('reports.decommission'),
                icon: 'bi-cash-coin',
                color: 'warning',
            )
        );

        $this->notifyManagement($batch, $fresh);

        return redirect()->route('decommission.show', $batch)
            ->with('success', 'Comparison submitted. Finance and '.$batch->company.' management have both been asked to review it.');
    }

    /**
     * IT asks AI to read every vendor's current quotation and suggest one, with reasons
     * grounded in the documents rather than the amount alone.
     *
     * PRE-FILLS the Recommend form on this page — submitForApproval() is still what the
     * module treats as IT's actual recommendation, so IT decides whether to use the
     * suggestion or pick differently. Never runs automatically; it is a billed AI call.
     */
    public function compareQuotations(AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        if (! $batch->isEwaste() || $batch->quotationsForComparison()->isEmpty()) {
            return redirect()->route('decommission.show', $batch)->with('error', 'There are no quotations to compare yet.');
        }

        // Reading several documents in one request can run past PHP's 30s default.
        @set_time_limit(180);

        $result = EwasteQuotationComparisonService::compare($batch->fresh(['quotations.vendor']));

        $batch->update([
            'ai_compare_status' => $result['status'],
            'ai_recommended_quotation_id' => $result['quotation_id'],
            'ai_recommendation_note' => $result['note'],
            'ai_recommended_at' => now(),
        ]);

        if ($result['status'] === 'ok') {
            $winner = $batch->fresh(['aiRecommendedQuotation.vendor'])->aiRecommendedQuotation;

            return redirect()->route('decommission.show', $batch)->with('success',
                'AI suggests '.($winner?->vendorName() ?? 'a vendor').' — the Recommend form below has been pre-filled. You decide whether to use it.');
        }

        return redirect()->route('decommission.show', $batch)->with(match ($result['status']) {
            'disabled' => 'info',
            default => 'error',
        }, match ($result['status']) {
            'disabled' => 'AI document reading is not configured — pick a recommendation by hand.',
            'empty' => 'There are no quotations to compare yet.',
            default => 'AI comparison could not be completed this time — pick a recommendation by hand.',
        });
    }

    /**
     * Finance approves the offer under review — one of the two mandatory, independent gates
     * (see managementApprove() for the other). Neither is sequenced ahead of the other: this
     * only fully approves the disposal when management have ALSO already approved; otherwise
     * it records Finance's half and the cycle stays open, awaiting management.
     */
    public function financeApprove(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeFinance();

        if (! $batch->isEwaste() || $batch->finance_status !== 'pending' || ! $batch->isAwaitingDecision()) {
            return back()->with('error', 'This cycle is not awaiting a Finance decision.');
        }

        $data = $request->validate(['remarks' => 'nullable|string|max:1000']);
        $remarks = mb_substr(strip_tags((string) ($data['remarks'] ?? '')), 0, 1000);

        $batch->recordFinanceDecision('approved', Auth::id(), $remarks ?: null);

        Log::info('E-waste quotation: Finance approved', [
            'batch' => $batch->batch_number,
            'quotation_id' => $batch->quotationUnderReview()?->id,
            'actor_id' => Auth::id(),
        ]);

        return $this->afterApprovalDecision($batch, 'Finance');
    }

    /** Finance rejects the offer under review. A reason is required — IT have to act on it. */
    public function financeReject(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeFinance();

        if (! $batch->isEwaste() || $batch->finance_status !== 'pending' || ! $batch->isAwaitingDecision()) {
            return back()->with('error', 'This cycle is not awaiting a Finance decision.');
        }

        $request->validate(['remarks' => 'required|string|max:1000'], [
            'remarks.required' => 'State why this offer is refused — IT have to act on the reason.',
        ]);
        $remarks = mb_substr(strip_tags((string) $request->input('remarks')), 0, 1000);

        // Recorded ON the quotation under review, so a later re-quote cannot erase it.
        $batch->recordFinanceDecision('rejected', Auth::id(), $remarks);

        Log::info('E-waste quotation: Finance rejected', [
            'batch' => $batch->batch_number,
            'quotation_id' => $batch->quotationUnderReview()?->id,
            'actor_id' => Auth::id(),
            'reason' => $remarks,
        ]);

        EwasteSweepService::notifyIt(new DecommissionNotification(
            event: 'ewaste.finance_rejected',
            batchNumber: $batch->batch_number,
            subject: 'E-waste disposal rejected by Finance',
            message: "Finance rejected the disposal for {$batch->batch_number}: {$remarks}. "
                .'Collect revised quotations or cancel the cycle.',
            url: route('decommission.show', $batch),
            icon: 'bi-x-circle',
            color: 'danger',
        ));

        return redirect()->route('assets.index', ['tab' => 'company-decom'])
            ->with('success', "Disposal for {$batch->batch_number} rejected — IT notified.");
    }

    /**
     * After either party records an approve, tell the other what happened: if the cycle is now
     * fully approved (the other side had already approved), notify the vendor + IT; if only
     * half-approved, say so and that it is still awaiting the other party.
     */
    private function afterApprovalDecision(AssetDecommissionBatch $batch, string $actor)
    {
        $fresh = $batch->fresh();

        if ($fresh->isApproved()) {
            $winner = $fresh->fresh(['selectedQuotation.vendor'])->selectedQuotation;
            $this->notifyApproved($fresh, $winner);

            return redirect()->route('assets.index', ['tab' => 'company-decom'])->with('success',
                "Disposal for {$batch->batch_number} is now FULLY APPROVED"
                .($winner?->vendor ? ' — '.$winner->vendor->name.' selected' : '')
                .'. The vendor and IT have been notified.');
        }

        $waitingOn = $actor === 'Finance' ? $batch->company.' management' : 'Finance';

        return redirect()->route('assets.index', ['tab' => 'company-decom'])->with('success',
            "{$actor}'s approval on {$batch->batch_number} is recorded — awaiting {$waitingOn} before this disposal is authorised.");
    }

    // ── Management: the decision that moves the cycle ─────────────────────────

    private function authorizeManagement(AssetDecommissionBatch $batch): void
    {
        if (! Auth::user()->canApproveEwasteAsManagement($batch->company)) {
            abort(403, 'Only '.($batch->company ?: 'this company').'\'s management may authorise this disposal.');
        }
    }

    /**
     * Management approve the disposal — the other of the two mandatory, independent gates
     * (see financeApprove()). Optionally on a DIFFERENT vendor's offer than the one IT
     * recommended ("or go with other company choices"). This only fully approves the disposal
     * when Finance have ALSO already approved; otherwise it records management's half and the
     * cycle stays open, awaiting Finance.
     *
     * First decision wins where a company names several approvers: waiting for all of them
     * would stall a cycle behind whoever is on leave.
     */
    public function managementApprove(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeManagement($batch);

        if (! $batch->isEwaste() || $batch->management_status !== 'pending' || ! $batch->isAwaitingDecision()) {
            return back()->with('error', 'This cycle is not awaiting a management decision.');
        }

        $data = $request->validate([
            'selected_quotation_id' => [
                'nullable',
                Rule::exists('asset_decommission_quotations', 'id')
                    ->where('asset_decommission_batch_id', $batch->id),
            ],
            'remarks' => 'nullable|string|max:1000',
        ], [
            'selected_quotation_id.exists' => 'That quotation does not belong to this cycle.',
        ]);

        $selected = ! empty($data['selected_quotation_id'])
            ? $batch->quotations()->find($data['selected_quotation_id'])
            : null;

        $batch->recordManagementDecision(
            'approved',
            Auth::id(),
            mb_substr(strip_tags((string) ($data['remarks'] ?? '')), 0, 1000) ?: null,
            $selected,
        );

        Log::info('E-waste quotation: management approved', [
            'batch' => $batch->batch_number, 'actor_id' => Auth::id(),
            'selected_quotation_id' => $selected?->id,
        ]);

        return $this->afterApprovalDecision($batch, 'Management');
    }

    /** Management refuse the disposal. A reason is required — IT have to act on it. */
    public function managementReject(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeManagement($batch);

        if (! $batch->isEwaste() || $batch->management_status !== 'pending' || ! $batch->isAwaitingDecision()) {
            return back()->with('error', 'This cycle is not awaiting a management decision.');
        }

        $request->validate(['remarks' => 'required|string|max:1000'], [
            'remarks.required' => 'State why the disposal is refused — IT have to act on the reason.',
        ]);
        $remarks = mb_substr(strip_tags((string) $request->input('remarks')), 0, 1000);

        $batch->recordManagementDecision('rejected', Auth::id(), $remarks);

        Log::info('E-waste quotation: management rejected', [
            'batch' => $batch->batch_number, 'actor_id' => Auth::id(), 'reason' => $remarks,
        ]);

        EwasteSweepService::notifyIt(new DecommissionNotification(
            event: 'ewaste.management_rejected',
            batchNumber: $batch->batch_number,
            subject: 'E-waste disposal rejected by management',
            message: $batch->company." management rejected the disposal for {$batch->batch_number}: {$remarks}. "
                .'Collect revised quotations or cancel the cycle.',
            url: route('decommission.show', $batch),
            icon: 'bi-x-circle',
            color: 'danger',
        ));

        return redirect()->route('decommission.show', $batch)
            ->with('success', "Disposal for {$batch->batch_number} rejected — IT notified.");
    }

    /** Stage 4 — IT uploads the vendor's payment receipt (only after the disposal is authorised). */
    public function uploadReceipt(Request $request, AssetDecommissionBatch $batch)
    {
        $this->authorizeManage();

        // Gated on the CYCLE being fully approved — BOTH Finance AND management have to have
        // approved; either one alone leaves the cycle at 'pending_approval'.
        // (isApproved() also covers the legacy `finance_approved` status.)
        if (! $batch->isEwaste() || ! $batch->isApproved()) {
            return back()->with('error', 'A receipt can only be uploaded once management have approved the disposal.');
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

    // pendingQuotationsQuery() lived here as the shared `finance_status = pending` query behind
    // Accounting → Assets → "Disposed". Removed with that page on 2026-08-14: the Decommissioning
    // page asks a different question — which cycles await THIS user's decision, Finance's or
    // management's — and a cycle whose Finance position is already recorded while management
    // have yet to answer is still open work that the old filter would have dropped.
    // See ReportController::decommissionReport().

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
            // WHICH quotation. With several vendors on a cycle there is no single "the"
            // quotation any more, and correcting a figure on the wrong vendor's offer would
            // silently change what the comparison ranks them by.
            'quotation_id' => [
                'nullable',
                Rule::exists('asset_decommission_quotations', 'id')
                    ->where('asset_decommission_batch_id', $batch->id),
            ],
        ], [
            'quotation_id.exists' => 'That quotation does not belong to this cycle.',
        ]);

        $column = $data['field'].'_amount';
        $amount = $data['amount'] === null || $data['amount'] === '' ? null : round((float) $data['amount'], 2);

        // A quotation amount belongs to a REVISION as well as the cache — correcting only the
        // cache would leave the cycle log quoting a figure the report contradicts.
        if ($data['field'] === 'quotation') {
            // A null target is legitimate: a cycle whose quotation predates the revision table
            // holds its figure in the cache columns alone, and setQuotationAmount() writes
            // those directly in that case.
            $quotation = ! empty($data['quotation_id'])
                ? $batch->quotations()->find($data['quotation_id'])
                : $batch->quotationUnderReview();

            $batch->setQuotationAmount($amount, $quotation);
        } else {
            $batch->update(['receipt_amount' => $amount]);
        }

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
     * Both ids come from the URL, so without this a quotation could be deleted through any
     * other cycle's route and would then vanish from the wrong batch.
     */
    private function assertQuotationBelongs(AssetDecommissionBatch $batch, AssetDecommissionQuotation $quotation): void
    {
        abort_unless($quotation->asset_decommission_batch_id === $batch->id, 404);
    }

    /**
     * Copy the quotation onto the sending vendor's Contracts tab, and say so in the flash.
     *
     * FAILS OPEN. The quotation gates the entire cycle — no offer, no comparison, no
     * collection — so a bookkeeping copy failing must never bounce the upload that carries it.
     * Same rule the OCR services follow, and the reason the filing is a side effect here
     * rather than part of addQuotationRevision()'s transaction.
     */
    private function fileToVendorRecord(AssetDecommissionQuotation $revision, ?string $originalFilename): string
    {
        try {
            $contract = EwasteQuotationFilingService::file($revision, $originalFilename);
        } catch (\Throwable $e) {
            Log::error('E-waste quotation filing to vendor record failed', [
                'quotation' => $revision->id, 'error' => $e->getMessage(),
            ]);

            return ' NOTE: it could NOT be copied to the vendor\'s Contracts tab — file it there by hand.';
        }

        if (! $contract) {
            return '';
        }

        return ' A copy was filed on '.$revision->vendorName().'\'s Contracts tab.';
    }

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

    /**
     * Ask this cycle's company management to decide.
     *
     * Addressed to the named approvers individually rather than to a role — they are named
     * people who span companies, and a role-wide mail would put one entity's disposal in front
     * of everyone. Every approver is asked; the first to answer settles it.
     */
    private function notifyManagement(AssetDecommissionBatch $batch, AssetDecommissionBatch $fresh): void
    {
        $approvers = $batch->managementApprovers();

        if ($approvers->isEmpty()) {
            // Nobody can move this cycle. Say so loudly to IT rather than leaving it to be
            // discovered when the collection does not happen.
            EwasteSweepService::notifyIt(new DecommissionNotification(
                event: 'ewaste.no_management_approver',
                batchNumber: $batch->batch_number,
                subject: 'No management approver configured',
                message: "Cycle {$batch->batch_number} was submitted but no management approver is set for "
                    .($batch->company ?: 'its company').' — set one in Settings → E-Waste Approvers or it cannot proceed.',
                url: route('decommission.show', $batch),
                icon: 'bi-exclamation-triangle',
                color: 'danger',
            ));

            return;
        }

        $emails = $approvers->pluck('work_email')->filter()->unique()->values()->all();
        if ($emails) {
            try {
                Mail::to($emails)->send(new EwasteManagementApprovalMail($fresh));
            } catch (\Throwable $e) {
                Log::error('E-waste management approval email failed for '.$batch->batch_number.': '.$e->getMessage());
            }
        }

        \Illuminate\Support\Facades\Notification::send($approvers, new DecommissionNotification(
            event: 'ewaste.management_pending',
            batchNumber: $batch->batch_number,
            subject: 'E-waste disposal awaiting your approval',
            message: "Cycle {$batch->batch_number} for {$batch->company} is awaiting your approval — "
                .$batch->quotationsForComparison()->count().' vendor quotation(s) to compare.',
            // Decommissioning, not the cycle page — that is where the decision is cast, and it
            // is where the approval EMAIL lands, so the bell must not send them somewhere else.
            url: route('reports.decommission'),
            icon: 'bi-shield-check',
            color: 'warning',
        ));
    }

    /**
     * Phase 5C — once the disposal is authorised, tell the winning vendor and IT.
     *
     * Only the winner is told. A losing vendor is not sent a rejection: they made an offer we
     * did not take up, and there is nothing they are required to do. Each leg is caught
     * separately — a typo in the vendor's PIC address must not silence the notice to IT.
     */
    private function notifyApproved(AssetDecommissionBatch $batch, ?AssetDecommissionQuotation $winner): void
    {
        $fresh = $batch->fresh(['vendor', 'items.asset', 'quotations.vendor', 'selectedQuotation.vendor', 'managementReviewer']);
        $vendorEmail = $winner?->vendor?->pic_email;

        if ($vendorEmail) {
            try {
                Mail::to($vendorEmail)->send(new EwasteAwardMail($fresh));
            } catch (\Throwable $e) {
                Log::error('E-waste award email to vendor failed for '.$batch->batch_number.': '.$e->getMessage());
            }
        }

        try {
            EwasteSweepService::mailIt(new EwasteAwardMail($fresh, audience: 'it'));
        } catch (\Throwable $e) {
            Log::error('E-waste award email to IT failed for '.$batch->batch_number.': '.$e->getMessage());
        }

        EwasteSweepService::notifyIt(new DecommissionNotification(
            event: 'ewaste.approved',
            batchNumber: $batch->batch_number,
            subject: 'E-waste disposal approved',
            message: $batch->company." management approved {$batch->batch_number}"
                .($winner?->vendor ? ' — '.$winner->vendor->name.' will collect' : '')
                .'. Arrange collection, then upload the payment receipt.'
                .($vendorEmail ? '' : ' NOTE: the vendor has no PIC email on file and was NOT notified.'),
            url: route('decommission.show', $batch),
            icon: 'bi-check-circle',
            color: 'success',
        ));
    }
}
