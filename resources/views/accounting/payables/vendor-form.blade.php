@extends('layouts.app')
@section('title', isset($vendor) ? 'Edit Vendor' : 'New Vendor')
@section('page-title', isset($vendor) ? 'Edit Vendor' : 'New Vendor')

@section('content')
@include('accounting.partials.nav')
<div class="card" style="max-width:700px;">
    <div class="card-body">
        <form method="POST" action="{{ isset($vendor) ? route('accounting.vendors.update', $vendor) : route('accounting.vendors.store') }}">
            @csrf
            @if(isset($vendor)) @method('PUT') @endif

            @if(!isset($vendor))
            <div class="mb-3"><label class="form-label">Company</label>
                <select name="company" class="form-select"><option value="">— None —</option>
                    @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ old('company') == $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                </select>
            </div>
            @endif

            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Vendor Code *</label><input type="text" name="vendor_code" class="form-control" value="{{ old('vendor_code', $vendor->vendor_code ?? '') }}" required {{ isset($vendor) ? 'readonly' : '' }}></div>
                <div class="col-md-8"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" value="{{ old('name', $vendor->name ?? '') }}" required></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="{{ old('email', $vendor->email ?? '') }}"></div>
                <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="{{ old('phone', $vendor->phone ?? '') }}"></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6"><label class="form-label">Address Line 1</label><input type="text" name="address_line_1" class="form-control" value="{{ old('address_line_1', $vendor->address_line_1 ?? '') }}"></div>
                <div class="col-md-6"><label class="form-label">Address Line 2</label><input type="text" name="address_line_2" class="form-control" value="{{ old('address_line_2', $vendor->address_line_2 ?? '') }}"></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4"><label class="form-label">Tax ID</label><input type="text" name="tax_id" class="form-control" value="{{ old('tax_id', $vendor->tax_id ?? '') }}"></div>
                <div class="col-md-4"><label class="form-label">Payment Terms (days)</label><input type="number" name="payment_terms_days" class="form-control" value="{{ old('payment_terms_days', $vendor->payment_terms_days ?? 30) }}" min="0"></div>
                <div class="col-md-4"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="{{ old('bank_name', $vendor->bank_name ?? '') }}"></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6"><label class="form-label">Bank Account No.</label><input type="text" name="bank_account_number" class="form-control" value="{{ old('bank_account_number', $vendor->bank_account_number ?? '') }}"></div>
                <div class="col-md-6"><label class="form-label">SWIFT</label><input type="text" name="bank_swift" class="form-control" value="{{ old('bank_swift', $vendor->bank_swift ?? '') }}"></div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">{{ isset($vendor) ? 'Update' : 'Create' }}</button>
                <a href="{{ route('accounting.vendors.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
