{{-- The AI reading's state, inline in a document row's first cell.

     Shown for every row that HAS an uploaded file, including the ones that could not be
     read: a document with no summary and no explanation reads as a document that said
     nothing. When there IS a summary this collapses to a toggle and the summary itself
     lives in the full-width row below (_ai-row).

     `data-vnd-ai-pending` is what the poller watches — a row being read in the background
     has to fill itself in, or the operator is left looking at "reading…" indefinitely.

     Expects: $doc (VendorContract|VendorBillingDocument). Its scope key comes off the
     model itself, so this and the assistant's chips can never format it differently. --}}
@if($doc->file_path)
    @php
        $vndAiOk = in_array($doc->ai_status, ['ok', 'partial'], true);
        $vndAiId = 'vndAiSum'.($doc instanceof \App\Models\VendorContract ? 'c' : 'b').$doc->id;
    @endphp
    <div class="vnd-ai-chipline mt-1" @if($doc->ai_status === 'pending') data-vnd-ai-pending="{{ $doc->askKey() }}" @endif>
        <span class="vnd-ai-chip {{ $vndAiOk ? '' : 'vnd-ai-chip-warn' }}">
            @if($doc->ai_status === 'pending')
                <span class="spinner-border spinner-border-sm me-1" style="width:.55rem;height:.55rem;border-width:.11em;"></span>
            @endif
            {{ $doc->ai_status ?: 'not read' }}
        </span>

        @if($doc->hasAiSummary())
            <button type="button" class="vnd-ai-toggle" data-bs-toggle="collapse"
                    data-bs-target="#{{ $vndAiId }}" aria-expanded="false" aria-controls="{{ $vndAiId }}">
                <i class="bi bi-chevron-down me-1"></i>AI summary
            </button>
        @else
            <span class="vnd-ai-chipnote">{{ $doc->aiNote() ?: 'Not read yet — use Re-summarise to read it.' }}</span>
        @endif
    </div>
@endif
