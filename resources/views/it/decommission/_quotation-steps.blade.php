{{--
    The quotation half of the e-waste timeline: one Uploaded + Finance-decision pair PER
    REVISION, oldest first.

    Why it walks $batch->quotations instead of the batch's quotation_* / finance_* columns:
    those are a cache of the CURRENT revision only, and a re-quote overwrites them. Read that
    way, a cycle that Finance had rejected once told the story of a single quotation sailing
    straight through — the rejected offer, its amount and Finance's reason were simply gone
    from the log. The rows keep each pass, so the log reads:
        revision 1 uploaded → rejected by Finance (reason) → revision 2 uploaded → approved.

    Falls back to the cache columns when there are no revision rows: a cycle before its first
    upload, and any legacy batch whose quotation predates the history table.

    Requires: $batch (with `quotations` loaded), $canManage.
--}}
@php
    $revisions = $batch->quotations;
    $revTotal = $revisions->count();
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
            $finDot = $batch->financeApproved() ? 'dcm-dot-done'
                : ($batch->financeRejected() ? 'dcm-dot-fail'
                : ($batch->financePending() ? 'dcm-dot-active' : 'dcm-dot-todo'));
        @endphp
        <span class="dcm-dot {{ $finDot }}">
            @if($batch->financeApproved())<i class="bi bi-check"></i>@elseif($batch->financeRejected())<i class="bi bi-x"></i>@endif
        </span>
        <div class="dcm-step-title">Finance decision</div>
        <div class="dcm-step-meta">
            @if($batch->finance_reviewed_at)
                {{ ucfirst($batch->finance_status) }} &middot; {{ fmt_datetime($batch->finance_reviewed_at) }}
                @if($batch->financeReviewer) &middot; {{ $batch->financeReviewer->name }} @endif
                @if($batch->finance_remarks)<div class="mt-1">Remarks: {{ $batch->finance_remarks }}</div>@endif
            @else
                {{ $batch->financePending() ? 'Awaiting Finance review' : 'Not yet submitted' }}
            @endif
        </div>
    </li>
@else
    @foreach($revisions as $q)
    @php
        $isCurrent = $loop->last;
        // Only annotate revisions when there is more than one — a single-quote cycle should
        // read exactly as it always has, with no revision numbering noise.
        $showRev = $revTotal > 1;
    @endphp
    <li class="dcm-step {{ $isCurrent ? '' : 'dcm-step-past' }}">
        <span class="dcm-dot {{ $isCurrent ? 'dcm-dot-done' : 'dcm-dot-past' }}"><i class="bi bi-check"></i></span>
        <div class="dcm-step-title">
            Quotation uploaded
            @if($showRev)<span class="dcm-rev">Revision {{ $q->revision }} of {{ $revTotal }}</span>@endif
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
             and editing it would rewrite what Finance actually rejected. --}}
        @if($canManage && $isCurrent)
        @include('it.decommission._amount-fix', ['batch' => $batch, 'field' => 'quotation', 'value' => $q->amount])
        @endif
    </li>
    <li class="dcm-step {{ $q->finance_status ? '' : 'dcm-step-todo' }} {{ $isCurrent ? '' : 'dcm-step-past' }}">
        @php
            $finDot = $q->isApproved() ? 'dcm-dot-done'
                : ($q->isRejected() ? 'dcm-dot-fail'
                : ($q->isPending() ? 'dcm-dot-active' : 'dcm-dot-todo'));
        @endphp
        <span class="dcm-dot {{ $finDot }}">
            @if($q->isApproved())<i class="bi bi-check"></i>@elseif($q->isRejected())<i class="bi bi-x"></i>@endif
        </span>
        <div class="dcm-step-title">
            Finance decision
            @if($showRev)<span class="dcm-rev">on revision {{ $q->revision }}</span>@endif
        </div>
        <div class="dcm-step-meta">
            @if($q->finance_reviewed_at)
                {{ ucfirst($q->finance_status) }} &middot; {{ fmt_datetime($q->finance_reviewed_at) }}
                @if($q->financeReviewer) &middot; {{ $q->financeReviewer->name }} @endif
                @if($q->finance_remarks)<div class="mt-1">Remarks: {{ $q->finance_remarks }}</div>@endif
                @if($q->isRejected() && ! $isCurrent)
                    <div class="mt-1 text-muted">A revised quotation was uploaded as revision {{ $q->revision + 1 }}.</div>
                @endif
            @else
                {{ $q->isPending() ? 'Awaiting Finance review' : 'Not yet submitted' }}
            @endif
        </div>
    </li>
    @endforeach
@endif
