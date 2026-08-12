{{-- Add + per-document edit modals for quotations/invoices. Same `_form` marker pattern as
     the contract modals: old() is global, so only the submitted form replays it and the
     rest keep showing the database truth. --}}
@php
    $vndBillingForms = collect([null])->merge($vendor->billingDocuments);
    $vndOldForm = old('_form');
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
                        {{-- The browser never sends a file input's value back, so an attachment
                             is always lost on a bounce. Said plainly: the field looks empty
                             either way, and a silently-dropped upload files an invoice record
                             with no invoice under it. --}}
                        <div class="mt-1 text-muted">
                            The uploaded file was not kept &mdash; please attach it again.
                        </div>
                    </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Document {{ $isNew ? '' : '(replace)' }}</label>
                        <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="form-text text-muted small">
                            PDF or image, max 10&nbsp;MB.
                            @if(! $isNew && $d->original_filename)
                                Currently: <strong>{{ $d->original_filename }}</strong> &mdash; uploading replaces it.
                            @endif
                            <span class="d-block">The document is read for its summary after you save &mdash;
                            the figures below are yours to fill in.</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            @php $vndDocType = $v('doc_type', $isNew ? 'invoice' : $d->doc_type); @endphp
                            <select name="doc_type" class="form-select" required>
                                @foreach(\App\Models\VendorBillingDocument::TYPES as $key => $label)
                                    <option value="{{ $key }}" {{ $vndDocType === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Document Number</label>
                            <input type="text" name="doc_number" class="form-control"
                                   value="{{ $v('doc_number', $isNew ? '' : $d->doc_number) }}">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            @php $vndDocStatus = $v('status', $isNew ? 'received' : $d->status); @endphp
                            <select name="status" class="form-select" required>
                                @foreach(\App\Models\VendorBillingDocument::STATUSES as $key => $label)
                                    <option value="{{ $key }}" {{ $vndDocStatus === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Against Contract</label>
                            @php $vndContractId = (string) $v('vendor_contract_id', $isNew ? '' : $d->vendor_contract_id); @endphp
                            <select name="vendor_contract_id" class="form-select">
                                <option value="">— None / ad-hoc —</option>
                                @foreach($vendor->contracts as $vndOpt)
                                    <option value="{{ $vndOpt->id }}" {{ $vndContractId === (string) $vndOpt->id ? 'selected' : '' }}>{{ $vndOpt->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Document Date</label>
                            <input type="date" name="doc_date" class="form-control"
                                   value="{{ $v('doc_date', $isNew ? '' : $d->doc_date?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Due Date</label>
                            <input type="date" name="due_date" class="form-control"
                                   value="{{ $v('due_date', $isNew ? '' : $d->due_date?->format('Y-m-d')) }}">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Subtotal</label>
                            <input type="number" step="0.01" min="0" name="subtotal" class="form-control"
                                   value="{{ $v('subtotal', $isNew ? '' : $d->subtotal) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">SST Amount</label>
                            <input type="number" step="0.01" min="0" name="sst_amount" class="form-control"
                                   value="{{ $v('sst_amount', $isNew ? '' : $d->sst_amount) }}">
                            @php $vndVerdict = $vendor->sstVerdict(); @endphp
                            @if(in_array($vndVerdict['state'], ['exempt', 'not_registered'], true))
                            <div class="form-text text-warning small">{{ $vndVerdict['reason'] }} An amount here will be flagged.</div>
                            @endif
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Total</label>
                            <input type="number" step="0.01" min="0" name="total" class="form-control"
                                   value="{{ $v('total', $isNew ? '' : $d->total) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Currency</label>
                            <input type="text" name="currency" maxlength="3" class="form-control text-uppercase"
                                   value="{{ $v('currency', $isNew ? 'MYR' : $d->currency) }}">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" name="description" class="form-control" placeholder="What is being billed"
                                   value="{{ $v('description', $isNew ? '' : $d->description) }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" rows="2" class="form-control">{{ $v('notes', $isNew ? '' : $d->notes) }}</textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-circle me-1"></i>{{ $isNew ? 'Save Document' : 'Save Changes' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach
