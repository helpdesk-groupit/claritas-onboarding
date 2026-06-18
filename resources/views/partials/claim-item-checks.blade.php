{{--
    Reviewer verification chips for one claim item ($item):
    - static intrinsic checks (math, computed amount, receipt present) from $item->checks()
    - a "Verify" button that OCR-checks the receipt amount + ORS-checks the mileage distance
    Shown on the review pages only (HR detail / Team). Purely assistive.
--}}
<div class="item-checks small mt-1">
    @foreach($item->checks() as $chk)
    <span class="badge rounded-pill {{ $chk['ok'] ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' }} me-1 mb-1"
          @if(!empty($chk['detail'])) title="{{ $chk['detail'] }}" @endif>
        <i class="bi bi-{{ $chk['ok'] ? 'check-circle' : 'exclamation-triangle' }} me-1"></i>{{ $chk['label'] }}
    </span>
    @endforeach

    @if($item->receipt_path || $item->isMileage())
    <span class="d-inline-block">
        <button type="button" class="btn btn-outline-info btn-sm py-0 px-2 mb-1 verify-item-btn" data-verify-url="{{ route('user.claims.items.verify', $item) }}">
            <i class="bi bi-shield-check me-1"></i>Verify{{ $item->isMileage() ? ' distance' : ' receipt' }}
        </button>
        <span class="verify-result d-block"></span>
    </span>
    @endif
</div>
