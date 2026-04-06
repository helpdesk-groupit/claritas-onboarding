@extends('layouts.app')
@section('title', isset($budget) ? 'Edit Budget' : 'New Budget')
@section('page-title', isset($budget) ? 'Edit Budget' : 'Create Budget')

@section('content')
@include('accounting.partials.nav')
<form method="POST" action="{{ isset($budget) ? route('accounting.budgets.update', $budget) : route('accounting.budgets.store') }}">
    @csrf
    @if(isset($budget)) @method('PUT') @endif

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Budget Name *</label><input type="text" name="name" class="form-control" value="{{ old('name', $budget->name ?? '') }}" required></div>
                <div class="col-md-3"><label class="form-label">Fiscal Year *</label>
                    <select name="fiscal_year_id" class="form-select" required>
                        @foreach($fiscalYears ?? [] as $fy)<option value="{{ $fy->id }}" {{ old('fiscal_year_id', $budget->fiscal_year_id ?? '') == $fy->id ? 'selected' : '' }}>{{ $fy->name }}</option>@endforeach
                    </select></div>
                <div class="col-md-3"><label class="form-label">Company</label>
                    <select name="company" class="form-select"><option value="">— All —</option>
                        @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ old('company', $budget->company ?? '') === $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                    </select></div>
                <div class="col-md-3"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control" value="{{ old('notes', $budget->notes ?? '') }}"></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Budget Lines — Monthly Amounts (RM)</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addLine"><i class="bi bi-plus-lg me-1"></i>Add Line</button>
        </div>
        <div class="card-body p-0" style="overflow-x:auto;">
            <table class="table table-sm mb-0" style="font-size:12px;">
                <thead>
                    <tr>
                        <th style="min-width:200px;">Account</th>
                        @for($m = 1; $m <= 12; $m++)<th style="min-width:90px;">{{ date('M', mktime(0,0,0,$m,1)) }}</th>@endfor
                        <th style="min-width:100px;">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="lineBody">
                @php $existingLines = old('lines', isset($budget) ? $budget->lines->toArray() : []); @endphp
                @foreach($existingLines as $idx => $line)
                    <tr class="budget-line">
                        <td>
                            <select name="lines[{{ $idx }}][account_id]" class="form-select form-select-sm" required>
                                <option value="">Select…</option>
                                @foreach($accounts ?? [] as $acc)<option value="{{ $acc->id }}" {{ ($line['account_id'] ?? '') == $acc->id ? 'selected' : '' }}>{{ $acc->account_code }} — {{ $acc->name }}</option>@endforeach
                            </select>
                        </td>
                        @for($m = 1; $m <= 12; $m++)
                        <td><input type="number" name="lines[{{ $idx }}][month_{{ $m }}]" class="form-control form-control-sm month-input" step="0.01" value="{{ $line['month_'.$m] ?? 0 }}"></td>
                        @endfor
                        <td class="line-total fw-bold">0.00</td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-line"><i class="bi bi-trash"></i></button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">{{ isset($budget) ? 'Update' : 'Create' }} Budget</button>
        <a href="{{ route('accounting.budgets.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<template id="lineTemplate">
    <tr class="budget-line">
        <td>
            <select name="lines[__IDX__][account_id]" class="form-select form-select-sm" required>
                <option value="">Select…</option>
                @foreach($accounts ?? [] as $acc)<option value="{{ $acc->id }}">{{ $acc->account_code }} — {{ $acc->name }}</option>@endforeach
            </select>
        </td>
        @for($m = 1; $m <= 12; $m++)
        <td><input type="number" name="lines[__IDX__][month_{{ $m }}]" class="form-control form-control-sm month-input" step="0.01" value="0"></td>
        @endfor
        <td class="line-total fw-bold">0.00</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-line"><i class="bi bi-trash"></i></button></td>
    </tr>
</template>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    let idx = {{ count($existingLines) }};
    const body = document.getElementById('lineBody');
    const tmpl = document.getElementById('lineTemplate');

    document.getElementById('addLine').addEventListener('click', function() {
        const html = tmpl.innerHTML.replace(/__IDX__/g, idx++);
        body.insertAdjacentHTML('beforeend', html);
        recalcAll();
    });

    body.addEventListener('click', function(e) {
        if (e.target.closest('.remove-line')) { e.target.closest('tr').remove(); recalcAll(); }
    });

    body.addEventListener('input', function(e) {
        if (e.target.classList.contains('month-input')) recalcRow(e.target.closest('tr'));
    });

    function recalcRow(tr) {
        let sum = 0;
        tr.querySelectorAll('.month-input').forEach(i => sum += parseFloat(i.value) || 0);
        tr.querySelector('.line-total').textContent = sum.toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2});
    }
    function recalcAll() { body.querySelectorAll('.budget-line').forEach(recalcRow); }
    recalcAll();
});
</script>
@endpush
@endsection
