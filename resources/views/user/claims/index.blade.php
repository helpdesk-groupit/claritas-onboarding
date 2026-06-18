@extends('layouts.app')
@section('title', 'My Claims')

@section('content')
@include('partials.dashboard-widgets-style')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-receipt-cutoff me-2"></i>My Expense Claims</h3>
            <p class="text-muted mb-0">
                {{ $employee->full_name }} &mdash; {{ $employee->department ?? 'N/A' }}
            </p>
        </div>
        <a href="{{ route('user.claims.reports') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Claim Reports</a>
    </div>

    {{-- Company letterhead (Expenses Claims Form) --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            @include('partials.claim-letterhead', ['company' => $company, 'employee' => $employee, 'event' => $currentClaim->event ?? null, 'showRules' => false, 'claimDate' => \Carbon\Carbon::create($year, $month, 1)])
            @if(!$currentClaim || $currentClaim->isEditable())
            <form action="{{ route('user.claims.save-details') }}" method="POST" class="row g-2 align-items-end mt-1">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">
                <input type="hidden" name="month" value="{{ $month }}">
                <div class="col-sm-8 col-md-6">
                    <label class="form-label small mb-0">Event / purpose for this month's claim</label>
                    <input type="text" name="event" class="form-control form-control-sm" value="{{ $currentClaim->event ?? '' }}" placeholder="e.g., Office Equipment Claim" maxlength="255">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-save me-1"></i>Save event</button>
                </div>
            </form>
            @endif
        </div>
    </div>

    {{-- success/error flash is rendered globally by layouts/app.blade.php; only validation errors need handling here --}}
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

    @php
        $now = \Carbon\Carbon::now();
        $selectedYear = $year ?? $now->year;
        $selectedMonth = $month ?? $now->month;
    @endphp

    {{-- ── Summary Stat Cards ── --}}
    @php
        $yearClaims     = $claims->where('year', $selectedYear);
        $thisMonthTotal = $currentClaim?->total_with_gst ?? 0;
        $thisMonthItems = $currentClaim ? $currentClaim->items->count() : 0;
        $approvedYtd    = $yearClaims->whereIn('status', ['hr_approved', 'paid'])->sum('total_with_gst');
        $pendingYtd     = $yearClaims->whereIn('status', ['submitted', 'manager_approved'])->sum('total_with_gst');
    @endphp
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-receipt"></i></div>
                        <div>
                            <div class="widget-number" style="font-size:22px;">RM {{ number_format($thisMonthTotal, 2) }}</div>
                            <div class="widget-label">{{ \Carbon\Carbon::create($selectedYear, $selectedMonth)->format('M Y') }} Total</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-list-check"></i></div>
                        <div>
                            <div class="widget-number">{{ $thisMonthItems }}</div>
                            <div class="widget-label">Items This Month</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="widget-number" style="font-size:22px;">RM {{ number_format($pendingYtd, 2) }}</div>
                            <div class="widget-label">Pending in {{ $selectedYear }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-check-circle-fill"></i></div>
                        <div>
                            <div class="widget-number" style="font-size:22px;">RM {{ number_format($approvedYtd, 2) }}</div>
                            <div class="widget-label">Approved in {{ $selectedYear }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Month Selector ── --}}
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-1 justify-content-center align-items-center">
                <span class="fw-semibold text-muted me-2 small">{{ $selectedYear }}</span>
                @for($m = 1; $m <= $now->month; $m++)
                <a href="{{ route('user.claims.index', ['month' => $m, 'year' => $selectedYear]) }}"
                   class="btn btn-sm {{ $m === $selectedMonth ? 'btn-primary' : 'btn-outline-secondary' }}">
                    {{ \Carbon\Carbon::create($selectedYear, $m)->format('M') }}
                </a>
                @endfor
            </div>
        </div>
    </div>

    {{-- ── Selected Month Claim ── --}}
    @php
        $monthLabel = \Carbon\Carbon::create($selectedYear, $selectedMonth)->format('F Y');
        if ($currentClaim) {
            $currentClaim->loadMissing('items.category', 'items.approver');
            $canEdit = $currentClaim->isEditable();
        } else {
            $canEdit = true; // No claim yet = user can add items (draft will be created on first add)
        }
    @endphp

    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header border-0 d-flex flex-wrap justify-content-between align-items-center gap-2" style="background:linear-gradient(135deg,#f8fafc,#eef2ff);">
            <div>
                <h5 class="mb-0"><i class="bi bi-calendar-event me-2 text-primary"></i>{{ $monthLabel }}</h5>
                @if($currentClaim)
                <small class="text-muted">{{ $currentClaim->claim_number }} &mdash; <span class="badge bg-{{ $currentClaim->statusBadge()['class'] }}">{{ $currentClaim->statusBadge()['label'] }}</span></small>
                @else
                <small class="text-muted">No claim yet &mdash; add an expense item to start</small>
                @endif
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                @if($currentClaim && $currentClaim->isSubmittable())
                <a href="{{ route('user.claims.submit-form', $currentClaim) }}" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Submit for Approval</a>
                @endif
                @if($currentClaim && $currentClaim->status === 'submitted')
                <form action="{{ route('user.claims.cancel', $currentClaim) }}" method="POST" class="d-inline js-confirm" data-confirm="Recall this claim to draft? You'll be able to edit and resubmit it." data-confirm-title="Recall claim" data-confirm-ok="Recall" data-confirm-variant="warning">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Recall</button>
                </form>
                @endif
            </div>
        </div>

        {{-- Rejection remarks --}}
        @if($currentClaim && $currentClaim->status === 'manager_rejected' && $currentClaim->manager_remarks)
        <div class="alert alert-warning mx-3 mt-2 mb-0">
            <strong><i class="bi bi-exclamation-triangle me-1"></i>Manager Remarks:</strong> {{ $currentClaim->manager_remarks }}
        </div>
        @endif
        @if($currentClaim && $currentClaim->status === 'hr_rejected' && $currentClaim->hr_remarks)
        <div class="alert alert-warning mx-3 mt-2 mb-0">
            <strong><i class="bi bi-exclamation-triangle me-1"></i>HR Remarks:</strong> {{ $currentClaim->hr_remarks }}
        </div>
        @endif

        {{-- ── Claim Status Tracker (visible after submission) ── --}}
        @if($currentClaim && $currentClaim->status !== 'draft')
        @php
            $statusSteps = [
                ['key' => 'submitted',         'icon' => 'bi-send-fill',       'label' => 'Submitted'],
                ['key' => 'manager_approved',   'icon' => 'bi-person-check-fill','label' => 'Manager'],
                ['key' => 'hr_approved',        'icon' => 'bi-building-check',  'label' => 'HR'],
                ['key' => 'paid',               'icon' => 'bi-cash-coin',       'label' => 'Paid'],
            ];
            $statusOrder = ['submitted' => 1, 'manager_approved' => 2, 'hr_approved' => 3, 'paid' => 4];
            $rejected = in_array($currentClaim->status, ['manager_rejected', 'hr_rejected', 'cancelled']);
            $currentStep = $statusOrder[$currentClaim->status] ?? 0;
        @endphp
        <div class="px-3 pt-3 pb-2">
            <div class="d-flex align-items-center justify-content-between position-relative" style="max-width:600px; margin:0 auto;">
                {{-- Connecting line --}}
                <div class="position-absolute" style="top:18px; left:36px; right:36px; height:3px; background:#dee2e6; z-index:0;"></div>
                @if(!$rejected && $currentStep > 0)
                <div class="position-absolute" style="top:18px; left:36px; height:3px; background:#0d6efd; z-index:1; width:{{ min(100, ($currentStep - 1) * 33.33) }}%;"></div>
                @endif

                @foreach($statusSteps as $i => $step)
                @php
                    $stepNum = $i + 1;
                    if ($rejected) {
                        // For rejected: mark steps up to the rejection point, then show red
                        $rejectedAt = $currentClaim->status === 'manager_rejected' ? 1 : ($currentClaim->status === 'hr_rejected' ? 2 : $currentStep);
                        if ($stepNum < $rejectedAt) {
                            $cls = 'bg-success text-white';
                        } elseif ($stepNum === $rejectedAt) {
                            $cls = 'bg-danger text-white';
                        } else {
                            $cls = 'bg-light text-muted border';
                        }
                    } elseif ($stepNum < $currentStep) {
                        $cls = 'bg-success text-white';
                    } elseif ($stepNum === $currentStep) {
                        $cls = 'bg-primary text-white';
                    } else {
                        $cls = 'bg-light text-muted border';
                    }
                @endphp
                <div class="text-center position-relative" style="z-index:2; flex:1;">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center {{ $cls }}" style="width:36px; height:36px;">
                        <i class="bi {{ $step['icon'] }}" style="font-size:.9rem;"></i>
                    </div>
                    <div class="small mt-1 {{ $stepNum <= $currentStep && !$rejected ? 'fw-semibold' : 'text-muted' }}">{{ $step['label'] }}</div>
                </div>
                @endforeach
            </div>

            @if($rejected)
            <div class="text-center mt-2">
                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>{{ $currentClaim->statusBadge()['label'] }}</span>
            </div>
            @elseif($currentClaim->status === 'submitted')
            <div class="text-center mt-2">
                <small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Awaiting manager approval{{ $currentClaim->submitted_at ? ' — submitted ' . $currentClaim->submitted_at->format('d/m/Y') : '' }}</small>
            </div>
            @elseif($currentClaim->status === 'manager_approved')
            <div class="text-center mt-2">
                <small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Awaiting HR approval{{ $currentClaim->manager_approved_at ? ' — manager approved ' . $currentClaim->manager_approved_at->format('d/m/Y') : '' }}</small>
            </div>
            @elseif($currentClaim->status === 'hr_approved')
            <div class="text-center mt-2">
                <small class="text-success"><i class="bi bi-check-circle me-1"></i>Approved — pending payment processing</small>
            </div>
            @elseif($currentClaim->status === 'paid')
            <div class="text-center mt-2">
                <small class="text-success"><i class="bi bi-check-all me-1"></i>Payment completed</small>
            </div>
            @endif
        </div>
        <hr class="mx-3 mt-0 mb-0">
        @endif

        <div class="card-body">
            {{-- Add New Item Form --}}
            @if($canEdit)
            <div class="border rounded p-3 mb-4 bg-light">
                <h6 class="mb-3"><i class="bi bi-plus-circle me-1"></i>Add Expense Item</h6>
                <form action="{{ route('user.claims.add-item') }}" method="POST" enctype="multipart/form-data" id="addItemForm" novalidate>
                    @csrf
                    {{-- Items accumulate in the claim month being viewed, regardless of each item's own (past) date --}}
                    <input type="hidden" name="claim_year" value="{{ $year }}">
                    <input type="hidden" name="claim_month" value="{{ $month }}">

                    {{-- Receipt first — upload a document, then click Scan to auto-fill the fields below (blanks only) --}}
                    <div class="border rounded p-2 mb-3 bg-white">
                        <label class="form-label fw-semibold mb-1"><i class="bi bi-camera me-1"></i>Receipt <span class="text-danger" id="receiptRequiredMark" style="display:none;">*</span> <span class="fw-normal text-muted small">— upload, then Scan to auto-fill the details below (only empty fields)</span></label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <input type="file" name="receipt" class="form-control @error('receipt') is-invalid @enderror" accept=".jpg,.jpeg,.png,.pdf" id="receiptFile" style="max-width:380px;">
                            <button type="button" class="btn btn-sm btn-primary d-none" id="scanReceiptBtn"><i class="bi bi-magic me-1"></i>Scan receipt</button>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 d-none" id="receiptClearBtn"><i class="bi bi-x-circle me-1"></i>Remove receipt</button>
                        </div>
                        <small class="text-info d-block mt-1" id="ocrHint" style="display:none;"></small>
                        @error('receipt')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <small class="text-muted d-block">JPG, PNG, PDF (max 5MB)</small>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label fw-semibold">Date of Expense <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" class="form-control @error('expense_date') is-invalid @enderror" value="{{ old('expense_date', date('Y-m-d')) }}" min="{{ date('Y') }}-01-01" max="{{ date('Y-m-d') }}" required>
                            @error('expense_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @else
                            <div class="invalid-feedback">Pick any date this year up to today — past dates are fine, future dates are not.</div>
                            @enderror
                        </div>
                        <div class="col-sm-6 col-md-5">
                            <label class="form-label fw-semibold">Expense Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control @error('description') is-invalid @enderror" id="expenseDescription" value="{{ old('description') }}" placeholder="e.g., Grab to client meeting" maxlength="500" required>
                            @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @else
                            <div class="invalid-feedback">Describe the expense (e.g., "Grab to ABC Corp meeting", "Lunch with client").</div>
                            @enderror
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Project / Client Name</label>
                            <input type="text" name="project_client" class="form-control" value="{{ old('project_client') }}" placeholder="e.g., Project Alpha" maxlength="255">
                            <small class="text-muted">Optional — link this expense to a project or client.</small>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Expense Category <span class="text-danger">*</span></label>
                            <select name="expense_category_id" class="form-select @error('expense_category_id') is-invalid @enderror" id="expenseCategory" required>
                                <option value="">-- Select Category --</option>
                                @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('expense_category_id') == $cat->id ? 'selected' : '' }} data-requires-receipt="{{ $cat->requires_receipt ? '1' : '0' }}" data-rate-type="{{ $cat->rate_type }}" data-rate-amount="{{ $cat->rate_amount ?? '' }}" data-gl-code="{{ $cat->gl_code ?? '' }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            @error('expense_category_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @else
                            <div class="invalid-feedback">Choose a category (e.g., "Transport", "Meals & Entertainment").</div>
                            @enderror
                            <small class="text-muted" id="categoryHint" style="display:none;"><i class="bi bi-magic me-1"></i>Auto-suggested</small>
                        </div>
                        <div class="col-6 col-md-2" id="quantityGroup" style="display:none;">
                            <label class="form-label fw-semibold" id="quantityLabel">Quantity</label>
                            <input type="number" name="quantity" class="form-control" id="quantityInput" step="0.01" min="0.01" max="99999.99" placeholder="0" value="{{ old('quantity') }}">
                            <small class="text-muted" id="quantityHint"></small>
                        </div>
                        {{-- Petrol: claim by receipt or by mileage (from Jaya One) --}}
                        <div class="col-12" id="mileagePanel" style="display:none;">
                            <div class="border rounded p-2 bg-light">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <span class="fw-semibold small"><i class="bi bi-fuel-pump me-1"></i>Petrol claim type:</span>
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="radio" name="claim_mode" id="modeReceipt" value="receipt" {{ old('claim_mode', 'receipt') === 'mileage' ? '' : 'checked' }}>
                                        <label class="form-check-label small" for="modeReceipt">By receipt</label>
                                    </div>
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="radio" name="claim_mode" id="modeMileage" value="mileage" {{ old('claim_mode') === 'mileage' ? 'checked' : '' }}>
                                        <label class="form-check-label small" for="modeMileage">By mileage (from {{ config('claims.mileage.origin') }})</label>
                                    </div>
                                </div>
                                <div class="row g-2 mt-1" id="mileageInputs" style="display:none;">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small mb-1">Vehicle</label>
                                        <select class="form-select form-select-sm" name="vehicle" id="mileageVehicle">
                                            <option value="car" {{ old('vehicle', 'car') === 'car' ? 'selected' : '' }}>Car (RM0.70/km)</option>
                                            <option value="motorcycle" {{ old('vehicle') === 'motorcycle' ? 'selected' : '' }}>Motorcycle (RM0.35/km)</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6" id="mileageDestWrap" style="display:none;">
                                        <label class="form-label small mb-1">Destination</label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" id="mileageDest" name="mileage_destination" placeholder="e.g., KLCC, Kuala Lumpur" maxlength="255">
                                            <button type="button" class="btn btn-outline-primary" id="mileageCalcBtn">Calculate</button>
                                        </div>
                                        <small class="text-muted" id="mileageCalcHint"></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label fw-semibold">RM (w/o GST) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control @error('amount') is-invalid @enderror" id="amountNoGst" value="{{ old('amount') }}" step="0.01" min="0.01" max="99999.99" placeholder="0.00" required>
                            @error('amount')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @else
                            <div class="invalid-feedback">Enter the amount before GST (e.g., 25.00).</div>
                            @enderror
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label fw-semibold">GST (RM)</label>
                            <input type="number" name="gst_amount" class="form-control" id="gstAmount" value="{{ old('gst_amount', 0) }}" step="0.01" min="0" max="99999.99" placeholder="0.00">
                            <small class="text-muted d-none d-md-block">Leave 0 if no GST.</small>
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label fw-semibold">Total (w/ GST)</label>
                            <input type="number" name="total_with_gst" class="form-control fw-bold" id="totalWithGst" step="0.01" min="0.01" value="{{ old('total_with_gst') }}" readonly>
                            <small class="text-muted d-none d-md-block">Auto-calculated.</small>
                        </div>
                    </div>
                    <div class="mt-3 text-end">
                        <button type="button" class="btn btn-outline-secondary me-2" id="clearFormBtn"><i class="bi bi-eraser me-1"></i>Clear</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add to List</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Items Table (desktop) --}}
            @if($currentClaim && $currentClaim->items->count() > 0)
            <div class="d-none d-md-block table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
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
                        <tr class="{{ $item->isRejected() ? 'table-danger' : '' }}">
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item->expense_date->format('d/m/Y') }}</td>
                            <td>
                                {{ $item->description }}
                                @if($item->isRejected())
                                <span class="badge bg-danger ms-1">Rejected</span>
                                @if($item->rejectionReason())<div class="small text-danger"><i class="bi bi-info-circle me-1"></i>{{ $item->rejectionReason() }}</div>@endif
                                @endif
                                @if($currentClaim && $currentClaim->status !== 'draft' && $item->approver)
                                <div class="small text-muted">
                                    <i class="bi bi-arrow-right-short"></i>{{ $item->approver->full_name }}:
                                    @if($item->manager_status === 'approved')<span class="text-success">approved</span>
                                    @elseif($item->manager_status === 'rejected')<span class="text-danger">rejected</span>
                                    @else<span>awaiting</span>@endif
                                </div>
                                @endif
                            </td>
                            <td>{{ $item->project_client ?? '-' }}</td>
                            <td><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">{{ $item->category->name ?? '-' }}</span></td>
                            <td class="text-end">{{ number_format($item->amount, 2) }}</td>
                            <td class="text-end">{{ number_format($item->gst_amount, 2) }}</td>
                            <td class="text-end fw-bold {{ $item->isRejected() ? 'text-decoration-line-through text-muted' : '' }}">{{ number_format($item->total_with_gst, 2) }}</td>
                            <td>
                                @if($item->receipt_path)
                                <a href="{{ route('user.claims.items.receipt', $item) }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip"></i></a>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            @if($canEdit)
                            <td>
                                @if(!$item->is_locked)
                                <form action="{{ route('user.claims.remove-item', $item) }}" method="POST" class="js-confirm" data-confirm="Remove this item from the claim?" data-confirm-title="Remove item" data-confirm-ok="Remove" data-confirm-variant="danger">
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
                            <td colspan="5" class="text-end">TOTAL</td>
                            <td class="text-end">{{ number_format($currentClaim?->total_amount ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($currentClaim?->total_gst ?? 0, 2) }}</td>
                            <td class="text-end text-primary">RM {{ number_format($currentClaim?->total_with_gst ?? 0, 2) }}</td>
                            <td></td>
                            @if($canEdit)<td></td>@endif
                        </tr>
                        @if($currentClaim && $currentClaim->hasRejectedItems())
                        <tr class="fw-bold">
                            <td colspan="7" class="text-end text-success">PAYABLE (after rejections)</td>
                            <td class="text-end text-success">RM {{ number_format($currentClaim->approvedTotal(), 2) }}</td>
                            <td></td>
                            @if($canEdit)<td></td>@endif
                        </tr>
                        @endif
                    </tfoot>
                </table>
            </div>

            {{-- Items Cards (mobile) --}}
            <div class="d-md-none">
                @foreach($currentClaim->items as $i => $item)
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <strong>{{ $i + 1 }}. {{ $item->description }}</strong>
                            <div class="small text-muted">{{ $item->expense_date->format('d/m/Y') }}@if($item->project_client) &middot; {{ $item->project_client }}@endif</div>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            @if($item->receipt_path)
                            <a href="{{ route('user.claims.items.receipt', $item) }}" target="_blank" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-paperclip"></i></a>
                            @endif
                            @if($canEdit && !$item->is_locked)
                            <form action="{{ route('user.claims.remove-item', $item) }}" method="POST" class="js-confirm" data-confirm="Remove this item from the claim?" data-confirm-title="Remove item" data-confirm-ok="Remove" data-confirm-variant="danger">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                            </form>
                            @endif
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">{{ $item->category->name ?? '-' }}</span>
                        <span class="fw-bold">RM {{ number_format($item->total_with_gst, 2) }}</span>
                    </div>
                </div>
                @endforeach
                <div class="border-top pt-2 mt-2 d-flex justify-content-between fw-bold">
                    <span>TOTAL</span>
                    <span class="text-primary">RM {{ number_format($currentClaim?->total_with_gst ?? 0, 2) }}</span>
                </div>
            </div>
            @else
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox" style="font-size:2rem;"></i>
                <p class="mt-2">No items added yet. Use the form above to add your expense items.</p>
            </div>
            @endif
        </div>
    </div>

    {{-- ── Claim History — categorized by status (drafts + the current month are excluded) ── --}}
    @php
        $historyClaims = $claims
            ->where('status', '!=', 'draft')
            ->when($currentClaim, fn($c) => $c->where('id', '!=', $currentClaim->id))
            ->sortByDesc(fn($c) => $c->year * 100 + $c->month)
            ->values();
        $pendingC  = $historyClaims->whereIn('status', ['submitted', 'manager_approved']);
        $approvedC = $historyClaims->whereIn('status', ['hr_approved', 'paid']);
        $rejectedC = $historyClaims->whereIn('status', ['manager_rejected', 'hr_rejected']);
    @endphp
    @if($historyClaims->count() > 0)
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-muted"></i>Claim History</h5>
        </div>
        <div class="card-body">
            <ul class="nav nav-pills flex-wrap gap-1 mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#mc-all" type="button" role="tab">All <span class="badge bg-secondary ms-1">{{ $historyClaims->count() }}</span></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#mc-pending" type="button" role="tab">Pending <span class="badge bg-info ms-1">{{ $pendingC->count() }}</span></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#mc-approved" type="button" role="tab">Approved <span class="badge bg-success ms-1">{{ $approvedC->count() }}</span></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#mc-rejected" type="button" role="tab">Rejected <span class="badge bg-danger ms-1">{{ $rejectedC->count() }}</span></button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="mc-all" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $historyClaims, 'showView' => true, 'emptyText' => 'No claims yet.'])
                </div>
                <div class="tab-pane fade" id="mc-pending" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $pendingC, 'showView' => true, 'emptyText' => 'No claims awaiting approval.'])
                </div>
                <div class="tab-pane fade" id="mc-approved" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $approvedC, 'showView' => true, 'emptyText' => 'No approved claims yet.'])
                </div>
                <div class="tab-pane fade" id="mc-rejected" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $rejectedC, 'showView' => true, 'emptyText' => 'No rejected claims — nice!'])
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

