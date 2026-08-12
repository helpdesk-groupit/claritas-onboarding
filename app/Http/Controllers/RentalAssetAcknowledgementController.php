<?php

namespace App\Http\Controllers;

use App\Mail\RentalAssetAcknowledgedMail;
use App\Models\DisposedAsset;
use App\Models\RentalAssetAcknowledgement;
use App\Models\RentalAssetAcknowledgementItem;
use App\Models\User;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * AARF — acknowledging that rental assets physically changed hands with a vendor.
 *
 * One format, two directions, and the parties swap between them:
 *
 *   RECEIPT (RRA) — the vendor delivers rental kit to us. Generated from the vendor
 *     profile. WE are the collector, we note any damage, and the vendor's delivery
 *     representative signs their own reply. Two submits.
 *
 *   RETURN (RTA) — we hand rental kit back. Generated from the IT Decommissioning queue
 *     once the assets are marked Returned. THE VENDOR'S COURIER is the collector: they
 *     verify the list, tick, note anything they will not accept, type their identity and
 *     acknowledge on our device, while our processor's reply is stamped with the account
 *     that operated it. One submit — and unlike a receipt it takes the assets out of
 *     service.
 *
 * Everything is IN-APP either way. There is deliberately no tokenized public page: on a
 * receipt the collector is us, and on a return the collector is standing at our desk.
 */
