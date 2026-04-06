@extends('layouts.app')
@section('title', 'Accounting Settings')
@section('page-title', 'Accounting Settings')

@section('content')
@include('accounting.partials.nav')
<div class="row g-4">
    {{-- General Settings --}}
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-gear me-1"></i>General Settings</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('accounting.settings.update') }}">
                    @csrf @method('PUT')
                    <div class="mb-3"><label class="form-label">Default Currency</label><input type="text" name="settings[default_currency]" class="form-control" value="{{ $settings['default_currency'] ?? 'MYR' }}"></div>
                    <h6 class="mt-3 mb-2" style="font-size:13px;">Document Number Prefixes</h6>
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label small">Invoice</label><input type="text" name="settings[invoice_prefix]" class="form-control form-control-sm" value="{{ $settings['invoice_prefix'] ?? 'INV-' }}"></div>
                        <div class="col-md-6"><label class="form-label small">Bill</label><input type="text" name="settings[bill_prefix]" class="form-control form-control-sm" value="{{ $settings['bill_prefix'] ?? 'BILL-' }}"></div>
                        <div class="col-md-6"><label class="form-label small">Journal Entry</label><input type="text" name="settings[journal_prefix]" class="form-control form-control-sm" value="{{ $settings['journal_prefix'] ?? 'JE-' }}"></div>
                        <div class="col-md-6"><label class="form-label small">Payment</label><input type="text" name="settings[payment_prefix]" class="form-control form-control-sm" value="{{ $settings['payment_prefix'] ?? 'PAY-' }}"></div>
                        <div class="col-md-6"><label class="form-label small">Credit Note</label><input type="text" name="settings[credit_note_prefix]" class="form-control form-control-sm" value="{{ $settings['credit_note_prefix'] ?? 'CN-' }}"></div>
                        <div class="col-md-6"><label class="form-label small">Purchase Order</label><input type="text" name="settings[po_prefix]" class="form-control form-control-sm" value="{{ $settings['po_prefix'] ?? 'PO-' }}"></div>
                    </div>
                    <h6 class="mt-3 mb-2" style="font-size:13px;">AI Configuration</h6>
                    <div class="mb-2"><label class="form-label small">AI Provider</label>
                        <select name="settings[ai_provider]" class="form-select form-select-sm">
                            <option value="openai" {{ ($settings['ai_provider'] ?? 'openai') === 'openai' ? 'selected' : '' }}>OpenAI</option>
                        </select></div>
                    <div class="mb-2"><label class="form-label small">AI API Key</label><input type="password" name="settings[ai_api_key]" class="form-control form-control-sm" value="{{ $settings['ai_api_key'] ?? '' }}" placeholder="sk-..."></div>
                    <div class="mb-2"><label class="form-label small">AI Model</label><input type="text" name="settings[ai_model]" class="form-control form-control-sm" value="{{ $settings['ai_model'] ?? 'gpt-4o' }}"></div>
                    <button type="submit" class="btn btn-primary btn-sm mt-3">Save Settings</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        {{-- Fiscal Years --}}
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-calendar3 me-1"></i>Fiscal Years</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('accounting.settings.store-fiscal-year') }}" class="row g-2 align-items-end mb-3">
                    @csrf
                    <div class="col-md-4"><label class="form-label small">Name *</label><input type="text" name="name" class="form-control form-control-sm" placeholder="FY 2026" required></div>
                    <div class="col-md-3"><label class="form-label small">Start *</label><input type="date" name="start_date" class="form-control form-control-sm" required></div>
                    <div class="col-md-3"><label class="form-label small">End *</label><input type="date" name="end_date" class="form-control form-control-sm" required></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add</button></div>
                </form>
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <thead><tr><th>Name</th><th>Period</th><th>Status</th><th>Periods</th></tr></thead>
                    <tbody>
                    @foreach($fiscalYears ?? [] as $fy)
                        <tr>
                            <td>{{ $fy->name }}</td>
                            <td>{{ \Carbon\Carbon::parse($fy->start_date)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($fy->end_date)->format('d/m/Y') }}</td>
                            <td><span class="badge bg-{{ $fy->status === 'open' ? 'success' : 'secondary' }}">{{ ucfirst($fy->status) }}</span></td>
                            <td>{{ $fy->periods_count ?? $fy->periods->count() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Currencies --}}
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-currency-exchange me-1"></i>Currencies</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('accounting.settings.store-currency') }}" class="row g-2 align-items-end mb-3">
                    @csrf
                    <div class="col-md-2"><label class="form-label small">Code *</label><input type="text" name="code" class="form-control form-control-sm" maxlength="3" required></div>
                    <div class="col-md-4"><label class="form-label small">Name *</label><input type="text" name="name" class="form-control form-control-sm" required></div>
                    <div class="col-md-2"><label class="form-label small">Symbol</label><input type="text" name="symbol" class="form-control form-control-sm"></div>
                    <div class="col-md-2"><label class="form-label small">Rate</label><input type="number" name="exchange_rate" class="form-control form-control-sm" step="0.000001" value="1"></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add</button></div>
                </form>
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <thead><tr><th>Code</th><th>Name</th><th>Symbol</th><th>Rate</th></tr></thead>
                    <tbody>
                    @foreach($currencies ?? [] as $cur)
                        <tr><td>{{ $cur->code }}</td><td>{{ $cur->name }}</td><td>{{ $cur->symbol }}</td><td>{{ $cur->exchange_rate }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
