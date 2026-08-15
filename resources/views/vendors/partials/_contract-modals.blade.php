{{-- Add + per-contract edit modals. Rendered once per page, outside the tables and tab
     panes (a <div> inside <tbody> is invalid markup the browser hoists out, detaching the
     form from its fields).

     ADD is an upload form: pick the document, it is read, the summary it produced is shown
     for correction, then Save. The dates, references and figures the reading also found are
     taken from the stored scan by the controller and are neither shown nor posted here —
     they are shown and owned on EDIT, which is the screen for looking at a record rather
     than filing one.

     old() is GLOBAL, so on a validation failure it would repopulate every modal on the
     page with the values of whichever one was submitted — the Add form's rejected input
     would appear inside all six Edit forms. Each form therefore carries a hidden `_form`
     naming itself, and only the form that matches old('_form') reads old(); the rest keep
     showing the truth from the database. The same marker re-opens that one modal. --}}
@php
    // Quotations filed automatically from a disposal cycle are excluded: they have no Edit
    // control on the row and the controller refuses an edit anyway, so a modal for one would
    // be a form nothing opens and the only way to reach it would be a hand-crafted submit.
    $vndContractForms = collect([null])->merge(
        $vendor->contracts->reject(fn ($c) => $c->isEwasteQuotation())
    );
    $vndOldForm = old('_form');
@endphp

