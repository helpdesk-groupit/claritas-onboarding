{{--
    Verification summary banner (#10) for a claim ($claim) on the review pages.
    Green when all automatic (no-API) checks pass; amber with a list when something
    needs a look. The OCR/ORS "Verify" buttons are separate (on-demand).
--}}
@php $reviewFlags = $claim->reviewFlags(); @endphp
@if(count($reviewFlags) === 0)
<div class="alert alert-success d-flex align-items-center py-2 mb-3">
    <i class="bi bi-shield-check me-2 fs-5"></i>
    <span>All automatic checks passed — no math, cap, or duplicate issues found.</span>
</div>
@else
<div class="alert alert-warning py-2 mb-3">
    <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-2"></i>{{ count($reviewFlags) }} point{{ count($reviewFlags) === 1 ? '' : 's' }} to review before approving:</div>
    <ul class="mb-0 small">
        @foreach($reviewFlags as $flag)
        <li>{{ $flag }}</li>
        @endforeach
    </ul>
</div>
@endif
