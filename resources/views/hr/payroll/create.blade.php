@extends('layouts.app')
@section('title', 'Create Pay Run')
@section('page-title', 'Create Pay Run')

@section('content')
<form method="POST" action="{{ route('hr.payroll.pay-runs.store') }}">
    @csrf
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>New Pay Run</h5></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. January 2026 Salary" required value="{{ old('title') }}">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select" required>
                        @for($y = now()->year - 1; $y <= now()->year + 1; $y++)
                        <option value="{{ $y }}" {{ $y == now()->year ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Month</label>
                    <select name="month" class="form-select" required>
                        @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" {{ $m == now()->month ? 'selected' : '' }}>{{ \Carbon\Carbon::create(null, $m)->format('F') }}</option>
                        @endfor
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Pay Date</label>
                    <input type="date" name="pay_date" class="form-control" required value="{{ old('pay_date', now()->endOfMonth()->format('Y-m-d')) }}">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Period Start</label>
                    <input type="date" name="period_start" class="form-control" required value="{{ old('period_start', now()->startOfMonth()->format('Y-m-d')) }}">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Period End</label>
                    <input type="date" name="period_end" class="form-control" required value="{{ old('period_end', now()->endOfMonth()->format('Y-m-d')) }}">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="{{ route('hr.payroll.pay-runs.index') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Create Pay Run</button>
    </div>
</form>
@endsection
