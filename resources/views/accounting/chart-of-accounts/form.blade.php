@extends('layouts.app')
@section('title', isset($account) ? 'Edit Account' : 'New Account')
@section('page-title', isset($account) ? 'Edit Account' : 'New Account')

@section('content')
@include('accounting.partials.nav')

<div class="card" style="max-width:700px;">
    <div class="card-body">
        <form method="POST" action="{{ isset($account) ? route('accounting.chart-of-accounts.update', $account) : route('accounting.chart-of-accounts.store') }}">
            @csrf
            @if(isset($account)) @method('PUT') @endif

            @if(!isset($account))
            <div class="mb-3">
                <label class="form-label">Company</label>
                <select name="company" class="form-select">
                    <option value="">— None —</option>
                    @foreach($companies ?? [] as $key => $name)
                        <option value="{{ $key }}" {{ old('company') == $key ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Account Code <span class="text-danger">*</span></label>
                    <input type="text" name="account_code" class="form-control @error('account_code') is-invalid @enderror"
                           value="{{ old('account_code', $account->account_code ?? '') }}" required {{ isset($account) ? 'readonly' : '' }}>
                    @error('account_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-8">
                    <label class="form-label">Account Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $account->name ?? '') }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Type <span class="text-danger">*</span></label>
                    <select name="type" class="form-select @error('type') is-invalid @enderror" required>
                        @foreach(['asset','liability','equity','revenue','cogs','expense'] as $t)
                            <option value="{{ $t }}" {{ old('type', $account->type ?? '') === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sub-Type</label>
                    <input type="text" name="sub_type" class="form-control" value="{{ old('sub_type', $account->sub_type ?? '') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Parent Account</label>
                    <select name="parent_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($parentAccounts ?? [] as $p)
                            <option value="{{ $p->id }}" {{ old('parent_id', $account->parent_id ?? '') == $p->id ? 'selected' : '' }}>{{ $p->account_code }} - {{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if(isset($account))
            <div class="mt-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                           {{ old('is_active', $account->is_active) ? 'checked' : '' }}>
                    <label class="form-check-label" for="isActive">Active</label>
                </div>
            </div>
            @endif

            <div class="mb-3 mt-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2">{{ old('description', $account->description ?? '') }}</textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>{{ isset($account) ? 'Update' : 'Create' }}</button>
                <a href="{{ route('accounting.chart-of-accounts.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
