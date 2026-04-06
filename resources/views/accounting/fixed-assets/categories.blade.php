@extends('layouts.app')
@section('title', 'Asset Categories')
@section('page-title', 'Fixed Asset Categories')

@section('content')
@include('accounting.partials.nav')
<div class="row g-4">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">{{ isset($editCategory) ? 'Edit Category' : 'New Category' }}</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ isset($editCategory) ? route('accounting.asset-categories.update', $editCategory) : route('accounting.asset-categories.store') }}">
                    @csrf
                    @if(isset($editCategory)) @method('PUT') @endif
                    <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" value="{{ old('name', $editCategory->name ?? '') }}" required></div>
                    <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ old('description', $editCategory->description ?? '') }}</textarea></div>
                    <div class="mb-3"><label class="form-label">Depreciation Method *</label>
                        <select name="depreciation_method" class="form-select" required>
                            @foreach(['straight_line'=>'Straight Line','declining_balance'=>'Declining Balance','sum_of_years'=>'Sum of Years'] as $val => $lbl)
                            <option value="{{ $val }}" {{ old('depreciation_method', $editCategory->depreciation_method ?? 'straight_line') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                            @endforeach
                        </select></div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">GL Asset Account</label>
                            <select name="gl_asset_account_id" class="form-select">
                                <option value="">—</option>
                                @foreach($accounts ?? [] as $acc)<option value="{{ $acc->id }}" {{ old('gl_asset_account_id', $editCategory->gl_asset_account_id ?? '') == $acc->id ? 'selected' : '' }}>{{ $acc->account_code }} — {{ $acc->name }}</option>@endforeach
                            </select></div>
                        <div class="col-md-6"><label class="form-label">GL Depreciation Account</label>
                            <select name="gl_depreciation_account_id" class="form-select">
                                <option value="">—</option>
                                @foreach($accounts ?? [] as $acc)<option value="{{ $acc->id }}" {{ old('gl_depreciation_account_id', $editCategory->gl_depreciation_account_id ?? '') == $acc->id ? 'selected' : '' }}>{{ $acc->account_code }} — {{ $acc->name }}</option>@endforeach
                            </select></div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary btn-sm">{{ isset($editCategory) ? 'Update' : 'Create' }}</button>
                        @if(isset($editCategory))<a href="{{ route('accounting.asset-categories.index') }}" class="btn btn-outline-secondary btn-sm">Cancel</a>@endif
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Categories</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <thead><tr><th>Name</th><th>Method</th><th>Assets</th><th></th></tr></thead>
                    <tbody>
                    @forelse($categories ?? [] as $cat)
                        <tr>
                            <td><strong>{{ $cat->name }}</strong>@if($cat->description)<br><small class="text-muted">{{ $cat->description }}</small>@endif</td>
                            <td>{{ ucwords(str_replace('_',' ',$cat->depreciation_method)) }}</td>
                            <td>{{ $cat->assets_count ?? $cat->assets->count() }}</td>
                            <td><a href="{{ route('accounting.asset-categories.edit', $cat) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No categories yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
