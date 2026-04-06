@extends('layouts.app')
@section('title', 'Bank Reconciliation — ' . $bankAccount->account_name)
@section('page-title', 'Bank Reconciliation — ' . $bankAccount->account_name)

@section('content')
@include('accounting.partials.nav')

<div class="card mb-3">
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.banking.store-reconciliation', $bankAccount) }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-md-3"><label class="form-label">Statement Date *</label><input type="date" name="statement_date" class="form-control" value="{{ $statementDate }}" required></div>
                <div class="col-md-3"><label class="form-label">Statement Balance *</label><input type="number" name="statement_balance" class="form-control" step="0.01" value="{{ $statementBalance }}" required></div>
                <div class="col-md-3"><label class="form-label">Book Balance</label><input type="text" class="form-control" value="{{ number_format($bookBalance, 2) }}" readonly></div>
            </div>

            <h6>Unreconciled Transactions</h6>
            <table class="table table-sm table-bordered" style="font-size:13px;">
                <thead><tr><th style="width:30px"><input type="checkbox" id="checkAll"></th><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                <tbody>
                @forelse($unreconciled ?? [] as $tx)
                    <tr>
                        <td><input type="checkbox" name="reconciled_ids[]" value="{{ $tx->id }}" class="recon-check"></td>
                        <td>{{ \Carbon\Carbon::parse($tx->date)->format('d M Y') }}</td>
                        <td>{{ $tx->description }}</td>
                        <td>{{ $tx->reference ?? '-' }}</td>
                        <td class="text-end">{{ $tx->debit > 0 ? number_format($tx->debit, 2) : '' }}</td>
                        <td class="text-end">{{ $tx->credit > 0 ? number_format($tx->credit, 2) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-3">All transactions reconciled</td></tr>
                @endforelse
                </tbody>
            </table>

            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Complete Reconciliation</button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.recon-check').forEach(c => c.checked = this.checked);
});
</script>
@endpush
