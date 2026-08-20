{{-- Company Asset Decommissioning — review surface, part 2 of 2 (the working queue). Replaces
     the former flat "E-waste Cycles" archive (which mixed every cycle, completed included, in
     one ungrouped table with an All/Active/Completed filter) — completed cycles now live
     exclusively on the Reports tab (AssetController::ewasteCycleReportsFor()), so every cycle
     here is genuinely still in flight.

     Nested Year → Month → Company, ALL THREE collapsible. IDs are prefixed `ewxAct*` —
     distinct from the Reports pane's `ewxRep*` — because both partials render into the DOM on
     the same page (page.blade.php ships every tab-pane regardless of which is active), so the
     two accordions must never collide on id.

     On page.blade.php this renders AFTER the IT-only "Operations" block (Run sweep / Ready for
     the next sweep) so the working queue reads as one continuous Operations zone; on the
     Finance/management-only decommission-review.blade.php it follows the summary partial
     directly, since that host has no Operations block at all.

     Expects: $activeByCompany (Year => [Y-m => [Company => Collection<AssetDecommissionBatch>]],
     already sorted by the controller, newest first), $cdFilters — built by
     AssetController::buildDecommissionReview(). $cdFilters is the year/month/company/status
     panel rendered at the bottom of the summary partial just above this include; it has
     already been applied to $activeByCompany server-side, so this partial only reads it back
     to phrase the empty state. --}}
@php
    $vndActGroupCount = fn ($companies) => $companies->sum(fn ($batches) => $batches->count());
    $vndActTotal = $activeByCompany->sum(fn ($months) => $months->sum($vndActGroupCount));
    $vndActFirstYear = $activeByCompany->keys()->first();
    $vndActFiltered = ! empty(array_filter($cdFilters));
