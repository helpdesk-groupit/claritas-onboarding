<?php

namespace App\Http\Controllers;

use App\Models\DisposedAsset;
use App\Models\RentalAssetAcknowledgement;
use App\Models\RentalAssetAcknowledgementItem;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AARF — acknowledging that rental assets physically changed hands with a vendor.
 *
 * One format, two directions, and the parties swap between them:
 *
 *   RECEIPT (RRA) — the vendor delivers rental kit to us. Generated from the vendor
 *     profile. WE are the collector (Company PIC): we tick, note any damage and type the
 *     collector details. The vendor's delivery representative (Vendor PIC) signs their own
 *     acknowledgement, typing their identity because they have no account.
 *
 *   RETURN (RTA) — we hand rental kit back. Generated from the IT Decommissioning queue
 *     once the assets are marked Returned. THE VENDOR'S COURIER is the collector: they
 *     verify the list, tick, note anything they will not accept, type their identity and
 *     acknowledge on our device. Our Company PIC signs the other side, stamped with the
 *     account that operated it. Unlike a receipt, closing it takes the assets out of service.
 *
 * BOTH DIRECTIONS TAKE TWO SIGNATURES, IN EITHER ORDER (2026-08-13). A handover is an
 * agreement between two parties, so one signature does not close the form and neither party
 * blocks the other from going first. finalizeIfComplete() is the single place the document
 * becomes final — status, archiving, the stored PDF and the three emails all happen there,
 * driven by whichever signature lands second.
 *
 * Everything is IN-APP either way. There is deliberately no tokenized public page: on a
 * receipt the collector is us, and on a return the collector is standing at our desk.
 */
