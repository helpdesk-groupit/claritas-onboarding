{{-- Company Asset Decommissioning — "Reports" tab content. Every non-cancelled e-waste cycle,
     nested Year → Month → Company → Report, the same shape the claim/ticket report listings use
     elsewhere in the app. Reuses the SAME batches, the SAME PDF and the SAME view/download
     routes as the rest of the module; this is another access point onto the same records, not a
     second report-generation path.

     Every non-cancelled cycle, not completed-only — a cycle both Finance and management have
     already approved but the vendor has not yet collected/paid still needs somewhere to appear.
     Grouping (not pagination) is what keeps this readable at scale — older years/months collapse
     by default instead of paging.

     Included by TWO hosts, both wrapping it in their own tab-pane div:
       - it/assets/page.blade.php — for canViewAssets() holders (IT/HR/superadmin).
       - it/assets/decommission-review.blade.php — for Finance + named management approvers,
         who reach the identical pane content scoped to the companies they may read (see
         AssetController::ewasteCycleReportsFor()).

     Expects: $reportGroups (Year => [Y-m => [Company => Collection<AssetDecommissionBatch>]],
     each level already sorted by the controller), $reportsCount. --}}
@php
    $vndFinished = fn ($b) => $b->isFinalized() || $b->status === 'completed';
    $vndGroupCount = fn ($companies) => $companies->sum(fn ($batches) => $batches->count());
    $vndGroupRecovered = fn ($companies) => $companies->sum(
        fn ($batches) => $batches->filter($vndFinished)->sum(fn ($b) => $b->reportAmount() ?? 0)
    );
    $vndFirstYear = $reportGroups->keys()->first();
@endphp
<div class="card" style="border-top-left-radius:0;border-top-right-radius:0;">
    <div class="card-body">
        @if($reportGroups->isEmpty())
            <p class="text-muted small mb-0">No decommissioning cycle recorded yet.</p>
        @else
        @foreach($reportGroups as $yr => $yearMonths)
        @php
            $yrOpen = $yr === $vndFirstYear;
            $yrCount = $yearMonths->sum(fn ($c) => $vndGroupCount($c));
            $yrRecovered = $yearMonths->sum(fn ($c) => $vndGroupRecovered($c));
        @endphp
        <div class="ewx-year-group">
            <button type="button" class="ewx-year-head" data-bs-toggle="collapse" data-bs-target="#ewxRepY-{{ $yr }}"
                    aria-expanded="{{ $yrOpen ? 'true' : 'false' }}">
                <span class="ewx-year-head-left">
                    <span class="ewx-chip ewx-chip-slate"><i class="bi bi-calendar3"></i></span>
                    <span>
                        <span class="ewx-year-title d-block">{{ $yr }}</span>
                        <span class="ewx-year-sub">{{ $yrCount }} report{{ $yrCount == 1 ? '' : 's' }}</span>
                    </span>
                </span>
                <span class="ewx-year-head-right">
                    <span class="ewx-year-total" title="Recovered from finished cycles only">RM {{ number_format($yrRecovered, 2) }}</span>
                    <i class="bi bi-chevron-down ewx-year-chevron"></i>
                </span>
            </button>
            <div class="collapse {{ $yrOpen ? 'show' : '' }}" id="ewxRepY-{{ $yr }}">
                <div class="ewx-year-body">
                    @foreach($yearMonths as $monthKey => $companies)
                    @php
                        [$gy, $gm] = explode('-', $monthKey);
                        $monthOpen = $yrOpen && $loop->first;
                        $moCount = $vndGroupCount($companies);
                        $moRecovered = $vndGroupRecovered($companies);
                        $moId = 'ewxRepM-'.$monthKey;
                    @endphp
                    <div class="ewx-month-group">
                        <button type="button" class="ewx-month-head" data-bs-toggle="collapse" data-bs-target="#{{ $moId }}"
                                aria-expanded="{{ $monthOpen ? 'true' : 'false' }}">
                            <span class="d-flex align-items-center gap-2">
                                <span class="ewx-month-title">{{ \Carbon\Carbon::createFromDate($gy, $gm, 1)->format('F Y') }}</span>
                                <span class="ewx-month-sub">{{ $moCount }} report{{ $moCount == 1 ? '' : 's' }}</span>
                            </span>
                            <span class="d-flex align-items-center gap-3">
                                <span class="ewx-month-total" title="Recovered from finished cycles only">RM {{ number_format($moRecovered, 2) }}</span>
                                <i class="bi bi-chevron-down ewx-month-chevron"></i>
                            </span>
                        </button>
                        <div class="collapse {{ $monthOpen ? 'show' : '' }}" id="{{ $moId }}">
                            <div class="ewx-month-body">
                                @foreach($companies as $companyName => $batches)
                                <div class="ewx-company-group">
                                    <div class="ewx-company-head">
                                        <i class="bi bi-building"></i>{{ $companyName }}
                                        <span class="text-muted fw-normal text-lowercase">&middot; {{ $batches->count() }} report{{ $batches->count() == 1 ? '' : 's' }}</span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                                            <thead style="background:#f8fafc;">
                                                <tr><th>Cycle</th><th>Status</th><th>Date</th><th>Assets</th><th>Vendor</th><th class="text-end">Amount (RM)</th><th class="text-end">Report</th></tr>
                                            </thead>
                                            <tbody>
                                            @foreach($batches as $b)
                                                @php
                                                    [$badgeClass, $badgeLabel] = $b->ewasteStageBadge();
                                                    $amt = $b->reportAmount();
                                                    $done = $vndFinished($b);
                                                @endphp
                                                <tr>
                                                    <td class="fw-semibold"><a href="{{ route('decommission.show', $b) }}" class="ewx-code">{{ $b->batch_number }}</a></td>
                                                    <td><span class="badge rounded-pill bg-{{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                                                    <td class="text-muted">{{ fmt_date($b->finalized_at ?? $b->created_at) }}</td>
                                                    <td>{{ $b->items_count }}</td>
                                                    <td>{{ $b->decidedVendorName() ?? '—' }}</td>
                                                    {{-- An offer on an unfinished cycle is money nobody has been paid, so it prints as a
                                                         muted "offer" rather than a +credit — same guard as the headline stat tiles. --}}
                                                    <td class="text-end {{ $amt !== null && $done ? 'ewx-amt' : 'text-muted' }}">
                                                        @if($amt === null)
                                                            —
                                                        @elseif($done)
                                                            +{{ number_format($amt, 2) }}
                                                        @else
                                                            {{ number_format($amt, 2) }} offer
                                                        @endif
                                                    </td>
                                                    <td class="text-end">
                                                        @if(Auth::user()->canViewDecommissionReports())
                                                        <a href="{{ route('reports.decommission.view', $b) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="View report">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="{{ route('reports.decommission.pdf', $b) }}" class="btn btn-sm btn-outline-secondary" title="Download report">
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                        @else
                                                        <span class="text-muted small">—</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
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