@include('partials.confirm-modal')
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
document.addEventListener('DOMContentLoaded', function() {
    const descInput = document.getElementById('expenseDescription');
    const categorySelect = document.getElementById('expenseCategory');
    const categoryHint = document.getElementById('categoryHint');
    const amountInput = document.getElementById('amountNoGst');
    const gstInput = document.getElementById('gstAmount');
    const totalInput = document.getElementById('totalWithGst');
    const form = document.getElementById('addItemForm');
    const quantityGroup = document.getElementById('quantityGroup');
    const quantityInput = document.getElementById('quantityInput');
    const quantityLabel = document.getElementById('quantityLabel');
    const quantityHint  = document.getElementById('quantityHint');
    let debounceTimer;

    // Track if the user has typed/picked a date themselves — OCR won't overwrite it
    // (the date defaults to today, so a plain "is it blank" check can't tell them apart).
    let dateUserSet = false;
    const expenseDateEl = form ? form.querySelector('[name="expense_date"]') : null;
    if (expenseDateEl) expenseDateEl.addEventListener('input', () => { dateUserSet = true; });

    // ── Client-side validation with field highlighting ──
    if (form) {
        form.addEventListener('submit', function(e) {
            let valid = true;
            form.querySelectorAll('.form-control, .form-select').forEach(el => el.classList.remove('is-invalid'));

            // Required field checks
            const requiredFields = [
                { el: form.querySelector('[name="expense_date"]'), msg: 'Select a date within the current year.' },
                { el: form.querySelector('[name="description"]'), msg: 'Describe the expense (e.g., "Grab to client meeting").' },
                { el: form.querySelector('[name="expense_category_id"]'), msg: 'Choose a category (e.g., "Transport").' },
                { el: form.querySelector('[name="amount"]'), msg: 'Enter the amount before GST (e.g., 25.00).' },
            ];

            requiredFields.forEach(f => {
                if (!f.el.value || f.el.value.trim() === '') {
                    f.el.classList.add('is-invalid');
                    valid = false;
                }
            });

            // Date range (this year, not future) — caught here so a server bounce + page
            // reload doesn't drop the chosen receipt (a file input can't be re-filled by old()).
            const dateEl = form.querySelector('[name="expense_date"]');
            if (dateEl && dateEl.value && ((dateEl.min && dateEl.value < dateEl.min) || (dateEl.max && dateEl.value > dateEl.max))) {
                dateEl.classList.add('is-invalid');
                valid = false;
            }

            // Amount must be > 0
            if (amountInput && parseFloat(amountInput.value) <= 0) {
                amountInput.classList.add('is-invalid');
                valid = false;
            }

            // Category receipt requirement check
            if (categorySelect && categorySelect.value) {
                const opt = categorySelect.selectedOptions[0];
                const receiptInput = document.getElementById('receiptFile');
                if (opt && opt.dataset.requiresReceipt === '1' && receiptInput && !receiptInput.files.length) {
                    receiptInput.classList.add('is-invalid');
                    const fb = receiptInput.nextElementSibling;
                    if (fb && fb.classList.contains('invalid-feedback')) {
                        fb.textContent = 'This category requires a receipt. Upload JPG, PNG, or PDF.';
                    }
                    valid = false;
                }
                // Computed categories (Event Day / Extra Hours) and Petrol mileage need a quantity
                const rt = opt ? opt.dataset.rateType : 'receipt';
                const mPanel = document.getElementById('mileagePanel');
                const mEl = document.getElementById('modeMileage');
                const mileageHere = mEl && mEl.checked && mPanel && mPanel.style.display !== 'none';
                if (((rt === 'per_day' || rt === 'per_hour') || mileageHere) && quantityInput && (!quantityInput.value || parseFloat(quantityInput.value) <= 0)) {
                    quantityInput.classList.add('is-invalid');
                    valid = false;
                }
            }

            if (!valid) {
                e.preventDefault();
                // Scroll to first invalid field
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Clear invalid state on input
        form.querySelectorAll('.form-control, .form-select').forEach(el => {
            el.addEventListener('input', () => el.classList.remove('is-invalid'));
            el.addEventListener('change', () => el.classList.remove('is-invalid'));
        });
    }

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

    // ── Computed categories (Event Day, Extra Hours) + Petrol mileage ──
    const MILEAGE_RATES = @json(config('claims.mileage.rates'));
    const MILEAGE_GL    = @json(config('claims.mileage.gl_code'));
    const MAPS_ENABLED  = @json((bool) (config('claims.distance.provider') === 'ors' ? config('claims.distance.ors_key') : config('claims.google_maps.key')));
    const mileagePanel    = document.getElementById('mileagePanel');
    const mileageInputs   = document.getElementById('mileageInputs');
    const mileageVehicle  = document.getElementById('mileageVehicle');
    const mileageDest     = document.getElementById('mileageDest');
    const mileageDestWrap = document.getElementById('mileageDestWrap');
    const mileageCalcBtn  = document.getElementById('mileageCalcBtn');
    const mileageCalcHint = document.getElementById('mileageCalcHint');
    const modeMileageEl   = document.getElementById('modeMileage');

    function otBand(h) { if (h >= 8) return 100; if (h >= 4) return 50; return 0; }
    function selOpt() { return categorySelect ? categorySelect.selectedOptions[0] : null; }
    function isMileageCat(opt) { return !!(opt && MILEAGE_GL && opt.dataset.glCode === MILEAGE_GL); }
    function mileageOn() { return !!(modeMileageEl && modeMileageEl.checked); }

    function computeFromQuantity() {
        const opt = selOpt();
        if (!opt) return;
        const rt = opt.dataset.rateType || 'receipt';
        const qty = parseFloat(quantityInput.value) || 0;
        let amt = null;
        if (isMileageCat(opt) && mileageOn()) {
            const veh = (mileageVehicle && mileageVehicle.value) || 'car';
            amt = qty * (MILEAGE_RATES[veh] || MILEAGE_RATES.car || 0);
        } else if (rt === 'per_day') {
            amt = qty * (parseFloat(opt.dataset.rateAmount) || 0);
        } else if (rt === 'per_hour') {
            amt = otBand(qty);
        } else {
            return; // receipt-based: manual amount
        }
        amountInput.value = amt ? amt.toFixed(2) : '';
        recalcTotal();
    }

    // Show the Receipt "*" only when the chosen category actually requires a receipt
    // (hidden for by-mileage and for categories that don't need one).
    function updateReceiptRequiredMark() {
        const mark = document.getElementById('receiptRequiredMark');
        if (!mark) return;
        const opt = selOpt();
        const hasCategory = !!(opt && opt.value);
        const mileageHere = isMileageCat(opt) && mileageOn();
        // Show by default (most claims need a receipt); hide only when a chosen category
        // doesn't require one, or for by-mileage (distance is the evidence).
        const needed = mileageHere ? false : (hasCategory ? (opt.dataset.requiresReceipt === '1') : true);
        mark.style.display = needed ? '' : 'none';
    }

    function applyPetrolMode() {
        const mileage = mileageOn();
        if (mileageInputs) mileageInputs.style.display = mileage ? '' : 'none';
        if (mileageDestWrap) mileageDestWrap.style.display = (mileage && MAPS_ENABLED) ? '' : 'none';
        if (quantityGroup) quantityGroup.style.display = mileage ? '' : 'none';
        if (amountInput) amountInput.readOnly = mileage;
        if (mileage) {
            if (gstInput) { gstInput.value = '0'; gstInput.readOnly = true; }
            quantityLabel.textContent = 'Distance (km)';
            const veh = (mileageVehicle && mileageVehicle.value) || 'car';
            quantityHint.textContent = 'RM ' + (MILEAGE_RATES[veh] || 0).toFixed(2) + '/km';
            computeFromQuantity();
        } else {
            if (gstInput) gstInput.readOnly = false;
            if (quantityInput) quantityInput.value = '';
        }
        updateReceiptRequiredMark();
        updateScanButtonLabel();
    }

    // Mileage mode reads a Google Maps screenshot for the distance; receipt mode reads a receipt.
    function updateScanButtonLabel() {
        const sb = document.getElementById('scanReceiptBtn');
        if (!sb) return;
        const opt = selOpt();
        sb.innerHTML = (isMileageCat(opt) && mileageOn())
            ? '<i class="bi bi-map me-1"></i>Scan map for distance'
            : '<i class="bi bi-magic me-1"></i>Scan receipt';
    }

    function applyCategoryMode() {
        if (!categorySelect) return;
        const opt = selOpt();
        const rt = opt ? (opt.dataset.rateType || 'receipt') : 'receipt';

        const mileageCat = isMileageCat(opt);
        if (mileagePanel) mileagePanel.style.display = mileageCat ? '' : 'none';
        if (mileageCat) { applyPetrolMode(); return; }

        const computed = (rt === 'per_day' || rt === 'per_hour');
        if (quantityGroup) quantityGroup.style.display = computed ? '' : 'none';
        if (amountInput) amountInput.readOnly = computed;
        if (computed) {
            if (gstInput) { gstInput.value = '0'; gstInput.readOnly = true; }
            if (rt === 'per_day') {
                quantityLabel.textContent = 'Number of Days';
                quantityHint.textContent = 'RM ' + (parseFloat(opt.dataset.rateAmount) || 0).toFixed(2) + ' per day';
            } else {
                quantityLabel.textContent = 'Hours Worked';
                quantityHint.textContent = '4 hrs = RM50, 8 hrs = RM100';
            }
            computeFromQuantity();
        } else {
            if (gstInput) gstInput.readOnly = false;
            if (quantityInput) quantityInput.value = '';
        }
        updateReceiptRequiredMark();
        updateScanButtonLabel();
    }

    if (categorySelect) categorySelect.addEventListener('change', applyCategoryMode);
    if (quantityInput) quantityInput.addEventListener('input', computeFromQuantity);
    if (mileageVehicle) mileageVehicle.addEventListener('change', applyPetrolMode);
    document.querySelectorAll('input[name="claim_mode"]').forEach(function (r) {
        r.addEventListener('change', applyPetrolMode);
    });
    if (mileageCalcBtn) mileageCalcBtn.addEventListener('click', function () {
        const dest = (mileageDest.value || '').trim();
        if (dest.length < 3) { mileageCalcHint.textContent = 'Enter a destination.'; return; }
        mileageCalcBtn.disabled = true;
        mileageCalcHint.textContent = 'Calculating…';
        fetch('{{ route("user.claims.mileage-distance") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify({ destination: dest })
        })
        .then(r => r.json())
        .then(data => {
            mileageCalcBtn.disabled = false;
            if (!data.enabled) { mileageCalcHint.textContent = 'Auto-distance is off — enter km manually.'; return; }
            if (!data.ok) { mileageCalcHint.textContent = data.message || 'Could not calculate.'; return; }
            quantityInput.value = data.km;
            mileageCalcHint.textContent = data.text + ' from ' + (data.origin || 'Jaya One');
            computeFromQuantity();
        })
        .catch(() => { mileageCalcBtn.disabled = false; mileageCalcHint.textContent = 'Lookup failed — enter km manually.'; });
    });

    // ── Receipt OCR — manual scan (user clicks "Scan receipt"; config-gated, fails open) ──
    const OCR_ENABLED = @json(\App\Services\ClaimReceiptOcrService::enabled(Auth::user()->employee?->company));
    const ocrHint = document.getElementById('ocrHint');
    const receiptFileEl = document.getElementById('receiptFile');
    const scanReceiptBtn = document.getElementById('scanReceiptBtn');

    function runReceiptScan() {
        if (!OCR_ENABLED || !receiptFileEl || !receiptFileEl.files.length) return;
        const opt = selOpt();
        const rt = opt ? (opt.dataset.rateType || 'receipt') : 'receipt';
        if (rt === 'per_day' || rt === 'per_hour') {          // computed: amount is derived
            if (ocrHint) { ocrHint.style.display = 'block'; ocrHint.textContent = 'This category’s amount is calculated — nothing to read from the receipt.'; }
            return;
        }
        // In mileage mode we read the DISTANCE off a Google Maps screenshot, not a receipt.
        const mileageScan = isMileageCat(opt) && mileageOn();
        const fd = new FormData();
        fd.append('receipt', receiptFileEl.files[0]);
        fd.append('_token', '{{ csrf_token() }}');
        if (ocrHint) { ocrHint.style.display = 'block'; ocrHint.textContent = mileageScan ? 'Reading distance from the map…' : 'Reading receipt…'; }
        if (scanReceiptBtn) scanReceiptBtn.disabled = true;
        // Hard client-side timeout so the hint can never hang (e.g. busy provider).
        const ocrAbort = new AbortController();
        const ocrTimer = setTimeout(() => ocrAbort.abort(), 35000);
        fetch('{{ route("user.claims.scan-receipt") }}', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, signal: ocrAbort.signal })
            .then(r => r.json())
            .then(data => {
                clearTimeout(ocrTimer);
                if (scanReceiptBtn) scanReceiptBtn.disabled = false;
                if (!data.enabled) { if (ocrHint) { ocrHint.style.display = 'block'; ocrHint.textContent = 'Scanning is off — please enter the details manually.'; } return; }
                if (!data.ok) {
                    if (ocrHint) { ocrHint.style.display = 'block'; ocrHint.textContent = 'Couldn’t read the image (provider busy?) — please enter the details manually.'; }
                    return;
                }
                // Mileage: fill the distance (km) from the map screenshot.
                if (mileageScan) {
                    if (data.distance_km && quantityInput) {
                        quantityInput.value = Number(data.distance_km).toFixed(1);
                        computeFromQuantity();
                        if (ocrHint) { ocrHint.style.display = 'block'; ocrHint.textContent = 'Distance read from map: ' + Number(data.distance_km).toFixed(1) + ' km — please verify.'; }
                    } else if (ocrHint) {
                        ocrHint.style.display = 'block';
                        ocrHint.textContent = 'Couldn’t read a distance from this image — enter the km manually.';
                    }
                    return;
                }
                const filled = [];
                if (data.amount && amountInput && !amountInput.value) { amountInput.value = Number(data.amount).toFixed(2); recalcTotal(); filled.push('amount'); }
                const dateEl = form.querySelector('[name="expense_date"]');
                if (data.date && dateEl && !dateUserSet) { dateEl.value = data.date; filled.push('date'); }
                const descEl = document.getElementById('expenseDescription');
                if (data.vendor && descEl && !descEl.value) { descEl.value = data.vendor; filled.push('vendor'); }
                if (data.category_id && categorySelect && !categorySelect.value) {
                    categorySelect.value = String(data.category_id);
                    categorySelect.dispatchEvent(new Event('change'));  // run applyCategoryMode + clear keyword hint
                    filled.push('category');
                }
                if (ocrHint) {
                    ocrHint.style.display = 'block';
                    ocrHint.textContent = filled.length
                        ? ('Auto-filled from receipt (' + filled.join(', ') + ') — please verify.')
                        : 'Could not read details — enter manually.';
                }
            })
            .catch(() => {
                clearTimeout(ocrTimer);
                if (scanReceiptBtn) scanReceiptBtn.disabled = false;
                if (ocrHint) { ocrHint.style.display = 'block'; ocrHint.textContent = 'Couldn’t read the receipt — please enter the details manually.'; }
            });
    }
    if (scanReceiptBtn) scanReceiptBtn.addEventListener('click', runReceiptScan);

    // Receipt remove — clear a wrongly-chosen file before adding (shows only when a file is picked)
    const receiptClearBtn = document.getElementById('receiptClearBtn');
    if (receiptFileEl) {
        receiptFileEl.addEventListener('change', function () {
            const hasFile = receiptFileEl.files.length > 0;
            if (receiptClearBtn) receiptClearBtn.classList.toggle('d-none', !hasFile);
            if (scanReceiptBtn) scanReceiptBtn.classList.toggle('d-none', !(hasFile && OCR_ENABLED)); // show Scan only when a file is picked + OCR on
            if (!hasFile && ocrHint) ocrHint.style.display = 'none';
        });
    }
    if (receiptFileEl && receiptClearBtn) {
        receiptClearBtn.addEventListener('click', function () {
            receiptFileEl.value = '';
            receiptClearBtn.classList.add('d-none');
            if (scanReceiptBtn) scanReceiptBtn.classList.add('d-none');
            if (ocrHint) ocrHint.style.display = 'none';
        });
    }

    // Clear — reset the Add Expense Item form to a fresh, empty state
    const clearFormBtn = document.getElementById('clearFormBtn');
    if (clearFormBtn) {
        clearFormBtn.addEventListener('click', function () {
            const byId = id => document.getElementById(id);
            const dateEl = form.querySelector('[name="expense_date"]');
            if (dateEl) dateEl.value = @json(date('Y-m-d'));
            dateUserSet = false; // allow OCR to fill the date again after a clear
            const projEl = form.querySelector('[name="project_client"]');
            if (projEl) projEl.value = '';
            if (byId('expenseDescription')) byId('expenseDescription').value = '';
            if (byId('expenseCategory')) byId('expenseCategory').value = '';
            if (byId('amountNoGst')) byId('amountNoGst').value = '';
            if (byId('gstAmount')) byId('gstAmount').value = '0';
            if (byId('totalWithGst')) byId('totalWithGst').value = '';
            if (byId('quantityInput')) byId('quantityInput').value = '';
            if (byId('mileageVehicle')) byId('mileageVehicle').value = 'car';
            if (byId('modeReceipt')) byId('modeReceipt').checked = true;
            if (byId('mileageDest')) byId('mileageDest').value = '';
            if (receiptFileEl) receiptFileEl.value = '';
            if (receiptClearBtn) receiptClearBtn.classList.add('d-none');
            if (scanReceiptBtn) scanReceiptBtn.classList.add('d-none');
            if (ocrHint) ocrHint.style.display = 'none';
            if (byId('categoryHint')) byId('categoryHint').style.display = 'none';
            if (byId('mileageCalcHint')) byId('mileageCalcHint').textContent = '';
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            applyCategoryMode(); // re-sync UI (hides mileage panel, quantity, etc.)
        });
    }

    applyCategoryMode();
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
