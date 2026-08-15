@extends('layouts.app')
@section('title', $aarf->reference)
@section('page-title', 'Rental Asset Acknowledgement')

@section('content')
@include('partials.decommission-ui-style')
@include('partials.vendor-ui-style')

@php
    $badge = $aarf->statusBadge();
    $categories = config('asset-categories.categories', []);
    $preparer = \App\Models\RentalAssetAcknowledgement::actorIdentity($aarf->creator);
    $signer = \App\Models\RentalAssetAcknowledgement::actorIdentity($aarf->acknowledger);
    // THREE states, because a form now takes TWO signatures in either order and one of them
    // on its own closes nothing:
    //   $closed     — both parties have signed; the document is final and wholly read-only.
    //   $mainSigned — the tick, the condition note and the collector details are signed, so
    //                 THOSE fields lock. The other party may still be outstanding.
    //   $secondSigned — the second party has signed, so their block locks.
    // A legacy form signed under the old single-signature rule is $closed with $secondSigned
    // false, which is why the second party's controls test $closed as well as their own
    // timestamp: nothing may be added to a document that was already declared final.
    $closed = $aarf->isAcknowledged();
    $mainSigned = $aarf->mainAcknowledged();
    // Which way the assets moved decides WHO fills each block, not just the wording — and
    // the two parties are symmetrical. Each writes their own block, signs their own
    // acknowledgement, and locks only what they wrote:
    //
    //   Receipt — WE are the collector (Company PIC): tick, condition note, collector
    //             details. The vendor's delivery rep (Vendor PIC) signs a typed identity,
    //             because they have no account.
    //   Return  — the vendor's COLLECTOR makes that same declaration on our screen, and our
    //             Company PIC signs with their account.
    //
    // $secondSigned is asked direction-agnostically: it is what makes section 4 read-only,
    // so that neither party can rewrite the note the other already signed an answer to.
    $isReturn = $aarf->isReturn();
    $secondSigned = $aarf->secondPartyAcknowledged();
    $repSigned = $aarf->vendorRepAcknowledged();
    $processorSigner = \App\Models\RentalAssetAcknowledgement::actorIdentity($aarf->processorAcknowledger);
    $party = $isReturn ? 'Collector' : 'Receiving Staff';
    // OUR side of the document is labelled "Company PIC (Internal Purpose only)" in both
    // directions — section 4 on a receipt (we take delivery) and section 5 on a return (we
    // reply to the collector). $party alone can therefore no longer label it, so the heading
    // is resolved once here: two sections naming the same block from two places is how they
    // drift apart.
    //
    // The "(Internal Purpose only)" qualifier is the operator's wording, restored 2026-08-14
    // after a day without it, and it is deliberately carried into the PDF too — see
    // vendors/aarf/pdf.blade.php, and change one only by changing the other. The screen and
    // the printed copy must read identically, so the qualifier could not be applied to only
    // one of them. The consequence, stated and accepted: the vendor's PIC is emailed this
    // document on closing, so the other party does read a block marked internal to us. It
    // marks WHO OWNS the block — paired with "Vendor Representative's Remarks" opposite —
    // not what is withheld; nothing on this form is kept from the vendor.
    $ourBlockHeading = 'Company PIC (Internal Purpose only)';
    // Section 6 is named for the party who FILLS it, which flips with the direction. On a
    // receipt that is our own staff collecting the delivery, so the operator's wording for
    // the block is "Company PIC" (2026-08-15); on a return it is the vendor's courier and
    // the block keeps the Collector Details name — calling that block "Company PIC" would
    // file the vendor's declaration under us. Declared identically in
    // vendors/aarf/pdf.blade.php; change one only by changing the other.
    $collectorHeading = $isReturn ? 'Collector Details' : 'Company PIC';
    // "Acknowledged by …", the operator's wording (2026-08-15). These two strings are the
    // BUTTON labels and are also quoted back in section 7's guidance ("before pressing
    // …"), so they are resolved once here — the guidance must name the button exactly.
    $mainLabel   = 'Acknowledged by '.$aarf->mainPartyLabel();
    $secondLabel = 'Acknowledged by '.$aarf->secondPartyLabel();
    // Who is named as making the section 3 declaration, and which organisation they are
    // from. On a return that is the vendor's courier; on a receipt it is our own staff, so
    // the company is the entity the assets were rented TO — already stated in section 1, so
    // the two can never disagree. Falls back to the signed-in user's company when the assets
    // never recorded one, rather than printing "the collector from —".
    $tickCompany = $isReturn
        ? $aarf->vendor->name
        : ($aarf->company_rented_to ?: (auth()->user()?->employee?->company ?: 'your company'));
    // Collector fields are pre-filled from the signed-in user while that party has not yet
    // signed; old() still wins so a validation bounce never discards what was typed. Both
    // sides of the document post together (one form, two formactions), so old() also carries
    // the collector's tick and remarks back across the other party's round-trip.
    $val = fn ($field) => old($field, $mainSigned ? $aarf->{$field} : ($aarf->{$field} ?? ($prefill[$field] ?? null)));
@endphp

