@extends('layouts.app')
@section('title', 'My Claims')

@section('content')
@include('partials.dashboard-widgets-style')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <a href="{{ route('user.claims.index') }}" class="btn btn-sm btn-outline-secondary mb-2"><i class="bi bi-arrow-left me-1"></i>All my claims</a>
            <h4 class="mb-1"><i class="bi bi-receipt-cutoff me-2"></i>{{ $claim->event ?: 'Untitled claim' }}</h4>
            <div class="text-muted small">
                {{ $employee->full_name }} &middot; {{ $employee->department ?? 'N/A' }} &middot;
                <span class="fw-semibold">{{ $claim->claim_number }}</span> &middot;
                <span class="badge bg-{{ $claim->statusBadge()['class'] }}">{{ $claim->statusBadge()['label'] }}</span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
            @if(in_array($claim->status, ['submitted','manager_approved','hr_approved','paid']))
            <a href="{{ route('user.claims.pdf', $claim) }}" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
            @endif
            @if($claim->isSubmittable())
            <a href="{{ route('user.claims.submit-form', $claim) }}" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Submit for Approval</a>
            @endif
            @if($claim->status === 'submitted')
            <form action="{{ route('user.claims.cancel', $claim) }}" method="POST" class="d-inline js-confirm" data-confirm="Recall this claim to draft? You'll be able to edit and resubmit it." data-confirm-title="Recall claim" data-confirm-ok="Recall" data-confirm-variant="warning">
                @csrf
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Recall</button>
            </form>
            @endif
        </div>
    </div>

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
                    <li>Submit to your reporting manager by <strong>{{ \App\Services\ClaimRulesService::employeeSubmissionDeadline($policy->submission_deadline_day ?? 20)->format('d M') }}</strong> so they can approve before the <strong>{{ ordinal($policy->submission_deadline_day ?? 20) }}</strong> HR cutoff.</li>
                    <li>Claims submitted after the deadline will be processed in the next month's cycle.</li>
                    <li>For Extra Hours claim, please state the number of extra hours clearly (e.g., Parentcraft Event, 8am–6pm).</li>
                    <li>Separate expense claim forms for different events and personal general claims.</li>
                    <li>Don't pick <strong>"Petty Cash" as an expense category</strong> &mdash; categorise each line properly (Petrol, Meals, etc.). A <em>"Petty Cash &ndash; [project]"</em> <strong>claim</strong> is perfectly fine.</li>
                    <li>Ensure all claims have <strong>supporting receipts/proof</strong> attached.</li>
                    <li>Admin reserves the right to refuse incomplete claims (no signature, no receipt, wrong category, etc.).</li>
                </ol>
            </div>
        </div>
    </div>

    {{-- Company letterhead (Expenses Claims Form) + event edit --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            @include('partials.claim-letterhead', ['company' => $company, 'employee' => $employee, 'event' => $claim->event, 'showRules' => false, 'claimDate' => $claim->submitted_at ?? \Carbon\Carbon::create($claim->year, $claim->month, 1)])
            @if($claim->isEditable())
            <form action="{{ route('user.claims.save-details', $claim) }}" method="POST" class="row g-2 align-items-end mt-1">
                @csrf
                <div class="col-sm-8 col-md-6">
                    <label class="form-label small mb-0">Event / purpose</label>
                    <input type="text" name="event" class="form-control form-control-sm" value="{{ $claim->event }}" placeholder="e.g., Parentcraft Shooting" maxlength="255" required>
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

    {{-- ── Claim items + add form ── --}}
    @php $canEdit = $claim->isEditable(); @endphp

    <div class="card shadow-sm mb-4 border-0">
        {{-- ── Rejection banner + correction flow ── --}}
        @if($claim->status === 'manager_rejected')
        <div class="alert alert-danger mx-3 mt-3 mb-0">
            <div class="fw-semibold"><i class="bi bi-x-octagon me-1"></i>Rejected by your manager{{ $claim->managerApprover ? ' ('.$claim->managerApprover->full_name.')' : '' }}</div>
            @if($claim->manager_remarks)<div class="mt-1"><strong>Reason:</strong> {{ $claim->manager_remarks }}</div>@endif
            <form action="{{ route('user.claims.correct', $claim) }}" method="POST" class="js-confirm mt-2" data-confirm="Start a correction? A new report opens pre-filled with these items so you can fix and resubmit. This rejected report is kept as history." data-confirm-title="Make correction" data-confirm-ok="Make correction" data-confirm-variant="primary">
                @csrf
                <button class="btn btn-primary btn-sm"><i class="bi bi-pencil-square me-1"></i>Make correction</button>
            </form>
        </div>
        @elseif($claim->status === 'hr_rejected')
        <div class="alert {{ $claim->canCorrect() ? 'alert-danger' : 'alert-warning' }} mx-3 mt-3 mb-0">
            <div class="fw-semibold"><i class="bi bi-x-octagon me-1"></i>Rejected by HR</div>
            @if($claim->hr_remarks)<div class="mt-1"><strong>HR reason:</strong> {{ $claim->hr_remarks }}</div>@endif
            @if($claim->awaitingRelease())
            <div class="mt-2 small"><i class="bi bi-hourglass-split me-1"></i>Your approving manager is reviewing this. You'll be able to make a correction once they release it to you.</div>
            @else
            @if($claim->release_remarks)<div class="mt-1"><strong>Manager's note:</strong> {{ $claim->release_remarks }}</div>@endif
            <div class="mt-1 small text-success"><i class="bi bi-unlock me-1"></i>Released by {{ optional($claim->releasedBy)->full_name ?? 'your manager' }} on {{ $claim->released_at?->format('d/m/Y') }}.</div>
            <form action="{{ route('user.claims.correct', $claim) }}" method="POST" class="js-confirm mt-2" data-confirm="Start a correction? A new report opens pre-filled with these items so you can fix and resubmit. This rejected report is kept as history." data-confirm-title="Make correction" data-confirm-ok="Make correction" data-confirm-variant="primary">
                @csrf
                <button class="btn btn-primary btn-sm"><i class="bi bi-pencil-square me-1"></i>Make correction</button>
            </form>
            @endif
        </div>
        @elseif($claim->correction_of_id)
        <div class="alert alert-info mx-3 mt-3 mb-0 small">
            <i class="bi bi-arrow-repeat me-1"></i>This is a correction of {{ optional($claim->correctionOf)->claim_number }}.
        </div>
        @endif

        {{-- ── Claim Status Tracker (visible after submission) ── --}}
        @if($claim && $claim->status !== 'draft')
        @php
            $statusSteps = [
                ['key' => 'submitted',         'icon' => 'bi-send-fill',       'label' => 'Submitted'],
                ['key' => 'manager_approved',   'icon' => 'bi-person-check-fill','label' => 'Manager'],
                ['key' => 'hr_approved',        'icon' => 'bi-building-check',  'label' => 'HR'],
                ['key' => 'paid',               'icon' => 'bi-cash-coin',       'label' => 'Paid'],
            ];
            $statusOrder = ['submitted' => 1, 'manager_approved' => 2, 'hr_approved' => 3, 'paid' => 4];
            $rejected = in_array($claim->status, ['manager_rejected', 'hr_rejected', 'cancelled']);
            $currentStep = $statusOrder[$claim->status] ?? 0;
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
                        $rejectedAt = $claim->status === 'manager_rejected' ? 1 : ($claim->status === 'hr_rejected' ? 2 : $currentStep);
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
                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>{{ $claim->statusBadge()['label'] }}</span>
            </div>
            @elseif($claim->status === 'submitted')
            <div class="text-center mt-2">
                <small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Awaiting manager approval{{ $claim->submitted_at ? ' — submitted ' . $claim->submitted_at->format('d/m/Y') : '' }}</small>
            </div>
            @elseif($claim->status === 'manager_approved')
            <div class="text-center mt-2">
                <small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Awaiting HR approval{{ $claim->manager_approved_at ? ' — manager approved ' . $claim->manager_approved_at->format('d/m/Y') : '' }}</small>
            </div>
            @elseif($claim->status === 'hr_approved')
            <div class="text-center mt-2">
                <small class="text-success"><i class="bi bi-check-circle me-1"></i>Approved — pending payment processing</small>
            </div>
            @elseif($claim->status === 'paid')
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
                    <input type="hidden" name="claim_id" value="{{ $claim->id }}">

                    {{-- Receipt first — upload a document, then click Scan to auto-fill the fields below (blanks only) --}}
                    <div class="border rounded p-2 mb-3 bg-white">
                        <label class="form-label fw-semibold mb-1"><i class="bi bi-paperclip me-1"></i>Attachment <span class="text-danger" id="receiptRequiredMark" style="display:none;">*</span> <span class="fw-normal text-muted small">— receipt or Google Maps screenshot; upload, then Scan to auto-fill the details below. Optional now — you can save the item and add the receipt later, but it's required before submitting.</span></label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <input type="file" name="receipt" class="form-control @error('receipt') is-invalid @enderror" accept=".jpg,.jpeg,.png,.pdf" id="receiptFile" style="max-width:380px;">
                            <button type="button" class="btn btn-sm btn-primary d-none" id="scanReceiptBtn"><i class="bi bi-magic me-1"></i>Scan attachment</button>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 d-none" id="receiptClearBtn"><i class="bi bi-x-circle me-1"></i>Remove attachment</button>
                        </div>
                        <small class="text-info mt-1" id="ocrHint" style="display:none;"></small>
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
                            <label class="form-label fw-semibold">Project / Client Name @if($projectRequired ?? false)<span class="text-danger">*</span>@endif</label>
                            <input type="text" name="project_client" class="form-control @error('project_client') is-invalid @enderror" value="{{ old('project_client') }}" placeholder="e.g., AMD Sanofi, Parentcraft" maxlength="255" @if($projectRequired ?? false)required @endif>
                            @error('project_client')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="text-muted">@if($projectRequired ?? false)Required — name the project/client this expense is for.@else Optional — link this expense to a project or client.@endif</small>
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
                            <small class="d-block fw-semibold mt-1" id="capHint" style="display:none;"></small>
                        </div>
                        <div class="col-6 col-md-2" id="quantityGroup" style="display:none;">
                            <label class="form-label fw-semibold" id="quantityLabel">Quantity</label>
                            <input type="number" name="quantity" class="form-control" id="quantityInput" step="0.01" min="0.01" max="99999.99" placeholder="0" value="{{ old('quantity') }}">
                            <small class="text-muted" id="quantityHint"></small>
                        </div>
                        {{-- Petrol: always claimed by mileage — employee chooses From + To --}}
                        <div class="col-12" id="mileagePanel" style="display:none;">
                            <div class="border rounded p-2 bg-light">
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                    <span class="fw-semibold small"><i class="bi bi-fuel-pump me-1"></i>Petrol — by mileage</span>
                                    <span class="text-muted small">Enter the route; we work out the distance and the amount.</span>
                                </div>
                                <div class="row g-2" id="mileageInputs">
                                    <div class="col-12 col-md-2">
                                        <label class="form-label small mb-1">Vehicle</label>
                                        <select class="form-select form-select-sm" name="vehicle" id="mileageVehicle">
                                            <option value="car" {{ old('vehicle', 'car') === 'car' ? 'selected' : '' }}>Car (RM0.70/km)</option>
                                            <option value="motorcycle" {{ old('vehicle') === 'motorcycle' ? 'selected' : '' }}>Motorcycle (RM0.35/km)</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-5">
                                        <label class="form-label small mb-1">From</label>
                                        <input type="text" class="form-control form-control-sm" id="mileageOrigin" name="mileage_origin" placeholder="Start location, e.g. Jaya One" maxlength="255" autocomplete="off" list="mileageOriginList" value="{{ old('mileage_origin') }}">
                                        <datalist id="mileageOriginList"></datalist>
                                    </div>
                                    <div class="col-12 col-md-5" id="mileageDestWrap">
                                        <label class="form-label small mb-1">To</label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" id="mileageDest" name="mileage_destination" placeholder="Destination, e.g. Suria KLCC" maxlength="255" autocomplete="off" list="mileageDestList" value="{{ old('mileage_destination') }}">
                                            <button type="button" class="btn btn-outline-primary" id="mileageCalcBtn">Calculate</button>
                                        </div>
                                        <datalist id="mileageDestList"></datalist>
                                        <small class="text-muted" id="mileageCalcHint"></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label fw-semibold">RM (w/o SST) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control @error('amount') is-invalid @enderror" id="amountNoGst" value="{{ old('amount') }}" step="0.01" min="0.01" max="99999.99" placeholder="0.00" required>
                            @error('amount')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @else
                            <div class="invalid-feedback">Enter the amount before SST (e.g., 25.00).</div>
                            @enderror
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label fw-semibold">SST (RM)</label>
                            <input type="number" name="gst_amount" class="form-control" id="gstAmount" value="{{ old('gst_amount', 0) }}" step="0.01" min="0" max="99999.99" placeholder="0.00">
                            <small class="text-muted d-none d-md-block">Leave 0 if no SST.</small>
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label fw-semibold">Total (w/ SST)</label>
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
            @if($claim && $claim->items->count() > 0)
            <div class="d-none d-md-block table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Project/Client</th>
                            <th>Category</th>
                            <th class="text-end">RM (w/o SST)</th>
                            <th class="text-end">SST (RM)</th>
                            <th class="text-end">Total (w/ SST)</th>
                            <th>Receipt</th>
                            @if($canEdit)<th></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($claim->items as $i => $item)
                        <tr class="{{ $item->isRejected() ? 'table-danger' : '' }}">
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item->expense_date->format('d/m/Y') }}</td>
                            <td>
                                {{ $item->description }}
                                @if($item->isRejected())
                                <span class="badge bg-danger ms-1">Rejected</span>
                                @if($item->rejectionReason())<div class="small text-danger"><i class="bi bi-info-circle me-1"></i>{{ $item->rejectionReason() }}</div>@endif
                                @endif
                                @if($claim && $claim->status !== 'draft' && $item->approver)
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
                                @elseif($item->needsReceipt())
                                <span class="badge bg-warning text-dark" title="Attach a receipt before submitting"><i class="bi bi-exclamation-triangle me-1"></i>Needed</span>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            @if($canEdit)
                            <td>
                                @if(!$item->is_locked)
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#edit-row-{{ $item->id }}" aria-expanded="false" title="Edit item"><i class="bi bi-pencil"></i></button>
                                    <form action="{{ route('user.claims.remove-item', $item) }}" method="POST" class="js-confirm" data-confirm="Remove this item from the claim?" data-confirm-title="Remove item" data-confirm-ok="Remove" data-confirm-variant="danger">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                                @endif
                            </td>
                            @endif
                        </tr>
                        @if($canEdit && !$item->is_locked)
                        <tr class="collapse" id="edit-row-{{ $item->id }}">
                            <td colspan="10" class="p-0 border-0 bg-light">
                                @include('partials.claim-item-edit', ['item' => $item, 'projectRequired' => $projectRequired ?? false])
                            </td>
                        </tr>
                        @endif
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="5" class="text-end">TOTAL</td>
                            <td class="text-end">{{ number_format($claim?->total_amount ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($claim?->total_gst ?? 0, 2) }}</td>
                            <td class="text-end text-primary">RM {{ number_format($claim?->total_with_gst ?? 0, 2) }}</td>
                            <td></td>
                            @if($canEdit)<td></td>@endif
                        </tr>
                        @if($claim && $claim->hasRejectedItems())
                        <tr class="fw-bold">
                            <td colspan="7" class="text-end text-success">PAYABLE (after rejections)</td>
                            <td class="text-end text-success">RM {{ number_format($claim->approvedTotal(), 2) }}</td>
                            <td></td>
                            @if($canEdit)<td></td>@endif
                        </tr>
                        @endif
                    </tfoot>
                </table>
            </div>

            {{-- Items Cards (mobile) --}}
            <div class="d-md-none">
                @foreach($claim->items as $i => $item)
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
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="collapse" data-bs-target="#edit-card-{{ $item->id }}" aria-expanded="false" title="Edit item"><i class="bi bi-pencil"></i></button>
                            <form action="{{ route('user.claims.remove-item', $item) }}" method="POST" class="js-confirm" data-confirm="Remove this item from the claim?" data-confirm-title="Remove item" data-confirm-ok="Remove" data-confirm-variant="danger">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                            </form>
                            @endif
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span>
                            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">{{ $item->category->name ?? '-' }}</span>
                            @if(!$item->receipt_path && $item->needsReceipt())
                            <span class="badge bg-warning text-dark ms-1"><i class="bi bi-exclamation-triangle me-1"></i>Receipt needed</span>
                            @endif
                        </span>
                        <span class="fw-bold">RM {{ number_format($item->total_with_gst, 2) }}</span>
                    </div>
                    @if($canEdit && !$item->is_locked)
                    <div class="collapse mt-2" id="edit-card-{{ $item->id }}">
                        @include('partials.claim-item-edit', ['item' => $item, 'projectRequired' => $projectRequired ?? false])
                    </div>
                    @endif
                </div>
                @endforeach
                <div class="border-top pt-2 mt-2 d-flex justify-content-between fw-bold">
                    <span>TOTAL</span>
                    <span class="text-primary">RM {{ number_format($claim?->total_with_gst ?? 0, 2) }}</span>
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

    {{-- Claim History card removed — full status history now lives on the Claim Reports page (status log per claim). --}}
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
                { el: form.querySelector('[name="amount"]'), msg: 'Enter the amount before SST (e.g., 25.00).' },
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

            // The attachment is optional at add time — a draft item can be saved now and
            // the receipt added before submitting (enforced server-side on submit).
            if (categorySelect && categorySelect.value) {
                const opt = categorySelect.selectedOptions[0];
                const mileageHere = isMileageCat(opt); // Petrol = always mileage, distance is the evidence
                // Computed categories (Event Day / Extra Hours) and Petrol mileage need a quantity
                const rt = opt ? opt.dataset.rateType : 'receipt';
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
    const mileageOrigin   = document.getElementById('mileageOrigin');
    const mileageDest     = document.getElementById('mileageDest');
    const mileageDestWrap = document.getElementById('mileageDestWrap');
    const mileageCalcBtn  = document.getElementById('mileageCalcBtn');
    const mileageCalcHint = document.getElementById('mileageCalcHint');

    function otBand(h) { if (h >= 8) return 100; if (h >= 4) return 50; return 0; }
    function selOpt() { return categorySelect ? categorySelect.selectedOptions[0] : null; }
    function isMileageCat(opt) { return !!(opt && MILEAGE_GL && opt.dataset.glCode === MILEAGE_GL); }
    // Petrol is always a mileage claim now (the by-receipt toggle was removed), so
    // "mileage mode" is simply: is the Petrol category selected.
    function mileageOn() { return isMileageCat(selOpt()); }

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
        // The attachment is optional when adding/saving a draft item (it can be added
        // before submission), so the "required" asterisk is never shown.
        const mark = document.getElementById('receiptRequiredMark');
        if (mark) mark.style.display = 'none';
    }

    function applyPetrolMode() {
        // Petrol is always by mileage: distance drives the amount.
        if (mileageInputs) mileageInputs.style.display = '';
        // The Calculate button only works with a distance provider configured.
        if (mileageCalcBtn) mileageCalcBtn.style.display = MAPS_ENABLED ? '' : 'none';
        if (quantityGroup) quantityGroup.style.display = '';
        if (amountInput) amountInput.readOnly = true;
        if (gstInput) { gstInput.value = '0'; gstInput.readOnly = true; }
        quantityLabel.textContent = 'Distance (km)';
        const veh = (mileageVehicle && mileageVehicle.value) || 'car';
        quantityHint.textContent = 'RM ' + (MILEAGE_RATES[veh] || 0).toFixed(2) + '/km';
        computeFromQuantity();
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
            : '<i class="bi bi-magic me-1"></i>Scan attachment';
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

    // ── Capped-category allowance hint (shows what's left before they enter an amount) ──
    const CAP_INFO = @json($capInfo ?? []);
    const capHint = document.getElementById('capHint');
    function updateCapHint() {
        if (!capHint) return;
        const info = categorySelect ? CAP_INFO[categorySelect.value] : null;
        if (!info) { capHint.style.display = 'none'; return; }
        const fmt = n => Number(n).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        capHint.style.display = 'block';
        if (info.remaining <= 0) {
            capHint.className = 'd-block fw-semibold mt-1 text-danger';
            capHint.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>RM ' + fmt(info.limit) + ' ' + info.period + ' ' + info.name + ' allowance fully used this period.';
        } else {
            capHint.className = 'd-block fw-semibold mt-1 text-success';
            capHint.innerHTML = '<i class="bi bi-wallet2 me-1"></i>RM ' + fmt(info.remaining) + ' of your RM ' + fmt(info.limit) + ' ' + info.period + ' ' + info.name + ' allowance is left. A bigger receipt is auto-capped to this.';
        }
    }
    if (categorySelect) categorySelect.addEventListener('change', updateCapHint);
    updateCapHint();

    if (quantityInput) quantityInput.addEventListener('input', computeFromQuantity);
    if (mileageVehicle) mileageVehicle.addEventListener('change', applyPetrolMode);
    if (mileageCalcBtn) mileageCalcBtn.addEventListener('click', function () {
        const origin = (mileageOrigin && mileageOrigin.value || '').trim();
        const dest = (mileageDest.value || '').trim();
        if (origin.length < 3) { mileageCalcHint.textContent = 'Enter the starting point (From).'; return; }
        if (dest.length < 3) { mileageCalcHint.textContent = 'Enter the destination (To).'; return; }
        mileageCalcBtn.disabled = true;
        mileageCalcHint.textContent = 'Calculating…';
        fetch('{{ route("user.claims.mileage-distance") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify({ origin: origin, destination: dest })
        })
        .then(r => r.json())
        .then(data => {
            mileageCalcBtn.disabled = false;
            if (!data.enabled) { mileageCalcHint.textContent = 'Auto-distance is off — enter km manually.'; return; }
            if (!data.ok) { mileageCalcHint.textContent = data.message || 'Could not calculate.'; return; }
            quantityInput.value = data.km;
            mileageCalcHint.textContent = data.text + ' from ' + (data.origin || origin);
            computeFromQuantity();
        })
        .catch(() => { mileageCalcBtn.disabled = false; mileageCalcHint.textContent = 'Lookup failed — enter km manually.'; });
    });

    // ── Place autocomplete for the From / To fields (ORS, debounced) ──
    function wirePlaceSuggest(input, listEl) {
        if (!input || !listEl) return;
        let timer = null;
        input.addEventListener('input', function () {
            const text = input.value.trim();
            if (timer) clearTimeout(timer);
            if (text.length < 3) { listEl.innerHTML = ''; return; }
            timer = setTimeout(function () {
                fetch('{{ route("user.claims.place-suggest") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify({ text: text })
                })
                .then(r => r.json())
                .then(data => {
                    listEl.innerHTML = '';
                    (data.suggestions || []).forEach(function (label) {
                        const opt = document.createElement('option');
                        opt.value = label;
                        listEl.appendChild(opt);
                    });
                })
                .catch(() => {});
            }, 300);
        });
    }
    wirePlaceSuggest(mileageOrigin, document.getElementById('mileageOriginList'));
    wirePlaceSuggest(mileageDest, document.getElementById('mileageDestList'));

    // ── Receipt OCR — manual scan (user clicks "Scan receipt"; config-gated, fails open) ──
    const OCR_ENABLED = @json(\App\Services\ClaimReceiptOcrService::enabled(Auth::user()->employee?->company));
    const ocrHint = document.getElementById('ocrHint');
    const receiptFileEl = document.getElementById('receiptFile');
    const scanReceiptBtn = document.getElementById('scanReceiptBtn');

    // A Maps screenshot was scanned — switch to Petrol "by mileage", fill the km,
    // the route, and the computed amount (vehicle defaults to car; user can change).
    function applyMapDistance(km, from, to) {
        // 1. Select the Petrol (mileage GL) category — this auto-applies mileage mode
        if (categorySelect && MILEAGE_GL) {
            const petrolOpt = Array.from(categorySelect.options).find(o => o.dataset.glCode === MILEAGE_GL);
            if (petrolOpt) { categorySelect.value = petrolOpt.value; categorySelect.dispatchEvent(new Event('change')); }
        }
        // 2. Fill distance + compute the amount
        if (quantityInput) { quantityInput.value = Number(km).toFixed(1); computeFromQuantity(); }
        // 3. Fill description + the From / To boxes (if empty)
        const descEl = document.getElementById('expenseDescription');
        if (descEl && !descEl.value && (from || to)) {
            descEl.value = 'Mileage' + (from ? ' from ' + from : '') + (to ? ' to ' + to : '');
        }
        if (mileageOrigin && from && !mileageOrigin.value) mileageOrigin.value = from;
        if (mileageDest && to && !mileageDest.value) mileageDest.value = to;
        // 5. Feedback
        if (ocrHint) {
            ocrHint.style.display = 'block';
            ocrHint.textContent = 'Map read: ' + Number(km).toFixed(1) + ' km'
                + ((from && to) ? ' (' + from + ' → ' + to + ')' : '')
                + ' → Petrol mileage = RM ' + (amountInput ? amountInput.value : '?') + '. Check the vehicle (car/motorcycle).';
        }
    }

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
                // A Google Maps screenshot → set up a Petrol mileage claim automatically,
                // whatever mode the form was in.
                if (data.distance_km) {
                    applyMapDistance(Number(data.distance_km), data.route_from, data.route_to);
                    return;
                }
                if (mileageScan) {       // was in mileage mode but no distance found
                    if (ocrHint) { ocrHint.style.display = 'block'; ocrHint.textContent = 'Couldn’t read a distance from this image — enter the km manually.'; }
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
            if (ocrHint) { ocrHint.textContent = ''; ocrHint.style.display = 'none'; }
        });
    }

    // Clear — reset the Add Expense Item form to a fresh, empty state.
    const clearFormBtn = document.getElementById('clearFormBtn');
    if (clearFormBtn) {
        clearFormBtn.addEventListener('click', function () {
            const byId = id => document.getElementById(id);
            const dateEl = form.querySelector('[name="expense_date"]');
            if (dateEl) dateEl.value = @json(date('Y-m-d'));
            dateUserSet = false;
            const projEl = form.querySelector('[name="project_client"]');
            if (projEl) projEl.value = '';
            if (byId('expenseDescription')) byId('expenseDescription').value = '';
            if (byId('expenseCategory')) byId('expenseCategory').value = '';
            if (byId('amountNoGst')) byId('amountNoGst').value = '';
            if (byId('gstAmount')) byId('gstAmount').value = '0';
            if (byId('totalWithGst')) byId('totalWithGst').value = '';
            if (byId('quantityInput')) byId('quantityInput').value = '';
            if (byId('mileageVehicle')) byId('mileageVehicle').value = 'car';
            if (byId('mileageOrigin')) byId('mileageOrigin').value = '';
            if (byId('mileageDest')) byId('mileageDest').value = '';
            if (receiptFileEl) receiptFileEl.value = '';
            if (receiptClearBtn) receiptClearBtn.classList.add('d-none');
            if (scanReceiptBtn) scanReceiptBtn.classList.add('d-none');
            if (ocrHint) { ocrHint.textContent = ''; ocrHint.style.display = 'none'; }
            if (byId('categoryHint')) byId('categoryHint').style.display = 'none';
            if (byId('mileageCalcHint')) byId('mileageCalcHint').textContent = '';
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            applyCategoryMode();
        });
    }

    // ── Per-item inline edit forms: live amount compute + cancel-to-collapse ──
    document.querySelectorAll('.inline-edit-form').forEach(function (f) {
        const mode = f.dataset.mode;
        const amtHidden = f.querySelector('input[name="amount"]');
        const gstHidden = f.querySelector('input[name="gst_amount"]');
        const totHidden = f.querySelector('input[name="total_with_gst"]');
        const preview = f.querySelector('.ie-amount-preview');
        const setComputed = function (amt) {
            const v = isFinite(amt) ? amt : 0;
            if (amtHidden) amtHidden.value = v.toFixed(2);
            if (gstHidden) gstHidden.value = '0';
            if (totHidden) totHidden.value = v.toFixed(2);
            if (preview) preview.textContent = 'RM ' + v.toFixed(2);
        };
        if (mode === 'mileage') {
            const km = f.querySelector('input[name="quantity"]');
            const veh = f.querySelector('select[name="vehicle"]');
            const calc = function () {
                const rate = (veh && veh.value === 'motorcycle') ? parseFloat(f.dataset.motoRate) : parseFloat(f.dataset.carRate);
                setComputed((parseFloat(km.value) || 0) * (rate || 0));
            };
            if (km) km.addEventListener('input', calc);
            if (veh) veh.addEventListener('change', calc);
        } else if (mode === 'per_day') {
            const q = f.querySelector('input[name="quantity"]');
            if (q) q.addEventListener('input', function () { setComputed((parseFloat(q.value) || 0) * (parseFloat(f.dataset.dayRate) || 0)); });
        } else if (mode === 'per_hour') {
            const q = f.querySelector('input[name="quantity"]');
            const band = function (h) { h = parseFloat(h) || 0; return h >= 8 ? 100 : (h >= 4 ? 50 : 0); };
            if (q) q.addEventListener('input', function () { setComputed(band(q.value)); });
        } else {
            const a = f.querySelector('input[name="amount"]');
            const g = f.querySelector('input[name="gst_amount"]');
            const t = f.querySelector('input[name="total_with_gst"]');
            const calc = function () {
                const v = (parseFloat(a.value) || 0) + (parseFloat(g.value) || 0);
                if (t) t.value = v.toFixed(2);
            };
            if (a) a.addEventListener('input', calc);
            if (g) g.addEventListener('input', calc);
        }
    });
    document.querySelectorAll('.inline-edit-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const host = btn.closest('.collapse');
            if (host && window.bootstrap && bootstrap.Collapse) {
                bootstrap.Collapse.getOrCreateInstance(host).hide();
            }
        });
    });

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
