{{-- Contracts we hold with this vendor. The add/edit form lives in the modal partial.

     FOUR columns as of 2026-08-13: Companies Involved · Summary · Status · Actions. The
     summary IS the row now — it is read off the document and editable, so it says what the
     contract is far better than a Type dropdown ever did.

     The contract's own IDENTITY (title, reference) leads the Summary cell rather than
     getting a column of its own. It has to be somewhere: four columns of parties, prose,
     a badge and buttons leave a reader unable to say WHICH contract a row is, and the
     Actions all act on a document they would then be identifying by its position.

     Type / Period / Value went as columns on 2026-08-11 and off every FORM on 2026-08-13 —
     nothing here is typed. They are NOT gone from the model: the assistant pairs those
     recorded fields with the document text, which is the only reason "does this invoice
     match the contract rate?" is answerable and how a mis-read value gets caught. They are
     read off the document by the scan; a wrong one is fixed by re-reading or replacing the
     document, not by hand. The expiry SIGNAL survives in the derived Status badge
     ("Expiring in 45d" / "Expired"), which is what the listing needed the Period column for. --}}
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <div class="fw-semibold">Contracts</div>
        <div class="text-muted small">Upload the signed document &mdash; it is read for a summary you can correct before saving.</div>
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
                <th class="ps-3">Companies Involved</th>
                <th>Summary</th>
                <th class="text-center">Status</th>
                <th class="text-end pe-3">Actions</th>
            </tr>
        </thead>
        <tbody>
        @foreach($vendor->contracts as $contract)
            @php
                $vndState = $contract->stateBadge();
                $vndParties = $contract->companiesInvolvedList();
                // Filed automatically from a disposal cycle, so it is a RECORD here, not a
                // contract entered on this tab: no Edit, no Delete. Its figure and its state
                // belong to the cycle, and letting somebody rewrite either from here would
                // alter what a vendor is recorded as having offered on a disposal that may
                // already have been approved on the strength of it.
                $vndFromCycle = $contract->isEwasteQuotation();
                $vndCycle = $vndFromCycle ? $contract->assetDecommissionQuotation?->batch : null;
            @endphp
            <tr>
                <td class="ps-3">
                    @forelse($vndParties as $vndParty)
                        <div class="vnd-party">{{ $vndParty }}</div>
                    @empty
                        {{-- Never blank: a document filed before the reading existed, or one
                             whose parties could not be read, has to say which it is — an
                             empty cell reads as a contract with no counterparty. --}}
                        <span class="text-muted small">
                            {{ $contract->file_path ? 'Not read from the document' : 'No document on file' }}
                        </span>
                    @endforelse
                </td>
                <td>
                    <div class="vnd-doc-name">{{ $contract->title }}</div>
                    @if($vndCycle)
                        {{-- The cycle is where this document is worked and where its figure and
                             decision live, so the row points at it rather than offering controls
                             that would edit a copy. --}}
                        <div class="vnd-pic-meta">
                            <i class="bi bi-recycle me-1"></i>Filed from
                            <a href="{{ route('decommission.show', $vndCycle) }}">{{ $vndCycle->batch_number }}</a>
                        </div>
                    @elseif($contract->contract_reference)
                        <div class="vnd-pic-meta">Ref. {{ $contract->contract_reference }}</div>
                    @endif

                    @if($contract->hasAiSummary())
                        {{-- Clamped, with the whole thing (and the key points) one click away
                             in the panel below. A cell that renders a 200-word summary in
                             full makes every row a screenful and the table unreadable; one
                             that shows a snippet with no way to reach the rest hides what
                             this column exists for. --}}
                        <div class="vnd-sum-cell">{{ \Illuminate\Support\Str::limit($contract->ai_summary ?: implode(' · ', $contract->ai_key_points ?? []), 240) }}</div>
                        <div class="vnd-sum-foot">
                            <button type="button" class="vnd-ai-toggle" data-bs-toggle="collapse"
                                    data-bs-target="#vndAiSumc{{ $contract->id }}" aria-expanded="false"
                                    aria-controls="vndAiSumc{{ $contract->id }}">
                                <i class="bi bi-chevron-down me-1"></i>Full summary
                            </button>
                            <span class="vnd-sum-prov">
                                @if($contract->summaryIsEdited())
                                    <i class="bi bi-pencil-square me-1"></i>Edited
                                @else
                                    <i class="bi bi-robot me-1"></i>From the document
                                @endif
                            </span>
                            @if($contract->ai_status === 'partial')
                                <span class="vnd-sum-partial" title="{{ $contract->aiNote() }}">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Part of the document only
                                </span>
                            @endif
                        </div>
                    @else
                        {{-- No summary: say why, rather than leaving the column this table is
                             now built around silently blank. --}}
                        @include('vendors.partials._ai-chip', ['doc' => $contract])
                    @endif
                </td>
                <td class="text-center">
                    <span class="badge rounded-pill bg-{{ $vndState['color'] }}">{{ $vndState['label'] }}</span>
                </td>
                <td class="text-end pe-3 text-nowrap">
                    @if($contract->file_path)
                    <a href="{{ secure_file_url($contract->file_path) }}" target="_blank"
                       class="btn btn-sm btn-outline-secondary" title="View the document">
                        <i class="bi bi-eye"></i>
                    </a>
                    @endif
                    @if($canManage && ! $vndFromCycle)
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-bs-toggle="modal" data-bs-target="#contractModal{{ $contract->id }}" title="Edit the summary and the companies">
                        <i class="bi bi-pencil"></i>
                    </button>
                    @endif
                    {{-- Keeps the operator on THIS tab and opens the assistant over it:
                         `ask` opens the panel, `focus` says which document to tick. Not
                         tab=ask — that names no tab and would bounce them to Profile, away
                         from the row they just asked about.

                         Rendered on EVERY row, including one with nothing read yet. The
                         panel is precisely where the reason a document cannot be asked about
                         is printed — and where it can be read without leaving — so a button
                         that disappears for an unread document hides the explanation behind
                         its own absence and reads as a broken feature.

                         SAME ICON as the floating button and the panel header, deliberately:
                         this opens the same assistant, and a second icon for it said they
                         were two different features. What differs is the SCOPE, which the
                         panel states in words — this one asks about this document alone, the
                         floating button about every readable document on the page.

                         data-vnd-ask-focus lets the script scope + open the panel in place;
                         the href stays as the no-JS path and is what carries the focus when
                         the page really does reload. --}}
                    <a href="{{ route('vendors.show', [$vendor, 'tab' => 'contracts', 'ask' => 1, 'focus' => $contract->askKey()]) }}"
                       class="btn btn-sm btn-outline-primary ms-1"
                       data-vnd-ask-focus="{{ $contract->askKey() }}"
                       title="{{ $contract->hasAiText() ? 'Ask AI about this document only' : 'Open the assistant — this document has not been read yet' }}">
                        <i class="bi bi-robot"></i>
                    </a>
                    @if($canManage && ! $vndFromCycle)
                    <form action="{{ route('vendors.contracts.destroy', [$vendor, $contract]) }}" method="POST" class="d-inline js-confirm ms-1"
                          data-confirm="Delete the contract &quot;{{ $contract->title }}&quot; and its uploaded document? This cannot be undone."
                          data-confirm-title="Delete contract"
                          data-confirm-ok="Delete"
                          data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger" title="Delete contract"><i class="bi bi-trash"></i></button>
                    </form>
                    @endif
                </td>
            </tr>
            {{-- The full summary + key points, as a full-width row under this one. Must
                 match the <th> count above, or the panel silently narrows. --}}
            @include('vendors.partials._ai-row', ['doc' => $contract, 'span' => 4])
        @endforeach
        </tbody>
    </table>
</div>
@endif
