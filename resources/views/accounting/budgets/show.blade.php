@extends('layouts.app')
@section('title', $budget->name)
@section('page-title', 'Budget vs Actual')

@section('content')
@include('accounting.partials.nav')
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-4 align-items-center" style="font-size:13px;">
        <div><strong>Budget:</strong> {{ $budget->name }}</div>
        <div><strong>Fiscal Year:</strong> {{ $budget->fiscalYear->name ?? '—' }}</div>
        <div><strong>Company:</strong> {{ $budget->company ?? 'All' }}</div>
        <div><span class="badge bg-{{ $budget->status === 'approved' ? 'success' : 'warning' }}">{{ ucfirst($budget->status) }}</span></div>
        <div class="ms-auto d-flex gap-2">
            @if($budget->status === 'draft')
                <form method="POST" action="{{ route('accounting.budgets.approve', $budget) }}">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                <a href="{{ route('accounting.budgets.edit', $budget) }}" class="btn btn-sm btn-outline-primary">Edit</a>
            @endif
            <a href="{{ route('accounting.budgets.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0">Budget vs Actual Comparison</h6></div>
    <div class="card-body p-0" style="overflow-x:auto;">
        <table class="table table-sm mb-0" style="font-size:12px;">
            <thead>
                <tr>
                    <th style="min-width:200px;">Account</th>
                    @for($m = 1; $m <= 12; $m++)
                    <th colspan="2" class="text-center" style="min-width:140px;">{{ date('M', mktime(0,0,0,$m,1)) }}</th>
                    @endfor
                    <th colspan="2" class="text-center" style="min-width:140px;">Total</th>
                    <th class="text-center" style="min-width:80px;">Var %</th>
                </tr>
                <tr>
                    <th></th>
                    @for($m = 1; $m <= 12; $m++)
                    <th class="text-end text-muted">Budget</th><th class="text-end text-muted">Actual</th>
                    @endfor
                    <th class="text-end text-muted">Budget</th><th class="text-end text-muted">Actual</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach($comparison ?? [] as $row)
                @php
                    $budgetTotal = 0; $actualTotal = 0;
                    for ($m = 1; $m <= 12; $m++) { $budgetTotal += $row['month_'.$m.'_budget'] ?? 0; $actualTotal += $row['month_'.$m.'_actual'] ?? 0; }
                    $variance = $budgetTotal > 0 ? (($actualTotal - $budgetTotal) / $budgetTotal * 100) : 0;
                @endphp
                <tr>
                    <td>{{ $row['account_code'] ?? '' }} — {{ $row['account_name'] ?? '' }}</td>
                    @for($m = 1; $m <= 12; $m++)
                    <td class="text-end">{{ number_format($row['month_'.$m.'_budget'] ?? 0, 0) }}</td>
                    <td class="text-end {{ ($row['month_'.$m.'_actual'] ?? 0) > ($row['month_'.$m.'_budget'] ?? 0) ? 'text-danger' : '' }}">{{ number_format($row['month_'.$m.'_actual'] ?? 0, 0) }}</td>
                    @endfor
                    <td class="text-end fw-bold">{{ number_format($budgetTotal, 0) }}</td>
                    <td class="text-end fw-bold {{ $actualTotal > $budgetTotal ? 'text-danger' : 'text-success' }}">{{ number_format($actualTotal, 0) }}</td>
                    <td class="text-center {{ $variance > 0 ? 'text-danger' : 'text-success' }}">{{ number_format($variance, 1) }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