{{-- Flash messages are rendered once by layouts/app.blade.php for all four types —
     repeating them here is what put the same banner on the page twice. --}}

<style nonce="{{ $cspNonce ?? '' }}">
    /* One continuous document, not a stack of cards. Sections are separated by rules and
       headings the way a printed form is, so the screen matches the PDF. */
    .aarf-doc      { max-width: 1100px; }
    .aarf-sect     { font-size: .72rem; font-weight: 700; text-transform: uppercase;
                     letter-spacing: .09em; color: #64748b;
                     border-bottom: 1px solid #dbe2ea; padding-bottom: .35rem;
                     margin: 1.7rem 0 .9rem; }
    .aarf-sect:first-of-type { margin-top: .5rem; }
    .aarf-sect .n  { display: inline-block; min-width: 1.35rem; color: #94a3b8; }
    .aarf-sub      { font-size: .68rem; font-weight: 700; text-transform: uppercase;
                     letter-spacing: .08em; color: #94a3b8; margin: 1.1rem 0 .5rem; }
    .aarf-hero     { border-bottom: 2px solid #1f2d3d; padding-bottom: .9rem; }
    /* The merged header block: label-over-value cells on a light grid, so Report Number,
       Type, Company and the vendor read as one panel rather than four stacked lines. */
    .aarf-panel    { border: 1px solid #dfe5ec; border-radius: .5rem; overflow: hidden; }
    .aarf-panel .r { display: flex; flex-wrap: wrap; }
    .aarf-panel .c { flex: 1 1 220px; min-width: 200px; padding: .7rem .9rem;
                     border-right: 1px solid #eef2f6; border-bottom: 1px solid #eef2f6; }
    .aarf-panel .c:last-child { border-right: 0; }
    .aarf-panel .r:last-child .c { border-bottom: 0; }
    .aarf-panel .k { font-size: .67rem; text-transform: uppercase; letter-spacing: .06em;
                     color: #8b97a4; margin-bottom: .15rem; }
    .aarf-panel .v { font-weight: 600; line-height: 1.35; }
    .aarf-panel .m { font-size: .78rem; color: #7b8794; }
    .aarf-ref      { font-size: 1.25rem; font-weight: 700; letter-spacing: .02em; }
    .aarf-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .05em;
                     background: #f4f7fa; color: #55606d; }
    .aarf-table td, .aarf-table th { border: 1px solid #dfe5ec; padding: .45rem .6rem; }
    .aarf-tick     { border: 1px solid #dfe5ec; border-radius: .4rem; padding: .8rem 1rem;
                     background: #fbfdff; }
    /* The tick box IS the document — it is the declaration everything else supports, and
       Bootstrap's 1em faint-bordered default read as decoration beside the sentence it
       confirms. Enlarged with a real border; the row's padding is widened to match so the
       label can never ride over it. */
    .aarf-tick .form-check       { padding-left: 2.25rem; min-height: 1.6rem; }
    .aarf-tick .form-check-input { width: 1.4rem; height: 1.4rem; margin-left: -2.25rem;
                                   margin-top: .1rem; border: 2px solid #64748b;
                                   background-color: #fff; cursor: pointer; }
    .aarf-tick .form-check-input:checked { background-color: #198754; border-color: #198754; }
    .aarf-tick .form-check-input:focus   { box-shadow: 0 0 0 .2rem rgba(25,135,84,.25); }
    .aarf-tick .form-check-label { cursor: pointer; }
    .aarf-rep      { border: 1px dashed #c8d3e0; border-radius: .5rem; padding: 1rem; }
    /* Process log — the same vertical timeline the decommission cycle log uses, restated
       here because that one's CSS lives inside it/decommission/show.blade.php rather than in
       the shared partial this page already includes. */
    .aarf-log       { list-style: none; margin: 0; padding: 0; }
    .aarf-log-step  { position: relative; padding: 0 0 1.05rem 1.9rem; }
    .aarf-log-step:last-child { padding-bottom: 0; }
    .aarf-log-step::before { content: ''; position: absolute; left: .48rem; top: 1.15rem;
                             bottom: -.1rem; width: 2px; background: #e2e8f0; }
    .aarf-log-step:last-child::before { display: none; }
    .aarf-log-dot   { position: absolute; left: 0; top: .12rem; width: 1.05rem; height: 1.05rem;
                      border-radius: 50%; display: inline-flex; align-items: center;
                      justify-content: center; font-size: .6rem; color: #fff;
                      background: linear-gradient(135deg, #22c55e, #15803d); }
    /* Outstanding work is a hollow dot, not a green one: the last row of this list is the
       one place the reader looks to see whether anything is still owed. */
    .aarf-log-open .aarf-log-dot   { background: #e2e8f0; }
    .aarf-log-open .aarf-log-title { color: #94a3b8; font-weight: 500; }
    .aarf-log-title { font-weight: 600; color: #1e293b; font-size: .85rem; }
    .aarf-log-meta  { font-size: .74rem; color: #64748b; }
    .aarf-log-line  { font-size: .74rem; color: #7b8794; }
    .aarf-log-intro { font-size: .78rem; color: #7b8794; margin-bottom: .85rem; }
    @media (prefers-color-scheme: dark) {
        .aarf-log-step::before { background: #33404f; }
        .aarf-log-open .aarf-log-dot { background: #33404f; }
        .aarf-log-title { color: #dfe6ee; }
        .aarf-log-meta, .aarf-log-line, .aarf-log-intro { color: #8b97a4; }
        .aarf-sect  { color: #94a3b8; border-bottom-color: #33404f; }
        .aarf-hero  { border-bottom-color: #64748b; }
        .aarf-panel { border-color: #33404f; }
        .aarf-panel .c { border-right-color: #2a3542; border-bottom-color: #2a3542; }
        .aarf-panel .k, .aarf-panel .m { color: #8b97a4; }
        .aarf-table th { background: #202b38; color: #9aa7b5; }
        .aarf-table td, .aarf-table th { border-color: #33404f; }
        .aarf-tick  { background: #1b2430; border-color: #33404f; }
        .aarf-tick .form-check-input { background-color: #0f1720; border-color: #8b97a4; }
        .aarf-tick .form-check-input:checked { background-color: #198754; border-color: #198754; }
        .aarf-rep   { border-color: #3d4c5e; }
    }
</style>

<div class="container-fluid px-0 aarf-doc">
    <div class="mb-3">
        {{-- Back to the Report tab, which is where this form is listed — the Assets tab it
             used to point at no longer carries the AARF register. --}}
        <a href="{{ route('vendors.show', [$vendor, 'tab' => 'report']) }}" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $vendor->name }}
        </a>
    </div>

    {{-- ONE card = the whole form. --}}
    <div class="card">
        <div class="card-body p-4">

            {{-- Document masthead --}}
            <div class="aarf-hero d-flex justify-content-between align-items-start flex-wrap gap-3">
                {{-- No company name here on purpose. It used to print the org from
                     config('decommission.org_name'), which is always Claritas — but the
                     entity on this document is whoever RENTED the assets, and that is
                     already stated as "Company Rented To" in section 1. A fixed letterhead
                     would have contradicted it on every form raised for another company. --}}
                <div>
                    <div class="h5 fw-bold mb-0">Asset Acceptance &amp; Return Form (AARF)</div>
                </div>
                <div class="text-end">
                    <span class="badge rounded-pill bg-{{ $badge['color'] }} mb-2">{{ $badge['label'] }}</span>
                    <div>
                        <a href="{{ route('vendors.aarf.pdf', [$vendor, $aarf]) }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-file-earmark-pdf me-1"></i>Download PDF
                        </a>
                    </div>
                </div>
            </div>

            {{-- ONE form, declared empty here; every control attaches by `form="aarfAckForm"`.

                 It is one form and not two because the two sides interleave — the vendor
                 rep's block sits between our tick box and our collector details — and a
                 nested <form> is invalid HTML that browsers silently drop.

                 Two forms was the first attempt and it lost the receiving staff's work:
                 submitting the rep's form sent only the rep's fields, so the tick box and
                 the condition remarks typed above it were never posted and came back blank.
                 With one form BOTH sides are always submitted, and the two buttons differ
                 only by `formaction` — each server action then saves just its own fields. --}}
            @if(! $closed && $canManage)
                <form id="aarfAckForm" action="{{ route('vendors.aarf.acknowledge', [$vendor, $aarf]) }}" method="POST">@csrf</form>
            @endif

            {{-- 1 ─ Report Details (Report Number, Type of Process, Company Rented To, Vendor) --}}
            <div class="aarf-sect"><span class="n">1</span>Report Details</div>
            <div class="aarf-panel">
                <div class="r">
                    <div class="c">
                        <div class="k">Report Number</div>
                        <div class="aarf-ref">{{ $aarf->reference }}</div>
                    </div>
                    <div class="c">
                        <div class="k">Type of Process</div>
                        <div class="v">{{ $aarf->typeLabel() }}</div>
                    </div>
                    <div class="c">
                        <div class="k">Company Rented To</div>
                        <div class="v">{{ $aarf->company_rented_to ?: '— not specified on the assets —' }}</div>
                    </div>
                </div>
                <div class="r">
                    <div class="c">
                        <div class="k">Date Prepared</div>
                        <div class="v">{{ fmt_date($aarf->created_at) }}</div>
                    </div>
                    <div class="c">
                        <div class="k">Total Assets</div>
                        <div class="v">{{ $aarf->items->count() }}</div>
                    </div>
                    <div class="c">
                        <div class="k">Status</div>
                        <div class="v">{{ $badge['label'] }}</div>
                    </div>
                </div>
            </div>

            <div class="aarf-sub">Vendor Details</div>
            <div class="aarf-panel">
                <div class="r">
                    <div class="c">
                        <div class="k">Vendor</div>
                        <div class="v">{{ $aarf->vendor->name }}</div>
                        @if($aarf->vendor->company_registration_no)
                            <div class="m">Reg. No. {{ $aarf->vendor->company_registration_no }}</div>
                        @endif
                    </div>
                    <div class="c">
                        <div class="k">Person In Charge</div>
                        <div class="v">{{ $aarf->vendor->pic_name ?: '—' }}</div>
                        <div class="m">{{ collect([$aarf->vendor->pic_email, $aarf->vendor->pic_phone])->filter()->implode(' · ') ?: '—' }}</div>
                    </div>
                    <div class="c">
                        <div class="k">Contact</div>
                        <div class="v">{{ $aarf->vendor->contact_number ?: '—' }}</div>
                        @if($aarf->vendor->address)
                            <div class="m">{{ $aarf->vendor->address }}</div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- 2 ─ List of Assets (Section A only) --}}
            <div class="aarf-sect"><span class="n">2</span>List of Assets &mdash; Section A</div>
            <div class="table-responsive">
                <table class="table table-sm aarf-table align-middle mb-1">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Asset Tag</th>
                            <th>Category</th>
                            <th>Type</th>
                            <th>Brand</th>
                            <th>Model</th>
                            <th>Spec</th>
                            <th>Serial Number</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($aarf->items as $i => $item)
                        <tr>
                            <td class="text-muted small">{{ $i + 1 }}</td>
                            <td class="fw-semibold small">{{ $item->asset_tag ?: '—' }}</td>
                            <td class="small">{{ $categories[$item->asset_category] ?? ($item->asset_category ?: '—') }}</td>
                            <td class="small">{{ $item->asset_type ?: '—' }}</td>
                            <td class="small">{{ $item->brand ?: '—' }}</td>
                            <td class="small">{{ $item->model ?: '—' }}</td>
                            {{-- Snapshot, not $item->asset->specSummary(): a spec re-keyed
                                 later must not change what a signed form says was handed
                                 over. Blank on forms raised before the column existed. --}}
                            <td class="small">{{ $item->spec_summary ?: '—' }}</td>
                            <td class="small">{{ $item->serial_number ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="text-muted small">Total: {{ $aarf->items->count() }} asset{{ $aarf->items->count() === 1 ? '' : 's' }}</div>

            {{-- 3 ─ Confirmation (mandatory, and the only tick box on the form).
                 On a receipt it is OUR declaration; on a return it is the collector's, made
                 on our screen — which is why the wording says who is speaking. --}}
            <div class="aarf-sect"><span class="n">3</span>Confirmation</div>
            @php
                $tickText = $isReturn
                    ? 'I confirm that I have collected the assets listed above and received them in good condition and without any physical damage.'
                    : 'I confirm that the assets listed above were received in good condition and without any physical damage.';
            @endphp
            {{-- Both directions carry the instruction; only the organisation changes, because
                 only the person changes. On a return the collector comes from the vendor; on
                 a receipt they are our own staff, so it names the company the assets were
                 rented to. --}}
            @if(! $mainSigned && $canManage)
            <div class="text-muted small mb-2">
                To be read and acknowledged by ticking on the box below by the collector from {{ $tickCompany }}.
            </div>
            @endif
            <div class="aarf-tick">
                @if($mainSigned)
                    <i class="bi bi-check-square-fill text-success me-1"></i>
                    {{ $tickText }}
                @elseif($canManage)
                    <div class="form-check mb-0">
                        <input class="form-check-input @error('condition_confirmed') is-invalid @enderror"
                               type="checkbox" name="condition_confirmed" value="1" id="aarfConditionConfirmed"
                               form="aarfAckForm" {{ old('condition_confirmed') ? 'checked' : '' }} required>
                        <label class="form-check-label" for="aarfConditionConfirmed">
                            {{ $tickText }} <span class="text-danger">*</span>
                            <span class="text-muted d-block small">
                                Mandatory. Anything not covered by this must be written in the Condition Remarks below.
                            </span>
                        </label>
                        @error('condition_confirmed')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                @else
                    <span class="text-muted">Not yet confirmed.</span>
                @endif
            </div>

            {{-- 4 ─ Condition Remarks — whoever is RECEIVING the assets in this direction.
                 On a receipt that is us, so the block takes $ourBlockHeading; on a return it
                 is the vendor's collector and keeps the Condition Remarks name. --}}
            <div class="aarf-sect"><span class="n">4</span>{{ $isReturn ? 'Condition Remarks — '.$party : $ourBlockHeading }}</div>
            <div class="text-muted small mb-2">
                @if($isReturn)
                    Completed by the collector: note any asset they are NOT receiving in good condition.
                @else
                    Completed by our staff processing this receipt: note any asset received damaged or
                    not in good condition.
                @endif
            </div>
            {{-- Read-only once the vendor rep has signed a reply to it: this note is the
                 question their signature answers, and editing it afterwards would put words
                 above their name. The controller enforces the same thing. --}}
            @if($mainSigned || ! $canManage || $secondSigned)
                <div class="small">{{ $aarf->condition_remarks ?: 'None recorded.' }}</div>
                @if($secondSigned && ! $mainSigned)
                    <div class="text-muted small mt-1">
                        <i class="bi bi-lock me-1"></i>Locked &mdash;
                        {{ $isReturn ? ($processorSigner['name'] ?? 'the Company PIC') : $aarf->vendor_rep_name }}
                        has signed a reply to this note.
                    </div>
                @endif
            @else
                <textarea name="condition_remarks" rows="3" maxlength="2000" form="aarfAckForm"
                          class="form-control @error('condition_remarks') is-invalid @enderror"
                          placeholder="Leave remarks if any">{{ old('condition_remarks', $aarf->condition_remarks) }}</textarea>
                @error('condition_remarks')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @endif

            {{-- 5 ─ The second party answers the condition remarks above.

                 WHO that is flips with the direction, and so does how they sign:

                 • Receipt — the VENDOR's delivery representative. They have no account, so
                   their typed identity plus a timestamp is the signature, captured by its
                   own submit (`vendorAcknowledge`).
                 • Return — OUR Company PIC. They ARE logged in, so the account reference plus
                   the timestamp is the signature and nothing is typed — but it is still
                   their own signed statement, captured by its own submit
                   (`processorAcknowledge`).

                 The REMARKS in this section are optional in both directions; the signature
                 is not. It acknowledges the handover, not the paragraph above it. --}}
            @if($isReturn)
            <div class="aarf-sect"><span class="n">5</span>{{ $ourBlockHeading }}</div>
            <div class="text-muted small mb-2">
                Completed by our staff processing this return, in response to the collector&rsquo;s
                remarks above. Optional &mdash; your acknowledgement in section 7 covers the whole
                handover, not only these remarks.
            </div>

            @if($aarf->processorAcknowledged())
                <div class="aarf-rep">
                    {{-- Remarks are optional, so a signature with none is normal and must
                         read as such rather than as an empty box above a name. --}}
                    <div class="mb-3 @if(! $aarf->processor_remarks) text-muted small @endif">{{ $aarf->processor_remarks ?: 'No remarks recorded.' }}</div>
                    {{-- Signature stated once, in section 7 — see the receipt arm below for
                         why. The two directions must lose it together or the same template
                         prints the same fact a different number of times each way. --}}
                </div>
            @elseif($closed || ! $canManage)
                <div class="small">{{ $aarf->processor_remarks ?: 'None recorded.' }}</div>
            @else
                <div class="aarf-rep">
                    <textarea name="processor_remarks" rows="3" maxlength="2000" form="aarfAckForm"
                              class="form-control @error('processor_remarks') is-invalid @enderror"
                              placeholder="Leave remarks if any">{{ old('processor_remarks', $aarf->processor_remarks) }}</textarea>
                    @error('processor_remarks')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                    {{-- The signature button used to sit here, which read as though it signed
                         only these remarks. It signs the handover, so it now sits beside the
                         collector's in section 7 — this line is what still points at it. --}}
                    <div class="text-muted small mt-2">
                        <i class="bi bi-arrow-down-circle me-1"></i>Sign in section 7 below.
                    </div>
                </div>
            @endif
            @else
            <div class="aarf-sect"><span class="n">5</span>Vendor Representative&rsquo;s Remarks</div>
            <div class="text-muted small mb-2">
                Completed by the representative from {{ $aarf->vendor->name }} who delivered the assets,
                in response to the condition remarks above. Optional &mdash; their acknowledgement in
                section 7 covers the whole handover, not only these remarks.
            </div>

            @if($repSigned)
                <div class="aarf-rep">
                    {{-- Remarks are optional, so a signature with none is normal and must
                         read as such rather than as an empty box above a name. --}}
                    <div class="mb-3 @if(! $aarf->vendor_rep_remarks) text-muted small @endif">{{ $aarf->vendor_rep_remarks ?: 'No remarks recorded.' }}</div>
                    <div class="aarf-panel">
                        <div class="r">
                            <div class="c"><div class="k">Company</div><div class="v">{{ $aarf->vendor_rep_company ?: '—' }}</div></div>
                            <div class="c"><div class="k">Name</div><div class="v">{{ $aarf->vendor_rep_name ?: '—' }}</div></div>
                            <div class="c"><div class="k">IC / Passport</div><div class="v">{{ $aarf->vendor_rep_ic ?: '—' }}</div></div>
                            <div class="c"><div class="k">Contact Number</div><div class="v">{{ $aarf->vendor_rep_phone ?: '—' }}</div></div>
                        </div>
                    </div>
                    {{-- No "acknowledged by …" line here. This section carries the vendor
                         rep's WORDS and the identity they are recorded against; the
                         SIGNATURE itself is stated once, in section 7, alongside the other
                         party's. Printing it here as well put the same fact on the page
                         three times — twice above the fold and again in the sign-off. --}}
                </div>
            @elseif($closed || ! $canManage)
                <div class="small text-muted">No remarks from the vendor representative.</div>
            @else
                <div class="aarf-rep">
                    <textarea name="vendor_rep_remarks" rows="3" maxlength="2000" form="aarfAckForm"
                              class="form-control mb-3 @error('vendor_rep_remarks') is-invalid @enderror"
                              placeholder="Leave remarks if any">{{ old('vendor_rep_remarks', $aarf->vendor_rep_remarks) }}</textarea>
                    @error('vendor_rep_remarks')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Company</label>
                            <input type="text" name="vendor_rep_company" maxlength="255" form="aarfAckForm"
                                   class="form-control form-control-sm @error('vendor_rep_company') is-invalid @enderror"
                                   value="{{ old('vendor_rep_company', $aarf->vendor_rep_company ?: $aarf->vendor->name) }}">
                            @error('vendor_rep_company')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                            <input type="text" name="vendor_rep_name" maxlength="255" form="aarfAckForm"
                                   class="form-control form-control-sm @error('vendor_rep_name') is-invalid @enderror"
                                   value="{{ old('vendor_rep_name', $aarf->vendor_rep_name) }}">
                            @error('vendor_rep_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">IC / Passport <span class="text-danger">*</span></label>
                            <input type="text" name="vendor_rep_ic" maxlength="60" form="aarfAckForm"
                                   class="form-control form-control-sm @error('vendor_rep_ic') is-invalid @enderror"
                                   value="{{ old('vendor_rep_ic', $aarf->vendor_rep_ic) }}">
                            @error('vendor_rep_ic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Contact Number</label>
                            <input type="text" name="vendor_rep_phone" maxlength="50" form="aarfAckForm"
                                   class="form-control form-control-sm @error('vendor_rep_phone') is-invalid @enderror"
                                   value="{{ old('vendor_rep_phone', $aarf->vendor_rep_phone) }}">
                            @error('vendor_rep_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    {{-- The signature button used to sit here, under these fields, which read
                         as though it signed only the remarks. It signs the handover, so it
                         now sits beside ours in section 7; the identity above is what it is
                         recorded against. --}}
                    <div class="text-muted small mt-3">
                        <i class="bi bi-arrow-down-circle me-1"></i>Fill in the identity above, then sign in
                        section 7 below &mdash; acknowledging the receipt there closes the whole document.
                    </div>
                </div>
            @endif
            @endif{{-- /direction split for section 5 --}}

            {{-- 6 ─ Who collected, and their identity.
                 Receipt: us, pre-filled from the signed-in account — headed "Company PIC".
                 Return: the vendor's courier, typed, headed "Collector Details" — only their
                 company is suggested, because putting our own staff's name and IC here would
                 file the vendor's declaration under us. --}}
            <div class="aarf-sect"><span class="n">6</span>{{ $collectorHeading }}</div>
            @if($isReturn && ! $mainSigned && $canManage)
            <div class="text-muted small mb-2">
                The person collecting the assets on behalf of {{ $aarf->vendor->name }}. Entered by them.
            </div>
            @endif
            @if($mainSigned || ! $canManage)
                <div class="aarf-panel">
                    <div class="r">
                        <div class="c"><div class="k">Company</div><div class="v">{{ $aarf->collector_company ?: '—' }}</div></div>
                        <div class="c"><div class="k">Name</div><div class="v">{{ $aarf->collector_name ?: '—' }}</div></div>
                        <div class="c"><div class="k">IC / Passport</div><div class="v">{{ $aarf->collector_ic ?: '—' }}</div></div>
                        <div class="c"><div class="k">Phone Number</div><div class="v">{{ $aarf->collector_phone ?: '—' }}</div></div>
                    </div>
                </div>
            @else
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Company</label>
                        <input type="text" name="collector_company" maxlength="255" form="aarfAckForm"
                               class="form-control form-control-sm @error('collector_company') is-invalid @enderror"
                               value="{{ $val('collector_company') }}">
                        @error('collector_company')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                        <input type="text" name="collector_name" maxlength="255" required form="aarfAckForm"
                               class="form-control form-control-sm @error('collector_name') is-invalid @enderror"
                               value="{{ $val('collector_name') }}">
                        @error('collector_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">IC / Passport <span class="text-danger">*</span></label>
                        <input type="text" name="collector_ic" maxlength="60" required form="aarfAckForm"
                               class="form-control form-control-sm @error('collector_ic') is-invalid @enderror"
                               value="{{ $val('collector_ic') }}">
                        @error('collector_ic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Phone Number</label>
                        <input type="text" name="collector_phone" maxlength="50" form="aarfAckForm"
                               class="form-control form-control-sm @error('collector_phone') is-invalid @enderror"
                               value="{{ $val('collector_phone') }}">
                        @error('collector_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            @endif

            {{-- 7 ─ Acknowledgement — BOTH signatures, in either order.
                 A handover is an agreement between two parties, so neither signature closes
                 the form on its own and neither party is barred by the other having gone
                 first. Each side's state is shown independently, and each button disappears
                 only when THAT side has signed.

                 TWO facts, in BOTH directions, and they are not the same fact: the person
                 NAMED IN SECTION 6 made the declaration (stamped by `acknowledged_at`), and
                 the handover was PROCESSED UNDER an account (`acknowledged_by`).

                 The return arm always printed both. The receipt arm printed only the account
                 until 2026-08-14, which was wrong for exactly the reason section 6 exists:
                 those fields are pre-filled from the signed-in user but stay EDITABLE,
                 because "a courier or a colleague without a login may be the one signing".
                 Edit them and the document named two different people with nothing
                 reconciling them — section 6 said one, section 7 and the sign-off said the
                 other. One code path now, so the two directions cannot drift again. --}}
            <div class="aarf-sect"><span class="n">7</span>Acknowledgement</div>
            @php $me = \App\Models\RentalAssetAcknowledgement::actorIdentity(auth()->user()); @endphp

            {{-- Side one — the main declaration (tick, condition note, collector details). --}}
            @if($mainSigned)
                <div class="alert alert-success py-2 px-3 small">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    Acknowledged by {{ $aarf->mainPartyLabel() }}
                    <strong>{{ $aarf->collector_name ?: '—' }}</strong>@if($aarf->collector_company) <span class="text-muted">({{ $aarf->collector_company }})</span>@endif
                    on {{ fmt_datetime($aarf->acknowledged_at) }},
                    processed under the account of <strong>{{ $signer['name'] ?? '—' }}</strong>@if(!empty($signer['details'])) <span class="text-muted">({{ $signer['details'] }})</span>@endif.
                    @if($isReturn && $closed)
                        The assets on this form have been archived out of the inventory.
                    @endif
                </div>
            @elseif($canManage)
                <div class="alert alert-light border py-2 px-3 small">
                    <i class="bi bi-person-badge me-1"></i>
                    @if($isReturn)
                        The signatory is the <strong>collector named in section 6</strong> &mdash; their
                        acknowledgement is recorded against their typed identity and the moment they sign.
                        The form also records that it was processed under the account of
                        <strong>{{ $me['name'] ?? auth()->user()->name }}</strong>, taken from your
                        account, not typed. Hand the screen to the collector before pressing
                        &ldquo;{{ $mainLabel }}&rdquo;. The {{ $aarf->items->count() }}
                        asset{{ $aarf->items->count() === 1 ? '' : 's' }} above leave the inventory and the
                        Decommissioning queue once both parties have acknowledged.
                    @else
                        &ldquo;{{ $mainLabel }}&rdquo; will be recorded as signed by
                        <strong>{{ $me['name'] ?? auth()->user()->name }}</strong>
                        &mdash; taken from your account, not typed.
                    @endif
                </div>
            @endif

            {{-- Side two — their own signature, shown the moment it lands rather than only
                 in section 5, so this section states who has agreed and who has not. --}}
            @if($secondSigned)
                <div class="alert alert-success py-2 px-3 small">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    Acknowledged by {{ $aarf->secondPartyLabel() }}
                    <strong>{{ $isReturn ? ($processorSigner['name'] ?? '—') : ($aarf->vendor_rep_name ?: '—') }}</strong>@if($isReturn && !empty($processorSigner['details'])) <span class="text-muted">({{ $processorSigner['details'] }})</span>@elseif(! $isReturn && $aarf->vendor_rep_company) <span class="text-muted">({{ $aarf->vendor_rep_company }})</span>@endif
                    on {{ fmt_datetime($aarf->secondPartyAcknowledgedAt()) }}.
                </div>
            @endif

            @if(! $canManage && ! $closed)
                <div class="ewx-empty">
                    <i class="bi bi-lock"></i>This AARF has not been acknowledged yet, and you do not have permission to sign it.
                </div>
            @elseif(! $closed && $canManage)
                {{-- BOTH buttons live here, because both are acknowledgements of the handover
                     rather than of the paragraph they used to sit under. The main declaration
                     keeps the solid button on the left; the second party's sits on the right.
                     Each is dropped once that side has signed, so the row is what still needs
                     doing — never a control that would 422 on press.

                     `formnovalidate` on the second party's button is load-bearing, exactly as
                     it was in section 5: the collector details carry `required`, and without
                     it the browser would refuse this submit until somebody else's fields were
                     filled. Their own fields are validated server-side and render inline. --}}
                <div class="d-flex flex-wrap align-items-center gap-2">
                    @unless($mainSigned)
                        <button type="submit" form="aarfAckForm" class="btn btn-success fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i>{{ $mainLabel }}
                        </button>
                    @endunless
                    @unless($secondSigned)
                        <button type="submit" form="aarfAckForm" class="btn btn-outline-success fw-semibold ms-auto"
                                formaction="{{ $isReturn
                                    ? route('vendors.aarf.processor-acknowledge', [$vendor, $aarf])
                                    : route('vendors.aarf.vendor-acknowledge', [$vendor, $aarf]) }}"
                                formnovalidate>
                            <i class="bi bi-pen me-1"></i>{{ $secondLabel }}
                        </button>
                    @endunless
                </div>
            @endif

            {{-- Sign-off trail --}}
            <div class="aarf-sect">Sign-off</div>
            <div class="aarf-panel">
                <div class="r">
                    <div class="c">
                        <div class="k">Prepared By</div>
                        <div class="v">{{ $preparer['name'] ?? 'Not recorded' }}</div>
                        @if(!empty($preparer['details']))<div class="m">{{ $preparer['details'] }}</div>@endif
                        <div class="m">{{ fmt_datetime($aarf->created_at) }}</div>
                    </div>
                    @if($isReturn)
                    {{-- On a return the signatory is the vendor's collector. The account the
                         handover was PROCESSED UNDER had a cell of its own here until
                         2026-08-14, when the operator asked for it off the panel in both
                         directions; the fact is not lost, because section 7 above still states
                         it in prose ("processed under the account of …"), which is also what
                         keeps the two parties from being credited with each other's act. --}}
                    <div class="c">
                        <div class="k">Acknowledged By ({{ $aarf->vendor->name }})</div>
                        <div class="v">{{ $aarf->collector_name ?: 'Not yet acknowledged' }}</div>
                        @if($aarf->collector_company)<div class="m">{{ $aarf->collector_company }}</div>@endif
                        @if($aarf->collector_ic)<div class="m">IC / Passport {{ $aarf->collector_ic }}</div>@endif
                        @if($aarf->acknowledged_at)<div class="m">{{ fmt_datetime($aarf->acknowledged_at) }}</div>@endif
                    </div>
                    @if($aarf->processorAcknowledged())
                    <div class="c">
                        <div class="k">Acknowledged By (Company PIC)</div>
                        <div class="v">{{ $processorSigner['name'] ?? '—' }}</div>
                        @if(!empty($processorSigner['details']))<div class="m">{{ $processorSigner['details'] }}</div>@endif
                        <div class="m">{{ fmt_datetime($aarf->processor_acknowledged_at) }}</div>
                    </div>
                    @endif
                    @else
                    {{-- Labelled with the same two names section 7 uses. They were
                         "Acknowledged By (Receiving)" and "Vendor Representative" until
                         2026-08-13, which left one page calling the same two people three
                         different things.

                         The signatory is whoever section 6 NAMES — normally, but not always,
                         the account holder, which is why the cell prints `collector_name` and
                         not the signer. The account itself came off this panel on 2026-08-14
                         with the return arm's, and stays stated in section 7's prose. --}}
                    <div class="c">
                        <div class="k">Acknowledged By (Company PIC)</div>
                        <div class="v">{{ $aarf->collector_name ?: 'Not yet acknowledged' }}</div>
                        @if($aarf->collector_company)<div class="m">{{ $aarf->collector_company }}</div>@endif
                        @if($aarf->collector_ic)<div class="m">IC / Passport {{ $aarf->collector_ic }}</div>@endif
                        @if($aarf->acknowledged_at)<div class="m">{{ fmt_datetime($aarf->acknowledged_at) }}</div>@endif
                    </div>
                    <div class="c">
                        <div class="k">Acknowledged By (Vendor PIC)</div>
                        <div class="v">{{ $aarf->vendor_rep_name ?: 'Not signed' }}</div>
                        @if($aarf->vendor_rep_company)<div class="m">{{ $aarf->vendor_rep_company }}</div>@endif
                        {{-- The IC is what makes a typed name a signature, and the cell
                             opposite prints it. The PDF prints it in both. --}}
                        @if($aarf->vendor_rep_ic)<div class="m">IC / Passport {{ $aarf->vendor_rep_ic }}</div>@endif
                        @if($aarf->vendor_rep_acknowledged_at)<div class="m">{{ fmt_datetime($aarf->vendor_rep_acknowledged_at) }}</div>@endif
                    </div>
                    @endif
                </div>
            </div>

            {{-- Process log — the SYSTEM's record of the document, from the day it was raised
                 to the final acknowledgement.

                 Screen only, and that is the requirement rather than an oversight: the PDF
                 template renders none of this, so neither the downloaded copy nor the one
                 emailed to the vendor's PIC carries it. Keep it that way — the printed form is
                 what the two parties signed, and a page of internal handling appended to it
                 would be a page nobody signed.

                 Every entry is DERIVED from a stored column; see
                 RentalAssetAcknowledgement::activityLog() for why there is no event table
                 behind it and what that rules out. --}}
            <div class="aarf-sect">Process Log</div>
            <p class="aarf-log-intro">
                A system record of this form, from the day it was raised to the final
                acknowledgement. It is shown here only &mdash; the printed copy and the copy
                emailed to the vendor carry the form itself, not this log.
            </p>
            <ul class="aarf-log">
                @foreach($aarf->activityLog() as $step)
                    <li class="aarf-log-step {{ $step['state'] === 'pending' ? 'aarf-log-open' : '' }}">
                        <span class="aarf-log-dot">
                            @if($step['state'] !== 'pending')
                                <i class="bi bi-check"></i>
                            @endif
                        </span>
                        <div class="aarf-log-title">{{ $step['title'] }}</div>
                        <div class="aarf-log-meta">
                            {{-- A step with no timestamp is the outstanding one, and saying so
                                 is the point of printing it at all. --}}
                            {{ $step['at'] ? fmt_datetime($step['at']) : 'Not yet' }}
                            @if($step['by'])
                                &middot; {{ $step['by'] }}
                            @endif
                            @if($step['by_meta'])
                                <span class="aarf-log-line">({{ $step['by_meta'] }})</span>
                            @endif
                        </div>
                        @foreach($step['notes'] as $note)
                            <div class="aarf-log-line">{{ $note }}</div>
                        @endforeach
                    </li>
                @endforeach
            </ul>

        </div>
    </div>
</div>
@endsection
