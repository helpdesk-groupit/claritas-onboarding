{{--
    The quotation half of the e-waste timeline: one Uploaded + Finance-decision pair PER
    REVISION, oldest first, GROUPED BY VENDOR.

    Why it walks $batch->quotations instead of the batch's quotation_* / finance_* columns:
    those are a cache of the CURRENT revision only, and a re-quote overwrites them. Read that
    way, a cycle that Finance had rejected once told the story of a single quotation sailing
    straight through — the rejected offer, its amount and Finance's reason were simply gone
    from the log. The rows keep each pass, so the log reads:
        revision 1 uploaded → rejected by Finance (reason) → revision 2 uploaded → approved.

    GROUPED BY VENDOR, and that grouping is load-bearing, not cosmetic. Revisions run PER
    VENDOR (AssetDecommissionBatch::addQuotationRevision()) — vendor A's revision 2 answers
    vendor A's rejected revision 1 and has nothing to do with vendor B's first offer. This
    partial predates the multi-vendor comparison (Phase 5/6) and originally just walked the
    flat, batch-wide list ordered by the bare `revision` column; with two vendors each on
    their own revision 1, that flat list read as one vendor re-quoting the other's offer —
    the SECOND vendor to upload was stamped "Superseded" over the first vendor's still-live,
    unrelated offer. Grouping first, then marking only the LAST item WITHIN EACH VENDOR'S OWN
    group as current/superseded, is what makes the label true again.

    Falls back to the cache columns when there are no revision rows: a cycle before its first
    upload, and any legacy batch whose quotation predates the history table.

    Requires: $batch (with `quotations.vendor` loaded), $canManage.
--}}
@php
    $revisions = $batch->quotations;
    $revTotal = $revisions->count();
    // groupBy() preserves each bucket's original order, and $revisions already comes ordered
    // oldest-revision-first, so every group is still oldest-to-newest internally. Grouped by
    // vendor id (0 for the legacy no-vendor case), then the groups themselves are read in the
    // order their first offer arrived — the timeline is a log, so it should read the way
    // things actually happened.
    $vendorGroups = $revisions->groupBy(fn ($q) => $q->vendor_id ?? 0)
        ->sortBy(fn ($group) => $group->first()->uploaded_at?->timestamp ?? PHP_INT_MAX)
        ->values();
    // Only label each step with its vendor once there is more than one to tell apart — a
    // single-vendor cycle should read exactly as it always has, with no vendor name noise.
    $multiVendor = $vendorGroups->count() > 1;
@endphp

