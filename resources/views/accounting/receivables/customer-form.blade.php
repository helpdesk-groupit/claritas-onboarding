@extends('layouts.app')
@section('title', isset($customer) ? 'Edit Customer' : 'New Customer')
@section('page-title', isset($customer) ? 'Edit Customer' : 'New Customer')

@section('content')
@include('accounting.partials.nav')
<div class="card" style="max-width:700px;">
    <div class="card-body">
        <form method="POST" action="{{ isset($customer) ? route('accounting.customers.update', $customer) : route('accounting.customers.store') }}">
            @csrf
            @if(isset($customer)) @method('PUT') @endif

            @if(!isset($customer))
            <div class="mb-3">
                <label class="form-label">Company</label>
                <select name="company" class="form-select"><option value="">— None —</option>
                    @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ old('company') == $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                </select>
            </div>
            @endif

            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Customer Code *</label><input type="text" name="customer_code" class="form-control" value="{{ old('customer_code', $customer->customer_code ?? '') }}" required></div>
                <div class="col-md-8"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" value="{{ old('name', $customer->name ?? '') }}" required></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="{{ old('email', $customer->email ?? '') }}"></div>
                <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="{{ old('phone', $customer->phone ?? '') }}"></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6"><label class="form-label">Address Line 1</label><input type="text" name="address_line_1" class="form-control" value="{{ old('address_line_1', $customer->address_line_1 ?? '') }}"></div>
                <div class="col-md-6"><label class="form-label">Address Line 2</label><input type="text" name="address_line_2" class="form-control" value="{{ old('address_line_2', $customer->address_line_2 ?? '') }}"></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="{{ old('city', $customer->city ?? '') }}"></div>
                <div class="col-md-4"><label class="form-label">State</label><input type="text" name="state" class="form-control" value="{{ old('state', $customer->state ?? '') }}"></div>
                <div class="col-md-4"><label class="form-label">Tax ID</label><input type="text" name="tax_id" class="form-control" value="{{ old('tax_id', $customer->tax_id ?? '') }}"></div>
            </div>
            <div class="mt-3 mb-3">
                <label class="form-label">Payment Terms (days)</label>
                <input type="number" name="payment_terms_days" class="form-control" style="width:120px;" value="{{ old('payment_terms_days', $customer->payment_terms_days ?? 30) }}" min="0">
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ isset($customer) ? 'Update' : 'Create' }}</button>
                <a href="{{ route('accounting.customers.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
