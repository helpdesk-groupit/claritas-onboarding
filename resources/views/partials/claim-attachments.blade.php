{{--
    Supporting Documents — renders each item's attachment inline (images shown,
    PDFs/other as a link) below the claim form. $items = a collection of
    ExpenseClaimItem. Self-contained styling so it works both inside the app layout
    (manager review) and the standalone printable report.
--}}
@php
    $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $withAtt = $items->filter(fn ($it) => count($it->attachmentPaths()) > 0);
@endphp
@if($withAtt->count() > 0)
<h6 class="fw-semibold mt-4 mb-2"><i class="bi bi-paperclip me-1"></i>Supporting Documents</h6>
@foreach($items as $item)
    @foreach($item->attachmentPaths() as $attachment)
    @php $ext = strtolower(pathinfo($attachment, PATHINFO_EXTENSION)); @endphp
    <div class="border rounded p-2 mb-2" style="page-break-inside:avoid;">
        <div class="small fw-semibold mb-1">
            {{ $loop->parent->iteration }}. {{ $item->expense_date->format('jS M Y') }} — {{ $item->description }}
            <span class="text-muted">(RM{{ number_format($item->total_with_gst, 2) }})</span>
        </div>
        @if(in_array($ext, $imageExt))
        <img src="{{ route('secure.file', $attachment) }}" alt="Attachment for item {{ $loop->parent->iteration }}" style="max-width:100%;max-height:560px;display:block;border:1px solid #e2e8f0;">
        @else
        <div><i class="bi bi-file-earmark-pdf text-danger me-1"></i><a href="{{ route('secure.file', $attachment) }}" target="_blank">Open attachment ({{ strtoupper($ext) }})</a></div>
        @endif
    </div>
    @endforeach
@endforeach
@endif
