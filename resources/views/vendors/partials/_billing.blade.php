{{-- Quotations + invoices received from this vendor. A document register, not an AP
     ledger — nothing here posts to Accounting.

     FIVE columns as of 2026-08-13: Companies Involved (with the document number) · Summary ·
     Status · Payment Slip · Actions.

     STATUS IS DERIVED, not chosen. An invoice reads Paid when a payment slip is filed
     against it and Pending when none is; the hand-set dropdown (Received / Under review /
     Accepted / Declined / Paid / Disputed) was retired with this change. On a register whose
     entire value is provenance, "Paid" has to mean a document was produced — not that
     somebody picked it from a list. A QUOTATION carries no payment status at all: it is an
     offer, and reading "Pending" against one would state that we owe money on a document
     nobody has acted on.

     The document's NUMBER leads the first column rather than the Summary cell (where it sat
     until the payment slip arrived). It has to be somewhere: without it a reader cannot say
     WHICH invoice a row is, and every Action would be acting on a document identified only
     by its position.

     Dates / Contract / Subtotal / SST / Total went as columns on 2026-08-11 and off every
     FORM on 2026-08-13 — nothing here is typed. They are NOT gone from the model and must
     not be: `recordedFields()` pairs them with the document text, which is how the assistant
     answers "does this invoice match the contract rate?"; the invoice total is also what a
     payment slip's amount is checked against. They are read off the document by the scan; a
     wrong one is fixed by re-reading or replacing the document, not by hand.

     TWO SIGNALS from those removed columns still live in the Status cell, because neither is
     a figure: the SST warning (a document charging tax this vendor may not charge) and
     Overdue (an invoice past its due date with nothing paid against it). The banner above
     counts SST flags but names no row, so dropping the per-row marker would leave
     "3 documents charge SST" with no way to tell which three. --}}
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <div class="fw-semibold">Billing Documents</div>
        <div class="text-muted small">Upload a quotation, invoice or credit note &mdash; it is read for a summary you can correct before saving. Nothing here posts to Accounting.</div>
    </div>
    @if($canManage)
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-primary" id="addBillingBtn" data-bs-toggle="modal" data-bs-target="#billingModalNew">
            <i class="bi bi-plus-lg me-1"></i>Add Billing Document
        </button>
        {{-- Rendered even when there is no invoice to pay yet. The modal states that in
             words; a button that disappears reads as a missing feature rather than as one
             with nothing to act on. --}}
        <button type="button" class="btn btn-sm btn-outline-success" id="addPaymentSlipBtn"
                data-bs-toggle="modal" data-bs-target="#paymentSlipUpload">
            <i class="bi bi-cash-coin me-1"></i>Upload Payment Slip
        </button>
    </div>
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
        No quotations, invoices or credit notes recorded for this vendor.
    </div>
