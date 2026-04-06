@extends('layouts.app')
@section('title', 'Tax Return — ' . strtoupper($taxReturn->return_type))
@section('page-title', 'Tax Return: ' . strtoupper($taxReturn->return_type))

@section('content')
@include('accounting.partials.nav')

<div class="card mb-3">
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-2"><strong>Type</strong><br>{{ strtoupper($taxReturn->return_type) }}</div>
            <div class="col-md-3"><strong>Period</strong><br>{{ \Carbon\Carbon::parse($taxReturn->period_start)->format('d M Y') }} — {{ \Carbon\Carbon::parse($taxReturn->period_end)->format('d M Y') }}</div>
            <div class="col-md-2"><strong>Due</strong><br>{{ \Carbon\Carbon::parse($taxReturn->filing_due_date)->format('d M Y') }}</div>
            <div class="col-md-2"><strong>Status</strong><br><span class="badge bg-{{ $taxReturn->status === 'filed' ? 'success' : 'warning' }}">{{ ucfirst($taxReturn->status) }}</span></div>
            <div class="col-md-3"><strong>Net Payable</strong><br><span class="fs-5 fw-bold {{ $taxReturn->net_tax_payable >= 0 ? 'text-danger' : 'text-success' }}">RM {{ number_format($taxReturn->net_tax_payable, 2) }}</span></div>
        </div>

        <table class="table table-sm table-bordered" style="font-size:13px;">
            <thead><tr><th>Description</th><th>Tax Code</th><th class="text-end">Taxable Amount</th><th class="text-end">Tax Amount</th></tr></thead>
            <tbody>
            @foreach($taxReturn->lines as $line)
                <tr>
                    <td>{{ $line->line_label }}</td>
                    <td>{{ $line->taxCode->code ?? '-' }}</td>
                    <td class="text-end">{{ number_format($line->taxable_amount, 2) }}</td>
                    <td class="text-end {{ $line->tax_amount < 0 ? 'text-success' : '' }}">{{ number_format($line->tax_amount, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="3" class="text-end fw-bold">Output Tax:</td><td class="text-end fw-bold">{{ number_format($taxReturn->total_output_tax, 2) }}</td></tr>
                <tr><td colspan="3" class="text-end fw-bold">Input Tax:</td><td class="text-end fw-bold text-success">{{ number_format($taxReturn->total_input_tax, 2) }}</td></tr>
                <tr><td colspan="3" class="text-end fw-bold">Net Payable:</td><td class="text-end fw-bold fs-5">{{ number_format($taxReturn->net_tax_payable, 2) }}</td></tr>
            </tfoot>
        </table>

        @if(Auth::user()->canApproveTransactions() && $taxReturn->status === 'draft')
        <form method="POST" action="{{ route('accounting.tax-returns.file', $taxReturn) }}" class="mt-3">
            @csrf
            <button class="btn btn-success btn-sm"><i class="bi bi-send me-1"></i>Mark as Filed</button>
        </form>
        @endif
    </div>
</div>
@endsection
