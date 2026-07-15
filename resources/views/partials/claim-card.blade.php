{{--
    A claim as a collapsible card (collapsed by default; opens only via ?open). Used for
    BOTH draft claims (in the Drafts section) and submitted claims (in the month
    accordion). The body (partials._claim-card-body) decides editable vs read-only.
    Needs from parent scope: $categories, $approvers, $projectRequired, $ocrEnabled.
--}}
@php
    $isOpen = (string) ($openClaimId ?? '') === (string) $claim->id;
    // Status-based icon colour (pipeline journey: amber → teal → green; reds for rejections).
    $iconBg = match ($claim->status) {
        'submitted' => 'linear-gradient(135deg,#f59e0b,#d97706)',        // Pending Manager (amber)
        'manager_approved' => 'linear-gradient(135deg,#14b8a6,#0d9488)', // Manager Approved / Pending HR (teal)
        'hr_approved', 'paid' => 'linear-gradient(135deg,#22c55e,#15803d)', // HR Approved / Completed (green)
        'manager_rejected', 'hr_rejected' => 'linear-gradient(135deg,#ef4444,#b91c1c)', // Rejected (red)
        default => 'linear-gradient(135deg,#64748b,#475569)',
    };
@endphp
<div class="claim-card" data-claim-card data-claim-id="{{ $claim->id }}" id="claim-{{ $claim->id }}">
    <button type="button" class="claim-card-head" data-bs-toggle="collapse" data-bs-target="#cc-{{ $claim->id }}" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
        <span class="cc-head-left">
            <span class="cc-icon" style="background:{{ $iconBg }};"><i class="bi bi-file-earmark-text"></i></span>
            <span>
                <span class="cc-title">{{ trim((string) $claim->event) ?: 'Untitled claim' }}</span>
                <span class="cc-sub">{{ $claim->claim_number }} · {{ $claim->item_count }} item{{ $claim->item_count == 1 ? '' : 's' }}</span>
            </span>
        </span>
        <span class="cc-head-right">
            <span class="d-inline-flex flex-wrap gap-1 justify-content-end">
                @if($rb = $claim->resubmissionBadge())
                <span class="badge bg-{{ $rb['class'] }}" title="{{ $rb['title'] }}{{ optional($claim->correctionOf)->claim_number ? ' ('.$claim->correctionOf->claim_number.')' : '' }}"><i class="bi {{ $rb['icon'] }} me-1"></i>{{ $rb['label'] }}</span>
                @endif
                @foreach($claim->stageBadges() as $sb)
                <span class="badge bg-{{ $sb['class'] }} {{ $sb['class'] === 'warning' ? 'text-dark' : '' }}">{{ $sb['label'] }}</span>
                @endforeach
            </span>
            <span class="cc-total">RM {{ number_format($claim->total_with_gst, 2) }}</span>
            <i class="bi bi-chevron-down cc-chevron"></i>
        </span>
    </button>

    <div class="collapse {{ $isOpen ? 'show' : '' }}" id="cc-{{ $claim->id }}">
        <div class="cc-body">
            @include('partials._claim-card-body', ['claim' => $claim])
        </div>
    </div>
</div>
