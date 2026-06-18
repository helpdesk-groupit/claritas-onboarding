@extends('layouts.app')
@section('title', 'Claim Form — ' . $claim->claim_number)

@section('content')
<div class="container py-4">
    <div class="d-print-none mb-3 d-flex gap-2">
        <a href="{{ route('user.claims.reports') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to reports</a>
        <button class="btn btn-sm btn-primary" id="printBtn"><i class="bi bi-printer me-1"></i>Print / Save as PDF</button>
        @if($claim->statusBadge())<span class="badge bg-{{ $claim->statusBadge()['class'] }} align-self-center">{{ $claim->statusBadge()['label'] }}</span>@endif
    </div>

    <div class="claim-form bg-white p-4 shadow-sm" style="max-width:900px;margin:0 auto;font-size:.85rem;">
        @include('partials.claim-letterhead', [
            'company' => $company,
            'employee' => $claim->employee,
            'event' => $claim->event,
            'showRules' => true,
            'claimDate' => $claim->submitted_at ?? \Carbon\Carbon::create($claim->year, $claim->month, 1),
        ])

        <table class="table table-bordered align-middle" style="font-size:.78rem;">
            <thead class="text-center">
                <tr>
                    <th>Date</th>
                    <th>Expense Description</th>
                    <th>Project/Client Name</th>
                    <th>Expense Type</th>
                    <th>RM<br>(w/o GST)</th>
                    <th>RM<br>(GST)</th>
                    <th>Total<br>(w/ GST)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                <tr>
                    <td class="text-nowrap">{{ $item->expense_date->format('jS M Y') }}</td>
                    <td>{{ $item->description }}@if($item->isRejected()) <span class="badge bg-danger">REJECTED</span>@endif</td>
                    <td>{{ $item->project_client ?: 'N/A' }}</td>
                    <td>{{ $item->category->gl_code ? $item->category->gl_code.': ' : '' }}{{ strtoupper($item->category->name ?? '') }}</td>
                    <td class="text-end">RM{{ number_format($item->amount, 2) }}</td>
                    <td class="text-end">{{ $item->gst_amount > 0 ? 'RM'.number_format($item->gst_amount, 2) : '-' }}</td>
                    <td class="text-end">RM{{ number_format($item->total_with_gst, 2) }}</td>
                </tr>
                @endforeach
                @for($i = $items->count(); $i < 14; $i++)
                <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td class="text-end">-</td></tr>
                @endfor
            </tbody>
            <tfoot>
                <tr class="fw-bold text-end">
                    <td colspan="4"></td>
                    <td>{{ number_format($items->sum('amount'), 2) }}</td>
                    <td>{{ number_format($items->sum('gst_amount'), 2) }}</td>
                    <td>{{ number_format($items->sum('total_with_gst'), 2) }}</td>
                </tr>
            </tfoot>
        </table>

        <div class="row mt-5">
            <div class="col-6">
                <div>Staff :- {{ $claim->employee->full_name }}</div>
                <div class="mt-4 pt-3 border-top" style="width:75%;">Signature / Date :</div>
                <div class="mt-4">Approving Manager :- {{ $approver->full_name ?? '(see each manager group)' }}</div>
                <div class="mt-4 pt-3 border-top" style="width:75%;">Signature / Date :</div>
            </div>
            <div class="col-6">
                <div>Checked by / Date :-</div>
                <div class="text-muted small">(HR/Finance)</div>
                <div class="mt-5 pt-3 border-top" style="width:75%;">Date :</div>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
@media print {
    .d-print-none, .app-sidebar, .sidebar, nav.navbar, .topbar { display: none !important; }
    .content-area, .container, body { background: #fff !important; }
    .claim-form { box-shadow: none !important; max-width: 100% !important; padding: 0 !important; }
    @page { margin: 12mm; }
}
</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    document.getElementById('printBtn')?.addEventListener('click', () => window.print());
</script>
@endpush
@endsection