@if($revTotal === 0)
    {{-- No revision on record — the pre-upload state, and the legacy-cache fallback. --}}
    <li class="dcm-step {{ $batch->quotation_uploaded_at ? '' : 'dcm-step-todo' }}">
        <span class="dcm-dot {{ $batch->quotation_uploaded_at ? 'dcm-dot-done' : 'dcm-dot-active' }}">@if($batch->quotation_uploaded_at)<i class="bi bi-check"></i>@endif</span>
        <div class="dcm-step-title">Quotation uploaded <span class="text-muted fw-normal">(vendor's offer — payable to us)</span></div>
        <div class="dcm-step-meta">
            @if($batch->quotation_uploaded_at)
                {{ fmt_datetime($batch->quotation_uploaded_at) }}
                @if($batch->quotationUploader) by {{ $batch->quotationUploader->name }} @endif
                @if($batch->quotation_amount !== null) &middot; <strong>RM {{ number_format((float) $batch->quotation_amount, 2) }}</strong>@else &middot; <span class="text-muted">amount not read</span>@endif
                @if($batch->quotation_path) &middot; <a href="{{ secure_file_url($batch->quotation_path) }}" target="_blank" rel="noopener">view quote</a>@endif
            @else
                Awaiting IT upload
            @endif
        </div>
        {{-- OCR pre-fills, a human owns the number: it feeds the Finance report, so it
             must be fixable without re-uploading the document. Blank clears it. --}}
        @if($canManage && $batch->quotation_uploaded_at)
        @include('it.decommission._amount-fix', ['batch' => $batch, 'field' => 'quotation', 'value' => $batch->quotation_amount])
        @endif
    </li>
    <li class="dcm-step {{ $batch->finance_status ? '' : 'dcm-step-todo' }}">
        @php
            // Legacy approve/reject dots stay for a cycle decided under the old rule;
            // 'noted' (Finance's optional remarks, since 2026-08-16) is a neutral "done",
            // never fail — remarks are not a decision to fail on.
            $finDot = $batch->financeApproved() ? 'dcm-dot-done'
                : ($batch->financeRejected() ? 'dcm-dot-fail'
                : ($batch->financeCommented() ? 'dcm-dot-done'
                : ($batch->financePending() ? 'dcm-dot-active' : 'dcm-dot-todo')));
        @endphp
        <span class="dcm-dot {{ $finDot }}">
            @if($batch->financeApproved() || $batch->financeCommented())<i class="bi bi-check"></i>@elseif($batch->financeRejected())<i class="bi bi-x"></i>@endif
        </span>
        <div class="dcm-step-title">Finance remarks <span class="text-muted fw-normal">(optional — advisory only)</span></div>
        <div class="dcm-step-meta">
            @if($batch->finance_reviewed_at)
                {{ $batch->financeApproved() ? 'Approved (legacy)' : ($batch->financeRejected() ? 'Rejected (legacy)' : 'Reviewed') }}
                &middot; {{ fmt_datetime($batch->finance_reviewed_at) }}
                @if($batch->financeReviewer) &middot; {{ $batch->financeReviewer->name }} @endif
                @if($batch->finance_remarks)
                    <div class="mt-1">Remarks: {{ $batch->finance_remarks }}</div>
                @elseif($batch->financeCommented())
                    <div class="mt-1 text-muted">No remarks left.</div>
                @endif
            @else
                {{ $batch->financePending() ? 'Not yet reviewed by Finance' : 'Not yet submitted' }}
            @endif
        </div>
    </li>
@else
    @foreach($vendorGroups as $group)
    @php
        $groupTotal = $group->count();
        // Per-vendor: numbering/"Superseded" must never be decided against the whole cycle's
        // row count, or a second vendor's first offer reads as replacing the first vendor's.
        $showRev = $groupTotal > 1;
        $vendorLabel = $multiVendor ? $group->first()->vendorName() : null;
    @endphp
    @foreach($group as $q)
    @php
        // "Current" within THIS vendor's own group — every vendor has exactly one current
        // offer at a time, not just the cycle as a whole.
        $isCurrent = $loop->last;
    @endphp
    <li class="dcm-step {{ $isCurrent ? '' : 'dcm-step-past' }}">
        <span class="dcm-dot {{ $isCurrent ? 'dcm-dot-done' : 'dcm-dot-past' }}"><i class="bi bi-check"></i></span>
        <div class="dcm-step-title">
            Quotation uploaded
            @if($vendorLabel)<span class="dcm-rev">{{ $vendorLabel }}</span>@endif
            @if($showRev)<span class="dcm-rev">Revision {{ $q->revision }} of {{ $groupTotal }}</span>@endif
            <span class="text-muted fw-normal">(vendor's offer — payable to us)</span>
            @unless($isCurrent)<span class="dcm-rev dcm-rev-past">Superseded</span>@endunless
        </div>
        <div class="dcm-step-meta">
            {{ $q->uploaded_at ? fmt_datetime($q->uploaded_at) : 'date not recorded' }}
            @if($q->uploader) by {{ $q->uploader->name }} @endif
            @if($q->amount !== null) &middot; <strong>RM {{ number_format((float) $q->amount, 2) }}</strong>@else &middot; <span class="text-muted">amount not read</span>@endif
            &middot; <a href="{{ secure_file_url($q->path) }}" target="_blank" rel="noopener">view quote</a>
        </div>
        {{-- Only the live revision's figure is correctable — a superseded offer is history,
             and editing it would rewrite what Finance actually rejected. quotationId is
             explicit so a multi-vendor cycle can never correct the wrong vendor's amount. --}}
        @if($canManage && $isCurrent)
        @include('it.decommission._amount-fix', ['batch' => $batch, 'field' => 'quotation', 'value' => $q->amount, 'quotationId' => $q->id])
        @endif
    </li>
    <li class="dcm-step {{ $q->finance_status ? '' : 'dcm-step-todo' }} {{ $isCurrent ? '' : 'dcm-step-past' }}">
        @php
            // Legacy approve/reject dots stay for a revision decided under the old rule;
            // isNoted() (Finance's optional remarks, since 2026-08-16) is a neutral "done".
            $finDot = $q->isApproved() ? 'dcm-dot-done'
                : ($q->isRejected() ? 'dcm-dot-fail'
                : ($q->isNoted() ? 'dcm-dot-done'
                : ($q->isPending() ? 'dcm-dot-active' : 'dcm-dot-todo')));
        @endphp
        <span class="dcm-dot {{ $finDot }}">
            @if($q->isApproved() || $q->isNoted())<i class="bi bi-check"></i>@elseif($q->isRejected())<i class="bi bi-x"></i>@endif
        </span>
        <div class="dcm-step-title">
            Finance remarks <span class="text-muted fw-normal">(optional)</span>
            @if($vendorLabel)<span class="dcm-rev">{{ $vendorLabel }}</span>@endif
            @if($showRev)<span class="dcm-rev">on revision {{ $q->revision }}</span>@endif
        </div>
        <div class="dcm-step-meta">
            @if($q->finance_reviewed_at)
                {{ $q->isApproved() ? 'Approved (legacy)' : ($q->isRejected() ? 'Rejected (legacy)' : 'Reviewed') }}
                &middot; {{ fmt_datetime($q->finance_reviewed_at) }}
                @if($q->financeReviewer) &middot; {{ $q->financeReviewer->name }} @endif
                @if($q->finance_remarks)
                    <div class="mt-1">Remarks: {{ $q->finance_remarks }}</div>
                @elseif($q->isNoted())
                    <div class="mt-1 text-muted">No remarks left.</div>
                @endif
                @if($q->isRejected() && ! $isCurrent)
                    <div class="mt-1 text-muted">A revised quotation was uploaded as revision {{ $q->revision + 1 }}.</div>
                @endif
            @else
                {{ $q->isPending() ? 'Not yet reviewed by Finance' : 'Not yet submitted' }}
            @endif
        </div>
    </li>
    @endforeach
    @endforeach
@endif
