{{--
    Shared body for a claim (used by both the flat draft FORM and the collapsible
    accordion card). DRAFT = Category B edit + add-item entry + items + submit/delete;
    other statuses = read-only items + rejection banner. Needs from parent scope:
    $claim, $categories, $approvers, $projectRequired, $ocrEnabled.
--}}
@php
    $editable = $claim->status === 'draft';
    $approverName = $claim->manager?->full_name ?? '';
    // Receipt dates are constrained to the claim's reporting month (a receipt is claimed
    // under its own month). Max is the month end, but never the future.
    [$ccPeriodStart, $ccPeriodEnd] = \App\Services\ClaimRulesService::periodBounds($claim->year, $claim->month);
    $ccDateMin = $ccPeriodStart->toDateString();
    $ccDateMax = ($ccPeriodEnd->greaterThan(now()) ? now() : $ccPeriodEnd)->toDateString();
    $ccMonthName = $ccPeriodStart->format('F Y');
    $ccDefaultDate = ($claim->event_date && \App\Services\ClaimRulesService::itemDateInPeriod($claim->event_date, $claim->year, $claim->month))
        ? $claim->event_date->toDateString()
        : $ccDateMax;
@endphp

{{-- Rejection banner (terminal claims kept as history) --}}
@if($claim->status === 'manager_rejected' || $claim->status === 'hr_rejected')
<div class="alert alert-danger py-2 small mb-3">
    <i class="bi bi-x-octagon me-1"></i>
    <strong>Rejected by {{ $claim->status === 'manager_rejected' ? 'manager' : 'HR' }}.</strong>
    {{ $claim->status === 'manager_rejected' ? $claim->manager_remarks : $claim->hr_remarks }}
    @if($claim->canCorrect())
        <form action="{{ route('user.claims.correct', $claim) }}" method="POST" class="mt-2">
            @csrf
            <button class="btn btn-sm btn-danger"><i class="bi bi-arrow-repeat me-1"></i>Make a correction</button>
        </form>
    @elseif($claim->hasCorrection())
        <div class="mt-1"><i class="bi bi-info-circle me-1"></i>A correction has already been filed for this claim — a rejected claim can be corrected only once.</div>
    @endif
</div>
@endif

