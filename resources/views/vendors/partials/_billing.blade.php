{{-- Quotations + invoices received from this vendor. A document register, not an AP
     ledger — nothing here posts to Accounting.

     Dates / Contract / Subtotal / SST / Total were dropped as columns on 2026-08-11,
     mirroring the contracts tab: the row's summary says what the document contains, and
     every figure is still on the record (edit the document to read or change it). They are
     NOT gone from the model and must not be — `recordedFields()` pairs them with the
     document text, which is how the assistant answers "does this invoice match the
     contract rate?" and how a mis-keyed total is caught.

     TWO SIGNALS from those columns had to survive, because neither is a figure:
      - the SST warning (a document charging tax this vendor may not charge), and
      - Overdue (an invoice past its due date).
     Both moved into the Status cell. The banner above counts SST flags but names no row,
     so dropping the per-row icon would have left "3 documents charge SST" with no way to
     tell which three. --}}
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <div class="fw-semibold">Billing Documents</div>
        <div class="text-muted small">Quotations and invoices &mdash; each is read for a summary you can open on its row. Nothing here posts to Accounting.</div>
    </div>
    @if($canManage)
    <button type="button" class="btn btn-sm btn-primary" id="addBillingBtn" data-bs-toggle="modal" data-bs-target="#billingModalNew">
        <i class="bi bi-plus-lg me-1"></i>Add Quotation / Invoice
    </button>
    @endif
</div>

@if($summary['sst_flags'])
<div class="alert alert-warning py-2 mb-3" style="font-size:12.5px;">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    <strong>{{ $summary['sst_flags'] }} document(s) charge SST that this vendor's registration says they may not charge.</strong>
    Flagged, never blocked &mdash; the vendor's SST category is master data that can be stale, so check it before challenging the bill.
</div>
@endif

@if($vendor->billingDocuments->isEmpty())
    <div class="ewx-empty">
        <i class="bi bi-receipt"></i>
        No quotations or invoices recorded for this vendor.
    </div>
@else
<div class="table-responsive">
    <table class="table table-hover ewx-table align-middle">
        <thead>
            <tr>
                <th class="ps-3">Document</th>
                <th class="text-center">Status</th>
                @if($canManage)<th class="text-end pe-3">Actions</th>@endif
            </tr>
        </thead>
        <tbody>
        @foreach($vendor->billingDocuments as $doc)
            @php $vndSstFlag = $doc->sstFlag(); @endphp
            <tr>
                <td class="ps-3">
                    <div class="vnd-doc-name">
                        <span class="vnd-type {{ $doc->doc_type === 'invoice' ? 'vnd-type-purchase' : 'vnd-type-rental' }}">{{ $doc->typeLabel() }}</span>
                        {{ $doc->doc_number ?: 'No number' }}
                    </div>
                    @if($doc->description)
                    <div class="vnd-pic-meta">{{ $doc->description }}</div>
                    @endif
                    @if($doc->file_path)
                    <a href="{{ secure_file_url($doc->file_path) }}" target="_blank" class="ewx-quote-link text-decoration-none small">
                        <i class="bi bi-{{ str_ends_with(strtolower((string) $doc->original_filename), '.pdf') ? 'file-earmark-pdf text-danger' : 'file-earmark-image text-primary' }} me-1"></i>View document
                    </a>
                    @endif
                    {{-- How many assets arrived on this document, linking to their group on the
                         Assets tab. Counted in the page query (withCount on the eager-load),
                         not per row. --}}
                    @if($doc->origin_assets_count)
                    <div class="vnd-pic-meta mt-1">
                        <a href="{{ route('vendors.show', [$vendor, 'tab' => 'assets']) }}#inv-doc-{{ $doc->id }}" class="text-decoration-none">
                            <i class="bi bi-box-seam me-1"></i>{{ $doc->origin_assets_count }} asset{{ $doc->origin_assets_count === 1 ? '' : 's' }} arrived on this
                        </a>
                    </div>
                    @endif
                    @include('vendors.partials._ai-chip', ['doc' => $doc])
                </td>
                {{-- Status, plus the two warnings rescued from the removed columns. Both are
                     conditions of the document rather than figures, so the summary below does
                     not carry them and the banner above names no row. --}}
                <td class="text-center text-nowrap">
                    <span class="badge rounded-pill bg-{{ $doc->statusColor() }}">{{ $doc->statusLabel() }}</span>
                    @if($doc->isOverdue())
                    <div class="mt-1"><span class="badge bg-danger">Overdue</span></div>
                    @endif
                    @if($vndSstFlag)
                    <div class="mt-1">
                        <span class="badge bg-warning text-dark" title="{{ $vndSstFlag }}">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>SST
                        </span>
                    </div>
                    @endif
                </td>
                @if($canManage)
                <td class="text-end pe-3 text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#billingModal{{ $doc->id }}" title="Edit document">
                        <i class="bi bi-pencil"></i>
                    </button>
                    @if($doc->file_path)
                    {{-- Here, not in the summary panel: a document that failed to read has no
                         panel, and that is the row that needs to try again. --}}
                    <form action="{{ route('vendors.billing.summarise', [$vendor, $doc]) }}" method="POST" class="d-inline js-confirm ms-1"
                          data-confirm="Read this document again for its summary and for the assistant? The current summary is cleared and replaced. No other field on the record changes."
                          data-confirm-title="Re-read for summary"
                          data-confirm-ok="Re-read"
                          data-confirm-variant="primary">
                        @csrf
                        <button class="btn btn-sm btn-outline-primary" title="Re-read this document for its AI summary"><i class="bi bi-robot"></i></button>
                    </form>
                    @endif
                    <form action="{{ route('vendors.billing.destroy', [$vendor, $doc]) }}" method="POST" class="d-inline js-confirm ms-1"
                          data-confirm="Delete this {{ strtolower($doc->typeLabel()) }} and its uploaded document? This cannot be undone."
                          data-confirm-title="Delete document"
                          data-confirm-ok="Delete"
                          data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger" title="Delete document"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
                @endif
            </tr>
            {{-- Must match the <th> count above, or the summary row silently narrows. --}}
            @include('vendors.partials._ai-row', ['doc' => $doc, 'span' => $canManage ? 3 : 2])
        @endforeach
        </tbody>
    </table>
</div>
@endif
