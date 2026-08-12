{{-- Add + per-contract edit modals. Rendered once per page, outside the tables and tab
     panes (a <div> inside <tbody> is invalid markup the browser hoists out, detaching the
     form from its fields).

     old() is GLOBAL, so on a validation failure it would repopulate every modal on the
     page with the values of whichever one was submitted — the Add form's rejected input
     would appear inside all six Edit forms. Each form therefore carries a hidden `_form`
     naming itself, and only the form that matches old('_form') reads old(); the rest keep
     showing the truth from the database. The same marker re-opens that one modal. --}}
@php
    $vndContractForms = collect([null])->merge($vendor->contracts);
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
    @endphp
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ $action }}" enctype="multipart/form-data">
                @csrf
                @if(! $isNew) @method('PUT') @endif
                <input type="hidden" name="_form" value="{{ $modalId }}">
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
                        {{-- The browser never sends a file input's value back, so an attachment
                             is always lost on a bounce. Said plainly, because the field looks
                             empty either way and a silently-dropped upload saves a contract
                             record with no document under it. --}}
                        <div class="mt-1 text-muted">
                            The uploaded file was not kept &mdash; please attach it again.
                        </div>
                    </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contract Document {{ $isNew ? '' : '(replace)' }}</label>
                        <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="form-text text-muted small">
                            PDF or image, max 10&nbsp;MB.
                            @if(! $isNew && $c->original_filename)
                                Currently: <strong>{{ $c->original_filename }}</strong> &mdash; uploading replaces it.
                            @endif
                            <span class="d-block">The document is read for its summary after you save &mdash;
                            the fields below are yours to fill in.</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required
                                   value="{{ $v('title', $isNew ? '' : $c->title) }}" placeholder="e.g. Laptop Rental Agreement 2026">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Reference</label>
                            <input type="text" name="contract_reference" class="form-control"
                                   value="{{ $v('contract_reference', $isNew ? '' : $c->contract_reference) }}">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Type</label>
                            @php $vndType = $v('contract_type', $isNew ? '' : $c->contract_type); @endphp
                            <select name="contract_type" class="form-select">
                                <option value="">—</option>
                                @foreach(\App\Models\VendorContract::TYPES as $key => $label)
                                    <option value="{{ $key }}" {{ $vndType === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            @php $vndStatus = $v('status', $isNew ? 'active' : $c->status); @endphp
                            <select name="status" class="form-select" required>
                                @foreach(\App\Models\VendorContract::STATUSES as $key => $label)
                                    <option value="{{ $key }}" {{ $vndStatus === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Billing Cycle</label>
                            @php $vndCycle = $v('billing_cycle', $isNew ? '' : $c->billing_cycle); @endphp
                            <select name="billing_cycle" class="form-select">
                                <option value="">—</option>
                                @foreach(\App\Models\VendorContract::BILLING_CYCLES as $key => $label)
                                    <option value="{{ $key }}" {{ $vndCycle === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Start Date</label>
                            <input type="date" name="start_date" class="form-control"
                                   value="{{ $v('start_date', $isNew ? '' : $c->start_date?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">End Date</label>
                            <input type="date" name="end_date" class="form-control"
                                   value="{{ $v('end_date', $isNew ? '' : $c->end_date?->format('Y-m-d')) }}">
                            <div class="form-text text-muted small">Leave blank if open-ended.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Notice Period (days)</label>
                            <input type="number" name="notice_period_days" class="form-control" min="0" max="3650"
                                   value="{{ $v('notice_period_days', $isNew ? '' : $c->notice_period_days) }}">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check mb-2">
                                {{-- An unchecked box submits nothing, so the paired hidden "0" is what
                                     makes unticking persist. Rendered on BOTH forms: it was edit-only
                                     while the field OCR existed (an absent value was what let a scan
                                     fill it), and that reason went with the scan on 2026-08-11. --}}
                                <input type="hidden" name="auto_renew" value="0">
                                <input class="form-check-input" type="checkbox" name="auto_renew" value="1"
                                       id="{{ $modalId }}_autorenew"
                                       {{ $v('auto_renew', $isNew ? false : $c->auto_renew) ? 'checked' : '' }}>
                                <label class="form-check-label" for="{{ $modalId }}_autorenew">Auto-renews</label>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Contract Value</label>
                            <input type="number" step="0.01" min="0" name="contract_value" class="form-control"
                                   value="{{ $v('contract_value', $isNew ? '' : $c->contract_value) }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Currency</label>
                            <input type="text" name="currency" maxlength="3" class="form-control text-uppercase"
                                   value="{{ $v('currency', $isNew ? 'MYR' : $c->currency) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Terms</label>
                            <input type="text" name="payment_terms" class="form-control" placeholder="e.g. 30 days from invoice date"
                                   value="{{ $v('payment_terms', $isNew ? '' : $c->payment_terms) }}">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Scope Summary</label>
                            <textarea name="scope_summary" rows="2" class="form-control" placeholder="What the vendor supplies under this contract">{{ $v('scope_summary', $isNew ? '' : $c->scope_summary) }}</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" rows="2" class="form-control">{{ $v('notes', $isNew ? '' : $c->notes) }}</textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-circle me-1"></i>{{ $isNew ? 'Save Contract' : 'Save Changes' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach
