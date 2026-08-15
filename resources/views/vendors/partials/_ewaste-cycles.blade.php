{{-- Phase 6 — e-waste collections this vendor carried out, archived on their own profile.

     The final report already lives in two places (the C-Suite Decommissioning archive and
     Finance's Disposed listing), but neither answers "what has THIS vendor collected for us",
     which is the question asked when a vendor is reviewed or replaced. Filing it here makes the
     vendor profile the single place their whole relationship with us is visible: contracts,
     invoices, the kit we rent from them, the forms they signed, and now the disposals they ran.

     Only cycles this vendor was SELECTED for. A vendor who quoted and lost did no work and
     holds no document — listing the cycle on their profile would read as though they had
     collected it. Their losing offer is still reproduced in the cycle's own report, where it
     belongs as evidence of the price comparison.

     Expects: $ewasteCycles (Collection<AssetDecommissionBatch>), $vendor.
--}}
<div class="ewx-section mt-4">
    <div class="d-flex align-items-center justify-content-between mb-1">
        <div class="fw-semibold">
            <i class="bi bi-recycle me-1 text-success"></i>E-Waste Collections
        </div>
        <span class="badge rounded-pill bg-secondary">{{ $ewasteCycles->count() }}</span>
    </div>
    <div class="text-muted small mb-2">
        Quarterly disposal cycles this vendor was awarded. Reference <span class="fw-semibold">EWA-</span>.
    </div>

    @if($ewasteCycles->isEmpty())
        <div class="ewx-empty">
            <i class="bi bi-recycle"></i>
            This vendor has not been awarded an e-waste collection yet.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th>Reference</th>
                        <th>Company</th>
                        <th class="text-center">Assets</th>
                        <th class="text-end">Amount (RM)</th>
                        <th>Status</th>
                        <th>Report</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($ewasteCycles as $cycle)
                    @php
                        $amount = $cycle->reportAmount();
                        $badge = $cycle->ewasteStageBadge();
                    @endphp
                    <tr>
                        <td>
                            <span class="fw-semibold">{{ $cycle->batch_number }}</span>
                            @if($cycle->finalized_at)
                                <div class="text-muted" style="font-size:11px;">{{ fmt_date($cycle->finalized_at) }}</div>
                            @endif
                        </td>
                        <td>{{ $cycle->company ?: '—' }}</td>
                        <td class="text-center">{{ $cycle->items_count ?? $cycle->items->count() }}</td>
                        <td class="text-end">
                            {{-- Never 0.00 for an unrecorded figure: that would state the vendor
                                 paid us nothing rather than "see the attached document". --}}
                            @if($amount !== null)
                                {{ number_format($amount, 2) }}
                                @if($cycle->receipt_amount === null)
                                    <div class="text-muted" style="font-size:11px;">offer &mdash; not yet received</div>
                                @endif
                            @else
                                <span class="text-muted">see document</span>
                            @endif
                        </td>
                        <td><span class="badge bg-{{ $badge[0] }}">{{ $badge[1] }}</span></td>
                        <td>
                            @if($cycle->report_pdf_path)
                                <a href="{{ route('reports.decommission.view', $cycle) }}" target="_blank" class="text-decoration-none">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>View
                                </a>
                            @else
                                <span class="text-muted small">not yet finalised</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
