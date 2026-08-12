@extends('layouts.app')
@section('title', 'Decommission Batch '.$batch->batch_number)
@section('page-title', 'Decommission — '.$batch->batch_number)

@section('content')
@include('partials.decommission-ui-style')
@php
    use Illuminate\Support\Facades\Storage;
    [$badgeClass, $badgeLabel] = $batch->statusBadge();
    // Photos live on the PUBLIC disk. Check existence server-side: a row can reference a
    // file that is no longer on disk, and rendering that <img> anyway shows the browser's
    // broken-image glyph, which reads as a bug in the report rather than a missing file.
    $photoExists = fn ($p) => $p && Storage::disk('public')->exists($p);
@endphp
<style>
    .dcm-band {
        position: relative; overflow: hidden; border-radius: 12px 12px 0 0;
        background: linear-gradient(135deg, #0f172a, #1e3a5f); padding: 1.4rem 1.5rem;
    }
    .dcm-band::before {
        content: ''; position: absolute; top: -60px; right: -40px;
        width: 200px; height: 200px; border-radius: 50%; background: rgba(255,255,255,.05);
    }
    .dcm-band::after {
        content: ''; position: absolute; bottom: -40px; right: 90px;
        width: 110px; height: 110px; border-radius: 50%; background: rgba(255,255,255,.04);
    }
    .dcm-band-icon {
        width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
        background: rgba(255,255,255,.16); color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.25rem;
    }
    .dcm-meta { background: #f8fafc; border-bottom: 1px solid #e9eef5; padding: .9rem 1.5rem; }
    .dcm-meta-k { font-size: .65rem; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; font-weight: 700; }
    .dcm-meta-v { font-weight: 700; color: #1e293b; font-size: .95rem; }
    .dcm-section { font-weight: 700; color: #1e293b; font-size: .95rem; display: flex; align-items: center; gap: .5rem; margin-bottom: .75rem; }
    .dcm-section .ewx-chip { width: 30px; height: 30px; border-radius: 9px; font-size: .8rem; }

    .dcm-asset { border: 1px solid #e9eef5; border-radius: 12px; overflow: hidden; margin-bottom: .85rem; }
    .dcm-asset-head {
        background: linear-gradient(135deg, #f8fafc, #eef2f7); padding: .6rem .9rem;
        border-bottom: 1px solid #e9eef5; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
    }
    .dcm-asset-tag { font-weight: 700; color: #1e293b; font-size: .85rem; }
    .dcm-spec-title { font-size: .65rem; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; font-weight: 700; margin-bottom: .35rem; }
    .dcm-kv { width: 100%; font-size: 12.5px; }
    .dcm-kv td { padding: .18rem 0; }
    .dcm-kv td.k { color: #64748b; width: 45%; }
    .dcm-kv td.v { color: #1e293b; font-weight: 600; }

    .dcm-photo { height: 84px; width: 108px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0; transition: transform .15s ease; }
    .dcm-photo:hover { transform: scale(1.04); }
    .dcm-photo-missing {
        height: 84px; width: 108px; border-radius: 8px; border: 1px dashed #cbd5e1; background: #f8fafc;
        display: inline-flex; flex-direction: column; align-items: center; justify-content: center;
        color: #94a3b8; font-size: .62rem; text-align: center; gap: .2rem;
    }
    .dcm-photo-missing i { font-size: 1.05rem; }

    /* Vertical progress timeline for the two flows. */
    .dcm-steps { list-style: none; margin: 0; padding: 0; }
    .dcm-step { position: relative; padding: 0 0 1rem 1.9rem; }
    .dcm-step:last-child { padding-bottom: 0; }
    .dcm-step::before {
        content: ''; position: absolute; left: .48rem; top: 1.15rem; bottom: -.1rem; width: 2px; background: #e2e8f0;
    }
    .dcm-step:last-child::before { display: none; }
    .dcm-dot {
        position: absolute; left: 0; top: .12rem; width: 1.05rem; height: 1.05rem; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center; font-size: .6rem; color: #fff;
    }
    .dcm-dot-done    { background: linear-gradient(135deg, #22c55e, #15803d); }
    .dcm-dot-active  { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .dcm-dot-todo    { background: #e2e8f0; }
    .dcm-dot-fail    { background: linear-gradient(135deg, #ef4444, #b91c1c); }
    /* A superseded quotation revision: it really happened, so it keeps its tick — but it is
       history, not the offer on the table, so the dot is neutral rather than green. */
    .dcm-dot-past    { background: #94a3b8; }
    .dcm-step-title  { font-weight: 600; color: #1e293b; font-size: .85rem; }
    .dcm-step-meta   { font-size: .74rem; color: #64748b; }
    .dcm-step-todo .dcm-step-title { color: #94a3b8; font-weight: 500; }
    .dcm-step-past .dcm-step-title { color: #64748b; font-weight: 500; }
    .dcm-step-past .dcm-step-meta  { color: #94a3b8; }
    .dcm-rev {
        display: inline-block; margin: 0 .3rem; padding: .05rem .4rem; border-radius: 6px;
        background: #eef2f7; border: 1px solid #e2e8f0; color: #475569;
        font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
        vertical-align: middle;
    }
    .dcm-rev-past { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

    .dcm-upload { border: 1px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; padding: .9rem; }

    [data-theme="dark"] .dcm-meta { background: #0f172a; border-bottom-color: #334155; }
    [data-theme="dark"] .dcm-meta-v, [data-theme="dark"] .dcm-asset-tag,
    [data-theme="dark"] .dcm-section, [data-theme="dark"] .dcm-kv td.v,
    [data-theme="dark"] .dcm-step-title { color: #e2e8f0; }
    [data-theme="dark"] .dcm-asset { border-color: #334155; }
    [data-theme="dark"] .dcm-asset-head { background: linear-gradient(135deg, #1e293b, #0f172a); border-bottom-color: #334155; }
    [data-theme="dark"] .dcm-upload, [data-theme="dark"] .dcm-photo-missing { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .dcm-rev { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .dcm-rev-past { background: #3f1d1d; border-color: #7f1d1d; color: #fca5a5; }
    [data-theme="dark"] .dcm-step-past .dcm-step-title { color: #94a3b8; }
</style>

<div class="container-fluid px-0" style="max-width:960px;">
    {{-- Flash messages are rendered globally by layouts/app.blade.php — don't duplicate here. --}}

    {{-- Action bar --}}
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <a href="{{ route('assets.index', ['tab' => 'damaged']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Decommissioning</a>
        <div class="btn-group btn-group-sm" role="group">
            <a href="{{ route('reports.decommission.view', $batch) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="bi bi-eye me-1"></i>View PDF</a>
            <a href="{{ route('reports.decommission.pdf', $batch) }}" class="btn btn-outline-primary"><i class="bi bi-download me-1"></i>Download PDF</a>
        </div>
    </div>

    {{-- ══════════════ Report paper ══════════════ --}}
    <div class="card border-0 shadow-sm mb-3 ewx-card">
        <div class="dcm-band">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 position-relative">
                <div class="d-flex align-items-center gap-3">
                    <span class="dcm-band-icon"><i class="bi bi-recycle"></i></span>
                    <div>
                        <h4 class="text-white fw-bold mb-1">Asset Decommissioning Report</h4>
                        <div class="text-white-50 small">{{ config('decommission.org_name') }} &mdash; {{ $batch->typeLabel() }}</div>
                    </div>
                </div>
                <div class="text-end">
                    <div class="text-white-50" style="font-size:11px;letter-spacing:.06em;">REFERENCE NO.</div>
                    <div class="text-white fw-bold" style="font-size:19px;">{{ $batch->batch_number }}</div>
                    <span class="badge rounded-pill bg-{{ $badgeClass }} mt-1">{{ $badgeLabel }}</span>
                </div>
            </div>
        </div>

        {{-- Meta strip --}}
        <div class="dcm-meta">
            <div class="row g-3">
                <div class="col-6 col-md-3"><div class="dcm-meta-k">Vendor</div><div class="dcm-meta-v">{{ $batch->vendor?->name ?? '—' }}</div></div>
                <div class="col-6 col-md-3"><div class="dcm-meta-k">Flow</div><div class="dcm-meta-v">{{ $batch->typeLabel() }}</div></div>
                <div class="col-6 col-md-3"><div class="dcm-meta-k">Created</div><div class="dcm-meta-v">{{ fmt_datetime($batch->created_at) }}</div></div>
                <div class="col-6 col-md-3"><div class="dcm-meta-k">Assets</div><div class="dcm-meta-v">{{ $batch->items->count() }}</div></div>
            </div>
        </div>

        <div class="card-body p-4">
            {{-- Assets summary --}}
            <div class="dcm-section"><span class="ewx-chip ewx-chip-slate"><i class="bi bi-box-seam"></i></span>Assets</div>
            <div class="table-responsive mb-4">
                <table class="table table-hover ewx-table" style="border:1px solid #e9eef5;border-radius:10px;overflow:hidden;">
                    <thead><tr><th class="ps-3">Asset Tag</th><th>Brand / Model</th><th>Type</th><th class="pe-3">Serial No.</th></tr></thead>
                    <tbody>
                        @foreach($batch->items as $item)
                        <tr>
                            <td class="ps-3 ewx-code">{{ $item->asset_tag }}</td>
                            <td>{{ trim(($item->brand ?? '').' '.($item->model ?? '')) ?: '—' }}</td>
                            <td>{{ ucfirst(str_replace('_',' ', $item->asset_type ?? '—')) }}</td>
                            <td class="pe-3">{{ $item->serial_number ?? '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Per-asset details (Section A/B + photos) --}}
            <div class="dcm-section"><span class="ewx-chip ewx-chip-blue"><i class="bi bi-card-list"></i></span>Asset Details</div>
            @foreach($batch->items as $item)
            @php $a = $item->asset; $photos = $a?->asset_photos ?? []; @endphp
            <div class="dcm-asset">
                <div class="dcm-asset-head">
                    <i class="bi bi-tag text-primary"></i>
                    <span class="dcm-asset-tag">{{ $item->asset_tag }}</span>
                    <span class="text-muted small">{{ trim(($item->brand ?? '').' '.($item->model ?? '')) ?: '—' }}</span>
                </div>
                <div class="p-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="dcm-spec-title">Section A — Identification</div>
                            <table class="dcm-kv">
                                <tr><td class="k">Asset Tag</td><td class="v">{{ $item->asset_tag }}</td></tr>
                                <tr><td class="k">Type</td><td class="v">{{ ucfirst(str_replace('_',' ',$item->asset_type ?? '—')) }}</td></tr>
                                <tr><td class="k">Brand</td><td class="v">{{ $item->brand ?? $a?->brand ?? '—' }}</td></tr>
                                <tr><td class="k">Model</td><td class="v">{{ $item->model ?? $a?->model ?? '—' }}</td></tr>
                                <tr><td class="k">Serial No.</td><td class="v">{{ $item->serial_number ?? '—' }}</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <div class="dcm-spec-title">Section B — Specification</div>
                            <table class="dcm-kv">
                                <tr><td class="k">Processor</td><td class="v">{{ $a?->processor ?? '—' }}</td></tr>
                                <tr><td class="k">RAM</td><td class="v">{{ $a?->ram_size ?? '—' }}</td></tr>
                                <tr><td class="k">Storage</td><td class="v">{{ $a?->storage ?? '—' }}</td></tr>
                                <tr><td class="k">OS</td><td class="v">{{ $a?->operating_system ?? '—' }}</td></tr>
                                <tr><td class="k">Screen</td><td class="v">{{ $a?->screen_size ?? '—' }}</td></tr>
                            </table>
                        </div>
                    </div>

                    @php $itemCompleteness = $item->isEwaste() ? $item->completenessLabel() : null; @endphp
                    @if($itemCompleteness)
                    <div class="mt-3">
                        <div class="dcm-spec-title">Completeness</div>
                        @if($item->isIncomplete())
                            <span class="badge rounded-pill bg-warning text-dark">Incomplete — parts removed</span>
                            @if($item->ewaste_parts_removed)
                                <div class="mt-1" style="font-size:12.5px;"><span class="text-muted">Parts removed:</span> <strong>{{ $item->ewaste_parts_removed }}</strong></div>
                            @endif
                        @else
                            <span class="badge rounded-pill bg-success">Complete — all parts intact</span>
                        @endif
                    </div>
                    @endif

                    @if($a?->notes)
                    <div class="mt-3">
                        <div class="dcm-spec-title">Notes</div>
                        <div style="font-size:12.5px;white-space:pre-wrap;">{{ $a->notes }}</div>
                    </div>
                    @endif

                    @if(!empty($photos))
                    <div class="mt-3">
                        <div class="dcm-spec-title">Asset Photos ({{ count($photos) }})</div>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($photos as $photo)
                                @if($photoExists($photo))
                                <a href="{{ asset('storage/'.$photo) }}" target="_blank" rel="noopener" title="Open full size">
                                    <img src="{{ asset('storage/'.$photo) }}" alt="Photo of {{ $item->asset_tag }}" loading="lazy" class="dcm-photo">
                                </a>
                                @else
                                {{-- Recorded but not on disk — say so rather than showing a broken image. --}}
                                <span class="dcm-photo-missing" title="{{ $photo }}">
                                    <i class="bi bi-image-alt"></i>File missing
                                </span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ══════════════ The e-waste cycle ══════════════ --}}
    @if($batch->isEwaste())
    <div class="card ewx-card mb-3">
        <div class="ewx-head">
            <span class="ewx-chip ewx-chip-green"><i class="bi bi-recycle"></i></span>
            <div class="me-2">
                <span class="ewx-title">E-Waste Cycle</span>
                <span class="ewx-sub">RFQ &rarr; quotation &rarr; Finance approval &rarr; receipt &rarr; completion.</span>
            </div>
        </div>
        <div class="card-body">
            <ul class="dcm-steps mb-3">
                <li class="dcm-step {{ $batch->rfq_sent_at ? '' : 'dcm-step-todo' }}">
                    <span class="dcm-dot {{ $batch->rfq_sent_at ? 'dcm-dot-done' : 'dcm-dot-todo' }}">@if($batch->rfq_sent_at)<i class="bi bi-check"></i>@endif</span>
                    <div class="dcm-step-title">RFQ sent to primary vendor</div>
                    <div class="dcm-step-meta">{{ $batch->rfq_sent_at ? fmt_datetime($batch->rfq_sent_at) : 'Not sent — no primary e-waste vendor set' }}</div>
                </li>
                <li class="dcm-step {{ $batch->finance_report_sent_at ? '' : 'dcm-step-todo' }}">
                    <span class="dcm-dot {{ $batch->finance_report_sent_at ? 'dcm-dot-done' : 'dcm-dot-todo' }}">@if($batch->finance_report_sent_at)<i class="bi bi-check"></i>@endif</span>
                    <div class="dcm-step-title">"Awaiting" report sent to Finance</div>
                    <div class="dcm-step-meta">{{ $batch->finance_report_sent_at ? fmt_datetime($batch->finance_report_sent_at) : 'Pending' }}</div>
                </li>
                {{-- One Uploaded + Finance-decision pair per quotation REVISION, so a cycle
                     Finance rejected once keeps both offers and both decisions on the log. --}}
                @include('it.decommission._quotation-steps', ['batch' => $batch, 'canManage' => $canManage])
                <li class="dcm-step {{ $batch->receipt_uploaded_at ? '' : 'dcm-step-todo' }}">
                    <span class="dcm-dot {{ $batch->receipt_uploaded_at ? 'dcm-dot-done' : 'dcm-dot-todo' }}">@if($batch->receipt_uploaded_at)<i class="bi bi-check"></i>@endif</span>
                    <div class="dcm-step-title">Payment receipt uploaded <span class="text-muted fw-normal">(proof the vendor paid us)</span></div>
                    <div class="dcm-step-meta">
                        @if($batch->receipt_uploaded_at)
                            {{ fmt_datetime($batch->receipt_uploaded_at) }}
                            @if($batch->receiptUploader) by {{ $batch->receiptUploader->name }} @endif
                            @if($batch->receipt_amount !== null) &middot; <strong>RM {{ number_format((float) $batch->receipt_amount, 2) }}</strong>@else &middot; <span class="text-muted">amount not read</span>@endif
                            @if($batch->receipt_path) &middot; <a href="{{ secure_file_url($batch->receipt_path) }}" target="_blank" rel="noopener">view receipt</a>@endif
                        @else
                            Pending
                        @endif
                    </div>
                    @if($canManage && $batch->receipt_uploaded_at)
                    @include('it.decommission._amount-fix', ['batch' => $batch, 'field' => 'receipt', 'value' => $batch->receipt_amount])
                    @endif
                </li>
                <li class="dcm-step {{ $batch->isFinalized() ? '' : 'dcm-step-todo' }}">
                    <span class="dcm-dot {{ $batch->isFinalized() ? 'dcm-dot-done' : 'dcm-dot-todo' }}">@if($batch->isFinalized())<i class="bi bi-check"></i>@endif</span>
                    <div class="dcm-step-title">Cycle completed &mdash; assets archived, final report sent to Finance</div>
                    <div class="dcm-step-meta">{{ $batch->isFinalized() ? fmt_datetime($batch->finalized_at) : 'Pending' }}</div>
                </li>
            </ul>

            @if($canManage && in_array($batch->status, ['awaiting_quotation', 'finance_rejected']))
            <form action="{{ route('ewaste.quotation', $batch) }}" method="POST" enctype="multipart/form-data" class="dcm-upload mb-3">@csrf
                {{-- Uploading after a rejection ADDS a revision — say so, and restate what
                     Finance asked to be fixed, so the operator isn't re-reading the log to
                     find it (and doesn't think the rejected quote is about to be erased). --}}
                @php $rejectedRev = $batch->financeRejected() ? $batch->currentQuotation() : null; @endphp
                @if($rejectedRev)
                <div class="alert alert-warning py-2 px-3 mb-3 small">
                    <div class="fw-semibold mb-1">
                        <i class="bi bi-arrow-repeat me-1"></i>This will be uploaded as revision {{ $rejectedRev->revision + 1 }} &mdash; revision {{ $rejectedRev->revision }} stays on the log.
                    </div>
                    <div>
                        Finance rejected revision {{ $rejectedRev->revision }}
                        @if($rejectedRev->finance_reviewed_at) on {{ fmt_datetime($rejectedRev->finance_reviewed_at) }}@endif
                        @if($rejectedRev->financeReviewer) ({{ $rejectedRev->financeReviewer->name }})@endif
                        @if($rejectedRev->finance_remarks): {{ $rejectedRev->finance_remarks }}@endif
                    </div>
                </div>
                @endif
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Quotation file (PDF/image)</label>
                        <input type="file" name="quotation_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                        <div class="form-text">Leave the amount blank and the system reads it from this document; you can correct it afterwards.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Offer amount (RM) <span class="text-muted fw-normal">— optional</span></label>
                        <input type="number" step="0.01" min="0.01" name="quotation_amount" class="form-control form-control-sm" placeholder="Read from document">
                    </div>
                    <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-upload me-1"></i>Upload quotation</button></div>
                </div>
            </form>
            @endif

            @if($batch->financeApproved() && $canManage && $batch->status !== 'completed')
            <form action="{{ route('ewaste.receipt', $batch) }}" method="POST" enctype="multipart/form-data" class="dcm-upload mb-3">@csrf
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Receipt file (PDF/image)</label>
                        <input type="file" name="receipt_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                        <div class="form-text">Uploading the receipt <strong>completes the cycle automatically</strong> — the assets are archived and the final report is sent to Finance. Leave the amount blank to read it from the document.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Amount received (RM) <span class="text-muted fw-normal">— optional</span></label>
                        <input type="number" step="0.01" min="0.01" name="receipt_amount" class="form-control form-control-sm" placeholder="Read from document">
                    </div>
                    <div class="col-md-3"><button class="btn btn-sm ewx-btn-approve w-100"><i class="bi bi-upload me-1"></i>Upload &amp; complete</button></div>
                </div>
            </form>
            @endif

            @if($canManage)
            <div class="d-flex gap-2 flex-wrap mt-2">
                @if($batch->status === 'collected')
                {{-- Fallback only: appears if the automatic finalize on receipt upload failed. --}}
                <form action="{{ route('ewaste.complete', $batch) }}" method="POST" class="js-confirm"
                      data-confirm="Finalize this cycle now? Assets will be archived and the final report sent to Finance."
                      data-confirm-title="Finalize cycle" data-confirm-ok="Finalize" data-confirm-variant="success">@csrf
                    <button class="btn btn-sm btn-warning"><i class="bi bi-arrow-repeat me-1"></i>Finalize cycle</button>
                </form>
                @endif
                @if(in_array($batch->status, ['awaiting_quotation', 'quotation_uploaded', 'finance_rejected']))
                <form action="{{ route('decommission.cancel', $batch) }}" method="POST" class="js-confirm"
                      data-confirm="Cancel this cycle and return its assets to the queue?"
                      data-confirm-title="Cancel cycle" data-confirm-ok="Cancel cycle" data-confirm-variant="danger">@csrf
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Cancel cycle</button>
                </form>
                @endif
            </div>
            @endif
        </div>
    </div>
    @endif
</div>

{{-- In-app confirmation dialog (replaces native confirm()) for all forms above. --}}
@include('partials.confirm-modal')
@endsection