@foreach($vndContractForms as $c)
    @php
        $isNew = $c === null;
        $modalId = $isNew ? 'contractModalNew' : 'contractModal'.$c->id;
        $action = $isNew
            ? route('vendors.contracts.store', $vendor)
            : route('vendors.contracts.update', [$vendor, $c]);
        // Only the submitted form replays old input.
        $v = function (string $key, $fallback = '') use ($modalId, $vndOldForm) {
            return $vndOldForm === $modalId ? old($key, $fallback) : $fallback;
        };
        $vndCompanies = $isNew ? '' : implode(', ', $c->companiesInvolvedList());
        // A bounced submit must not throw the upload away. The file is already stored
        // against the token, so replaying it is what makes "the document you attached is
        // still held" true rather than reassuring.
        $vndToken = $v('scan_token', '');
        $vndReadingOpen = ! $isNew || filled($vndToken);
    @endphp
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            {{-- No enctype: the file never travels with this form. It is uploaded to the scan
                 endpoint first and this form carries only the token that names it — which is
                 what lets the summary be reviewed before the record exists. --}}
            <form method="POST" action="{{ $action }}" data-vnd-doc-form data-vnd-kind="contract"
                  data-vnd-scan-url="{{ route('vendors.documents.scan', $vendor) }}"
                  data-vnd-status-url="{{ route('vendors.documents.scan-status', $vendor) }}"
                  data-vnd-new="{{ $isNew ? '1' : '0' }}">
                @csrf
                @if(! $isNew) @method('PUT') @endif
                <input type="hidden" name="_form" value="{{ $modalId }}">
                <input type="hidden" name="scan_token" value="{{ $vndToken }}" data-vnd-token>
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">
                        <i class="bi bi-file-earmark-text me-2"></i>{{ $isNew ? 'Add Contract' : 'Edit Contract' }}
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
                        {{-- The upload itself survives a bounce now — it is already stored
                             against the scan token, which the form replays. Only say
                             otherwise if that ever stops being true. --}}
                        <div class="mt-1 text-muted">
                            The document you attached is still held &mdash; you do not need to attach it again.
                        </div>
                    </div>
                    @endif

                    {{-- ── 1. The document ─────────────────────────────────────── --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Contract Document {{ $isNew ? '' : '(replace)' }}
                            @if($isNew)<span class="text-danger">*</span>@endif
                        </label>
                        <input type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" data-vnd-file>
                        <div class="form-text text-muted small">
                            PDF or image, max 10&nbsp;MB.
                            @if(! $isNew && $c->original_filename)
                                Currently: <strong>{{ $c->original_filename }}</strong> &mdash; uploading replaces it and re-reads it.
                            @endif
                            <span class="d-block">It is read as soon as you choose it, and its summary appears below for you to correct.</span>
                        </div>
                    </div>

                    {{-- Reading state. One region, three faces: working / read / could not be
                         read. Never hidden entirely once a file has been chosen — a scan that
                         failed has to say so, or an empty summary box reads as an empty
                         document. --}}
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
                                    The parties named in the document, separated by commas. Read off the document &mdash; correct them if it read them wrong.
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="{{ $modalId }}_summary">Summary</label>
                                <textarea name="ai_summary" rows="8" class="form-control" id="{{ $modalId }}_summary"
                                          data-vnd-summary placeholder="The summary of the document appears here once it has been read.">{{ $v('ai_summary', $isNew ? '' : $c->ai_summary) }}</textarea>
                                <div class="form-text text-muted small">
                                    Generated from the document and yours to edit. What you save here is what the row shows
                                    @if(! $isNew && $c->summaryIsEdited())
                                        &mdash; <em>{{ $c->summaryProvenance() }}</em>
                                    @endif
                                </div>
                            </div>
                            {{-- Key points are shown, not edited: they are the reading's own
                                 pointers into the document, and a half-edited list beside an
                                 edited summary would leave two answers to the same question. --}}
                            <div class="col-12 d-none" data-vnd-points-wrap>
                                <div class="vnd-scan-points">
                                    <div class="vnd-scan-points-head">Key points read from the document</div>
                                    <ul class="mb-0" data-vnd-points></ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ── 3. Nothing else ─────────────────────────────────────
                         There is deliberately NO field-entry form here, on either the Add or
                         the Edit side. The dates, references, figures and billing cycle are
                         read off the document by the scan and stored — the derived Status
                         badge, the assistant's recorded-fields comparison and the asset ↔
                         invoice link are all computed from them — but they are never typed
                         and never posted from this form. A value the form cannot show is a
                         value nobody checked, so accepting one from the request would let a
                         crafted submit set a contract value that was never on screen.

                         Consequence, accepted deliberately: a figure the scan read wrong is
                         corrected by re-reading the document or replacing it, not by hand. --}}
                    @if(! $isNew && $c->file_path)
                        <hr class="my-3">
                        {{-- The one control that belongs beside the summary: the summary is
                             what this modal edits, so the way to get a better one is here. It
                             is also reachable from the assistant panel, which is behind its
                             own backdrop when the row is not. --}}
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                            <div class="text-muted" style="font-size:12px;">
                                <i class="bi bi-robot me-1"></i>{{ $c->summaryProvenance() }}
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                    form="vndResummariseC{{ $c->id }}">
                                <i class="bi bi-arrow-repeat me-1"></i>Read the document again
                            </button>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" data-vnd-save {{ $vndReadingOpen ? '' : 'disabled' }}>
                        <i class="bi bi-check-circle me-1"></i>{{ $isNew ? 'Save Contract' : 'Save Changes' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

{{-- The Re-read forms for the edit modals. OUTSIDE the modal markup: they are submitted by
     a button inside another <form> via its `form` attribute, and a nested <form> is invalid
     markup the browser silently drops — taking the fields with it. --}}
@foreach($vndContractForms->filter() as $c)
    @if($c->file_path)
    <form id="vndResummariseC{{ $c->id }}" action="{{ route('vendors.contracts.summarise', [$vendor, $c]) }}" method="POST" class="js-confirm d-none"
          data-confirm="Read this document again? The summary{{ $c->summaryIsEdited() ? ' — including the edits made to it — ' : ', its key points ' }}and the companies read off it are replaced by a fresh reading. The recorded terms are not touched."
          data-confirm-title="Read the document again"
          data-confirm-ok="Read again"
          data-confirm-variant="primary">
        @csrf
    </form>
    @endif
@endforeach
