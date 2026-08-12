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
    $locked = $aarf->isAcknowledged();
    // Which way the assets moved decides WHO fills each block, not just the wording — and
    // the two parties are symmetrical. In BOTH directions the second party replies to the
    // condition note and signs that reply first, then the closing signatory locks the form:
    //
    //   Receipt — we note damage and close; the vendor's delivery rep replies, typing their
    //             identity because they have no account.
    //   Return  — the vendor's collector notes damage and closes; our processor replies,
    //             signed by their account because they are logged in.
    //
    // $secondSigned is therefore asked direction-agnostically: it is what makes section 4
    // read-only, so that neither closing signatory can rewrite the question the other party
    // already signed an answer to.
    $isReturn = $aarf->isReturn();
    $secondSigned = $aarf->secondPartyAcknowledged();
    $repSigned = $aarf->vendorRepAcknowledged();
    $processorSigner = \App\Models\RentalAssetAcknowledgement::actorIdentity($aarf->processorAcknowledger);
    $party = $isReturn ? 'Collector' : 'Receiving Staff';
    // Collector fields are pre-filled from the signed-in user while the form is a draft;
    // old() still wins so a validation bounce never discards what was typed. Both sides of
    // the document post together (one form, two formactions), so old() also carries the
    // receiving staff's tick and remarks back across the vendor rep's round-trip.
    $val = fn ($field) => old($field, $locked ? $aarf->{$field} : ($aarf->{$field} ?? ($prefill[$field] ?? null)));
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
    .aarf-rep      { border: 1px dashed #c8d3e0; border-radius: .5rem; padding: 1rem; }
    @media (prefers-color-scheme: dark) {
        .aarf-sect  { color: #94a3b8; border-bottom-color: #33404f; }
        .aarf-hero  { border-bottom-color: #64748b; }
        .aarf-panel { border-color: #33404f; }
        .aarf-panel .c { border-right-color: #2a3542; border-bottom-color: #2a3542; }
        .aarf-panel .k, .aarf-panel .m { color: #8b97a4; }
        .aarf-table th { background: #202b38; color: #9aa7b5; }
        .aarf-table td, .aarf-table th { border-color: #33404f; }
        .aarf-tick  { background: #1b2430; border-color: #33404f; }
        .aarf-rep   { border-color: #3d4c5e; }
    }
</style>

<div class="container-fluid px-0 aarf-doc">
    <div class="mb-3">
        <a href="{{ route('vendors.show', [$vendor, 'tab' => 'assets']) }}" class="text-decoration-none small text-muted">
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
            @if(! $locked && $canManage)
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
            @if($isReturn && ! $locked && $canManage)
            <div class="text-muted small mb-2">
                To be read and ticked by the collector from {{ $aarf->vendor->name }}, on this screen.
            </div>
            @endif
            <div class="aarf-tick">
                @if($locked)
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

            {{-- 4 ─ Condition Remarks — whoever is RECEIVING the assets in this direction --}}
            <div class="aarf-sect"><span class="n">4</span>Condition Remarks &mdash; {{ $party }}</div>
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
            @if($locked || ! $canManage || $secondSigned)
                <div class="small">{{ $aarf->condition_remarks ?: 'None recorded.' }}</div>
                @if($secondSigned && ! $locked)
                    <div class="text-muted small mt-1">
                        <i class="bi bi-lock me-1"></i>Locked &mdash;
                        {{ $isReturn ? ($processorSigner['name'] ?? 'our processor') : $aarf->vendor_rep_name }}
                        has signed a reply to this note.
                    </div>
                @endif
            @else
                <textarea name="condition_remarks" rows="3" maxlength="2000" form="aarfAckForm"
                          class="form-control @error('condition_remarks') is-invalid @enderror"
                          placeholder="e.g. LT-0042 — screen has a hairline crack on the lower left.">{{ old('condition_remarks', $aarf->condition_remarks) }}</textarea>
                @error('condition_remarks')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @endif

            {{-- 5 ─ The second party answers the condition remarks above.

                 WHO that is flips with the direction, and so does how they sign:

                 • Receipt — the VENDOR's delivery representative. They have no account, so
                   their typed identity plus a timestamp is the signature, captured by its
                   own submit (`vendorAcknowledge`) before we close the document.
                 • Return — OUR processor. They ARE logged in, so the account reference plus
                   the timestamp is the signature and nothing is typed — but it is still
                   their own signed statement, captured by its own submit
                   (`processorAcknowledge`) before the collector closes the document. --}}
            @if($isReturn)
            <div class="aarf-sect"><span class="n">5</span>Processor&rsquo;s Remarks (Internal Purpose Only)</div>
            <div class="text-muted small mb-2">
                Completed by our staff processing this return, in response to the collector&rsquo;s
                remarks above. Optional &mdash; but signed by you if used.
            </div>

            @if($aarf->processorAcknowledged())
                <div class="aarf-rep">
                    <div class="mb-3">{{ $aarf->processor_remarks }}</div>
                    <div class="alert alert-success py-2 px-3 small mb-0">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        Signed by <strong>{{ $processorSigner['name'] ?? '—' }}</strong>@if(!empty($processorSigner['details'])) <span class="text-muted">({{ $processorSigner['details'] }})</span>@endif
                        on {{ fmt_datetime($aarf->processor_acknowledged_at) }}.
                    </div>
                </div>
            @elseif($locked || ! $canManage)
                <div class="small">{{ $aarf->processor_remarks ?: 'None recorded.' }}</div>
            @else
                <div class="aarf-rep">
                    <textarea name="processor_remarks" rows="3" maxlength="2000" form="aarfAckForm"
                              class="form-control @error('processor_remarks') is-invalid @enderror"
                              placeholder="e.g. Crack on LT-0042 predates this rental; photographed and accepted by the collector.">{{ old('processor_remarks', $aarf->processor_remarks) }}</textarea>
                    @error('processor_remarks')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                    <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                        {{-- Same form as the collector's, different target. `formnovalidate`
                             is required: the collector's own fields below carry `required`,
                             and without it the browser would refuse this submit until their
                             name and IC were filled — which is not this person's job. Our
                             field is validated server-side and renders inline. --}}
                        <button type="submit" form="aarfAckForm" class="btn btn-outline-success btn-sm fw-semibold"
                                formaction="{{ route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]) }}"
                                formnovalidate>
                            <i class="bi bi-pen me-1"></i>Acknowledge as Processor
                        </button>
                        <span class="text-muted small">
                            Sign before the collector closes the form below &mdash; acknowledging the return locks the whole document.
                        </span>
                    </div>
                </div>
            @endif
            @else
            <div class="aarf-sect"><span class="n">5</span>Vendor Representative&rsquo;s Remarks</div>
            <div class="text-muted small mb-2">
                Completed by the representative from {{ $aarf->vendor->name }} who delivered the assets,
                in response to the condition remarks above. Optional &mdash; but signed by them if used.
            </div>

            @if($repSigned)
                <div class="aarf-rep">
                    <div class="mb-3">{{ $aarf->vendor_rep_remarks }}</div>
                    <div class="aarf-panel">
                        <div class="r">
                            <div class="c"><div class="k">Company</div><div class="v">{{ $aarf->vendor_rep_company ?: '—' }}</div></div>
                            <div class="c"><div class="k">Name</div><div class="v">{{ $aarf->vendor_rep_name ?: '—' }}</div></div>
                            <div class="c"><div class="k">IC / Passport</div><div class="v">{{ $aarf->vendor_rep_ic ?: '—' }}</div></div>
                            <div class="c"><div class="k">Contact Number</div><div class="v">{{ $aarf->vendor_rep_phone ?: '—' }}</div></div>
                        </div>
                    </div>
                    <div class="alert alert-success py-2 px-3 small mb-0 mt-3">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        Acknowledged by <strong>{{ $aarf->vendor_rep_name }}</strong> on {{ fmt_datetime($aarf->vendor_rep_acknowledged_at) }}.
                    </div>
                </div>
            @elseif($locked || ! $canManage)
                <div class="small text-muted">No remarks from the vendor representative.</div>
            @else
                <div class="aarf-rep">
                    <textarea name="vendor_rep_remarks" rows="3" maxlength="2000" form="aarfAckForm"
                              class="form-control mb-3 @error('vendor_rep_remarks') is-invalid @enderror"
                              placeholder="e.g. Crack on LT-0042 was present before dispatch; noted and accepted.">{{ old('vendor_rep_remarks', $aarf->vendor_rep_remarks) }}</textarea>
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

                    <div class="d-flex align-items-center gap-3 flex-wrap mt-3">
                        {{-- Same form as the receipt, different target. `formnovalidate` is
                             required: the receipt's own fields carry `required`, and without
                             it the browser would refuse this submit until the collector
                             details below were filled — which is not this person's job. The
                             rep's own fields are validated server-side and render inline. --}}
                        <button type="submit" form="aarfAckForm" class="btn btn-outline-success btn-sm fw-semibold"
                                formaction="{{ route('vendors.aarf.vendor-acknowledge', [$vendor, $aarf]) }}"
                                formnovalidate>
                            <i class="bi bi-pen me-1"></i>Acknowledge as Vendor Representative
                        </button>
                        <span class="text-muted small">
                            Sign before the form is closed below &mdash; acknowledging the receipt locks the whole document.
                        </span>
                    </div>
                </div>
            @endif
            @endif{{-- /direction split for section 5 --}}

            {{-- 6 ─ Collector Details.
                 Receipt: us, pre-filled from the signed-in account. Return: the vendor's
                 courier, typed — only their company is suggested, because putting our own
                 staff's name and IC here would file the vendor's declaration under us. --}}
            <div class="aarf-sect"><span class="n">6</span>Collector Details</div>
            @if($isReturn && ! $locked && $canManage)
            <div class="text-muted small mb-2">
                The person collecting the assets on behalf of {{ $aarf->vendor->name }}. Entered by them.
            </div>
            @endif
            @if($locked || ! $canManage)
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

            {{-- 7 ─ Acknowledgement — the closing signature, which locks the document.
                 WHO signs flips with the direction, and on a return that is not the same
                 person as the account it is submitted from. Both facts are stated: the
                 COLLECTOR acknowledges (their typed identity in section 6, stamped by
                 `acknowledged_at`), and the handover was PROCESSED UNDER our account
                 (`acknowledged_by`). Saying only the second would credit our staff with a
                 declaration the vendor made. --}}
            <div class="aarf-sect"><span class="n">7</span>Acknowledgement</div>
            @php $me = \App\Models\RentalAssetAcknowledgement::actorIdentity(auth()->user()); @endphp
            @if($locked)
                <div class="alert alert-success py-2 px-3 small mb-0">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    @if($isReturn)
                        Acknowledged by <strong>{{ $aarf->collector_name ?: '—' }}</strong>@if($aarf->collector_company) <span class="text-muted">({{ $aarf->collector_company }})</span>@endif
                        on {{ fmt_datetime($aarf->acknowledged_at) }},
                        processed under the account of <strong>{{ $signer['name'] ?? '—' }}</strong>@if(!empty($signer['details'])) <span class="text-muted">({{ $signer['details'] }})</span>@endif.
                        The assets on this form have been archived out of the inventory.
                    @else
                        Acknowledged by <strong>{{ $signer['name'] ?? '—' }}</strong>@if(!empty($signer['details'])) <span class="text-muted">({{ $signer['details'] }})</span>@endif
                        on {{ fmt_datetime($aarf->acknowledged_at) }}.
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
                        account, not typed. Hand the screen to the collector before pressing this.
                        Acknowledging locks the form and archives the {{ $aarf->items->count() }}
                        asset{{ $aarf->items->count() === 1 ? '' : 's' }} above out of the inventory
                        and the Decommissioning queue.
                    @else
                        This will be recorded as signed by
                        <strong>{{ $me['name'] ?? auth()->user()->name }}</strong>
                        &mdash; taken from your account, not typed. The form is locked once acknowledged.
                    @endif
                </div>
                {{-- Every signature button on this form reads "Acknowledge as {Party}", in
                     both directions and in both sections — the closing signatory here, and
                     the second party in section 5. `$party` drives this one so the button
                     and the section 4 heading can never name different people. --}}
                <button type="submit" form="aarfAckForm" class="btn btn-success fw-semibold">
                    <i class="bi bi-check2-circle me-1"></i>Acknowledge as {{ $party }}
                </button>
            @else
                <div class="ewx-empty">
                    <i class="bi bi-lock"></i>This AARF has not been acknowledged yet, and you do not have permission to sign it.
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
                    {{-- On a return the closing signatory is the vendor's collector, and the
                         account is only the desk it was processed at. Two cells, because
                         collapsing them into one "signed by" would credit whichever party
                         got printed with the other's act. --}}
                    <div class="c">
                        <div class="k">Acknowledged By ({{ $aarf->vendor->name }})</div>
                        <div class="v">{{ $aarf->collector_name ?: 'Not yet acknowledged' }}</div>
                        @if($aarf->collector_company)<div class="m">{{ $aarf->collector_company }}</div>@endif
                        @if($aarf->collector_ic)<div class="m">IC / Passport {{ $aarf->collector_ic }}</div>@endif
                        @if($aarf->acknowledged_at)<div class="m">{{ fmt_datetime($aarf->acknowledged_at) }}</div>@endif
                    </div>
                    <div class="c">
                        <div class="k">Processed Under Account</div>
                        <div class="v">{{ $signer['name'] ?? 'Not yet acknowledged' }}</div>
                        @if(!empty($signer['details']))<div class="m">{{ $signer['details'] }}</div>@endif
                        @if($aarf->acknowledged_at)<div class="m">{{ fmt_datetime($aarf->acknowledged_at) }}</div>@endif
                    </div>
                    @if($aarf->processorAcknowledged())
                    <div class="c">
                        <div class="k">Processor&rsquo;s Reply Signed By</div>
                        <div class="v">{{ $processorSigner['name'] ?? '—' }}</div>
                        @if(!empty($processorSigner['details']))<div class="m">{{ $processorSigner['details'] }}</div>@endif
                        <div class="m">{{ fmt_datetime($aarf->processor_acknowledged_at) }}</div>
                    </div>
                    @endif
                    @else
                    <div class="c">
                        <div class="k">Acknowledged By (Receiving)</div>
                        <div class="v">{{ $signer['name'] ?? 'Not yet acknowledged' }}</div>
                        @if(!empty($signer['details']))<div class="m">{{ $signer['details'] }}</div>@endif
                        @if($aarf->acknowledged_at)<div class="m">{{ fmt_datetime($aarf->acknowledged_at) }}</div>@endif
                    </div>
                    <div class="c">
                        <div class="k">Vendor Representative</div>
                        <div class="v">{{ $aarf->vendor_rep_name ?: 'Not signed' }}</div>
                        @if($aarf->vendor_rep_company)<div class="m">{{ $aarf->vendor_rep_company }}</div>@endif
                        @if($aarf->vendor_rep_acknowledged_at)<div class="m">{{ fmt_datetime($aarf->vendor_rep_acknowledged_at) }}</div>@endif
                    </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