@if($editable)
{{-- ── Category B — claim-level details ── --}}
<form action="{{ route('user.claims.inline-details', $claim) }}" method="POST" class="cc-details mb-3">
    @csrf
    <div class="row g-2">
        <div class="col-md-6">
            <label class="form-label small mb-1">Name of the Event <span class="text-danger">*</span></label>
            <input type="text" name="event" class="form-control form-control-sm" maxlength="255" value="{{ $claim->event }}" required>
        </div>
        <div class="col-md-6">
            <label class="form-label small mb-1">Approving PIC / Manager <span class="text-danger">*</span></label>
            @php
                $apprCompanies = $approvers->pluck('company')->filter()->unique()->sort()->values();
                $defaultApprCo = $claim->employee->company ?: $apprCompanies->first();
            @endphp
            <div class="d-flex gap-2 align-items-start">
                @if($apprCompanies->count() > 1)
                <select class="form-select form-select-sm cc-appr-company" style="max-width:210px;flex-shrink:0;" title="Approver's company">
                    @foreach($apprCompanies as $co)
                    <option value="{{ $co }}" {{ $co === $defaultApprCo ? 'selected' : '' }}>{{ \Illuminate\Support\Str::limit($co, 26) }}</option>
                    @endforeach
                </select>
                @endif
                <div class="approver-combo position-relative flex-grow-1">
                    <input type="text" class="form-control form-control-sm cc-appr-search" autocomplete="off" placeholder="Search by name or nickname…" value="{{ $approverName }}">
                    <input type="hidden" name="manager_id" class="cc-appr-id" value="{{ $claim->manager_id }}">
                    <div class="list-group position-absolute w-100 shadow-sm d-none cc-appr-list" style="z-index:1050;max-height:240px;overflow:auto;">
                        @foreach($approvers as $ap)
                        @continue($ap->id === $claim->employee_id) {{-- an employee can't approve their own claim --}}
                        @php
                            $nick = trim((string) $ap->preferred_name);
                            $nickTxt = ($nick !== '' && strcasecmp($nick, $ap->full_name) !== 0) ? ' "'.$nick.'"' : '';
                            $deptTxt = $ap->department ? ' — '.$ap->department : '';
                        @endphp
                        <button type="button" class="list-group-item list-group-item-action py-1 cc-appr-opt"
                                data-id="{{ $ap->id }}" data-name="{{ $ap->full_name }}" data-company="{{ $ap->company }}"
                                data-search="{{ strtolower($ap->full_name.' '.$nick) }}">
                            {{ $ap->full_name }}<span class="text-muted">{{ $nickTxt }}</span><span class="text-muted small">{{ $deptTxt }}</span>@unless($ap->has_login) <span class="badge bg-warning-subtle text-warning-emphasis" style="font-size:.65rem;">no login yet</span>@endunless
                        </button>
                        @endforeach
                        <div class="list-group-item text-muted small py-1 d-none cc-appr-nomatch">No matching name.</div>
                    </div>
                </div>
            </div>
            @if($apprCompanies->count() > 1)
            <div class="form-text small">Working an event for another company? Switch the company to pick its approving manager.</div>
            @endif
        </div>
        <div class="col-md-6">
            <label class="form-label small mb-1">Date of the Event</label>
            <input type="date" name="event_date" class="form-control form-control-sm" max="{{ now()->toDateString() }}" value="{{ $claim->event_date?->toDateString() }}">
        </div>
        <div class="col-md-6">
            <label class="form-label small mb-1">Project / Client Name @if($projectRequired)<span class="text-danger">*</span>@else<span class="text-muted">(optional)</span>@endif</label>
            <input type="text" name="project_client" class="form-control form-control-sm" maxlength="255" value="{{ $claim->project_client }}" placeholder="e.g. Parentcraft, MCA, internal">
        </div>
    </div>
</form>

<hr class="my-3">

{{-- ── Add expense item (Description · Date · Type · w/o SST · SST · w/ SST · Attachment) ── --}}
<h6 class="fw-semibold mb-2"><i class="bi bi-plus-circle me-1 text-success"></i>Add expense item</h6>
<div class="cc-entry">
    {{-- Expense Description + Date (entered manually — NOT auto-filled by OCR) --}}
    <div class="row g-2">
        <div class="col-md-8">
            <label class="form-label small mb-1">Expense Description <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm cc-i-desc" maxlength="500">
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1">Date of Expense <span class="text-danger">*</span></label>
            <input type="date" class="form-control form-control-sm cc-i-date" min="{{ $ccDateMin }}" max="{{ $ccDateMax }}" value="{{ $ccDefaultDate }}">
            <div class="cc-date-note small mt-1 py-2 px-2 rounded" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;">
                <i class="bi bi-calendar-check me-1"></i>This is a <strong>{{ $ccMonthName }}</strong> claim — every receipt added here must be dated in <strong>{{ $ccMonthName }}</strong>. A receipt from another month should be claimed under that month's own claim.
            </div>
        </div>
    </div>

    {{-- Upload attachment (under Description, above Expense Type) --}}
    <div class="row g-2 mt-1">
        <div class="col-12">
            <label class="form-label small mb-1"><i class="bi bi-paperclip me-1"></i>Upload attachment @if($ocrEnabled)<span class="text-muted">— upload one or more files, then Scan to auto-fill. One image with several receipts, or several files at once, opens a review list. Tip: for a long statement, highlight or screenshot just the rows you’re claiming.</span>@endif</label>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <input type="file" class="form-control form-control-sm cc-i-file" accept=".jpg,.jpeg,.png,.pdf" multiple style="max-width:340px;">
                @if($ocrEnabled)<button type="button" class="btn btn-sm btn-primary d-none cc-scan-btn"><i class="bi bi-magic me-1"></i>Scan</button>@endif
                <span class="small text-muted cc-scan-hint"></span>
            </div>
            <div class="small text-success mt-1 d-none cc-ocr-details"></div>
            {{-- Current attachment shown when editing an item (will be replaced on re-upload). --}}
            <div class="small mt-1 d-none cc-edit-att"></div>
        </div>
    </div>

    {{-- Supporting documents — optional, NOT scanned; extra proof (approval, MC, breakdown). --}}
    <div class="row g-2 mt-1">
        <div class="col-12">
            <label class="form-label small mb-1"><i class="bi bi-folder-plus me-1"></i>Supporting documents <span class="text-muted">— optional; attach extra files (e.g. approval, MC, cost breakdown). Multiple allowed; these are not scanned.</span></label>
            <input type="file" class="form-control form-control-sm cc-i-support" accept=".jpg,.jpeg,.png,.pdf" multiple style="max-width:340px;">
        </div>
    </div>

    {{-- Expense Type + amounts --}}
    <div class="row g-2 mt-1">
        <div class="col-md-4">
            <label class="form-label small mb-1">Expense Type (Category) <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm cc-i-cat">
                <option value="">-- Select --</option>
                @foreach($categories as $cat)
                @php $catLabel = ($cat->gl_code ? $cat->gl_code.': ' : '').$cat->name.($cat->code === 'PARKING_JAYAONE' ? ' — Season pass (flat RM80)' : ''); @endphp
                <option value="{{ $cat->id }}" data-rate-type="{{ $cat->rate_type }}" data-rate-amount="{{ $cat->rate_amount ?? '' }}" data-gl-code="{{ $cat->gl_code }}" data-mileage="{{ $cat->isMileageClaim() ? '1' : '0' }}">{{ $catLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Amount (w/o SST) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0" max="99999.99" class="form-control form-control-sm cc-i-amount">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">SST (RM)</label>
            <input type="number" step="0.01" min="0" max="99999.99" class="form-control form-control-sm cc-i-gst" value="0">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Amount (w/ SST)</label>
            <input type="number" step="0.01" class="form-control form-control-sm fw-bold cc-i-total" readonly>
        </div>
        <div class="col-12">
            <span class="cc-cap-hint small d-none"></span>
            {{-- Friendly counter-check: warns (doesn't block) if the claimed amount ≠ the receipt total read by the scan. --}}
            <div class="cc-receipt-check small d-none mt-1 py-2 px-3 rounded" style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;"></div>
        </div>
    </div>

    {{-- Mileage row — shown only for the Petrol/mileage category (amount = km × vehicle rate) --}}
    <div class="row g-2 mt-1 cc-mileage-row d-none">
        <div class="col-md-3">
            <label class="form-label small mb-1">Vehicle <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm cc-i-vehicle">
                <option value="car" data-rate="{{ (float) config('claims.mileage.rates.car', 0.7) }}">Car — RM{{ number_format((float) config('claims.mileage.rates.car', 0.7), 2) }}/km</option>
                <option value="motorcycle" data-rate="{{ (float) config('claims.mileage.rates.motorcycle', 0.35) }}">Motorcycle — RM{{ number_format((float) config('claims.mileage.rates.motorcycle', 0.35), 2) }}/km</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Distance (km) <span class="text-danger">*</span></label>
            <input type="number" step="0.1" min="0" max="99999.99" class="form-control form-control-sm cc-i-km">
        </div>
        <div class="col-md-6 d-flex align-items-end gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-primary cc-calc-dist"><i class="bi bi-signpost-2 me-1"></i>Calculate distance</button>
            <span class="small text-muted cc-mileage-note">Amount = distance × vehicle rate.</span>
        </div>
    </div>

    {{-- Category C — receipt details read by OCR (read-only; fill on Scan). Sent with the item. --}}
    <div class="row g-2 mt-1">
        <div class="col-12">
            <label class="form-label small mb-0 fw-bold"><i class="bi bi-receipt-cutoff me-1 text-info"></i>Receipt details <span class="fw-normal text-muted">(read from the attachment — for the report)</span></label>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Company</label>
            <input type="text" class="form-control form-control-sm bg-light cc-c-company" readonly placeholder="—">
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1">Item description</label>
            <input type="text" class="form-control form-control-sm bg-light cc-c-itemdesc" readonly placeholder="—">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Date</label>
            <input type="text" class="form-control form-control-sm bg-light cc-c-date" readonly placeholder="—">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Who paid</label>
            <input type="text" class="form-control form-control-sm bg-light cc-c-paidby" readonly placeholder="—">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Total paid (RM)</label>
            <input type="text" class="form-control form-control-sm bg-light cc-c-total" readonly placeholder="—">
        </div>
        <div class="col-12 cc-c-calc-wrap d-none">
            <label class="form-label small mb-1">Mileage calculation</label>
            <input type="text" class="form-control form-control-sm bg-light cc-c-calc" readonly placeholder="—">
        </div>
    </div>

    <div class="text-danger small mt-1 d-none cc-item-error"></div>
    <div class="mt-2 d-flex gap-2">
        <button type="button" class="btn btn-success btn-sm cc-add-item-btn"><i class="bi bi-plus-circle me-1"></i>Add to list</button>
        <button type="button" class="btn btn-outline-secondary btn-sm cc-clear-entry"><i class="bi bi-eraser me-1"></i>Clear</button>
        <button type="button" class="btn btn-outline-secondary btn-sm d-none cc-cancel-edit"><i class="bi bi-x-lg me-1"></i>Cancel edit</button>
    </div>
</div>
@endif

{{-- ── Items table ── --}}
<div class="cc-items mt-3">
    <h6 class="fw-semibold mb-2"><i class="bi bi-list-check me-1"></i>Items <span class="badge bg-secondary cc-item-count">{{ $claim->item_count }}</span></h6>
    @if($editable)
    <div class="alert py-2 px-3 small mb-2" style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;">
        <i class="bi bi-info-circle me-1"></i>Edit of any item in the list require deletion.
    </div>
    @endif
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-2 bg-white">
            <thead class="table-light">
                <tr>
                    <th>Date</th><th>Description</th><th>Expense Type</th>
                    <th class="text-end">w/o SST</th><th class="text-end">SST</th><th class="text-end">w/ SST</th>
                    <th>Attachment</th>@if($editable)<th></th>@endif
                </tr>
            </thead>
            <tbody class="cc-items-body">
                @forelse($claim->items as $it)
                <tr data-item-row="{{ $it->id }}"
                    data-date-input="{{ $it->expense_date->format('Y-m-d') }}"
                    data-desc="{{ $it->description }}"
                    data-cat-id="{{ $it->expense_category_id }}"
                    data-amount="{{ number_format($it->amount, 2, '.', '') }}"
                    data-gst="{{ number_format($it->gst_amount, 2, '.', '') }}"
                    data-km="{{ $it->ocr_details['km'] ?? '' }}"
                    data-vehicle="{{ $it->ocr_details['vehicle'] ?? '' }}"
                    data-ocr="{{ $it->ocr_details ? json_encode($it->ocr_details) : '' }}"
                    data-receipt-url="{{ count($it->attachmentPaths()) > 0 ? route('user.claims.items.receipt', $it) : '' }}"
                    data-has-receipt="{{ count($it->attachmentPaths()) > 0 ? '1' : '' }}"
                    data-receipt-hash="{{ $it->receipt_hash ?: '' }}">
                    <td class="text-nowrap">{{ $it->expense_date->format('d/m/Y') }}</td>
                    <td>{{ $it->description }}</td>
                    <td><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">{{ $it->category->name ?? '—' }}</span></td>
                    <td class="text-end">RM {{ number_format($it->amount, 2) }}</td>
                    <td class="text-end">RM {{ number_format($it->gst_amount, 2) }}</td>
                    <td class="text-end fw-semibold">RM {{ number_format($it->total_with_gst, 2) }}</td>
                    <td>
                        @if(count($it->attachmentPaths()) > 0)
                        <a href="{{ route('user.claims.items.receipt', $it) }}" target="_blank" class="text-success" title="View attachment"><i class="bi bi-paperclip"></i></a>
                        @else<span class="text-muted small">—</span>@endif
                    </td>
                    @if($editable)
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 cc-remove-item" data-item-id="{{ $it->id }}" title="Delete (to change an item, delete it and add it again)"><i class="bi bi-trash"></i></button>
                    </td>
                    @endif
                </tr>
                @if($it->reject_comment)
                <tr>
                    <td></td>
                    <td colspan="{{ $editable ? 7 : 6 }}" class="small" style="background:#fff7ed;color:#9a3412;">
                        <i class="bi bi-flag-fill me-1"></i><strong>Reviewer flagged this item:</strong> {{ $it->reject_comment }}
                    </td>
                </tr>
                @endif
                @empty
                <tr class="cc-empty-row"><td colspan="{{ $editable ? 8 : 7 }}" class="text-center text-muted py-3">No items yet.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="5" class="text-end">Claim total</td>
                    <td class="text-end cc-claim-total">RM {{ number_format($claim->total_with_gst, 2) }}</td>
                    <td></td>@if($editable)<td></td>@endif
                </tr>
            </tfoot>
        </table>
    </div>
</div>

{{-- ── Actions ── --}}
<div class="d-flex gap-2 flex-wrap justify-content-end align-items-center mt-3">
    {{-- Auto-save status — the claim header is saved automatically as you type (no Save button). --}}
    <span class="cc-autosave-status small text-muted me-auto"><i class="bi bi-cloud-check me-1"></i>All changes saved automatically</span>
    <a href="{{ route('user.claims.report-print', $claim) }}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>{{ $editable ? 'Preview Report' : 'View Report' }}</a>
    @if($editable)
    <button type="button" class="btn btn-sm btn-outline-danger cc-delete-claim"><i class="bi bi-trash me-1"></i>Delete Whole Claim</button>
    <button type="button" class="btn btn-sm btn-success cc-submit-claim" data-approver="{{ $approverName ?: 'your approver' }}" {{ $claim->item_count == 0 ? 'disabled' : '' }}><i class="bi bi-send me-1"></i>Submit claim</button>

    {{-- Hidden forms posted by the buttons after the Category B details are saved. --}}
    <form action="{{ route('user.claims.discard', $claim) }}" method="POST" class="cc-delete-form d-none">@csrf @method('DELETE')</form>
    <form action="{{ route('user.claims.inline-submit', $claim) }}" method="POST" class="cc-submit-form d-none">@csrf</form>
    @endif
</div>
