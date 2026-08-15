{{-- Proof of payment for an invoice in the register above — a bank transfer slip,
     remittance advice or receipt. NOT the payroll payslip.

     TWO KINDS OF MODAL, and the split is deliberate:

      1. ONE upload modal (#paymentSlipUpload) — choose the invoice, attach the slip, it is
         read, correct the summary, Save. Filing it is what marks that invoice PAID.
      2. ONE per filed slip (#paymentSlipModal{id}) — open the file, correct its summary, or
         remove it. Removing is what marks the invoice Pending again, and it is the only
         control that can: with the status derived, a slip filed against the wrong invoice
         would otherwise leave that bill reading Paid with nothing able to say otherwise.

     There is no file input on the per-slip modal, and that is not an omission. One invoice
     holds at most one slip (a unique index says so), so REPLACING one is the same act as
     filing one: pick the same invoice in the upload modal again. A second file input here
     would be a second way to do it, on a second form, that _scan-js would also have to
     bind — for no capability the upload modal does not already have.

     Rendered once per page, OUTSIDE the tab panes and the tables: a <div> inside <tbody> is
     invalid markup the browser hoists out, silently detaching each form from its fields.

     old() is GLOBAL, so each form carries a hidden `_form` naming itself; only the form
     matching old('_form') replays old input, and show.blade's shared re-open script uses the
     same marker to bring exactly that modal back after a rejected submit. --}}
@php
    $vndOldForm = old('_form');
    // Invoices only. A quotation is an offer, not a bill: paying one is not a state this
    // register can represent, and the controller refuses it on the same grounds.
    $vndPayableInvoices = $vendor->billingDocuments->where('doc_type', 'invoice');
    $vndFiledSlips = $vndPayableInvoices->map->paymentSlip->filter();
    $vndUploadToken = $vndOldForm === 'paymentSlipUpload' ? old('scan_token', '') : '';
@endphp

{{-- ── 1. Upload a payment slip ───────────────────────────────────────────────── --}}
<div class="modal fade" id="paymentSlipUpload" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            {{-- No enctype: the file goes to the scan endpoint first and this form carries
                 only the token naming it, which is what lets the summary be reviewed before
                 the record exists. --}}
            <form method="POST" action="{{ route('vendors.payment-slips.store', $vendor) }}"
                  data-vnd-doc-form data-vnd-kind="payment"
                  data-vnd-scan-url="{{ route('vendors.documents.scan', $vendor) }}"
                  data-vnd-status-url="{{ route('vendors.documents.scan-status', $vendor) }}"
                  data-vnd-new="1">
                @csrf
                <input type="hidden" name="_form" value="paymentSlipUpload">
                <input type="hidden" name="scan_token" value="{{ $vndUploadToken }}" data-vnd-token>
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">
                        <i class="bi bi-cash-coin me-2"></i>Upload Payment Slip
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if($vndOldForm === 'paymentSlipUpload' && $errors->any())
                    <div class="alert alert-danger py-2" style="font-size:12.5px;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0 ps-3">
                            @foreach($errors->all() as $vndError)
                                <li>{{ $vndError }}</li>
                            @endforeach
                        </ul>
                        <div class="mt-1 text-muted">
                            The slip you attached is still held &mdash; you do not need to attach it again.
                        </div>
                    </div>
                    @endif

                    @if($vndPayableInvoices->isEmpty())
                        {{-- Stated rather than hidden: the button that opens this modal is
                             rendered unconditionally, so this is where "there is nothing to
                             pay yet" gets said. An absent button would read as a broken
                             feature instead. --}}
                        <div class="alert alert-secondary py-2 mb-0" style="font-size:12.5px;">
                            <i class="bi bi-info-circle me-1"></i>
                            There are no invoices filed for this vendor yet. A payment slip is filed against an
                            invoice, so add the invoice first &mdash; a quotation cannot be paid.
                        </div>
                    @else
                    {{-- ── The invoice this payment settles ─────────────────────
                         CHOSEN, never read off the slip. A transfer slip regularly names no
                         invoice at all, and guessing which bill a payment settles is not a
                         guess this page may make — filing it marks that invoice Paid. What
                         the slip DOES say about an invoice number is stored beside it and
                         compared, and a disagreement is flagged on the row. --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="paymentSlipInvoice">
                            Which invoice is this payment for? <span class="text-danger">*</span>
                        </label>
                        <select name="vendor_billing_document_id" id="paymentSlipInvoice" class="form-select" required data-vnd-slip-select>
                            <option value="">Choose an invoice…</option>
                            @foreach($vndPayableInvoices as $vndInv)
                                @php
                                    // The warning rides in the label rather than appearing as
                                    // a banner when the choice changes: no JS, so it cannot
                                    // break under CSP, and it is read at the moment of
                                    // choosing rather than after.
                                    $vndLabel = $vndInv->optionLabel()
                                        .($vndInv->paymentSlip ? '  ⚠ already has a payment slip — uploading replaces it' : '');
                                @endphp
                                <option value="{{ $vndInv->id }}"
                                        {{ (string) old('vendor_billing_document_id') === (string) $vndInv->id ? 'selected' : '' }}>
                                    {{ $vndLabel }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text text-muted small">
                            Only invoices are listed &mdash; a quotation is an offer, not a bill.
                            An invoice can hold one payment slip; uploading a second one replaces the first.
                        </div>
                    </div>

                    {{-- ── The slip ─────────────────────────────────────────────── --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Payment slip <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" data-vnd-file>
                        <div class="form-text text-muted small">
                            PDF or image, max 10&nbsp;MB &mdash; a bank transfer slip, remittance advice or receipt.
                            <span class="d-block">It is read as soon as you choose it, and its summary appears below for you to correct.</span>
                        </div>
                    </div>

                    <div class="vnd-scan-state d-none" data-vnd-state role="status" aria-live="polite">
                        <span class="spinner-border spinner-border-sm me-2" data-vnd-spinner></span>
                        <span data-vnd-state-text></span>
                    </div>

                    {{-- ── What it says ─────────────────────────────────────────── --}}
                    <div class="{{ filled($vndUploadToken) ? '' : 'd-none' }}" data-vnd-reading>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="paymentSlipCompanies">Companies Involved</label>
                                <input type="text" name="companies_involved" class="form-control"
                                       id="paymentSlipCompanies" data-vnd-companies
                                       placeholder="e.g. Enlinea Sdn Bhd, Fixmaster Sdn Bhd"
                                       value="{{ $vndOldForm === 'paymentSlipUpload' ? old('companies_involved') : '' }}">
                                <div class="form-text text-muted small">
                                    Who paid whom, as named on the slip. Read off the document &mdash; correct them if it read them wrong.
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="paymentSlipSummary">Summary</label>
                                <textarea name="ai_summary" rows="7" class="form-control" id="paymentSlipSummary"
                                          data-vnd-summary placeholder="The summary of the payment slip appears here once it has been read.">{{ $vndOldForm === 'paymentSlipUpload' ? old('ai_summary') : '' }}</textarea>
                                <div class="form-text text-muted small">
                                    Generated from the slip and yours to edit. What you save here is what the row shows.
                                </div>
                            </div>
                            <div class="col-12 d-none" data-vnd-points-wrap>
                                <div class="vnd-scan-points">
                                    <div class="vnd-scan-points-head">Key points read from the slip</div>
                                    <ul class="mb-0" data-vnd-points></ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- The amount, date and reference are read off the slip and stored; the
                         row prints them and checks the amount against the invoice total. They
                         are not inputs here for the same reason no figure on this tab is one:
                         a value the form cannot show is a value nobody checked. --}}
                    <div class="text-muted mt-3" style="font-size:12px;">
                        <i class="bi bi-info-circle me-1"></i>
                        The amount paid, the payment date and the reference number are read off the slip and shown on
                        its row. If the amount does not match the invoice total, the row says so &mdash; it is flagged,
                        never blocked.
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    @if($vndPayableInvoices->isNotEmpty())
                    <button type="submit" class="btn btn-success btn-sm" data-vnd-save {{ filled($vndUploadToken) ? '' : 'disabled' }}>
                        <i class="bi bi-check-circle me-1"></i>Save Payment Slip
                    </button>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── 2. A filed payment slip ────────────────────────────────────────────────── --}}
@foreach($vndFiledSlips as $vndSlip)
    @php
        $vndSlipModalId = 'paymentSlipModal'.$vndSlip->id;
        $vndSlipInvoice = $vndSlip->document;
        $vndSlipMismatch = $vndSlip->mismatchFlag();
        $vndSlipIsOld = $vndOldForm === $vndSlipModalId;
    @endphp
<div class="modal fade" id="{{ $vndSlipModalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ route('vendors.payment-slips.update', [$vendor, $vndSlip]) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="_form" value="{{ $vndSlipModalId }}">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">
                        <i class="bi bi-cash-coin me-2"></i>Payment Slip &mdash; {{ $vndSlipInvoice->doc_number ?: 'invoice with no number' }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if($vndSlipIsOld && $errors->any())
                    <div class="alert alert-danger py-2" style="font-size:12.5px;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0 ps-3">
                            @foreach($errors->all() as $vndError)
                                <li>{{ $vndError }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    @if($vndSlipMismatch)
                    <div class="alert alert-warning py-2" style="font-size:12.5px;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>This slip does not match the invoice it is filed against.</strong>
                        <div>{{ $vndSlipMismatch }}</div>
                        <div class="mt-1 text-muted">
                            Both figures are machine readings, so this is as likely to be a misread as a mis-payment.
                            Check the two documents; if the slip belongs to a different invoice, remove it here and
                            upload it against the right one.
                        </div>
                    </div>
                    @endif

                    {{-- ── What was read off the slip ───────────────────────────
                         Displayed, never editable: they are read off the document, and this
                         page does not let a figure be typed over. A misread amount is fixed
                         by uploading the slip again, which re-reads it. --}}
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="vnd-label">Amount paid</div>
                            <div class="vnd-value">{{ $vndSlip->amountLabel() ?: '—' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="vnd-label">Paid on</div>
                            <div class="vnd-value">{{ $vndSlip->paid_on ? fmt_date($vndSlip->paid_on) : '—' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="vnd-label">Method</div>
                            <div class="vnd-value">{{ $vndSlip->payment_method ?: '—' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="vnd-label">Payment reference</div>
                            <div class="vnd-value">{{ $vndSlip->payment_reference ?: '—' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="vnd-label">Invoice named on the slip</div>
                            <div class="vnd-value">{{ $vndSlip->invoice_reference ?: '—' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="vnd-label">Invoice total</div>
                            <div class="vnd-value">
                                {{ $vndSlipInvoice->total === null ? '—' : $vndSlipInvoice->currency.' '.number_format((float) $vndSlipInvoice->total, 2) }}
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
                        <a href="{{ secure_file_url($vndSlip->file_path) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye me-1"></i>Open the slip
                        </a>
                        <span class="text-muted" style="font-size:12px;">
                            {{ $vndSlip->original_filename ?: 'uploaded document' }}
                            @php
                                // Built in PHP, never glued to a word as "…by @if(...)": Blade
                                // only treats @ as a directive when it is not preceded by a
                                // word character, so a glued one compiles through as text and
                                // leaves its @endif unbalanced — at render, not compile.
                                $vndSlipWho = $vndSlip->uploader?->name;
                            @endphp
                            @if($vndSlipWho) &middot; uploaded by {{ $vndSlipWho }} @endif
                            @if($vndSlip->created_at) &middot; {{ fmt_datetime($vndSlip->created_at) }} @endif
                        </span>
                    </div>

                    <hr class="my-3">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="{{ $vndSlipModalId }}_companies">Companies Involved</label>
                            <input type="text" name="companies_involved" class="form-control"
                                   id="{{ $vndSlipModalId }}_companies"
                                   value="{{ $vndSlipIsOld ? old('companies_involved') : implode(', ', $vndSlip->companiesInvolvedList()) }}">
                            <div class="form-text text-muted small">Who paid whom, separated by commas.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="{{ $vndSlipModalId }}_summary">Summary</label>
                            <textarea name="ai_summary" rows="7" class="form-control" id="{{ $vndSlipModalId }}_summary">{{ $vndSlipIsOld ? old('ai_summary') : $vndSlip->ai_summary }}</textarea>
                            <div class="form-text text-muted small">
                                {{ $vndSlip->summaryProvenance() }}
                                @if($vndSlip->ai_status === 'partial')
                                    <span class="d-block text-warning-emphasis">{{ $vndSlip->aiNote() }}</span>
                                @endif
                            </div>
                        </div>
                        @if($vndSlip->ai_key_points)
                        <div class="col-12">
                            <div class="vnd-scan-points">
                                <div class="vnd-scan-points-head">Key points read from the slip</div>
                                <ul class="mb-0">
                                    @foreach($vndSlip->ai_key_points as $vndPoint)
                                        <li>{{ $vndPoint }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    {{-- Submitted through the `form` attribute from a form rendered OUTSIDE
                         this one: a nested <form> is invalid markup the browser silently
                         drops, taking the fields with it. --}}
                    <button type="submit" class="btn btn-sm btn-outline-danger" form="vndSlipDelete{{ $vndSlip->id }}">
                        <i class="bi bi-trash me-1"></i>Remove payment slip
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-circle me-1"></i>Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

{{-- Removal forms for the modals above. OUTSIDE the modal markup for the reason stated on
     the button: a <form> inside another <form> is dropped by the browser.

     The confirmation says what removal does to the INVOICE, not just to the slip — the
     consequence lands on a different row from the one being acted on, and it is the whole
     reason this control exists. --}}
@foreach($vndFiledSlips as $vndSlip)
<form id="vndSlipDelete{{ $vndSlip->id }}" method="POST"
      action="{{ route('vendors.payment-slips.destroy', [$vendor, $vndSlip]) }}"
      class="js-confirm d-none"
      data-confirm="Remove this payment slip and delete the uploaded file? {{ $vndSlip->document->doc_number ?: 'The invoice' }} goes back to Pending until another slip is filed against it."
      data-confirm-title="Remove payment slip"
      data-confirm-ok="Remove"
      data-confirm-variant="danger">
    @csrf
    @method('DELETE')
</form>
@endforeach

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
// Pre-select the invoice when the upload modal is opened from a row, so the payment is
// filed against the invoice the operator is looking at rather than one they pick again from
// a list of similar numbers. CSP-safe: no inline handlers, and the value written is an id
// read from our own markup.
//
// Bound on the MODAL rather than on each trigger: Bootstrap hands the trigger over in
// event.relatedTarget, and one listener cannot drift out of step with the rows the way one
// listener per button would.
(function () {
    var modal = document.getElementById('paymentSlipUpload');
    if (!modal) { return; }

    var select = modal.querySelector('[data-vnd-slip-select]');
    if (!select) { return; }

    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger || !trigger.getAttribute) { return; }

        var invoice = trigger.getAttribute('data-vnd-slip-invoice');
        // The tab-level button carries no invoice: it opens the modal to be filled in from
        // scratch, and must not silently keep whichever row was picked last.
        select.value = invoice || '';
    });
})();
</script>
@endpush
