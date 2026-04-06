@extends('layouts.app')
@section('title', 'Generate Tax Return')
@section('page-title', 'Generate Tax Return')

@section('content')
@include('accounting.partials.nav')
<div class="card" style="max-width:600px;">
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.tax-returns.store') }}">
            @csrf
            <div class="mb-3"><label class="form-label">Company</label>
                <select name="company" class="form-select"><option value="">— None —</option>
                    @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Return Type *</label>
                <select name="return_type" class="form-select" required>
                    <option value="sst-02">SST-02 (Sales Tax)</option>
                    <option value="sst-03">SST-03 (Service Tax)</option>
                    <option value="cp204">CP204 (Tax Estimate)</option>
                    <option value="cp207">CP207 (Revised Estimate)</option>
                    <option value="e_filing">E-Filing</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4"><label class="form-label">Period Start *</label><input type="date" name="period_start" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Period End *</label><input type="date" name="period_end" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Filing Due Date *</label><input type="date" name="filing_due_date" class="form-control" required></div>
            </div>
            <div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-calculator me-1"></i>Generate Return</button>
                <a href="{{ route('accounting.tax-returns.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
