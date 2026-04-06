@extends('layouts.app')
@section('title', 'Review Scanned Invoice')
@section('page-title', 'Review & Confirm Scanned Invoice')

@section('content')
@include('accounting.partials.nav')
<div class="row g-4">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Original Document</h6></div>
            <div class="card-body text-center">
                @if($scan->file_path)
                    @if(str_ends_with(strtolower($scan->file_path), '.pdf'))
                        <embed src="{{ asset('storage/' . $scan->file_path) }}" type="application/pdf" width="100%" height="500px">
                    @else
                        <img src="{{ asset('storage/' . $scan->file_path) }}" class="img-fluid rounded" alt="Invoice scan" style="max-height:500px;">
                    @endif
                @else
                    <div class="text-muted py-5">No file available</div>
                @endif
            </div>
        </div>
        @if($scan->raw_response)
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">AI Confidence</h6></div>
            <div class="card-body" style="font-size:13px;">
                <div class="mb-2"><strong>Confidence:</strong>
                    @php $conf = $scan->extracted_data['confidence'] ?? 0; @endphp
                    <span class="badge bg-{{ $conf >= 0.8 ? 'success' : ($conf >= 0.5 ? 'warning' : 'danger') }}">{{ number_format($conf * 100, 0) }}%</span>
                </div>
                <details><summary class="text-muted">Raw AI Response</summary><pre class="bg-dark text-light p-2 rounded mt-2" style="font-size:11px;max-height:300px;overflow:auto;">{{ json_encode(json_decode($scan->raw_response), JSON_PRETTY_PRINT) }}</pre></details>
            </div>
        </div>
        @endif
    </div>
    <div class="col-md-7">
        <form method="POST" action="{{ route('accounting.ai.confirm-scan', $scan) }}">
            @csrf
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-robot me-1"></i>Extracted Data — Review & Edit</h6></div>
                <div class="card-body">
                    @php $data = $scan->extracted_data ?? []; @endphp
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Vendor Name *</label><input type="text" name="vendor_name" class="form-control" value="{{ $data['vendor_name'] ?? '' }}" required></div>
                        <div class="col-md-6"><label class="form-label">Invoice Number</label><input type="text" name="invoice_number" class="form-control" value="{{ $data['invoice_number'] ?? '' }}"></div>
                        <div class="col-md-4"><label class="form-label">Invoice Date</label><input type="date" name="invoice_date" class="form-control" value="{{ $data['invoice_date'] ?? now()->toDateString() }}"></div>
                        <div class="col-md-4"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="{{ $data['due_date'] ?? '' }}"></div>
                        <div class="col-md-4"><label class="form-label">Currency</label><input type="text" name="currency" class="form-control" value="{{ $data['currency'] ?? 'MYR' }}"></div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0">Line Items</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" style="font-size:13px;">
                        <thead><tr><th>Description</th><th style="width:80px;">Qty</th><th style="width:100px;">Unit Price</th><th style="width:100px;">Amount</th></tr></thead>
                        <tbody>
                        @foreach($data['items'] ?? [] as $i => $item)
                            <tr>
                                <td><input type="text" name="items[{{ $i }}][description]" class="form-control form-control-sm" value="{{ $item['description'] ?? '' }}"></td>
                                <td><input type="number" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" step="0.01" value="{{ $item['quantity'] ?? 1 }}"></td>
                                <td><input type="number" name="items[{{ $i }}][unit_price]" class="form-control form-control-sm" step="0.01" value="{{ $item['unit_price'] ?? 0 }}"></td>
                                <td><input type="number" name="items[{{ $i }}][amount]" class="form-control form-control-sm" step="0.01" value="{{ $item['amount'] ?? 0 }}"></td>
                            </tr>
                        @endforeach
                        @if(empty($data['items']))
                            <tr>
                                <td><input type="text" name="items[0][description]" class="form-control form-control-sm" value=""></td>
                                <td><input type="number" name="items[0][quantity]" class="form-control form-control-sm" step="0.01" value="1"></td>
                                <td><input type="number" name="items[0][unit_price]" class="form-control form-control-sm" step="0.01" value="0"></td>
                                <td><input type="number" name="items[0][amount]" class="form-control form-control-sm" step="0.01" value="0"></td>
                            </tr>
                        @endif
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Subtotal</label><input type="number" name="subtotal" class="form-control" step="0.01" value="{{ $data['subtotal'] ?? $data['total_amount'] ?? 0 }}"></div>
                        <div class="col-md-4"><label class="form-label">Tax Amount</label><input type="number" name="tax_amount" class="form-control" step="0.01" value="{{ $data['tax_amount'] ?? 0 }}"></div>
                        <div class="col-md-4"><label class="form-label">Total Amount *</label><input type="number" name="total_amount" class="form-control" step="0.01" value="{{ $data['total_amount'] ?? 0 }}" required></div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Confirm & Create Bill</button>
                <a href="{{ route('accounting.ai.invoice-scanner') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
