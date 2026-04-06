@extends('layouts.app')
@section('title', isset($code) ? 'Edit Tax Code' : 'New Tax Code')
@section('page-title', isset($code) ? 'Edit Tax Code' : 'New Tax Code')

@section('content')
@include('accounting.partials.nav')
<div class="card" style="max-width:500px;">
    <div class="card-body">
        <form method="POST" action="{{ isset($code) ? route('accounting.tax.update', $code) : route('accounting.tax.store') }}">
            @csrf
            @if(isset($code)) @method('PUT') @endif

            @if(!isset($code))
            <div class="mb-3"><label class="form-label">Company</label>
                <select name="company" class="form-select"><option value="">— None —</option>
                    @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Code *</label><input type="text" name="code" class="form-control" required></div>
            @endif
            <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" value="{{ old('name', $code->name ?? '') }}" required></div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label">Rate (%) *</label><input type="number" name="rate" class="form-control" step="0.01" min="0" max="100" value="{{ old('rate', $code->rate ?? 0) }}" required></div>
                <div class="col-md-6"><label class="form-label">Type *</label>
                    <select name="type" class="form-select" required>
                        @foreach(['sales','purchase','both','withholding'] as $t)<option value="{{ $t }}" {{ old('type', $code->type ?? '') === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>@endforeach
                    </select></div>
            </div>
            <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ old('description', $code->description ?? '') }}</textarea></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ isset($code) ? 'Update' : 'Create' }}</button>
                <a href="{{ route('accounting.tax.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
