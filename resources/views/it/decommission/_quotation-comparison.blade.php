{{--
    Phase 5/6 — the vendor comparison, an optional AI suggestion, IT's recommendation, and the
    two decisions.

    The cycle asks every active e-waste vendor to quote, so this panel is where the offers are
    gathered and ranked, optionally compared by AI, submitted for approval with a
    recommendation, and then decided by both Finance and management. Finance's position is
    recorded but never binding — only management's decision moves the cycle.

    The vendor pays US for scrap, so the BEST offer is the HIGHEST — the sign is the opposite of
    a purchase, and every ordering and label here depends on getting that right.

    ONE partial holds all three roles' controls, rendered with different flags per page — which
    is what keeps "the review lives in one place" true in the code and not just on the screen:

        Cycle detail (IT)  canManage=true   canDecide=false  canFinance=false
        Decommissioning    canManage=false  canDecide=per-company  canFinance=per-gate

    Expects: $batch, $canManage (IT), $canDecide (this user may cast the management decision),
             $canFinance (this user may record Finance's position), $ewasteVendors.
--}}
@php
    // Defaulted rather than required: the cycle page renders this without the finance flag,
    // and an undefined variable there would be a ViewException on IT's own working page.
    $canFinance = $canFinance ?? false;
@endphp
@php
    $comparison = $batch->quotationsForComparison();
    $best = $batch->bestOffer();
    $recommended = $batch->recommendedQuotation;
    $selected = $batch->selectedQuotation;
    $collecting = in_array($batch->status, ['awaiting_quotation', 'quotation_uploaded', 'rejected', 'finance_rejected']);
    // Revision numbering appears only once some vendor has re-quoted — on a cycle with one
    // offer per vendor there is nothing for a revision number to tell apart, and a column of
    // 1s reads as though something were missing.
    $showRevisions = $comparison->contains(fn ($q) => $q->revision > 1);
    // Delete is only ever offered to IT, and only while the cycle is still gathering offers —
    // see AssetDecommissionQuotation::isDeletable() for the rest of the guard.
    $showActions = $canManage && $collecting;
    $colspan = 4 + ($showRevisions ? 1 : 0) + ($showActions ? 1 : 0);
@endphp

<div class="ewx-section mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h6 class="fw-bold mb-0"><i class="bi bi-cash-stack me-2 text-primary"></i>Vendor Quotations</h6>
        <div class="d-flex gap-2">
            <span class="badge bg-{{ $batch->financeDecisionBadge()[0] }}">{{ $batch->financeDecisionBadge()[1] }}</span>
            <span class="badge bg-{{ $batch->managementDecisionBadge()[0] }}">{{ $batch->managementDecisionBadge()[1] }}</span>
        </div>
    </div>

    @if($comparison->isEmpty())
        <p class="text-muted small mb-3">
            No quotations filed yet. Every active e-waste vendor has been sent the RFQ; file each reply below.
        </p>
    @else
        <div class="table-responsive mb-2">
            <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th>Vendor</th>
                        @if($showRevisions)<th>Revision</th>@endif
                        <th class="text-end">Offer (RM)</th>
                        <th>Document</th>
                        <th>Status</th>
                        @if($showActions)<th class="text-end">Actions</th>@endif
                    </tr>
                </thead>
                <tbody>
                @foreach($comparison as $q)
                    @php
                        $isRec = $recommended && $q->id === $recommended->id;
                        $isSel = $selected && $q->id === $selected->id;
                        $rowClass = $isSel ? 'table-success' : ($isRec ? 'table-light' : '');
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td>
                            <span class="fw-semibold">{{ $q->vendorName() }}</span>
                            @if($best && $q->id === $best->id)
                                <span class="badge bg-success ms-1" title="Pays us the most">Best offer</span>
                            @endif
                            @if($isSel)<span class="badge bg-primary ms-1">Selected</span>
                            @elseif($isRec)<span class="badge bg-info text-dark ms-1">IT recommends</span>@endif
                        </td>
                        @if($showRevisions)<td>{{ $q->revision }}</td>@endif
                        <td class="text-end">
                            @if($q->amount !== null)
                                {{ number_format((float) $q->amount, 2) }}
                            @else
                                {{-- A missing figure blocks submission, so it must be visibly a
                                     gap rather than a dash that reads as "nothing offered". --}}
                                <span class="text-danger fw-semibold">Not recorded</span>
                            @endif
                            @if($canManage && $collecting)
                                @include('it.decommission._amount-fix', [
                                    'batch' => $batch, 'field' => 'quotation',
                                    'value' => $q->amount, 'quotationId' => $q->id,
                                ])
                            @endif
                        </td>
                        <td>
                            @if($q->path)
                                <a href="{{ secure_file_url($q->path) }}" target="_blank" class="text-decoration-none">
                                    <i class="bi bi-file-earmark-text me-1"></i>View
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @php $d = $q->decisionBadge(); @endphp
                            <span class="badge bg-{{ $d[0] }}">{{ $d[1] }}</span>
                        </td>
                        @if($showActions)
                        <td class="text-end">
                            @if($q->isDeletable())
                            <form action="{{ route('ewaste.quotation.delete', [$batch, $q]) }}" method="POST" class="js-confirm d-inline"
                                  data-confirm="Delete the quotation from {{ $q->vendorName() }}? The uploaded document is removed — this cannot be undone."
                                  data-confirm-title="Delete quotation" data-confirm-ok="Delete" data-confirm-variant="danger">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this quotation">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        @endif
                    </tr>
                    {{-- AI reading — a short summary from the same read that transcribes the
                         document for "Ask AI to compare quotations", so it costs nothing extra
                         to show once that has run at least once. --}}
                    <tr class="{{ $rowClass }}">
                        <td colspan="{{ $colspan }}" class="pt-0 pb-2 border-top-0">
                            <div class="small text-muted">
                                @if($q->hasAiSummary())
                                    <i class="bi bi-stars me-1 text-primary"></i>{{ $q->ai_summary }}
                                @else
                                    <i class="bi bi-info-circle me-1"></i>{{ $q->aiSummaryUnavailableReason() }}
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if($batch->recommendation_note)
            <p class="small text-muted mb-2"><strong>IT's reason:</strong> {{ $batch->recommendation_note }}</p>
        @endif
    @endif

    {{-- ── File a quotation (one per vendor; a re-quote is a new revision) ── --}}
    @if($canManage && $collecting)
    <form action="{{ route('ewaste.quotation', $batch) }}" method="POST" enctype="multipart/form-data" class="dcm-upload mb-3">@csrf
        @if($batch->management_status === 'rejected' && $batch->management_remarks)
        <div class="alert alert-warning py-2 px-3 mb-3 small">
            <div class="fw-semibold mb-1"><i class="bi bi-arrow-repeat me-1"></i>Management rejected this disposal</div>
            <div>{{ $batch->management_remarks }}</div>
        </div>
        @endif
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Vendor</label>
                <select name="vendor_id" class="form-select form-select-sm" required>
                    <option value="">— which vendor sent this? —</option>
                    @foreach($ewasteVendors as $v)
                        <option value="{{ $v->id }}">{{ $v->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Quotation file (PDF/image)</label>
                <input type="file" name="quotation_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                <div class="form-text">Leave the amount blank and the system reads it from this document.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Offer amount (RM) <span class="text-muted fw-normal">— optional</span></label>
                <input type="number" step="0.01" min="0.01" name="quotation_amount" class="form-control form-control-sm" placeholder="Read from document">
            </div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-upload me-1"></i>File</button></div>
        </div>
        <div class="form-text mt-1">
            File one quotation per vendor — filing a new vendor's quotation never replaces or removes another vendor's offer, and a re-quote from the same vendor is kept as a new revision alongside the one it replaces.
        </div>
    </form>
    @endif

    {{-- ── AI comparison — reads every quotation's full content, not just the amount ──
         Explicit, never automatic: it is a billed AI call, so it only runs when IT asks
         for it. The result PRE-FILLS the Recommend form below; submitForApproval() is
         still what the module treats as IT's actual recommendation, so IT can accept the
         suggestion as-is or overwrite it before submitting. --}}
    @if($canManage && $collecting)
    <form action="{{ route('ewaste.compare', $batch) }}" method="POST" class="mb-2">@csrf
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-stars me-1"></i>Ask AI to compare quotations</button>
        <span class="text-muted small ms-1">Reads every quotation in full and suggests one — the choice is still yours.</span>
    </form>
    @if($batch->ai_recommended_at)
    <div class="alert alert-{{ $batch->ai_compare_status === 'ok' ? 'info' : 'secondary' }} py-2 px-3 small mb-3">
        @if($batch->ai_compare_status === 'ok' && $batch->aiRecommendedQuotation)
            <i class="bi bi-stars me-1"></i>
            <strong>AI suggests: {{ $batch->aiRecommendedQuotation->vendorName() }}</strong>
            @if($batch->aiRecommendedQuotation->amount !== null) — RM {{ number_format((float) $batch->aiRecommendedQuotation->amount, 2) }}@endif
            @if($batch->ai_recommendation_note)<div class="mt-1">{{ $batch->ai_recommendation_note }}</div>@endif
            <div class="text-muted mt-1">Pre-filled in the Recommend form below — pick differently if you disagree.</div>
        @elseif($batch->ai_compare_status === 'disabled')
            <i class="bi bi-info-circle me-1"></i>AI document reading is not configured. Pick a recommendation by hand below.
        @elseif($batch->ai_compare_status === 'empty')
            <i class="bi bi-info-circle me-1"></i>There is nothing to compare yet — file at least one quotation first.
        @else
            <i class="bi bi-exclamation-circle me-1"></i>AI comparison could not be completed this time. Pick a recommendation by hand below.
        @endif
    </div>
    @endif
    @endif

    {{-- ── Submit the comparison for approval ── --}}
    @if($canManage && $collecting && $comparison->isNotEmpty())
        @if(! $batch->everyQuotationHasAnAmount())
            <div class="alert alert-warning py-2 px-3 small mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Fill in every missing offer amount above before submitting — the offers cannot be ranked without them.
            </div>
        @else
        @php
            // AI's suggestion wins the default when there is one; otherwise fall back to the
            // mechanical highest offer, as before. Either way this is only ever a DEFAULT —
            // IT picks the option and can change it before submitting.
            $defaultPick = $batch->ai_compare_status === 'ok' && $batch->ai_recommended_quotation_id
                ? $batch->ai_recommended_quotation_id
                : $best?->id;
        @endphp
        <form action="{{ route('ewaste.submit', $batch) }}" method="POST" class="ewx-subsection">@csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Recommend</label>
                    <select name="recommended_quotation_id" class="form-select form-select-sm" required>
                        @foreach($comparison as $q)
                            <option value="{{ $q->id }}" {{ $defaultPick === $q->id ? 'selected' : '' }}>
                                {{ $q->vendorName() }} — RM {{ number_format((float) $q->amount, 2) }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Defaults to {{ $batch->ai_compare_status === 'ok' ? "the AI's suggestion" : 'the offer that pays us most' }} — pick a different one if you disagree.</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Why <span class="text-muted fw-normal">— optional</span></label>
                    <input type="text" name="recommendation_note" class="form-control form-control-sm" maxlength="1000"
                           value="{{ old('recommendation_note', $batch->ai_compare_status === 'ok' ? $batch->ai_recommendation_note : '') }}"
                           placeholder="e.g. Highest offer and collects from both sites">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-primary w-100"><i class="bi bi-send me-1"></i>Submit for approval</button>
                </div>
            </div>
            <div class="form-text mt-1">
                Sends the comparison to Finance and to {{ $batch->company ?: 'the company' }}'s management together.
                Management's decision is what authorises the disposal.
            </div>
        </form>
        @endif
    @endif

    {{-- ── Finance's position ──
         RECORDED, never binding. Finance approving does not release anything and does not
         move the cycle: only management's decision does. That is what keeps a later
         management rejection able to stop a collection that has not happened yet, so the
         wording here must never suggest this approval authorises the disposal. --}}
    @if($canFinance && $batch->finance_status === 'pending')
    <div class="ewx-subsection mt-3">
        <h6 class="fw-bold small text-uppercase text-muted mb-2">Finance review</h6>

        @if($batch->managementDecided())
            <p class="small mb-2">
                <span class="badge bg-{{ $batch->managementDecisionBadge()[0] }}">{{ $batch->managementDecisionBadge()[1] }}</span>
                @if($batch->management_remarks)<span class="text-muted">— {{ $batch->management_remarks }}</span>@endif
            </p>
        @endif

        @php
            // A reviewer looking at a second quote needs to know why without opening the cycle
            // log — the rejection it answers is the whole context for the new price.
            //
            // Built here rather than inline: the sentence is a chain of optional clauses, and
            // in Blade `@endif@if(` glues `@` to a word character, which stops it being read as
            // a directive at all. It then compiles clean and throws "unexpected end of file"
            // only at RENDER, so view:cache does not catch it.
            $vndUnderReview = $batch->quotationUnderReview();
            $vndRejected = $vndUnderReview && $vndUnderReview->revision > 1 ? $batch->lastRejectedQuotation() : null;
            $vndRequoteNote = null;

            if ($vndUnderReview && $vndUnderReview->revision > 1) {
                $vndRequoteNote = 'Revision '.$vndUnderReview->revision.' from '.$vndUnderReview->vendorName();

                if ($vndRejected) {
                    $vndRequoteNote .= ' — you rejected revision '.$vndRejected->revision;
                    if ($vndRejected->finance_reviewed_at) {
                        $vndRequoteNote .= ' on '.fmt_date($vndRejected->finance_reviewed_at);
                    }
                    if ($vndRejected->finance_remarks) {
                        $vndRequoteNote .= ' because '.$vndRejected->finance_remarks;
                    }
                }
            }
        @endphp
        @if($vndRequoteNote)
            <p class="small text-muted mb-2">
                <i class="bi bi-arrow-repeat me-1"></i>{{ $vndRequoteNote }}
            </p>
        @endif

        <form action="{{ route('finance.ewaste.approve', $batch) }}" method="POST" class="row g-2 align-items-end mb-2">@csrf
            <div class="col-md-9">
                <label class="form-label small fw-semibold">Remarks <span class="text-muted fw-normal">— optional</span></label>
                <input type="text" name="remarks" class="form-control form-control-sm" maxlength="1000">
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm ewx-btn-approve w-100"><i class="bi bi-check2-circle me-1"></i>Approve</button>
            </div>
        </form>

        <form action="{{ route('finance.ewaste.reject', $batch) }}" method="POST" class="row g-2 align-items-end">@csrf
            <div class="col-md-9">
                <label class="form-label small fw-semibold">Or object — reason required</label>
                <input type="text" name="remarks" class="form-control form-control-sm" maxlength="1000" required
                       placeholder="Why is this offer refused?">
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-circle me-1"></i>Object</button>
            </div>
        </form>

        <div class="form-text mt-1">
            Your position is recorded alongside management's, who authorise the disposal.
        </div>
    </div>
    @endif

    {{-- ── The management decision ── --}}
    @if($canDecide && $batch->management_status === 'pending')
    <div class="ewx-subsection mt-3">
        <h6 class="fw-bold small text-uppercase text-muted mb-2">Your decision</h6>

        @if($batch->financeDecided())
            {{-- Finance's position is shown but explicitly not binding — management may
                 approve over an objection, which is the whole point of the override rule. --}}
            <p class="small mb-2">
                <span class="badge bg-{{ $batch->financeDecisionBadge()[0] }}">{{ $batch->financeDecisionBadge()[1] }}</span>
                @if($batch->finance_remarks)<span class="text-muted">— {{ $batch->finance_remarks }}</span>@endif
            </p>
        @else
            <p class="small text-muted mb-2">Finance have not recorded a position yet. You may still decide.</p>
        @endif

        <form action="{{ route('management.ewaste.approve', $batch) }}" method="POST" class="row g-2 align-items-end mb-2">@csrf
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Approve this offer</label>
                <select name="selected_quotation_id" class="form-select form-select-sm">
                    @foreach($comparison as $q)
                        <option value="{{ $q->id }}" {{ $recommended && $q->id === $recommended->id ? 'selected' : '' }}>
                            {{ $q->vendorName() }} — RM {{ $q->amount !== null ? number_format((float) $q->amount, 2) : '—' }}
                            {{ $recommended && $q->id === $recommended->id ? '(IT recommends)' : '' }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">You may pick a different vendor from the one IT recommended.</div>
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-semibold">Remarks <span class="text-muted fw-normal">— optional</span></label>
                <input type="text" name="remarks" class="form-control form-control-sm" maxlength="1000">
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm ewx-btn-approve w-100"><i class="bi bi-check2-circle me-1"></i>Approve disposal</button>
            </div>
        </form>

        <form action="{{ route('management.ewaste.reject', $batch) }}" method="POST" class="row g-2 align-items-end">@csrf
            <div class="col-md-9">
                <label class="form-label small fw-semibold">Or reject — reason required</label>
                <input type="text" name="remarks" class="form-control form-control-sm" maxlength="1000" required
                       placeholder="Why is this disposal refused?">
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-circle me-1"></i>Reject</button>
            </div>
        </form>
    </div>
    @endif

    {{-- ── The decisions, once made ── --}}
    @if($batch->managementDecided())
    <div class="ewx-subsection mt-3">
        <div class="small">
            <div>
                <span class="badge bg-{{ $batch->managementDecisionBadge()[0] }}">{{ $batch->managementDecisionBadge()[1] }}</span>
                @if($batch->managementReviewer) by {{ $batch->managementReviewer->name }}@endif
                @if($batch->management_reviewed_at) on {{ fmt_datetime($batch->management_reviewed_at) }}@endif
                @if($batch->management_remarks) — {{ $batch->management_remarks }}@endif
            </div>
            @if($batch->financeDecided())
            <div class="mt-1">
                <span class="badge bg-{{ $batch->financeDecisionBadge()[0] }}">{{ $batch->financeDecisionBadge()[1] }}</span>
                @if($batch->financeReviewer) by {{ $batch->financeReviewer->name }}@endif
                @if($batch->finance_reviewed_at) on {{ fmt_datetime($batch->finance_reviewed_at) }}@endif
                @if($batch->finance_remarks) — {{ $batch->finance_remarks }}@endif
            </div>
            @endif
            @php $overrode = $recommended && $selected && $recommended->id !== $selected->id; @endphp
            @if($overrode)
            <div class="mt-1 text-warning-emphasis">
                <i class="bi bi-info-circle me-1"></i>
                Management selected {{ $selected->vendorName() }}, not the {{ $recommended->vendorName() }} offer IT recommended.
            </div>
            @endif
        </div>
    </div>
    @endif
</div>
