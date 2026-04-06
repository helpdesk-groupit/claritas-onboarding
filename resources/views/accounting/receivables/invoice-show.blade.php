@extends('layouts.app')
@section('title', 'Invoice ' . $invoice->invoice_number)
@section('page-title', 'Invoice: ' . $invoice->invoice_number)

@section('content')
@include('accounting.partials.nav')

<div class="card mb-3">
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-2"><strong>Invoice #</strong><br>{{ $invoice->invoice_number }}</div>
            <div class="col-md-2"><strong>Customer</strong><br>{{ $invoice->customer->name ?? '-' }}</div>
            <div class="col-md-2"><strong>Date</strong><br>{{ \Carbon\Carbon::parse($invoice->date)->format('d M Y') }}</div>
            <div class="col-md-2"><strong>Due Date</strong><br>{{ \Carbon\Carbon::parse($invoice->due_date)->format('d M Y') }}</div>
            <div class="col-md-2"><strong>Status</strong><br>
                @if($invoice->status === 'paid')<span class="badge bg-success">Paid</span>
                @elseif($invoice->status === 'overdue')<span class="badge bg-danger">Overdue</span>
                @elseif($invoice->status === 'partial')<span class="badge bg-info">Partial</span>
                @elseif($invoice->status === 'sent')<span class="badge bg-primary">Sent</span>
                @else<span class="badge bg-warning text-dark">{{ ucfirst($invoice->status) }}</span>@endif
            </div>
            <div class="col-md-2"><strong>Reference</strong><br>{{ $invoice->reference ?? '-' }}</div>
        </div>

        <table class="table table-sm table-bordered" style="font-size:13px;">
            <thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th>Tax</th><th class="text-end">Tax Amt</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="text-end">{{ number_format($item->quantity, 2) }}</td>
                    <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                    <td>{{ $item->taxCode->code ?? '-' }}</td>
                    <td class="text-end">{{ number_format($item->tax_amount, 2) }}</td>
                    <td class="text-end">{{ number_format($item->line_total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="5" class="text-end">Subtotal:</td><td class="text-end fw-semibold">{{ number_format($invoice->subtotal, 2) }}</td></tr>
                <tr><td colspan="5" class="text-end">Tax:</td><td class="text-end">{{ number_format($invoice->tax_amount, 2) }}</td></tr>
                <tr><td colspan="5" class="text-end fw-bold">Total:</td><td class="text-end fw-bold">{{ number_format($invoice->total, 2) }}</td></tr>
                <tr><td colspan="5" class="text-end">Paid:</td><td class="text-end text-success">{{ number_format($invoice->amount_paid, 2) }}</td></tr>
                <tr><td colspan="5" class="text-end fw-bold">Balance Due:</td><td class="text-end fw-bold text-danger">{{ number_format($invoice->balance_due, 2) }}</td></tr>
            </tfoot>
        </table>

        @if($invoice->notes)
        <div class="mt-2"><strong>Notes:</strong> {{ $invoice->notes }}</div>
        @endif

        @if(Auth::user()->canManageAccounting() && $invoice->balance_due > 0)
        <div class="mt-3">
            <a href="{{ route('accounting.customer-payments.create', ['invoice_id' => $invoice->id]) }}" class="btn btn-success btn-sm">
                <i class="bi bi-credit-card me-1"></i>Record Payment
            </a>
        </div>
        @endif
    </div>
</div>
@endsection
