@extends('layouts.app')
@section('title', 'Journal Entries')
@section('page-title', 'General Ledger — Journal Entries')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2">
        <select name="company" class="form-select form-select-sm" style="width:180px;" onchange="this.form.submit()">
            <option value="">All Companies</option>
            @foreach($companies ?? [] as $key => $name)
                <option value="{{ $key }}" {{ request('company') == $key ? 'selected' : '' }}>{{ $name }}</option>
            @endforeach
        </select>
        <select name="status" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
            <option value="">All Status</option>
            @foreach(['draft','posted','voided'] as $s)
                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </form>
    @if(Auth::user()->canManageAccounting())
    <a href="{{ route('accounting.journal-entries.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Entry</a>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Entry #</th><th>Date</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-center">Status</th><th></th></tr></thead>
            <tbody>
            @forelse($entries ?? [] as $je)
                <tr>
                    <td class="fw-semibold">{{ $je->entry_number }}</td>
                    <td>{{ \Carbon\Carbon::parse($je->date)->format('d M Y') }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($je->description, 50) }}</td>
                    <td class="text-end">{{ number_format($je->total_debit, 2) }}</td>
                    <td class="text-end">{{ number_format($je->total_credit, 2) }}</td>
                    <td class="text-center">
                        @if($je->status === 'posted')<span class="badge bg-success">Posted</span>
                        @elseif($je->status === 'voided')<span class="badge bg-danger">Voided</span>
                        @else<span class="badge bg-warning text-dark">Draft</span>@endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('accounting.journal-entries.show', $je) }}" class="btn btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No journal entries yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($entries ?? collect(), 'links'))
<div class="mt-3">{{ $entries->withQueryString()->links() }}</div>
@endif
@endsection