@else
<div class="table-responsive">
    <table class="table table-hover ewx-table align-middle">
        <thead>
            <tr>
                <th class="ps-3">Companies Involved</th>
                <th>Summary</th>
                <th class="text-center">Status</th>
                <th>Payment Slip</th>
                <th class="text-end pe-3">Actions</th>
            </tr>
        </thead>
        <tbody>
        @foreach($vendor->billingDocuments as $doc)
            @php
                $vndSstFlag = $doc->sstFlag();
                $vndParties = $doc->companiesInvolvedList();
                $vndPayment = $doc->paymentState();
                $vndSlip = $doc->paymentSlip;
                $vndSlipFlag = $vndSlip?->mismatchFlag();
            @endphp
            <tr>
                {{-- Identity first, then the parties. Never blank: a document filed before
                     the scan existed, or one whose parties could not be read, says which it
                     is rather than leaving a column that reads as a bill with no
                     counterparty. --}}
                <td class="ps-3">
                    <div class="vnd-doc-name">
                        <span class="vnd-type {{ $doc->typeBadgeClass() }}">{{ $doc->typeLabel() }}</span>
                        {{ $doc->doc_number ?: 'No number' }}
                    </div>
                    @forelse($vndParties as $vndParty)
                        <div class="vnd-party">{{ $vndParty }}</div>
                    @empty
                        <span class="text-muted small">
                            {{ $doc->file_path ? 'Not read from the document' : 'No document on file' }}
                        </span>
                    @endforelse
                </td>
                <td>
                    @if($doc->hasAiSummary())
                        <div class="vnd-sum-cell">{{ \Illuminate\Support\Str::limit($doc->ai_summary ?: implode(' · ', $doc->ai_key_points ?? []), 240) }}</div>
                        <div class="vnd-sum-foot">
                            <button type="button" class="vnd-ai-toggle" data-bs-toggle="collapse"
                                    data-bs-target="#vndAiSumb{{ $doc->id }}" aria-expanded="false"
                                    aria-controls="vndAiSumb{{ $doc->id }}">
                                <i class="bi bi-chevron-down me-1"></i>Full summary
                            </button>
                            <span class="vnd-sum-prov">
                                @if($doc->summaryIsEdited())
                                    <i class="bi bi-pencil-square me-1"></i>Edited
                                @else
                                    <i class="bi bi-robot me-1"></i>From the document
                                @endif
                            </span>
                            @if($doc->ai_status === 'partial')
                                <span class="vnd-sum-partial" title="{{ $doc->aiNote() }}">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Part of the document only
                                </span>
                            @endif
                        </div>
                    @else
                        @include('vendors.partials._ai-chip', ['doc' => $doc])
                    @endif
                </td>
                {{-- Status, plus the two warnings rescued from the removed columns. Both are
                     conditions of the document rather than figures, so the summary does not
                     carry them and the banner above names no row. --}}
                <td class="text-center text-nowrap">
                    @if($vndPayment['color'])
                        <span class="badge rounded-pill bg-{{ $vndPayment['color'] }}" title="{{ $vndPayment['note'] }}">{{ $vndPayment['label'] }}</span>
                    @else
                        <span class="text-muted" title="{{ $vndPayment['note'] }}">{{ $vndPayment['label'] }}</span>
                    @endif
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
                {{-- What the proof of payment says. The figures lead — an amount, a date and
                     a reference are what a reader checks at a glance — with the slip's own
                     summary clamped beneath and in full in its modal. --}}
                <td>
                    @if($vndSlip)
                        @php $vndSlipLine = $vndSlip->detailLine(); @endphp
                        <div class="vnd-doc-name">
                            {{ $vndSlipLine ?: 'Filed — no figures could be read' }}
                        </div>
                        @if($vndSlip->hasAiSummary())
                            <div class="vnd-sum-cell">{{ \Illuminate\Support\Str::limit($vndSlip->ai_summary ?: implode(' · ', $vndSlip->ai_key_points ?? []), 160) }}</div>
                        @else
                            <div class="vnd-sum-foot"><span class="vnd-sum-prov">{{ $vndSlip->aiNote() ?: 'Not read.' }}</span></div>
                        @endif
                        @if($vndSlipFlag)
                        <div class="mt-1">
                            <span class="badge bg-warning text-dark" title="{{ $vndSlipFlag }}">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>Does not match this invoice
                            </span>
                        </div>
                        @endif
                    @elseif($doc->carriesPaymentStatus())
                        <span class="text-muted small">Not paid yet &mdash; no payment slip filed.</span>
                    @else
                        <span class="text-muted small">&mdash;</span>
                    @endif
                </td>
                <td class="text-end pe-3 text-nowrap">
                    @if($doc->file_path)
                    <a href="{{ secure_file_url($doc->file_path) }}" target="_blank"
                       class="btn btn-sm btn-outline-secondary" title="View the document">
                        <i class="bi bi-eye"></i>
                    </a>
                    @endif
                    @if($canManage)
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-bs-toggle="modal" data-bs-target="#billingModal{{ $doc->id }}" title="Edit the summary and the companies">
                        <i class="bi bi-pencil"></i>
                    </button>
                    @endif

                    {{-- One control, two states — and only on an invoice, because a quotation
                         is never paid. With a slip on file it opens that slip; without one it
                         opens the upload modal with this invoice already chosen, so the
                         payment is filed against the row the operator is looking at rather
                         than one they pick again from a list. --}}
                    @if($doc->carriesPaymentStatus())
                        @if($vndSlip && $canManage)
                        <button type="button" class="btn btn-sm btn-outline-success ms-1"
                                data-bs-toggle="modal" data-bs-target="#paymentSlipModal{{ $vndSlip->id }}"
                                title="View the payment slip filed against this invoice">
                            <i class="bi bi-cash-coin"></i>
                        </button>
                        @elseif($vndSlip)
                        {{-- A read-only viewer gets the document itself rather than the modal,
                             which is a management surface (correct the summary, remove the
                             slip). Reading it is not a management control — the invoice's own
                             View button is offered to the same viewer on the same row. --}}
                        <a href="{{ secure_file_url($vndSlip->file_path) }}" target="_blank"
                           class="btn btn-sm btn-outline-success ms-1"
                           title="Open the payment slip filed against this invoice">
                            <i class="bi bi-cash-coin"></i>
                        </a>
                        @elseif($canManage)
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-1"
                                data-bs-toggle="modal" data-bs-target="#paymentSlipUpload"
                                data-vnd-slip-invoice="{{ $doc->id }}"
                                title="Upload the payment slip for this invoice">
                            <i class="bi bi-cash-coin"></i>
                        </button>
                        @endif
                    @endif

                    {{-- On EVERY row, read or not: the panel is where the reason a document
                         cannot be asked about is printed, and where it can be read without
                         leaving. A button that vanishes for an unread document hides the
                         explanation behind its own absence.

                         Same icon as the floating button and the panel header — one
                         assistant, one icon. The difference between them is SCOPE, and the
                         panel says which in words: this row's button asks about this
                         document alone, the floating one about everything readable here.
                         See the fuller note on the contracts listing. --}}
                    <a href="{{ route('vendors.show', [$vendor, 'tab' => 'billing', 'ask' => 1, 'focus' => $doc->askKey()]) }}"
                       class="btn btn-sm btn-outline-primary ms-1"
                       data-vnd-ask-focus="{{ $doc->askKey() }}"
                       title="{{ $doc->hasAiText() ? 'Ask AI about this document only' : 'Open the assistant — this document has not been read yet' }}">
                        <i class="bi bi-robot"></i>
                    </a>
                    @if($canManage)
                    <form action="{{ route('vendors.billing.destroy', [$vendor, $doc]) }}" method="POST" class="d-inline js-confirm ms-1"
                          data-confirm="Delete this {{ strtolower($doc->typeLabel()) }} and its uploaded document?{{ $vndSlip ? ' The payment slip filed against it is deleted too.' : '' }} This cannot be undone."
                          data-confirm-title="Delete document"
                          data-confirm-ok="Delete"
                          data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger" title="Delete document"><i class="bi bi-trash"></i></button>
                    </form>
                    @endif
                </td>
            </tr>
            {{-- Must match the <th> count above, or the panel silently narrows. --}}
            @include('vendors.partials._ai-row', ['doc' => $doc, 'span' => 5])
        @endforeach
        </tbody>
    </table>
</div>
@endif