class RentalAssetAcknowledgementController extends Controller
{
    /** Where the signed snapshot PDF is written. Private disk. */
    private const DIR = 'rental_acknowledgements';

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
                ->route('vendors.show', [$vendor, 'tab' => 'assets'])
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
            ->route('vendors.show', [$vendor, 'tab' => 'assets'])
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
            ->with('success', count($created).' return forms generated, one per vendor and company rented to: '.$refs.'. Open each from the Asset column below.')
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
            'prefill' => match (true) {
                $aarf->isAcknowledged() => [],
                $aarf->isReturn() => ['collector_company' => $aarf->vendor?->name],
                default => RentalAssetAcknowledgement::prefillCollector(Auth::user()),
            },
        ]);
    }

    /**
     * Sign the form. The tick box is REQUIRED to be accepted — it is the whole point of
     * the document, and anything not covered by it belongs in the condition remarks.
     *
     * On a RETURN this single submit closes the whole document: the vendor's collector has
     * ticked and typed their identity on our screen, and our processor's reply rides along
     * because it needs no separate signature — the account stamped into `acknowledged_by`
     * IS that signature. It also takes the assets out of service, which a receipt never does.
     */
    public function acknowledge(Request $request, Vendor $vendor, RentalAssetAcknowledgement $aarf)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $aarf);

        if ($aarf->isAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'This AARF has already been acknowledged.');
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
        $attributes = [
            'condition_confirmed' => true,
            'condition_remarks' => $validated['condition_remarks'] ?? null,
            'collector_company' => $validated['collector_company'] ?? null,
            'collector_name' => $validated['collector_name'],
            'collector_ic' => $validated['collector_ic'],
            'collector_phone' => $validated['collector_phone'] ?? null,
            'status' => RentalAssetAcknowledgement::STATUS_ACKNOWLEDGED,
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

        // Signing a return and taking its assets out of service are ONE act, so they commit
        // together. Archiving separately would allow the half-state nobody could see: a
        // signed return whose assets still read as ours, sitting in the queue asking to be
        // returned a second time.
        DB::transaction(function () use ($aarf, $attributes, $isReturn) {
            $aarf->update($attributes);

            if ($isReturn) {
                $aarf->archiveAssets();
            }
        });

        // The stored PDF is the snapshot of what was signed. A failure to write it must
        // not undo the acknowledgement — the record above already IS the acknowledgement,
        // and the form can be re-printed on demand from the same data.
        try {
            $path = $this->storePdf($aarf->fresh(['items', 'vendor', 'creator', 'acknowledger', 'processorAcknowledger']));
            $aarf->update(['pdf_path' => $path]);
        } catch (\Throwable $e) {
            Log::error("AARF PDF generation failed for {$aarf->reference}: ".$e->getMessage());
        }

        $sent = $this->distributeSignedCopy($aarf->fresh(['items', 'vendor', 'creator', 'acknowledger', 'processorAcknowledger']));

        return redirect()
            ->route('vendors.aarf.show', [$vendor, $aarf])
            ->with('success', "AARF {$aarf->reference} acknowledged."
                .($isReturn ? ' The assets have been archived out of the inventory.' : '')
                .($sent ? '' : ' The signed copy could not be emailed — see the logs.'));
    }

    /**
     * The VENDOR's delivery representative answers our damage note and signs it.
     *
     * Optional — most handovers have nothing to reply to. But the reply and the identity
     * behind it are validated and written TOGETHER, so "a damage reply nobody signed"
     * cannot be stored: an anonymous rebuttal of a damage note is worth nothing in the
     * argument it exists to settle.
     *
     * There is no account behind this person, so the typed identity plus the timestamp IS
     * the signature. It is captured in person on our screen, which is why it must happen
     * while the form is still a draft — our acknowledgement closes the document.
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

        if ($aarf->isAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'This AARF is already closed — the vendor representative can no longer sign it.');
        }

        if ($aarf->vendorRepAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'The vendor representative has already signed this form.');
        }

        $validated = $request->validate([
            'vendor_rep_remarks' => 'required|string|max:2000',
            'vendor_rep_company' => 'nullable|string|max:255',
            'vendor_rep_name' => 'required|string|max:255',
            'vendor_rep_ic' => 'required|string|max:60',
            'vendor_rep_phone' => 'nullable|string|max:50',
            // Posted alongside, because both sides share one form. Not the rep's to sign,
            // but see below — the note they are answering has to be stored with the answer.
            'condition_remarks' => 'nullable|string|max:2000',
        ]);

        $aarf->update([
            'vendor_rep_remarks' => $validated['vendor_rep_remarks'],
            'vendor_rep_company' => $validated['vendor_rep_company'] ?? null,
            'vendor_rep_name' => $validated['vendor_rep_name'],
            'vendor_rep_ic' => $validated['vendor_rep_ic'],
            'vendor_rep_phone' => $validated['vendor_rep_phone'] ?? null,
            'vendor_rep_acknowledged_at' => now(),
            // The rep is signing a reply to OUR damage note, which until now existed only
            // as unsubmitted text in a textarea. Persist it in the same write: a signed
            // reply whose subject was never saved refers to nothing, and the reply would
            // read as an answer to whatever got typed there later.
            'condition_remarks' => $validated['condition_remarks'] ?? null,
        ]);

        // `condition_confirmed` is deliberately NOT stored here — the tick is our formal
        // declaration and is made by acknowledge(), not carried in on someone else's
        // submit. Storing it on a draft would also make a printed draft claim a
        // confirmation nobody had given. It is flashed back instead, so ticking the box
        // before handing the screen over survives the round-trip.
        return redirect()
            ->route('vendors.aarf.show', [$vendor, $aarf])
            ->withInput(['condition_confirmed' => $request->input('condition_confirmed')])
            ->with('success', "Vendor representative {$validated['vendor_rep_name']} acknowledged the remarks.");
    }

    /**
     * OUR processor answers the return collector's condition remarks and signs the answer.
     *
     * The exact mirror of vendorAcknowledge(), with the parties swapped — on a return the
     * collector is the closing signatory and we are the second party. Optional, like the
     * rep's block: most handovers have nothing to reply to. But the reply and the identity
     * behind it are written TOGETHER, so "a rebuttal nobody signed" cannot be stored.
     *
     * The one legitimate asymmetry is the SHAPE of the signature. The vendor's rep has no
     * account, so their typed name plus a timestamp is it; our processor is logged in, so
     * the account reference plus the timestamp is it and nothing is typed — the same
     * distinction acknowledge() already draws between the two directions.
     *
     * It must happen while the form is still a draft: the collector's acknowledgement closes
     * the document, and a reply added afterwards would be answering a signed declaration.
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

        if ($aarf->isAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'This AARF is already closed — the collector has signed it, so a reply can no longer be added.');
        }

        if ($aarf->processorAcknowledged()) {
            return redirect()
                ->route('vendors.aarf.show', [$vendor, $aarf])
                ->with('error', 'A processor has already signed a reply on this form.');
        }

        $validated = $request->validate([
            'processor_remarks' => 'required|string|max:2000',
            // Posted alongside, because both sides share one form. Not ours to declare, but
            // the note we are answering has to be stored with the answer.
            'condition_remarks' => 'nullable|string|max:2000',
        ], [
            'processor_remarks.required' => 'Write the reply before signing it — a signature with nothing above it records nothing.',
        ]);

        $aarf->update([
            'processor_remarks' => $validated['processor_remarks'],
            'processor_acknowledged_by' => Auth::id(),
            'processor_acknowledged_at' => now(),
            // The collector's note existed only as unsubmitted text in a textarea until now
            // (they submit at the end, we submit first). Persist it in the same write: a
            // signed reply whose subject was never saved refers to nothing, and would read
            // as an answer to whatever got typed there later.
            'condition_remarks' => $validated['condition_remarks'] ?? null,
        ]);

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
            ->with('success', "Reply signed by {$who}. The collector can now review it and acknowledge the return.");
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

        return $this->renderPdf($aarf)->download($aarf->reference.'.pdf');
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

        if ($aarf->isAcknowledged()) {
            return redirect()
                ->route('vendors.show', [$vendor, 'tab' => 'assets'])
                ->with('error', 'An acknowledged AARF cannot be deleted.');
        }

        $reference = $aarf->reference;
        $aarf->delete();

        return redirect()
            ->route('vendors.show', [$vendor, 'tab' => 'assets'])
            ->with('success', "Draft AARF {$reference} discarded — its assets are pending again.");
    }

    /**
     * Send the signed form, with the PDF attached, to the three parties that need it:
     * the vendor's PIC, the IT team and the Finance team.
     *
     * Each leg is sent and caught INDEPENDENTLY. One bad address — a vendor PIC with a
     * typo'd email is the likely one — must not stop the other two from being told; a
     * single try/catch around all three would let the first failure silence the rest.
     *
     * The whole thing fails open: the acknowledgement is already committed and the PDF is
     * downloadable from the page, so a mail outage must never roll back a signature. The
     * caller surfaces a partial failure in the flash rather than hiding it.
     */
    private function distributeSignedCopy(RentalAssetAcknowledgement $aarf): bool
    {
        $ok = true;

        // 1 ─ The vendor's PIC. Skipped silently when the vendor has no email on file:
        // that is a gap in the vendor master, not a failure of this send.
        if ($pic = $aarf->vendor?->pic_email) {
            $ok = $this->send(
                fn () => Mail::to($pic)->send(
                    new RentalAssetAcknowledgedMail($aarf, RentalAssetAcknowledgedMail::AUDIENCE_VENDOR)
                ),
                "vendor PIC ({$pic})",
                $aarf
            ) && $ok;
        }

        // 2 & 3 ─ IT and Finance: TO the manager(s), CC the executive(s), work email only.
        foreach ([
            [RentalAssetAcknowledgedMail::AUDIENCE_IT, User::itEmailRecipients(), 'IT'],
            [RentalAssetAcknowledgedMail::AUDIENCE_FINANCE, User::financeEmailRecipients(), 'Finance'],
        ] as [$audience, $recipients, $label]) {
            if (empty($recipients['to'])) {
                Log::warning("AARF {$aarf->reference}: no {$label} recipients configured, signed copy not sent.");
                $ok = false;

                continue;
            }

            $ok = $this->send(function () use ($recipients, $aarf, $audience) {
                $mail = Mail::to($recipients['to']);
                if (! empty($recipients['cc'])) {
                    $mail->cc($recipients['cc']);
                }
                $mail->send(new RentalAssetAcknowledgedMail($aarf, $audience));
            }, $label, $aarf) && $ok;
        }

        return $ok;
    }

    private function send(callable $send, string $label, RentalAssetAcknowledgement $aarf): bool
    {
        try {
            $send();

            return true;
        } catch (\Throwable $e) {
            Log::error("AARF {$aarf->reference}: signed copy to {$label} failed: ".$e->getMessage());

            return false;
        }
    }

    // ── PDF ───────────────────────────────────────────────────────────────────
    private function renderPdf(RentalAssetAcknowledgement $aarf)
    {
        // No `orgName` — the form carries no company letterhead (the entity is whoever
        // rented the assets, stated in section 1). Keep this call shape identical to the
        // mailable's fallback render, or one path breaks the moment the view gains a var.
        return Pdf::loadView('vendors.aarf.pdf', ['aarf' => $aarf])->setPaper('a4');
    }

    private function storePdf(RentalAssetAcknowledgement $aarf): string
    {
        $path = self::DIR.'/'.$aarf->vendor_id.'/'.$aarf->reference.'.pdf';
        Storage::disk('local')->put($path, $this->renderPdf($aarf)->output());

        return $path;
    }
}
