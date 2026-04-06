@extends('layouts.app')
@section('title', 'Depreciation Schedule — ' . $asset->name)
@section('page-title', 'Depreciation Schedule')

@section('content')
@include('accounting.partials.nav')
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-4" style="font-size:13px;">
        <div><strong>Asset:</strong> {{ $asset->asset_code }} — {{ $asset->name }}</div>
        <div><strong>Purchase Cost:</strong> RM {{ number_format($asset->purchase_cost, 2) }}</div>
        <div><strong>Residual Value:</strong> RM {{ number_format($asset->residual_value, 2) }}</div>
        <div><strong>Useful Life:</strong> {{ $asset->useful_life_months }} months</div>
        <div><strong>Monthly Depreciation:</strong> RM {{ number_format($asset->monthly_depreciation, 2) }}</div>
        <div><strong>Current Book Value:</strong> RM {{ number_format($asset->current_value, 2) }}</div>
        <div><strong>Status:</strong> <span class="badge bg-{{ $asset->status === 'active' ? 'success' : ($asset->status === 'disposed' ? 'danger' : 'secondary') }}">{{ ucwords(str_replace('_',' ',$asset->status)) }}</span></div>
    </div>
</div>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Depreciation Entries</h6>
        <a href="{{ route('accounting.fixed-assets.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <thead><tr><th>#</th><th>Period</th><th>Amount (RM)</th><th>Accum. Depreciation (RM)</th><th>Book Value (RM)</th><th>Posted</th></tr></thead>
            <tbody>
            @php $accum = 0; @endphp
            @forelse($entries as $i => $entry)
                @php $accum += $entry->amount; @endphp
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ \Carbon\Carbon::parse($entry->depreciation_date)->format('M Y') }}</td>
                    <td>{{ number_format($entry->amount, 2) }}</td>
                    <td>{{ number_format($accum, 2) }}</td>
                    <td>{{ number_format($asset->purchase_cost - $accum, 2) }}</td>
                    <td>@if($entry->journal_entry_id)<span class="badge bg-success">Yes</span>@else<span class="badge bg-secondary">No</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">No depreciation entries recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
