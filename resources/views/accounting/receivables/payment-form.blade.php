@extends('layouts.app')
@section('title', 'Record Customer Payment')
@section('page-title', 'Record Customer Payment')

@section('content')
@include('accounting.partials.nav')

<div class="card" style="max-width:700px;">
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.customer-payments.store') }}">
            @csrf
            <input type="hidden" name="company" value="{{ request('company') }}">

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Customer *</label>
                    <select name="customer_id" class="form-select" required>
                        <option value="">Select</option>
                        @foreach($customers ?? [] as $c)<option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Date *</label><input type="date" name="date" class="form-control" value="{{ now()->toDateString() }}" required></div>
                <div class="col-md-3"><label class="form-label">Amount *</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Payment Method *</label>
                    <select name="payment_method" class="form-select" required>
                        @foreach(['bank_transfer','cash','cheque','credit_card','online','other'] as $m)<option value="{{ $m }}">{{ ucwords(str_replace('_',' ',$m)) }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Bank Account</label>
                    <select name="bank_account_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($bankAccounts ?? [] as $ba)<option value="{{ $ba->id }}">{{ $ba->account_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label">Reference</label><input type="text" name="reference" class="form-control"></div>
            </div>

            @if(!empty($invoices) && count($invoices))
            <h6 class="mt-3">Allocate to Invoices</h6>
            <table class="table table-sm table-bordered" style="font-size:13px;">
                <thead><tr><th>Invoice</th><th class="text-end">Balance</th><th style="width:150px">Allocate</th></tr></thead>
                <tbody>
                @foreach($invoices as $i => $inv)
                    <tr>
                        <td>{{ $inv->invoice_number }} <span class="text-muted">({{ $inv->customer->name ?? '' }})</span></td>
                        <td class="text-end">{{ number_format($inv->balance_due, 2) }}</td>
                        <td>
                            <input type="hidden" name="allocations[{{ $i }}][invoice_id]" value="{{ $inv->id }}">
                            <input type="number" name="allocations[{{ $i }}][amount]" class="form-control form-control-sm" step="0.01" min="0" max="{{ $inv->balance_due }}" value="{{ request('invoice_id') == $inv->id ? $inv->balance_due : 0 }}">
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Record Payment</button>
                <a href="{{ route('accounting.customer-payments.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
