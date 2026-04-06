@extends('layouts.app')
@section('title', 'Tax Returns')
@section('page-title', 'Tax Returns')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="{{ route('accounting.tax.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Tax Codes</a>
    @if(Auth::user()->canApproveTransactions())
    <a href="{{ route('accounting.tax-returns.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Generate Return</a>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Type</th><th>Period</th><th>Due</th><th class="text-end">Output Tax</th><th class="text-end">Input Tax</th><th class="text-end">Net Payable</th><th class="text-center">Status</th><th></th></tr></thead>
            <tbody>
            @forelse($returns ?? [] as $r)
                <tr>
                    <td class="fw-semibold">{{ strtoupper($r->return_type) }}</td>
                    <td>{{ \Carbon\Carbon::parse($r->period_start)->format('M Y') }} - {{ \Carbon\Carbon::parse($r->period_end)->format('M Y') }}</td>
                    <td>{{ \Carbon\Carbon::parse($r->filing_due_date)->format('d M Y') }}</td>
                    <td class="text-end">{{ number_format($r->total_output_tax, 2) }}</td>
                    <td class="text-end">{{ number_format($r->total_input_tax, 2) }}</td>
                    <td class="text-end fw-semibold {{ $r->net_tax_payable >= 0 ? 'text-danger' : 'text-success' }}">{{ number_format($r->net_tax_payable, 2) }}</td>
                    <td class="text-center"><span class="badge bg-{{ $r->status === 'filed' ? 'success' : ($r->status === 'paid' ? 'primary' : 'warning') }}">{{ ucfirst($r->status) }}</span></td>
                    <td class="text-end"><a href="{{ route('accounting.tax-returns.show', $r) }}" class="btn btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-eye"></i></a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No tax returns yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
