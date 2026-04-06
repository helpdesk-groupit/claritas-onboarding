@extends('layouts.app')
@section('title', 'Bill ' . $bill->bill_number)
@section('page-title', 'Bill: ' . $bill->bill_number)

@section('content')
@include('accounting.partials.nav')

<div class="card mb-3">
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-2"><strong>Bill #</strong><br>{{ $bill->bill_number }}</div>
            <div class="col-md-2"><strong>Vendor</strong><br>{{ $bill->vendor->name ?? '-' }}</div>
            <div class="col-md-2"><strong>Date</strong><br>{{ \Carbon\Carbon::parse($bill->date)->format('d M Y') }}</div>
            <div class="col-md-2"><strong>Due Date</strong><br>{{ \Carbon\Carbon::parse($bill->due_date)->format('d M Y') }}</div>
            <div class="col-md-2"><strong>Status</strong><br>
                @if($bill->status === 'paid')<span class="badge bg-success">Paid</span>
                @elseif($bill->status === 'received')<span class="badge bg-primary">Received</span>
                @else<span class="badge bg-warning text-dark">{{ ucfirst($bill->status) }}</span>@endif
            </div>
            <div class="col-md-2"><strong>Vendor Ref</strong><br>{{ $bill->vendor_bill_number ?? '-' }}</div>
        </div>

        <table class="table table-sm table-bordered" style="font-size:13px;">
            <thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th>Account</th><th>Tax</th><th class="text-end">Tax Amt</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            @foreach($bill->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="text-end">{{ number_format($item->quantity, 2) }}</td>
                    <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                    <td>{{ $item->account->name ?? '-' }}</td>
                    <td>{{ $item->taxCode->code ?? '-' }}</td>
                    <td class="text-end">{{ number_format($item->tax_amount, 2) }}</td>
                    <td class="text-end">{{ number_format($item->line_total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="6" class="text-end">Subtotal:</td><td class="text-end fw-semibold">{{ number_format($bill->subtotal, 2) }}</td></tr>
                <tr><td colspan="6" class="text-end">Tax:</td><td class="text-end">{{ number_format($bill->tax_amount, 2) }}</td></tr>
                <tr><td colspan="6" class="text-end fw-bold">Total:</td><td class="text-end fw-bold">{{ number_format($bill->total, 2) }}</td></tr>
                <tr><td colspan="6" class="text-end">Paid:</td><td class="text-end text-success">{{ number_format($bill->amount_paid, 2) }}</td></tr>
                <tr><td colspan="6" class="text-end fw-bold">Balance Due:</td><td class="text-end fw-bold text-danger">{{ number_format($bill->balance_due, 2) }}</td></tr>
            </tfoot>
        </table>

        <div class="d-flex gap-2 mt-3">
            @if(Auth::user()->canApproveTransactions() && $bill->status === 'draft')
            <form method="POST" action="{{ route('accounting.bills.approve', $bill) }}">@csrf
                <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i>Approve</button>
            </form>
            @endif

            @if(Auth::user()->canManageAccounting() && $bill->balance_due > 0)
            <a href="{{ route('accounting.vendor-payments.create', ['bill_id' => $bill->id]) }}" class="btn btn-primary btn-sm">
                <i class="bi bi-credit-card me-1"></i>Record Payment
            </a>
            @endif
        </div>
    </div>
</div>
@endsection
