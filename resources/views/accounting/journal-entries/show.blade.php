@extends('layouts.app')
@section('title', 'Journal Entry ' . $entry->entry_number)
@section('page-title', 'Journal Entry: ' . $entry->entry_number)

@section('content')
@include('accounting.partials.nav')

<div class="card mb-3">
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-2"><strong>Entry #</strong><br>{{ $entry->entry_number }}</div>
            <div class="col-md-2"><strong>Date</strong><br>{{ \Carbon\Carbon::parse($entry->date)->format('d M Y') }}</div>
            <div class="col-md-2"><strong>Status</strong><br>
                @if($entry->status === 'posted')<span class="badge bg-success">Posted</span>
                @elseif($entry->status === 'voided')<span class="badge bg-danger">Voided</span>
                @else<span class="badge bg-warning text-dark">Draft</span>@endif
            </div>
            <div class="col-md-3"><strong>Description</strong><br>{{ $entry->description }}</div>
            <div class="col-md-3"><strong>Reference</strong><br>{{ $entry->reference ?? '-' }}</div>
        </div>

        <table class="table table-sm table-bordered" style="font-size:13px;">
            <thead><tr><th>Account</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
            <tbody>
            @foreach($entry->lines as $line)
                <tr>
                    <td>{{ $line->account->account_code ?? '' }} - {{ $line->account->name ?? '' }}</td>
                    <td>{{ $line->description }}</td>
                    <td class="text-end">{{ $line->debit > 0 ? number_format($line->debit, 2) : '' }}</td>
                    <td class="text-end">{{ $line->credit > 0 ? number_format($line->credit, 2) : '' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="2" class="text-end">Total</td>
                    <td class="text-end">{{ number_format($entry->total_debit, 2) }}</td>
                    <td class="text-end">{{ number_format($entry->total_credit, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        @if(Auth::user()->canApproveTransactions())
        <div class="d-flex gap-2 mt-3">
            @if($entry->status === 'draft')
            <form method="POST" action="{{ route('accounting.journal-entries.post', $entry) }}">
                @csrf
                <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i>Post</button>
            </form>
            @endif
            @if($entry->status === 'posted')
            <form method="POST" action="{{ route('accounting.journal-entries.void', $entry) }}">
                @csrf
                <button class="btn btn-danger btn-sm" onclick="return confirm('Void this entry? A reversing entry will be created.')"><i class="bi bi-x-circle me-1"></i>Void</button>
            </form>
            @endif
        </div>
        @endif
    </div>
</div>
@endsection
