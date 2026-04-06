@extends('layouts.app')
@section('title', isset($account) ? 'Edit Bank Account' : 'New Bank Account')
@section('page-title', isset($account) ? 'Edit Bank Account' : 'New Bank Account')

@section('content')
@include('accounting.partials.nav')
<div class="card" style="max-width:600px;">
    <div class="card-body">
        <form method="POST" action="{{ isset($account) ? route('accounting.banking.update', $account) : route('accounting.banking.store') }}">
            @csrf
            @if(isset($account)) @method('PUT') @endif

            @if(!isset($account))
            <div class="mb-3"><label class="form-label">Company</label>
                <select name="company" class="form-select"><option value="">— None —</option>
                    @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ old('company') == $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                </select>
            </div>
            @endif

            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Bank Name *</label><input type="text" name="bank_name" class="form-control" value="{{ old('bank_name', $account->bank_name ?? '') }}" required></div>
                <div class="col-md-6"><label class="form-label">Account Name *</label><input type="text" name="account_name" class="form-control" value="{{ old('account_name', $account->account_name ?? '') }}" required></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4"><label class="form-label">Account Number *</label><input type="text" name="account_number" class="form-control" value="{{ old('account_number', $account->account_number ?? '') }}" required></div>
                <div class="col-md-4"><label class="form-label">Type *</label>
                    <select name="account_type" class="form-select" required>
                        @foreach(['checking','savings','credit_card','cash','other'] as $t)<option value="{{ $t }}" {{ old('account_type', $account->account_type ?? '') === $t ? 'selected' : '' }}>{{ ucwords(str_replace('_',' ',$t)) }}</option>@endforeach
                    </select></div>
                <div class="col-md-4"><label class="form-label">Currency *</label><input type="text" name="currency" class="form-control" value="{{ old('currency', $account->currency ?? 'MYR') }}" maxlength="3" required></div>
            </div>
            @if(!isset($account))
            <div class="mt-3"><label class="form-label">Opening Balance *</label><input type="number" name="opening_balance" class="form-control" style="width:200px;" step="0.01" value="{{ old('opening_balance', 0) }}" required></div>
            @endif
            <div class="mt-3"><label class="form-label">GL Account</label>
                <select name="gl_account_id" class="form-select"><option value="">— None —</option>
                    @foreach($glAccounts ?? [] as $a)<option value="{{ $a->id }}" {{ old('gl_account_id', $account->gl_account_id ?? '') == $a->id ? 'selected' : '' }}>{{ $a->account_code }} - {{ $a->name }}</option>@endforeach
                </select>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">{{ isset($account) ? 'Update' : 'Create' }}</button>
                <a href="{{ route('accounting.banking.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
