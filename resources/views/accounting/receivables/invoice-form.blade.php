@extends('layouts.app')
@section('title', 'New Sales Invoice')
@section('page-title', 'New Sales Invoice')

@section('content')
@include('accounting.partials.nav')

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.invoices.store') }}" id="invoiceForm">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">Company</label>
                    <select name="company" class="form-select"><option value="">— None —</option>
                        @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ old('company') == $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Customer *</label>
                    <select name="customer_id" class="form-select" required>
                        <option value="">Select Customer</option>
                        @foreach($customers ?? [] as $c)<option value="{{ $c->id }}">{{ $c->customer_code }} - {{ $c->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label">Date *</label><input type="date" name="date" class="form-control" value="{{ old('date', now()->toDateString()) }}" required></div>
                <div class="col-md-2"><label class="form-label">Due Date *</label><input type="date" name="due_date" class="form-control" value="{{ old('due_date', now()->addDays(30)->toDateString()) }}" required></div>
                <div class="col-md-2"><label class="form-label">Reference</label><input type="text" name="reference" class="form-control" value="{{ old('reference') }}"></div>
            </div>

            <table class="table table-sm table-bordered" id="itemsTable" style="font-size:13px;">
                <thead><tr><th>Description</th><th style="width:10%">Qty</th><th style="width:12%">Unit Price</th><th style="width:20%">Account</th><th style="width:13%">Tax</th><th style="width:10%" class="text-end">Total</th><th style="width:35px"></th></tr></thead>
                <tbody>
                    <tr class="item-row">
                        <td><input type="text" name="items[0][description]" class="form-control form-control-sm" required></td>
                        <td><input type="number" name="items[0][quantity]" class="form-control form-control-sm qty" step="0.01" min="0.01" value="1" required></td>
                        <td><input type="number" name="items[0][unit_price]" class="form-control form-control-sm price" step="0.01" min="0" value="0" required></td>
                        <td><select name="items[0][account_id]" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach($accounts ?? [] as $a)<option value="{{ $a->id }}">{{ $a->account_code }} - {{ $a->name }}</option>@endforeach
                        </select></td>
                        <td><select name="items[0][tax_code_id]" class="form-select form-select-sm tax-select">
                            <option value="" data-rate="0">No Tax</option>
                            @foreach($taxCodes ?? [] as $t)<option value="{{ $t->id }}" data-rate="{{ $t->rate }}">{{ $t->code }} ({{ $t->rate }}%)</option>@endforeach
                        </select></td>
                        <td class="text-end fw-semibold line-total">0.00</td>
                        <td></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr><td colspan="5" class="text-end">Subtotal:</td><td class="text-end fw-bold" id="subtotal">0.00</td><td></td></tr>
                    <tr><td colspan="5" class="text-end">Tax:</td><td class="text-end" id="totalTax">0.00</td><td></td></tr>
                    <tr><td colspan="5" class="text-end fw-bold">Total:</td><td class="text-end fw-bold fs-5" id="grandTotal">0.00</td><td></td></tr>
                </tfoot>
            </table>
            <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="addItem"><i class="bi bi-plus me-1"></i>Add Line</button>

            <div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create Invoice</button>
                <a href="{{ route('accounting.invoices.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    let idx = 1;
    const tbody = document.querySelector('#itemsTable tbody');

    document.getElementById('addItem').addEventListener('click', function() {
        const first = tbody.querySelector('.item-row');
        const tr = first.cloneNode(true);
        tr.querySelectorAll('input').forEach(i => { i.value = i.type === 'number' ? (i.classList.contains('qty') ? '1' : '0') : ''; i.name = i.name.replace(/\[\d+\]/, `[${idx}]`); });
        tr.querySelectorAll('select').forEach(s => { s.selectedIndex = 0; s.name = s.name.replace(/\[\d+\]/, `[${idx}]`); });
        tr.querySelector('.line-total').textContent = '0.00';
        if (!tr.querySelector('td:last-child button')) {
            tr.querySelector('td:last-child').innerHTML = '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 remove-item"><i class="bi bi-x"></i></button>';
        }
        tbody.appendChild(tr);
        idx++;
        recalc();
    });

    tbody.addEventListener('click', e => { if (e.target.closest('.remove-item')) { e.target.closest('tr').remove(); recalc(); } });
    tbody.addEventListener('input', recalc);
    tbody.addEventListener('change', recalc);

    function recalc() {
        let sub = 0, tax = 0;
        tbody.querySelectorAll('.item-row').forEach(row => {
            const q = parseFloat(row.querySelector('.qty')?.value) || 0;
            const p = parseFloat(row.querySelector('.price')?.value) || 0;
            const r = parseFloat(row.querySelector('.tax-select')?.selectedOptions[0]?.dataset?.rate) || 0;
            const line = q * p;
            const t = line * r / 100;
            row.querySelector('.line-total').textContent = line.toFixed(2);
            sub += line;
            tax += t;
        });
        document.getElementById('subtotal').textContent = sub.toFixed(2);
        document.getElementById('totalTax').textContent = tax.toFixed(2);
        document.getElementById('grandTotal').textContent = (sub + tax).toFixed(2);
    }
});
</script>
@endpush
