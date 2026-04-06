@extends('layouts.app')
@section('title', 'My Claims')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-receipt-cutoff me-2"></i>My Expense Claims</h3>
            <p class="text-muted mb-0">
                {{ $employee->full_name }} &mdash; {{ $employee->department ?? 'N/A' }}
            </p>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    {{-- ── Company Rules ── --}}
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-info bg-opacity-10 border-0 d-flex align-items-center">
            <i class="bi bi-info-circle text-info me-2"></i>
            <strong>Important Reminders</strong>
            <button class="btn btn-sm btn-link ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#rulesCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse" id="rulesCollapse">
            <div class="card-body small text-muted" style="line-height:1.8;">
                <ol class="mb-0">
                    <li>All claims are for <strong>business purposes only</strong>.</li>
                    <li>Submit your monthly claims with reporting manager acknowledgement by the <strong>{{ ordinal($policy->submission_deadline_day ?? 20) }}</strong> of each month.</li>
                    <li>Claims submitted after the deadline will be processed in the next month's cycle.</li>
                    <li>For Extra Hours claim, please state the number of extra hours clearly (e.g., Parentcraft Event, 8am–6pm).</li>
                    <li>Separate expense claim forms for different events and personal general claims.</li>
                    <li>Do <strong>not</strong> use "Petty Cash" as an expense type &mdash; use the correct category.</li>
                    <li>Ensure all claims have <strong>supporting receipts/proof</strong> attached.</li>
                    <li>Admin reserves the right to refuse incomplete claims (no signature, no receipt, wrong category, etc.).</li>
                </ol>
            </div>
        </div>
    </div>

    {{-- ── Current Month Claim ── --}}
    @php
        $currentClaim->loadMissing('items.category');
        $monthLabel = \Carbon\Carbon::create($currentClaim->year, $currentClaim->month)->format('F Y');
        $canEdit = $currentClaim->isEditable();
    @endphp

    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>{{ $monthLabel }}</h5>
                <small class="text-muted">{{ $currentClaim->claim_number }} &mdash; <span class="badge bg-{{ $currentClaim->statusBadge()['class'] }}">{{ $currentClaim->statusBadge()['label'] }}</span></small>
            </div>
            <div class="d-flex gap-2">
                @if($currentClaim->isSubmittable())
                <form action="{{ route('user.claims.submit', $currentClaim) }}" method="POST" class="d-inline" onsubmit="return confirm('Submit this claim for manager approval? Items will be locked after submission.')">
                    @csrf
                    <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Submit for Approval</button>
                </form>
                @endif
                @if($currentClaim->status === 'submitted')
                <form action="{{ route('user.claims.cancel', $currentClaim) }}" method="POST" class="d-inline" onsubmit="return confirm('Recall this claim to draft?')">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Recall</button>
                </form>
                @endif
            </div>
        </div>

        {{-- Rejection remarks --}}
        @if($currentClaim->status === 'manager_rejected' && $currentClaim->manager_remarks)
        <div class="alert alert-warning mx-3 mt-2 mb-0">
            <strong><i class="bi bi-exclamation-triangle me-1"></i>Manager Remarks:</strong> {{ $currentClaim->manager_remarks }}
        </div>
        @endif
        @if($currentClaim->status === 'hr_rejected' && $currentClaim->hr_remarks)
        <div class="alert alert-warning mx-3 mt-2 mb-0">
            <strong><i class="bi bi-exclamation-triangle me-1"></i>HR Remarks:</strong> {{ $currentClaim->hr_remarks }}
        </div>
        @endif

        <div class="card-body">
            {{-- Add New Item Form --}}
            @if($canEdit)
            <div class="border rounded p-3 mb-4 bg-light">
                <h6 class="mb-3"><i class="bi bi-plus-circle me-1"></i>Add Expense Item</h6>
                <form action="{{ route('user.claims.add-item') }}" method="POST" enctype="multipart/form-data" id="addItemForm">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Date of Expense <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" class="form-control" value="{{ old('expense_date', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Expense Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control" id="expenseDescription" placeholder="e.g., Grab to client meeting" maxlength="500" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Project / Client Name</label>
                            <input type="text" name="project_client" class="form-control" placeholder="e.g., Project Alpha" maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Expense Category <span class="text-danger">*</span></label>
                            <select name="expense_category_id" class="form-select" id="expenseCategory" required>
                                <option value="">-- Select Category --</option>
                                @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" data-requires-receipt="{{ $cat->requires_receipt ? '1' : '0' }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted" id="categoryHint" style="display:none;"><i class="bi bi-magic me-1"></i>Auto-suggested</small>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">RM (w/o GST) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" id="amountNoGst" step="0.01" min="0.01" max="99999.99" placeholder="0.00" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">GST (RM)</label>
                            <input type="number" name="gst_amount" class="form-control" id="gstAmount" step="0.01" min="0" max="99999.99" placeholder="0.00" value="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Total (w/ GST)</label>
                            <input type="number" name="total_with_gst" class="form-control fw-bold" id="totalWithGst" step="0.01" min="0.01" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Receipt</label>
                            <input type="file" name="receipt" class="form-control" accept=".jpg,.jpeg,.png,.pdf" id="receiptFile">
                            <small class="text-muted">JPG, PNG, PDF (max 5MB)</small>
                        </div>
                    </div>
                    <div class="mt-3 text-end">
                        <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add to List</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Items Table --}}
            @if($currentClaim->items->count() > 0)
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Project/Client</th>
                            <th>Category</th>
                            <th class="text-end">RM (w/o GST)</th>
                            <th class="text-end">GST (RM)</th>
                            <th class="text-end">Total (w/ GST)</th>
                            <th>Receipt</th>
                            @if($canEdit)<th></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($currentClaim->items as $i => $item)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item->expense_date->format('d M Y') }}</td>
                            <td>{{ $item->description }}</td>
                            <td>{{ $item->project_client ?? '-' }}</td>
                            <td><span class="badge bg-secondary">{{ $item->category->name ?? '-' }}</span></td>
                            <td class="text-end">{{ number_format($item->amount, 2) }}</td>
                            <td class="text-end">{{ number_format($item->gst_amount, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($item->total_with_gst, 2) }}</td>
                            <td>
                                @if($item->receipt_path)
                                <a href="{{ route('secure.file', $item->receipt_path) }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip"></i></a>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            @if($canEdit)
                            <td>
                                @if(!$item->is_locked)
                                <form action="{{ route('user.claims.remove-item', $item) }}" method="POST" onsubmit="return confirm('Remove this item?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                                @endif
                            </td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="{{ $canEdit ? 5 : 5 }}" class="text-end">TOTAL</td>
                            <td class="text-end">{{ number_format($currentClaim->total_amount, 2) }}</td>
                            <td class="text-end">{{ number_format($currentClaim->total_gst, 2) }}</td>
                            <td class="text-end text-primary">RM {{ number_format($currentClaim->total_with_gst, 2) }}</td>
                            <td></td>
                            @if($canEdit)<td></td>@endif
                        </tr>
                    </tfoot>
                </table>
            </div>
            @else
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox" style="font-size:2rem;"></i>
                <p class="mt-2">No items added yet. Use the form above to add your expense items.</p>
            </div>
            @endif
        </div>
    </div>

    {{-- ── Claims History ── --}}
    @php $historyClaims = $claims->where('id', '!=', $currentClaim->id); @endphp
    @if($historyClaims->count() > 0)
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Previous Claims</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Claim No.</th>
                            <th>Period</th>
                            <th>Items</th>
                            <th class="text-end">Total (w/ GST)</th>
                            <th>Status</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($historyClaims as $hc)
                        <tr>
                            <td class="fw-semibold">{{ $hc->claim_number }}</td>
                            <td>{{ \Carbon\Carbon::create($hc->year, $hc->month)->format('M Y') }}</td>
                            <td>{{ $hc->item_count }}</td>
                            <td class="text-end fw-semibold">RM {{ number_format($hc->total_with_gst, 2) }}</td>
                            <td><span class="badge bg-{{ $hc->statusBadge()['class'] }}">{{ $hc->statusBadge()['label'] }}</span></td>
                            <td>{{ $hc->submitted_at?->format('d M Y') ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const descInput = document.getElementById('expenseDescription');
    const categorySelect = document.getElementById('expenseCategory');
    const categoryHint = document.getElementById('categoryHint');
    const amountInput = document.getElementById('amountNoGst');
    const gstInput = document.getElementById('gstAmount');
    const totalInput = document.getElementById('totalWithGst');
    let debounceTimer;

    // Auto-detect category based on description
    if (descInput && categorySelect) {
        descInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                if (this.value.length < 3) return;
                fetch('{{ route("user.claims.detect-category") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ description: this.value }),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.category_id && !categorySelect.value) {
                        categorySelect.value = data.category_id;
                        categoryHint.style.display = 'block';
                        categoryHint.textContent = '✨ Auto-suggested: ' + data.category_name;
                    }
                })
                .catch(() => {});
            }, 400);
        });

        categorySelect.addEventListener('change', function () {
            categoryHint.style.display = 'none';
        });
    }

    // Auto-calculate total
    function recalcTotal() {
        const amt = parseFloat(amountInput.value) || 0;
        const gst = parseFloat(gstInput.value) || 0;
        totalInput.value = (amt + gst).toFixed(2);
    }
    if (amountInput) amountInput.addEventListener('input', recalcTotal);
    if (gstInput) gstInput.addEventListener('input', recalcTotal);
});
</script>
@endpush

@php
function ordinal($n) {
    $s = ['th','st','nd','rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}
@endphp
