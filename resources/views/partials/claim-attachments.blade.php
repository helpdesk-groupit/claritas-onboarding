{{--
    Supporting Documents — renders each item's attachment inline (images shown,
    PDFs/other as a link) below the claim form. $items = a collection of
    ExpenseClaimItem. Self-contained styling so it works both inside the app layout
    (manager review) and the standalone printable report.
--}}
@php
    $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $withAtt = $items->filter(fn ($it) => $it->receipt_path);
@endphp
@if($withAtt->count() > 0)
<h6 class="fw-semibold mt-4 mb-2"><i class="bi bi-paperclip me-1"></i>Supporting Documents</h6>
@foreach($items as $item)
    @if($item->receipt_path)
    @php $ext = strtolower(pathinfo($item->receipt_path, PATHINFO_EXTENSION)); @endphp
    <div class="border rounded p-2 mb-2" style="page-break-inside:avoid;">
        <div class="small fw-semibold mb-1">
            {{ $loop->iteration }}. {{ $item->expense_date->format('jS M Y') }} — {{ $item->description }}
            <span class="text-muted">(RM{{ number_format($item->total_with_gst, 2) }})</span>
        </div>
        @if(in_array($ext, $imageExt))
        <img src="{{ route('user.claims.items.receipt', $item) }}" alt="Attachment for item {{ $loop->iteration }}" style="max-width:100%;max-height:560px;display:block;border:1px solid #e2e8f0;">
        @else
        <div><i class="bi bi-file-earmark-pdf text-danger me-1"></i><a href="{{ route('user.claims.items.receipt', $item) }}" target="_blank">Open attachment ({{ strtoupper($ext) }})</a></div>
        @endif
    </div>
    @endif
@endforeach
@endif
