{{--
    Compact, NON-editable summary row for a DRAFT claim inside the year/month accordion
    (Option B). Editing happens in the top inline builder — "Continue editing" reloads with
    ?open so the draft loads up there. Avoids id="claim-{id}"/"cc-{id}" so it never clashes
    with the top editor's ids. Needs: $claim.
--}}
<div class="claim-card draft-row" data-draft-summary="{{ $claim->id }}">
    <div class="claim-card-head draft-row-head">
        <span class="cc-head-left">
            <span class="cc-icon draft-icon"><i class="bi bi-pencil-square"></i></span>
            <span>
                <span class="cc-title ds-title">{{ trim((string) $claim->event) ?: 'Untitled claim' }}</span>
                <span class="cc-sub">{{ $claim->claim_number }} · <span class="ds-count">{{ $claim->item_count }}</span> item{{ $claim->item_count == 1 ? '' : 's' }}@if($claim->correction_of_id) · <span class="text-warning-emphasis">Correction of {{ optional($claim->correctionOf)->claim_number ?? 'a rejected claim' }}</span>@endif</span>
            </span>
        </span>
        <span class="cc-head-right">
            <span class="badge bg-secondary">Draft</span>
            <span class="cc-total">RM <span class="ds-total">{{ number_format($claim->total_with_gst, 2) }}</span></span>
            <span class="d-flex gap-1 flex-wrap draft-row-actions">
                <a href="{{ route('user.claims.index', ['open' => $claim->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Continue editing</a>
                <a href="{{ route('user.claims.report-print', $claim) }}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Preview Report</a>
                <button type="button" class="btn btn-sm btn-outline-danger draft-delete-btn"><i class="bi bi-trash"></i></button>
                <form action="{{ route('user.claims.discard', $claim) }}" method="POST" class="draft-delete-form d-none">@csrf @method('DELETE')</form>
            </span>
        </span>
    </div>
</div>
