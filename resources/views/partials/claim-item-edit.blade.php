{{--
    Inline per-item edit form (My Claims). Rendered inside a collapsible host under
    each item row/card. Type-aware: receipt categories edit amount/GST directly;
    mileage + computed categories edit the quantity and the amount is derived (the
    server is still authoritative). The category itself is fixed here — to change a
    line's category, remove it and add a new one. Posts PUT to user.claims.update-item.
--}}
@php
    $gl = config('claims.mileage.gl_code');
    $isMileage = $item->category && $item->category->gl_code === $gl;
    $rt = $item->category->rate_type ?? 'receipt';
    $isComputed = in_array($rt, ['per_day', 'per_hour']);
    $vehicle = ($item->rate_applied !== null && (float) $item->rate_applied == (float) config('claims.mileage.rates.motorcycle')) ? 'motorcycle' : 'car';
    $mode = $isMileage ? 'mileage' : ($isComputed ? $rt : 'receipt');
@endphp
<form action="{{ route('user.claims.update-item', $item) }}" method="POST" enctype="multipart/form-data"
      class="inline-edit-form border rounded p-3 m-2 bg-white text-start"
      data-mode="{{ $mode }}"
      data-car-rate="{{ config('claims.mileage.rates.car') }}"
      data-moto-rate="{{ config('claims.mileage.rates.motorcycle') }}"
      data-day-rate="{{ $item->category->rate_amount ?? config('claims.event_day_rate') }}"
      novalidate>
    @csrf
    @method('PUT')
    <input type="hidden" name="expense_category_id" value="{{ $item->expense_category_id }}">

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-pencil-square me-1"></i>Edit Item
            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis ms-1">{{ $item->category->name ?? '—' }}</span>
        </h6>
        <button type="button" class="btn btn-sm btn-outline-secondary inline-edit-cancel"><i class="bi bi-x-lg me-1"></i>Cancel</button>
    </div>

    <div class="row g-2">
        <div class="col-md-3">
            <label class="form-label small mb-1">Date of Expense <span class="text-danger">*</span></label>
            <input type="date" name="expense_date" class="form-control form-control-sm" value="{{ $item->expense_date->format('Y-m-d') }}" min="{{ now()->startOfYear()->toDateString() }}" max="{{ now()->toDateString() }}" required>
        </div>
        <div class="col-md-5">
            <label class="form-label small mb-1">Description <span class="text-danger">*</span></label>
            <input type="text" name="description" class="form-control form-control-sm" value="{{ $item->description }}" maxlength="500" required>
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1">Project / Client <span class="text-muted">(optional)</span></label>
            <input type="text" name="project_client" class="form-control form-control-sm" value="{{ $item->project_client }}" maxlength="255">
        </div>
    </div>

    @if($isMileage)
    <div class="row g-2 mt-1">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Vehicle</label>
            <select name="vehicle" class="form-select form-select-sm">
                <option value="car" {{ $vehicle === 'car' ? 'selected' : '' }}>Car (RM{{ number_format(config('claims.mileage.rates.car'), 2) }}/km)</option>
                <option value="motorcycle" {{ $vehicle === 'motorcycle' ? 'selected' : '' }}>Motorcycle (RM{{ number_format(config('claims.mileage.rates.motorcycle'), 2) }}/km)</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Distance (km) <span class="text-danger">*</span></label>
            <input type="number" step="0.1" min="0.1" max="99999.99" name="quantity" class="form-control form-control-sm" value="{{ $item->quantity }}" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">From</label>
            <input type="text" name="mileage_origin" class="form-control form-control-sm" value="{{ $item->mileage_origin }}" maxlength="255">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">To</label>
            <input type="text" name="mileage_destination" class="form-control form-control-sm" value="{{ $item->mileage_destination }}" maxlength="255">
        </div>
    </div>
    <input type="hidden" name="amount" value="{{ $item->amount }}">
    <input type="hidden" name="gst_amount" value="0">
    <input type="hidden" name="total_with_gst" value="{{ $item->total_with_gst }}">
    <div class="small text-muted mt-1">Amount: <span class="fw-semibold ie-amount-preview">RM {{ number_format($item->amount, 2) }}</span> (km × vehicle rate)</div>
    @elseif($isComputed)
    <div class="row g-2 mt-1">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">{{ $rt === 'per_day' ? 'Number of Days' : 'Hours Worked' }} <span class="text-danger">*</span></label>
            <input type="number" step="{{ $rt === 'per_day' ? '1' : '0.5' }}" min="0.5" max="99999.99" name="quantity" class="form-control form-control-sm" value="{{ $item->quantity }}" required>
        </div>
    </div>
    <input type="hidden" name="amount" value="{{ $item->amount }}">
    <input type="hidden" name="gst_amount" value="0">
    <input type="hidden" name="total_with_gst" value="{{ $item->total_with_gst }}">
    <div class="small text-muted mt-1">Amount: <span class="fw-semibold ie-amount-preview">RM {{ number_format($item->amount, 2) }}</span></div>
    @else
    <div class="row g-2 mt-1">
        <div class="col-4 col-md-2">
            <label class="form-label small mb-1">RM (w/o GST) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0.01" max="99999.99" name="amount" class="form-control form-control-sm" value="{{ $item->amount }}" required>
        </div>
        <div class="col-4 col-md-2">
            <label class="form-label small mb-1">GST (RM)</label>
            <input type="number" step="0.01" min="0" max="99999.99" name="gst_amount" class="form-control form-control-sm" value="{{ $item->gst_amount }}">
        </div>
        <div class="col-4 col-md-2">
            <label class="form-label small mb-1">Total (w/ GST)</label>
            <input type="number" step="0.01" name="total_with_gst" class="form-control form-control-sm fw-bold" value="{{ $item->total_with_gst }}" readonly>
        </div>
    </div>
    @endif

    <div class="row g-2 mt-1">
        <div class="col-md-8">
            <label class="form-label small mb-1"><i class="bi bi-paperclip me-1"></i>Replace attachment
                <span class="text-muted">(optional — leave empty to keep {{ $item->receipt_path ? 'the current one' : 'none' }})</span>
            </label>
            <input type="file" name="receipt" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
        </div>
        @if($item->receipt_path)
        <div class="col-md-4 d-flex align-items-end">
            <a href="{{ route('user.claims.items.receipt', $item) }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip me-1"></i>View current</a>
        </div>
        @endif
    </div>

    <div class="text-end mt-3">
        <button type="button" class="btn btn-outline-secondary btn-sm inline-edit-cancel me-2"><i class="bi bi-x-lg me-1"></i>Cancel</button>
        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Update Item</button>
    </div>
</form>
