{{-- Contracts we hold with this vendor. The add/edit form lives in the modal partial.

     Type / Period / Value were dropped as columns on 2026-08-11: the summary on each row
     says what the contract is, and the terms are still on the record — edit the contract
     to read or change them. They are NOT gone from the model, and must not be: the
     assistant pairs those recorded fields with the document text, which is the only reason
     "does this invoice match the contract rate?" is answerable, and how a mis-keyed value
     gets caught. The expiry SIGNAL survives the Period column in the derived State badge
     ("Expiring in 45d" / "Expired"), which is what the listing needed it for. --}}
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <div class="fw-semibold">Contracts</div>
        <div class="text-muted small">Record the terms and upload the signed document &mdash; it is read for a summary you can open on its row.</div>
    </div>
    @if($canManage)
    <button type="button" class="btn btn-sm btn-primary" id="addContractBtn" data-bs-toggle="modal" data-bs-target="#contractModalNew">
        <i class="bi bi-plus-lg me-1"></i>Add Contract
    </button>
    @endif
</div>

@if($vendor->contracts->isEmpty())
    <div class="ewx-empty">
        <i class="bi bi-file-earmark-text"></i>
        No contracts recorded for this vendor.
    </div>
@else
<div class="table-responsive">
    <table class="table table-hover ewx-table align-middle">
        <thead>
            <tr>
                <th class="ps-3">Contract</th>
                <th>Document</th>
                <th class="text-center">State</th>
                @if($canManage)<th class="text-end pe-3">Actions</th>@endif
            </tr>
        </thead>
        <tbody>
        @foreach($vendor->contracts as $contract)
            @php $vndState = $contract->stateBadge(); @endphp
            <tr>
                <td class="ps-3">
                    <div class="vnd-doc-name">{{ $contract->title }}</div>
                    <div class="vnd-pic-meta">
                        @if($contract->contract_reference)Ref. {{ $contract->contract_reference }}@endif
                        @if($contract->payment_terms)<span class="ms-2">{{ $contract->payment_terms }}</span>@endif
                    </div>
                    @if($contract->scope_summary)
                    <div class="vnd-pic-meta mt-1" style="max-width:420px;">{{ \Illuminate\Support\Str::limit($contract->scope_summary, 160) }}</div>
                    @endif
                </td>
                <td>
                    @if($contract->file_path)
                        <a href="{{ secure_file_url($contract->file_path) }}" target="_blank" class="ewx-quote-link text-decoration-none">
                            <i class="bi bi-{{ str_ends_with(strtolower((string) $contract->original_filename), '.pdf') ? 'file-earmark-pdf text-danger' : 'file-earmark-image text-primary' }} me-1"></i>View
                        </a>
                        @include('vendors.partials._ai-chip', ['doc' => $contract])
                    @else
                        <span class="text-muted small">No document</span>
                    @endif
                </td>
                <td class="text-center">
                    <span class="badge rounded-pill bg-{{ $vndState['color'] }}">{{ $vndState['label'] }}</span>
                </td>
                @if($canManage)
                <td class="text-end pe-3 text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#contractModal{{ $contract->id }}" title="Edit contract">
                        <i class="bi bi-pencil"></i>
                    </button>
                    @if($contract->file_path)
                    {{-- Lives here rather than in the summary panel below, because a document
                         that FAILED to read has no panel — and that is exactly the row that
                         needs to be able to try again. --}}
                    <form action="{{ route('vendors.contracts.summarise', [$vendor, $contract]) }}" method="POST" class="d-inline js-confirm ms-1"
                          data-confirm="Read this document again for its summary and for the assistant? The current summary is cleared and replaced. No other field on the record changes."
                          data-confirm-title="Re-read for summary"
                          data-confirm-ok="Re-read"
                          data-confirm-variant="primary">
                        @csrf
                        <button class="btn btn-sm btn-outline-primary" title="Re-read this document for its AI summary"><i class="bi bi-robot"></i></button>
                    </form>
                    @endif
                    <form action="{{ route('vendors.contracts.destroy', [$vendor, $contract]) }}" method="POST" class="d-inline js-confirm ms-1"
                          data-confirm="Delete the contract &quot;{{ $contract->title }}&quot; and its uploaded document? This cannot be undone."
                          data-confirm-title="Delete contract"
                          data-confirm-ok="Delete"
                          data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger" title="Delete contract"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
                @endif
            </tr>
            {{-- Must match the <th> count above: the summary row is a single full-width cell,
                 and a colspan left behind after a column change silently narrows it. --}}
            @include('vendors.partials._ai-row', ['doc' => $contract, 'span' => $canManage ? 4 : 3])
        @endforeach
        </tbody>
    </table>
</div>
@endif