class RentalAssetAcknowledgementController extends Controller
{
    private function authorizeView(): void
    {
        if (! Auth::user()->canViewVendors()) {
            abort(403);
        }
    }

    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageVendors()) {
            abort(403, 'No permission to manage vendor acknowledgements.');
        }
    }

    /**
     * The Decommissioning queue is IT's, not Vendor Management's, so raising a return form
     * from it is gated on the decommissioning capability. Signing the form afterwards is
     * still canManageVendors() — the same gate as every other AARF.
     */
    private function authorizeQueue(): void
    {
        if (! Auth::user()->canManageDecommission()) {
            abort(403, 'No permission to manage decommissioning.');
        }
    }

    /**
     * Both ids come from the URL, so without this an acknowledgement could be opened,
     * signed or deleted through any OTHER vendor's route and would then read as that
     * vendor's document. Same guard as VendorContractController::assertBelongs().
     */
    private function assertBelongs(Vendor $vendor, RentalAssetAcknowledgement $aarf): void
    {
        abort_unless((int) $aarf->vendor_id === (int) $vendor->id, 404);
    }

    /**
     * Generate the outstanding AARFs for a vendor — one per "Company Rented to".
     *
     * Auto-grouped rather than hand-picked: a form names one legal entity, and the
     * entity that rented the asset is already on the asset. Only assets that have never
     * been acknowledged are included, so a second click after signing produces nothing
     * rather than re-signing kit that was received months ago.
     */
    public function generate(Vendor $vendor)
    {
        $this->authorizeManage();

        $pending = RentalAssetAcknowledgement::pendingAssetsFor($vendor);

        if ($pending->isEmpty()) {
            return redirect()
                ->route('vendors.show', [$vendor, 'tab' => 'report'])
                ->with('error', 'No rental assets from this vendor are waiting to be acknowledged.');
        }

        // Group by the company the asset was rented to. Assets with the field blank are
        // NOT dropped — they collect under one "unspecified" form, because silently
        // omitting an asset from the form meant to account for it is the worse failure.
        $groups = $pending->groupBy(fn ($asset) => $asset->company_supplied_to ?: '');

        $created = DB::transaction(function () use ($vendor, $groups) {
            $made = [];

            foreach ($groups as $company => $assets) {
                $aarf = RentalAssetAcknowledgement::create([
                    'reference' => RentalAssetAcknowledgement::generateReference(RentalAssetAcknowledgement::TYPE_RECEIPT),
                    'type' => RentalAssetAcknowledgement::TYPE_RECEIPT,
                    'vendor_id' => $vendor->id,
                    'company_rented_to' => $company !== '' ? $company : null,
                    'status' => RentalAssetAcknowledgement::STATUS_DRAFT,
                    'created_by' => Auth::id(),
                ]);

                foreach ($assets as $asset) {
                    $aarf->items()->create(RentalAssetAcknowledgementItem::snapshotFrom(
                        $asset,
                        RentalAssetAcknowledgement::TYPE_RECEIPT
                    ));
                }

                $made[] = $aarf;
            }

            return $made;
        });

        // One form → straight into it. Several → back to the tab, which now lists them.
        if (count($created) === 1) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $created[0]])
                ->with('success', "AARF {$created[0]->reference} generated — review and acknowledge it.");
        }

        return redirect()
            ->route('vendors.show', [$vendor, 'tab' => 'report'])
            ->with('success', count($created).' AARFs generated, one per company rented to.');
    }

    /**
     * "Create Collection Batch" — turn assets ticked in the IT Decommissioning queue into
     * return AARFs.
     *
     * The vendor is detected from each asset, not picked from a dropdown. That is the whole
     * point of the change: the old modal asked IT to choose one vendor for the whole
     * selection, so ticking two vendors' assets together filed them all under one of them,
     * archived them that way, and mailed the signed copy to the wrong PIC. Now the selection
     * splits into as many forms as there are (vendor, company rented to) pairs in it.
     *
     * Nothing is created outside the transaction and nothing ticked is silently ignored —
     * see RentalAssetAcknowledgement::planReturns() for the reasons an asset can be skipped.
     */
    public function generateReturns(Request $request)
    {
        $this->authorizeQueue();

        $validated = $request->validate([
            'dispose_ids' => 'required|array|min:1',
            'dispose_ids.*' => 'integer|exists:dispose_assets,id',
        ], [
            'dispose_ids.required' => 'Tick at least one returned asset first.',
        ]);

        // Only staged returns whose asset is still live. A row already archived by an earlier
        // form is not an error worth naming — it simply is not in the queue any more.
        $assets = DisposedAsset::whereIn('id', $validated['dispose_ids'])
            ->where('decommission_type', 'vendor_return')
            ->whereHas('asset', fn ($q) => $q->whereNull('decommissioned_at'))
            ->with('asset.vendor')
            ->get()
            ->pluck('asset')
            ->filter()
            ->unique('id')
            ->values();

        if ($assets->isEmpty()) {
            return back()->with('error', 'None of the selected assets are still awaiting return — they may already be on a form or archived.');
        }

        ['groups' => $groups, 'skipped' => $skipped] = RentalAssetAcknowledgement::planReturns($assets);

        if (empty($groups)) {
            return back()->with('error', 'No return form could be raised: '.$this->skippedSentence($skipped));
        }

        $created = DB::transaction(function () use ($groups) {
            $made = [];

            foreach ($groups as $group) {
                $aarf = RentalAssetAcknowledgement::create([
                    'reference' => RentalAssetAcknowledgement::generateReference(RentalAssetAcknowledgement::TYPE_RETURN),
                    'type' => RentalAssetAcknowledgement::TYPE_RETURN,
                    'vendor_id' => $group['vendor']->id,
                    'company_rented_to' => $group['company'],
                    'status' => RentalAssetAcknowledgement::STATUS_DRAFT,
                    'created_by' => Auth::id(),
                ]);

                foreach ($group['assets'] as $asset) {
                    $aarf->items()->create(RentalAssetAcknowledgementItem::snapshotFrom(
                        $asset,
                        RentalAssetAcknowledgement::TYPE_RETURN
                    ));
                }

                $made[] = $aarf;
            }

            return $made;
        });

        // The skipped list is a WARNING, not a footnote on the success message: the operator
        // ticked those assets and would otherwise assume they were on the form.
        $warning = $skipped->isEmpty() ? null : 'Not included — '.$this->skippedSentence($skipped);

        if (count($created) === 1) {
            $only = $created[0];

            return redirect()
                ->route('vendors.aarf.show', [$only->vendor, $only])
                ->with('success', "Return form {$only->reference} generated for {$only->vendor->name} — review it with the collector and acknowledge.")
                ->with('warning', $warning);
        }

        $refs = collect($created)->map(fn ($a) => $a->reference.' ('.$a->vendor->name.')')->implode(', ');

        return redirect()
            ->route('assets.index', ['tab' => 'damaged'])
            ->with('success', count($created).' return forms generated, one per vendor and company rented to: '.$refs.'. Open each from the "Return To / Batch" column below.')
            ->with('warning', $warning);
    }

    /** "TAG-1 (reason), TAG-2 (reason)" — every ticked asset that did not make it onto a form. */
    private function skippedSentence($skipped): string
    {
        return $skipped
            ->map(fn ($s) => ($s['asset']->asset_tag ?: 'asset #'.$s['asset']->id).' ('.$s['reason'].')')
            ->implode('; ').'.';
    }

    public function show(Vendor $vendor, RentalAssetAcknowledgement $aarf)
    {
        $this->authorizeView();
        $this->assertBelongs($vendor, $aarf);

        $aarf->load(['items', 'creator', 'acknowledger', 'processorAcknowledger', 'vendor']);

        return view('vendors.aarf.show', [
            'vendor' => $vendor,
            'aarf' => $aarf,
            'canManage' => Auth::user()->canManageVendors(),
            // Only meaningful while unsigned; a signed form shows what was stored.
            //
            // On a RETURN the collector is the vendor's courier, so their name, IC and phone
            // are typed and nothing is offered for them — pre-filling our own employee's
            // identity there would put our staff's IC number under the vendor's declaration.
            // Only the company is suggested, and only because a courier normally does come
            // from the vendor; it stays editable for the times they do not.
            // Keyed on the MAIN signature, not on the document being closed: once that party
            // has signed, their identity is stored and the fields render read-only, whether
            // or not the other party has got to it yet.
            'prefill' => match (true) {
                $aarf->mainAcknowledged() => [],
                $aarf->isReturn() => ['collector_company' => $aarf->vendor?->name],
                default => RentalAssetAcknowledgement::prefillCollector(Auth::user()),
            },
        ]);
    }

    /**
     * Sign the MAIN declaration — the tick box, the condition note and the collector's
     * identity. The tick is REQUIRED to be accepted: it is the whole point of the document,
     * and anything not covered by it belongs in the condition remarks.
     *
     * This is ONE OF THE TWO signatures on the form, not the closing one. Either party may
     * go first and neither blocks the other; the document is finished — and a return's assets
     * archived — only when both have signed. See finalizeIfComplete().
     */
    public function acknowledge(Request $request, Vendor $vendor, RentalAssetAcknowledgement $aarf)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $aarf);

        // Guarded on THIS party's signature, not the document's completion: the other party
        // may well have signed already, and that must not bar this one from signing.
        if ($aarf->mainAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'The '.$aarf->mainPartyLabel().' has already acknowledged this AARF.');
        }

        $isReturn = $aarf->isReturn();

        $rules = [
            'condition_confirmed' => 'accepted',
            'condition_remarks' => 'nullable|string|max:2000',
            'collector_company' => 'nullable|string|max:255',
            'collector_name' => 'required|string|max:255',
            'collector_ic' => 'required|string|max:60',
            'collector_phone' => 'nullable|string|max:50',
        ];

        // NEITHER direction accepts the second party's reply here, and that is the point of
        // the two-submit shape: on a receipt the vendor's rep signs their own words through
        // vendorAcknowledge(), and on a return our processor signs theirs through
        // processorAcknowledge(). Letting `processor_remarks` ride along on this submit is
        // what let the closing signatory author the other party's statement — which on a
        // return is the collector authoring OUR reply, the mirror of the thing the receipt
        // side already refuses.
        $validated = $request->validate($rules, [
            'condition_confirmed.accepted' => $isReturn
                ? 'The collector must confirm the condition of the assets before the return can be acknowledged.'
                : 'You must confirm the condition of the assets before acknowledging.',
            'collector_name.required' => "Enter the collector's name.",
            'collector_ic.required' => "Enter the collector's IC or passport number.",
        ]);

        // The second party's reply is NOT written here in either direction — it is their own
        // signed statement, submitted through vendorAcknowledge() (receipt) or
        // processorAcknowledge() (return). Letting it ride along on this submit would mean
        // the closing signatory could write words above the other party's name.
        // `status` is deliberately NOT set here — it is flipped by whichever of the two
        // signatures lands second, in finalizeIfComplete(). Writing it on this one would
        // state that the document was agreed by both parties on the strength of one.
        $attributes = [
            'condition_confirmed' => true,
            'condition_remarks' => $validated['condition_remarks'] ?? null,
            'collector_company' => $validated['collector_company'] ?? null,
            'collector_name' => $validated['collector_name'],
            'collector_ic' => $validated['collector_ic'],
            'collector_phone' => $validated['collector_phone'] ?? null,
            'acknowledged_by' => Auth::id(),
            'acknowledged_at' => now(),
        ];

        // Once the SECOND PARTY has signed a reply to the condition note, that note is what
        // they answered and it may no longer be edited — otherwise this submit could rewrite
        // the question above their signature, and their reply would read as an answer to
        // whatever got typed here afterwards.
        //
        // Direction-agnostic on purpose. It reads the vendor rep on a receipt and our
        // processor on a return, so neither closing signatory can move the goalposts on the
        // other. The view renders the field read-only from the moment they sign; the guard
        // is here as well because a server-side drop on its own would silently discard what
        // the operator typed.
        if ($aarf->secondPartyAcknowledged()) {
            unset($attributes['condition_remarks']);
        }

        $aarf->update($attributes);

        return redirect()
            ->route('vendors.aarf.show', [$vendor, $aarf])
            ->with('success', "AARF {$aarf->reference} acknowledged by the {$aarf->mainPartyLabel()}."
                .$this->finalizeIfComplete($aarf));
    }

    /**
     * Close the document once BOTH parties have signed — whichever order they signed in.
     *
     * This is the single place the form becomes final, and it is called at the end of all
     * three signature actions rather than living in the "last" one, because there is no last
     * one: either party may go first. It is idempotent (it returns immediately if the status
     * has already flipped), so a re-entry cannot archive the same assets twice or send the
     * signed copy a second time.
     *
     * Returns the sentence to append to the caller's flash message, or '' while the document
     * is still waiting on somebody — the operator must be able to tell "your signature was
     * recorded" from "the handover is now closed", since only the second one archives assets
     * and puts a signed PDF in four inboxes.
     */
    private function finalizeIfComplete(RentalAssetAcknowledgement $aarf): string
    {
        $aarf->refresh();

        if ($aarf->isAcknowledged()) {
            return '';
        }

        if (! $aarf->bothPartiesAcknowledged()) {
            return " It closes once the {$aarf->awaitingPartyLabel()} has acknowledged it too.";
        }

        $isReturn = $aarf->isReturn();

        // Closing a return and taking its assets out of service are ONE act, so they commit
        // together. Archiving separately would allow the half-state nobody could see: a
        // signed return whose assets still read as ours, sitting in the queue asking to be
        // returned a second time.
        DB::transaction(function () use ($aarf, $isReturn) {
            $aarf->update(['status' => RentalAssetAcknowledgement::STATUS_ACKNOWLEDGED]);

            if ($isReturn) {
                $aarf->archiveAssets();
            }
        });

        // The stored PDF is the snapshot of what was signed. A failure to write it must not
        // undo the acknowledgement — the record above already IS the acknowledgement, and
        // the form can be re-printed on demand from the same data.
        $relations = ['items', 'vendor', 'creator', 'acknowledger', 'processorAcknowledger'];

        try {
            $aarf->fresh($relations)->storePdf();
        } catch (\Throwable $e) {
            Log::error("AARF PDF generation failed for {$aarf->reference}: ".$e->getMessage());
        }

        $sent = $aarf->fresh($relations)->distributeSignedCopy();

        return ' Both parties have now signed'
            .($isReturn ? ', and the assets have been archived out of the inventory' : '').'.'
            .($sent ? '' : ' The signed copy could not be emailed — see the logs.');
    }

    /**
     * The VENDOR's delivery representative acknowledges the handover, and answers our damage
     * note if they have anything to say about it.
     *
     * REQUIRED, not optional, since 2026-08-13: a handover is an agreement between two
     * parties and the form is not finished until both have signed. Their REMARKS stay
     * optional — most handovers have nothing to reply to, and demanding filler text in a box
     * captioned "Leave remarks if any" would be demanding a sentence nobody meant to write.
     * The identity is what makes the signature, so it is still required and is written in
     * the SAME action as the remarks: "a damage reply nobody signed" cannot be stored,
     * because an anonymous rebuttal is worth nothing in the argument it exists to settle.
     *
     * There is no account behind this person, so the typed identity plus the timestamp IS
     * the signature, captured in person on our screen. They may sign before or after us.
     */
    public function vendorAcknowledge(Request $request, Vendor $vendor, RentalAssetAcknowledgement $aarf)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $aarf);

        // A RETURN has no vendor-representative block. The vendor's person on a return is
        // the collector, who signs the main acknowledgement — routing them through here as
        // well would record one person twice under two different roles on one document.
        if ($aarf->isReturn()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', "This is a return form — the vendor's collector signs it in the Collector Details section, not as a separate representative.");
        }

        // Unreachable in the normal two-signature flow — the status only flips once BOTH
        // parties have signed, and the next guard catches this party. It is here for the
        // forms CLOSED UNDER THE OLD SINGLE-SIGNATURE RULE, which are final with only our
        // signature on them: nothing may be added to a document already declared complete.
        if ($aarf->isAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'This AARF is already closed and can no longer be signed.');
        }

        if ($aarf->vendorRepAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'The Vendor PIC has already acknowledged this form.');
        }

        $validated = $request->validate([
            'vendor_rep_remarks' => 'nullable|string|max:2000',
            'vendor_rep_company' => 'nullable|string|max:255',
            'vendor_rep_name' => 'required|string|max:255',
            'vendor_rep_ic' => 'required|string|max:60',
            'vendor_rep_phone' => 'nullable|string|max:50',
            // Posted alongside, because both sides share one form. Not the rep's to sign,
            // but see below — the note they are answering has to be stored with the answer.
            'condition_remarks' => 'nullable|string|max:2000',
        ]);

        $attributes = [
            'vendor_rep_remarks' => $validated['vendor_rep_remarks'] ?? null,
            'vendor_rep_company' => $validated['vendor_rep_company'] ?? null,
            'vendor_rep_name' => $validated['vendor_rep_name'],
            'vendor_rep_ic' => $validated['vendor_rep_ic'],
            'vendor_rep_phone' => $validated['vendor_rep_phone'] ?? null,
            'vendor_rep_acknowledged_at' => now(),
            // The rep may be answering a damage note that until now existed only as
            // unsubmitted text in a textarea (we share one form, so it posts with them).
            // Persist it in the same write: a signed reply whose subject was never saved
            // refers to nothing, and would read as an answer to whatever got typed later.
            'condition_remarks' => $validated['condition_remarks'] ?? null,
        ];

        // ...unless WE have already signed, in which case the note is our stored declaration
        // and the page renders it read-only. Accepting it back from this submit would let
        // the party answering the note rewrite the note itself.
        if ($aarf->mainAcknowledged()) {
            unset($attributes['condition_remarks']);
        }

        $aarf->update($attributes);

        // `condition_confirmed` is deliberately NOT stored here — the tick is our formal
        // declaration and is made by acknowledge(), not carried in on someone else's
        // submit. Storing it on a draft would also make a printed draft claim a
        // confirmation nobody had given. It is flashed back instead, so ticking the box
        // before handing the screen over survives the round-trip.
        return redirect()
            ->route('vendors.aarf.show', [$vendor, $aarf])
            ->withInput(['condition_confirmed' => $request->input('condition_confirmed')])
            ->with('success', "AARF {$aarf->reference} acknowledged by Vendor PIC {$validated['vendor_rep_name']}."
                .$this->finalizeIfComplete($aarf));
    }

    /**
     * OUR Company PIC acknowledges the return, and answers the collector's condition remarks
     * if they have anything to say about them.
     *
     * The exact mirror of vendorAcknowledge(), with the parties swapped: on a return the
     * vendor's collector makes the main declaration and we are the second party. Required
     * for the form to close, remarks optional, either party may go first.
     *
     * The one legitimate asymmetry is the SHAPE of the signature. The vendor's rep has no
     * account, so their typed name plus a timestamp is it; our Company PIC is logged in, so
     * the account reference plus the timestamp is it and nothing is typed — the same
     * distinction acknowledge() already draws between the two directions.
     */
    public function processorAcknowledge(Request $request, Vendor $vendor, RentalAssetAcknowledgement $aarf)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $aarf);

        // A RECEIPT has no processor block. There the second party is the vendor's delivery
        // rep, and our own staff are the closing signatory — routing us through here as well
        // would record one party twice under two roles on one document.
        if (! $aarf->isReturn()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'This is a receipt form — our staff sign the main acknowledgement, and the reply belongs to the vendor representative.');
        }

        // See vendorAcknowledge(): only reachable for a form closed under the old
        // single-signature rule, which is final with only the collector's signature on it.
        if ($aarf->isAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'This AARF is already closed and can no longer be signed.');
        }

        if ($aarf->processorAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'The Company PIC has already acknowledged this form.');
        }

        $validated = $request->validate([
            'processor_remarks' => 'nullable|string|max:2000',
            // Posted alongside, because both sides share one form. Not ours to declare, but
            // the note we are answering has to be stored with the answer.
            'condition_remarks' => 'nullable|string|max:2000',
        ]);

        $attributes = [
            'processor_remarks' => $validated['processor_remarks'] ?? null,
            'processor_acknowledged_by' => Auth::id(),
            'processor_acknowledged_at' => now(),
            // The collector's note may still be unsubmitted text in a textarea (we share one
            // form, so it posts with us). Persist it in the same write: a signed reply whose
            // subject was never saved refers to nothing, and would read as an answer to
            // whatever got typed there later.
            'condition_remarks' => $validated['condition_remarks'] ?? null,
        ];

        // ...unless the collector has already signed, in which case the note is THEIR stored
        // declaration and the page renders it read-only. Accepting it back here would let us
        // rewrite what the vendor's collector said about the condition of their own assets.
        if ($aarf->mainAcknowledged()) {
            unset($attributes['condition_remarks']);
        }

        $aarf->update($attributes);

        $who = RentalAssetAcknowledgement::actorIdentity(Auth::user())['name'] ?? Auth::user()->name;

        // `condition_confirmed` and the collector's identity are deliberately NOT stored
        // here — they are the COLLECTOR'S declaration, made by acknowledge(). Writing them
        // on our submit would be us signing for them, and would make a printed draft claim
        // a confirmation nobody had given. They are flashed back instead, so anything the
        // collector had already typed survives our round-trip. (The receipt path flashes
        // only the tick, because there the collector fields re-populate from the prefill;
        // on a return there is no prefill for the name and IC, so they would be lost.)
        return redirect()
            ->route('vendors.aarf.show', [$vendor, $aarf])
            ->withInput($request->only([
                'condition_confirmed', 'collector_company', 'collector_name',
                'collector_ic', 'collector_phone',
            ]))
            ->with('success', "AARF {$aarf->reference} acknowledged by Company PIC {$who}."
                .$this->finalizeIfComplete($aarf));
    }

    /** Stream the form as a PDF — the stored copy once signed, rendered live while draft. */
    public function pdf(Vendor $vendor, RentalAssetAcknowledgement $aarf)
    {
        $this->authorizeView();
        $this->assertBelongs($vendor, $aarf);

        $aarf->load(['items', 'vendor', 'creator', 'acknowledger', 'processorAcknowledger']);

        if ($aarf->pdf_path && Storage::disk('local')->exists($aarf->pdf_path)) {
            return Storage::disk('local')->download($aarf->pdf_path, $aarf->reference.'.pdf');
        }

        return $aarf->renderPdf()->download($aarf->reference.'.pdf');
    }

    /**
     * Discard an unsigned form. This is the ONLY way an asset returns to the pending
     * list, since the items table holds a unique index per asset — which is deliberate:
     * a signed acknowledgement is evidence and has no delete path at all.
     */
    public function destroy(Vendor $vendor, RentalAssetAcknowledgement $aarf)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $aarf);

        // EITHER signature, not the completed status. A form waiting on its second party is
        // still `draft`, so guarding on isAcknowledged() here would let a discard destroy a
        // signature one of the two parties had already given.
        if ($aarf->anyPartyAcknowledged()) {
            return redirect()
                ->route('vendors.show', [$vendor, 'tab' => 'report'])
                ->with('error', $aarf->isAcknowledged()
                    ? 'An acknowledged AARF cannot be deleted.'
                    : 'This AARF has already been acknowledged by the '
                        .($aarf->mainAcknowledged() ? $aarf->mainPartyLabel() : $aarf->secondPartyLabel())
                        .' and can no longer be discarded.');
        }

        $reference = $aarf->reference;
        $aarf->delete();

        return redirect()
            ->route('vendors.show', [$vendor, 'tab' => 'report'])
            ->with('success', "Draft AARF {$reference} discarded — its assets are pending again.");
    }

    // distributeSignedCopy(), renderPdf() and storePdf() now live on RentalAssetAcknowledgement
    // itself — moved 2026-08-19 so the historical-backfill command can call the exact same
    // notification/PDF logic instead of hand-duplicating it. See the model for both.
}
