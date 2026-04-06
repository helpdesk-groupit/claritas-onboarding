@extends('layouts.app')
@section('title', 'New Journal Entry')
@section('page-title', 'New Journal Entry')

@section('content')
@include('accounting.partials.nav')

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.journal-entries.store') }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">Company</label>
                    <select name="company" class="form-select">
                        <option value="">— None —</option>
                        @foreach($companies ?? [] as $key => $name)
                            <option value="{{ $key }}" {{ old('company') == $key ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date" name="date" class="form-control" value="{{ old('date', now()->toDateString()) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference" class="form-control" value="{{ old('reference') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Description <span class="text-danger">*</span></label>
                    <input type="text" name="description" class="form-control" value="{{ old('description') }}" required>
                </div>
            </div>

            <table class="table table-sm table-bordered" id="linesTable" style="font-size:13px;">
                <thead><tr><th style="width:35%">Account</th><th>Description</th><th style="width:15%">Debit</th><th style="width:15%">Credit</th><th style="width:40px"></th></tr></thead>
                <tbody>
                    <tr class="line-row">
                        <td><select name="lines[0][account_id]" class="form-select form-select-sm" required>
                            <option value="">Select Account</option>
                            @foreach($accounts ?? [] as $a)<option value="{{ $a->id }}">{{ $a->account_code }} - {{ $a->name }}</option>@endforeach
                        </select></td>
                        <td><input type="text" name="lines[0][description]" class="form-control form-control-sm"></td>
                        <td><input type="number" name="lines[0][debit]" class="form-control form-control-sm debit-input" step="0.01" min="0" value="0"></td>
                        <td><input type="number" name="lines[0][credit]" class="form-control form-control-sm credit-input" step="0.01" min="0" value="0"></td>
                        <td></td>
                    </tr>
                    <tr class="line-row">
                        <td><select name="lines[1][account_id]" class="form-select form-select-sm" required>
                            <option value="">Select Account</option>
                            @foreach($accounts ?? [] as $a)<option value="{{ $a->id }}">{{ $a->account_code }} - {{ $a->name }}</option>@endforeach
                        </select></td>
                        <td><input type="text" name="lines[1][description]" class="form-control form-control-sm"></td>
                        <td><input type="number" name="lines[1][debit]" class="form-control form-control-sm debit-input" step="0.01" min="0" value="0"></td>
                        <td><input type="number" name="lines[1][credit]" class="form-control form-control-sm credit-input" step="0.01" min="0" value="0"></td>
                        <td><button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 remove-line"><i class="bi bi-x"></i></button></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-end fw-bold">Totals:</td>
                        <td class="fw-bold" id="totalDebit">0.00</td>
                        <td class="fw-bold" id="totalCredit">0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="2" class="text-end">Difference:</td>
                        <td colspan="2" class="fw-bold" id="difference">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="addLine"><i class="bi bi-plus me-1"></i>Add Line</button>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save as Draft</button>
                <a href="{{ route('accounting.journal-entries.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    let lineIndex = 2;
    const tbody = document.querySelector('#linesTable tbody');
    const accountOptions = document.querySelector('.line-row select').innerHTML;

    document.getElementById('addLine').addEventListener('click', function() {
        const tr = document.createElement('tr');
        tr.className = 'line-row';
        tr.innerHTML = `
            <td><select name="lines[${lineIndex}][account_id]" class="form-select form-select-sm" required>${accountOptions}</select></td>
            <td><input type="text" name="lines[${lineIndex}][description]" class="form-control form-control-sm"></td>
            <td><input type="number" name="lines[${lineIndex}][debit]" class="form-control form-control-sm debit-input" step="0.01" min="0" value="0"></td>
            <td><input type="number" name="lines[${lineIndex}][credit]" class="form-control form-control-sm credit-input" step="0.01" min="0" value="0"></td>
            <td><button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 remove-line"><i class="bi bi-x"></i></button></td>`;
        tbody.appendChild(tr);
        lineIndex++;
        recalc();
    });

    tbody.addEventListener('click', function(e) {
        if (e.target.closest('.remove-line')) {
            e.target.closest('tr').remove();
            recalc();
        }
    });

    tbody.addEventListener('input', recalc);

    function recalc() {
        let d = 0, c = 0;
        document.querySelectorAll('.debit-input').forEach(el => d += parseFloat(el.value) || 0);
        document.querySelectorAll('.credit-input').forEach(el => c += parseFloat(el.value) || 0);
        document.getElementById('totalDebit').textContent = d.toFixed(2);
        document.getElementById('totalCredit').textContent = c.toFixed(2);
        const diff = document.getElementById('difference');
        diff.textContent = (d - c).toFixed(2);
        diff.style.color = Math.abs(d - c) < 0.005 ? '#22c55e' : '#ef4444';
    }
});
</script>
@endpush