@endphp
<div class="card ewx-card">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-slate"><i class="bi bi-hourglass-split"></i></span>
        <div class="me-2">
            <span class="ewx-title">Cycles by Company</span>
            <span class="ewx-sub">Every disposal cycle still in flight — gathering quotes, awaiting a decision, or awaiting collection &amp; payment. Completed cycles are on the Reports tab.</span>
        </div>
        @if($activeByCompany->isNotEmpty())
        <span class="ewx-count">{{ $vndActTotal }}</span>
        @endif
    </div>
    <div class="card-body">
        @if($activeByCompany->isEmpty())
            <div class="ewx-empty">
                <i class="bi bi-inbox"></i>
                No cycle currently in flight{{ $vndActFiltered ? ' matching these filters' : '' }}.
            </div>
        @else
        @foreach($activeByCompany as $yr => $yearMonths)
        @php
            $yrOpen = $yr === $vndActFirstYear;
            $yrCount = $yearMonths->sum($vndActGroupCount);
        @endphp
        <div class="ewx-year-group">
            <button type="button" class="ewx-year-head" data-bs-toggle="collapse" data-bs-target="#ewxActY-{{ $yr }}"
                    aria-expanded="{{ $yrOpen ? 'true' : 'false' }}">
                <span class="ewx-year-head-left">
                    <span class="ewx-chip ewx-chip-slate"><i class="bi bi-calendar3"></i></span>
                    <span>
                        <span class="ewx-year-title d-block">{{ $yr }}</span>
                        <span class="ewx-year-sub">{{ $yrCount }} cycle{{ $yrCount == 1 ? '' : 's' }}</span>
                    </span>
                </span>
                <i class="bi bi-chevron-down ewx-year-chevron"></i>
            </button>
            <div class="collapse {{ $yrOpen ? 'show' : '' }}" id="ewxActY-{{ $yr }}">
                <div class="ewx-year-body">
                    @foreach($yearMonths as $monthKey => $companies)
                    @php
                        [$gy, $gm] = explode('-', $monthKey);
                        $monthOpen = $yrOpen && $loop->first;
                        $moCount = $vndActGroupCount($companies);
                        $moId = 'ewxActM-'.$monthKey;
                        $vndFirstCompany = $companies->keys()->first();
                    @endphp
                    <div class="ewx-month-group">
                        <button type="button" class="ewx-month-head" data-bs-toggle="collapse" data-bs-target="#{{ $moId }}"
                                aria-expanded="{{ $monthOpen ? 'true' : 'false' }}">
                            <span class="d-flex align-items-center gap-2">
                                <span class="ewx-month-title">{{ \Carbon\Carbon::createFromDate($gy, $gm, 1)->format('F Y') }}</span>
                                <span class="ewx-month-sub">{{ $moCount }} cycle{{ $moCount == 1 ? '' : 's' }}</span>
                            </span>
                            <i class="bi bi-chevron-down ewx-month-chevron"></i>
                        </button>
                        <div class="collapse {{ $monthOpen ? 'show' : '' }}" id="{{ $moId }}">
                            <div class="ewx-month-body">
                                @foreach($companies as $companyName => $batches)
                                @php
                                    // Only the first company under the month that opens by
                                    // default starts expanded — every other company still
                                    // needs a click, same cascading "open the newest path"
                                    // rule the year/month levels already follow.
                                    $coOpen = $monthOpen && $companyName === $vndFirstCompany;
                                    $coId = 'ewxActC-'.substr(md5($monthKey.'|'.$companyName), 0, 10);
                                @endphp
                                <div class="ewx-company-group">
                                    <button type="button" class="ewx-company-toggle" data-bs-toggle="collapse" data-bs-target="#{{ $coId }}"
                                            aria-expanded="{{ $coOpen ? 'true' : 'false' }}">
                                        <span class="ewx-company-head">
                                            <i class="bi bi-building"></i>{{ $companyName }}
                                            <span class="text-muted fw-normal text-lowercase">&middot; {{ $batches->count() }} cycle{{ $batches->count() == 1 ? '' : 's' }}</span>
                                        </span>
                                        <i class="bi bi-chevron-down ewx-company-chevron"></i>
                                    </button>
                                    <div class="collapse {{ $coOpen ? 'show' : '' }}" id="{{ $coId }}">
                                        <div class="table-responsive">
                                            <table class="table table-hover ewx-table">
                                                <thead>
                                                    <tr>
                                                        <th class="ps-3">Cycle</th>
                                                        <th class="text-center">Qty</th>
                                                        <th>Vendor</th>
                                                        <th class="text-end">Amount (RM)</th>
                                                        <th>Date</th>
                                                        <th>Status</th>
                                                        <th class="text-end pe-3">Report</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($batches as $batch)
                                                    @php
                                                        [$badgeClass, $badgeLabel] = $batch->ewasteStageBadge();
                                                        $amt = $batch->reportAmount();
                                                    @endphp
                                                    <tr>
                                                        <td class="ps-3">
                                                            <a href="{{ route('decommission.show', $batch) }}" class="ewx-code">{{ $batch->batch_number }}</a>
                                                        </td>
                                                        <td class="text-center">{{ $batch->items_count }}</td>
                                                        <td>{{ $batch->decidedVendorName() ?? '—' }}</td>
                                                        {{-- An in-flight offer is money nobody has been paid, so it prints as a
                                                             muted "offer" rather than a +credit — same guard as the headline
                                                             stat tiles. --}}
                                                        <td class="text-end {{ $amt !== null ? 'text-muted' : '' }}">
                                                            @if($amt === null)
                                                                —
                                                            @else
                                                                {{ number_format($amt, 2) }} offer
                                                            @endif
                                                        </td>
                                                        <td>{{ fmt_date($batch->created_at) }}</td>
                                                        <td><span class="badge rounded-pill bg-{{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                                                        <td class="text-end pe-3">
                                                            <div class="btn-group btn-group-sm" role="group">
                                                                <a href="{{ route('reports.decommission.view', $batch) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener" title="View report">
                                                                    <i class="bi bi-eye me-1"></i>View
                                                                </a>
                                                                <a href="{{ route('reports.decommission.pdf', $batch) }}" class="btn btn-outline-primary" title="Download PDF">
                                                                    <i class="bi bi-download me-1"></i>PDF
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endforeach
        @endif
    </div>
</div>
