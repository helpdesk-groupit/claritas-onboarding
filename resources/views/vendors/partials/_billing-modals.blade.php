{{-- Add + per-document edit modals for quotations and invoices. Rendered once per page,
     outside the tables and tab panes (a <div> inside <tbody> is invalid markup the browser
     hoists out, detaching the form from its fields).

     Same shape as the contract modals: ADD is an upload form — pick the document, it is
     read, the summary is shown for correction, Save. Whether it is a quotation or an
     invoice is decided by the reading, not asked for; so are the number, the dates and the
     figures, which the controller takes from the stored scan and which no form here ever
     displays or accepts. EDIT is for the SUMMARY, the companies and replacing the document
     — a mis-read total is corrected by re-reading the file, not by typing over it.

     old() is GLOBAL, so each form carries a hidden `_form` naming itself and only the form
     matching old('_form') replays old input; the same marker re-opens that one modal. --}}
@php
    $vndBillingForms = collect([null])->merge($vendor->billingDocuments);
    $vndOldForm = old('_form');
    $vndSstVerdict = $vendor->sstVerdict();
@endphp

@foreach($vndBillingForms as $d)
    @php
        $isNew = $d === null;
        $modalId = $isNew ? 'billingModalNew' : 'billingModal'.$d->id;
        $action = $isNew
            ? route('vendors.billing.store', $vendor)
            : route('vendors.billing.update', [$vendor, $d]);
        $v = function (string $key, $fallback = '') use ($modalId, $vndOldForm) {
            return $vndOldForm === $modalId ? old($key, $fallback) : $fallback;
        };
        $vndCompanies = $isNew ? '' : implode(', ', $d->companiesInvolvedList());
        // A bounced submit must not throw the upload away — the file is already stored
        // against the token, so replaying it is what makes "still held" true.
        $vndToken = $v('scan_token', '');
        $vndReadingOpen = ! $isNew || filled($vndToken);
    @endphp
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            {{-- No enctype: the file goes to the scan endpoint first and this form carries
                 only the token naming it, which is what lets the summary be reviewed before
                 the record exists. --}}
            <form method="POST" action="{{ $action }}" data-vnd-doc-form data-vnd-kind="billing"
                  data-vnd-scan-url="{{ route('vendors.documents.scan', $vendor) }}"
                  data-vnd-status-url="{{ route('vendors.documents.scan-status', $vendor) }}"
                  data-vnd-new="{{ $isNew ? '1' : '0' }}">
                @csrf
                @if(! $isNew) @method('PUT') @endif
                <input type="hidden" name="_form" value="{{ $modalId }}">
                <input type="hidden" name="scan_token" value="{{ $vndToken }}" data-vnd-token>
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">
                        <i class="bi bi-receipt me-2"></i>{{ $isNew ? 'Add Quotation / Invoice' : 'Edit '.$d->typeLabel() }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if($vndOldForm === $modalId && $errors->any())
                    <div class="alert alert-danger py-2" style="font-size:12.5px;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0 ps-3">
                            @foreach($errors->all() as $vndError)
                                <li>{{ $vndError }}</li>
                            @endforeach
                        </ul>
                        <div class="mt-1 text-muted">
                            The document you attached is still held &mdash; you do not need to attach it again.
                        </div>
                    </div>
                    @endif

                    {{-- The vendor's SST standing, stated where a document charging SST is
                         about to be filed. It flags, it never blocks: the vendor's category is
                         master data that can be stale, and refusing a real invoice over it
                         would be worse than showing the discrepancy to whoever can check. --}}
                    @if(in_array($vndSstVerdict['state'], ['exempt', 'not_registered'], true))
                    <div class="alert alert-warning py-2" style="font-size:12.5px;">
                        <i class="bi bi-info-circle me-1"></i>{{ $vndSstVerdict['reason'] }}
                        If the document charges SST, it is filed anyway and flagged on its row.
                    </div>
                    @endif

                    {{-- ── 1. The document ─────────────────────────────────────── --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Document {{ $isNew ? '' : '(replace)' }}
                            @if($isNew)<span class="text-danger">*</span>@endif
                        </label>
                        <input type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" data-vnd-file>
                        <div class="form-text text-muted small">
                            PDF or image, max 10&nbsp;MB.
                            @if(! $isNew && $d->original_filename)
                                Currently: <strong>{{ $d->original_filename }}</strong> &mdash; uploading replaces it and re-reads it.
                            @endif
                            <span class="d-block">It is read as soon as you choose it, and its summary appears below for you to correct.</span>
                        </div>
                    </div>

                    <div class="vnd-scan-state d-none" data-vnd-state role="status" aria-live="polite">
                        <span class="spinner-border spinner-border-sm me-2" data-vnd-spinner></span>
                        <span data-vnd-state-text></span>
                    </div>

                    {{-- ── 2. What it says ─────────────────────────────────────── --}}
                    <div class="{{ $vndReadingOpen ? '' : 'd-none' }}" data-vnd-reading>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="{{ $modalId }}_companies">Companies Involved</label>
                                <input type="text" name="companies_involved" class="form-control"
                                       id="{{ $modalId }}_companies" data-vnd-companies
                                       placeholder="e.g. Fixmaster Sdn Bhd, Enlinea Sdn Bhd"
                                       value="{{ $v('companies_involved', $vndCompanies) }}">
                                <div class="form-text text-muted small">
                                    The parties named on the document, separated by commas. Read off the document &mdash; correct them if it read them wrong.
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="{{ $modalId }}_summary">Summary</label>
                                <textarea name="ai_summary" rows="8" class="form-control" id="{{ $modalId }}_summary"
                                          data-vnd-summary placeholder="The summary of the document appears here once it has been read.">{{ $v('ai_summary', $isNew ? '' : $d->ai_summary) }}</textarea>
                                <div class="form-text text-muted small">
                                    Generated from the document and yours to edit. What you save here is what the row shows
                                    @if(! $isNew && $d->summaryIsEdited())
                                        &mdash; <em>{{ $d->summaryProvenance() }}</em>
                                    @endif
                                </div>
                            </div>
                            <div class="col-12 d-none" data-vnd-points-wrap>
                                <div class="vnd-scan-points">
                                    <div class="vnd-scan-points-head">Key points read from the document</div>
                                    <ul class="mb-0" data-vnd-points></ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ── 3. Nothing else ──────────────────────────────────────
                         There is deliberately NO figure-entry form here, on either the Add or
                         the Edit side. The type, number, dates and amounts are read off the
                         document by the scan and stored — the SST flag, the Overdue badge, the
                         asset ↔ invoice link and the payment-slip amount check are all
                         computed from them — but they are never typed and never posted from
                         this form. A value the form cannot show is a value nobody checked, so
                         accepting one from the request would let a crafted submit set a total
                         that was never on screen.

                         Consequence, accepted deliberately: a figure the scan read wrong is
                         corrected by re-reading the document or replacing it, not by hand.

                         STATUS was the one exception until 2026-08-13, when it went the same
                         way. Paid/Pending is now derived from whether a payment slip is filed
                         against the invoice, so this dropdown would have been a second way to
                         answer a question the evidence already answers — and the one that
                         could say "Paid" with no document behind it. The invoice is marked
                         Paid by uploading the slip, and Pending again by removing it. --}}
                    @if(! $isNew)
                        {{-- Where the payment stands, stated but not editable: it belongs to
                             the slip, and this is the modal for the invoice. Shown so that
                             "why does this row say Pending?" is answered where the operator
                             is already looking. --}}
                        @php $vndPaymentState = $d->paymentState(); @endphp
                        <hr class="my-3">
                        <div class="d-flex align-items-center gap-2 flex-wrap" style="font-size:12.5px;">
                            <span class="fw-semibold">Payment status:</span>
                            @if($vndPaymentState['color'])
                                <span class="badge rounded-pill bg-{{ $vndPaymentState['color'] }}">{{ $vndPaymentState['label'] }}</span>
                            @endif
                            <span class="text-muted">{{ $vndPaymentState['note'] }}</span>
                        </div>

                        @if($d->file_path)
                        <hr class="my-3">
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                            <div class="text-muted" style="font-size:12px;">
                                <i class="bi bi-robot me-1"></i>{{ $d->summaryProvenance() }}
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-primary" form="vndResummariseB{{ $d->id }}">
                                <i class="bi bi-arrow-repeat me-1"></i>Read the document again
                            </button>
                        </div>
                        @endif
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" data-vnd-save {{ $vndReadingOpen ? '' : 'disabled' }}>
                        <i class="bi bi-check-circle me-1"></i>{{ $isNew ? 'Save Document' : 'Save Changes' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

{{-- Re-read forms for the edit modals. OUTSIDE the modal markup: submitted by a button
     inside another <form> via its `form` attribute, and a nested <form> is invalid markup
     the browser silently drops — taking the fields with it. --}}
@foreach($vendor->billingDocuments as $d)
    @if($d->file_path)
    <form id="vndResummariseB{{ $d->id }}" action="{{ route('vendors.billing.summarise', [$vendor, $d]) }}" method="POST" class="js-confirm d-none"
          data-confirm="Read this document again? The summary{{ $d->summaryIsEdited() ? ' — including the edits made to it — ' : ', its key points ' }}and the companies read off it are replaced by a fresh reading. The recorded figures are not touched."
          data-confirm-title="Read the document again"
          data-confirm-ok="Read again"
          data-confirm-variant="primary">
        @csrf
    </form>
    @endif
@endforeach
