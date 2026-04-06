@extends('layouts.app')
@section('title', isset($asset) ? 'Edit Fixed Asset' : 'New Fixed Asset')
@section('page-title', isset($asset) ? 'Edit Fixed Asset' : 'Register Fixed Asset')

@section('content')
@include('accounting.partials.nav')
<div class="card" style="max-width:700px;">
    <div class="card-body">
        <form method="POST" action="{{ isset($asset) ? route('accounting.fixed-assets.update', $asset) : route('accounting.fixed-assets.store') }}">
            @csrf
            @if(isset($asset)) @method('PUT') @endif

            @if(!isset($asset))
            <div class="mb-3"><label class="form-label">Company</label>
                <select name="company" class="form-select"><option value="">— None —</option>
                    @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach
                </select></div>
            @endif
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Category *</label>
                    <select name="category_id" class="form-select" required>
                        @foreach($categories ?? [] as $cat)<option value="{{ $cat->id }}" {{ old('category_id', $asset->category_id ?? '') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>@endforeach
                    </select></div>
                <div class="col-md-4"><label class="form-label">Asset Code *</label><input type="text" name="asset_code" class="form-control" value="{{ old('asset_code', $asset->asset_code ?? '') }}" required {{ isset($asset) ? 'readonly' : '' }}></div>
                <div class="col-md-4"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" value="{{ old('name', $asset->name ?? '') }}" required></div>
            </div>
            <div class="mb-3 mt-2"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ old('description', $asset->description ?? '') }}</textarea></div>
            @if(!isset($asset))
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Purchase Date *</label><input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date', now()->toDateString()) }}" required></div>
                <div class="col-md-3"><label class="form-label">Purchase Cost *</label><input type="number" name="purchase_cost" class="form-control" step="0.01" min="0" required></div>
                <div class="col-md-3"><label class="form-label">Residual Value *</label><input type="number" name="residual_value" class="form-control" step="0.01" min="0" value="0" required></div>
                <div class="col-md-3"><label class="form-label">Useful Life (months) *</label><input type="number" name="useful_life_months" class="form-control" min="1" value="60" required></div>
            </div>
            @else
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Residual Value *</label><input type="number" name="residual_value" class="form-control" step="0.01" value="{{ old('residual_value', $asset->residual_value) }}" required></div>
                <div class="col-md-4"><label class="form-label">Status *</label>
                    <select name="status" class="form-select" required>
                        @foreach(['active','disposed','fully_depreciated'] as $s)<option value="{{ $s }}" {{ $asset->status === $s ? 'selected' : '' }}>{{ ucwords(str_replace('_',' ',$s)) }}</option>@endforeach
                    </select></div>
            </div>
            @endif
            <div class="row g-3 mt-1">
                <div class="col-md-6"><label class="form-label">Serial Number</label><input type="text" name="serial_number" class="form-control" value="{{ old('serial_number', $asset->serial_number ?? '') }}"></div>
                <div class="col-md-6"><label class="form-label">Location</label><input type="text" name="location" class="form-control" value="{{ old('location', $asset->location ?? '') }}"></div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">{{ isset($asset) ? 'Update' : 'Register' }}</button>
                <a href="{{ route('accounting.fixed-assets.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
