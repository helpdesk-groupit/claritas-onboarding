@extends('layouts.app')
@section('title', 'Submit Claim — ' . $claim->claim_number)

@section('content')
<div class="container-fluid py-4">
    <a href="{{ route('user.claims.index', ['month' => $claim->month, 'year' => $claim->year]) }}" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to claim
    </a>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header border-0" style="background:linear-gradient(135deg,#f8fafc,#eef2ff);">
            <h5 class="mb-0"><i class="bi bi-send-check me-2 text-primary"></i>Submit {{ \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y') }} Claim</h5>
            <small class="text-muted">{{ $claim->claim_number }} — choose who approves each item, then submit.</small>
        </div>
        <div class="card-body">
            @if($approvers->isEmpty())
            <div class="alert alert-danger">No approving managers are available in the system. Please contact HR.</div>
            @else
            <p class="text-muted">
                Each item goes to the manager you pick below. Most items go to your reporting manager;
                for an event or programme run by another manager, route those items to them.
                @if(!$defaultApproverId)
                <span class="text-danger d-block mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Your reporting manager isn't linked in the system, so please choose an approver for each item.</span>
                @endif
            </p>

            <form action="{{ route('user.claims.submit', $claim) }}" method="POST" id="submitClaimForm">
                @csrf

                {{-- Quick "set all" helper --}}
                <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                    <label class="form-label mb-0 small fw-semibold">Set all items to:</label>
                    <select class="form-select form-select-sm w-auto" id="setAllApprover">
                        <option value="">— pick a manager —</option>
                        @foreach($approvers as $a)
                        <option value="{{ $a->id }}">{{ $a->full_name }}@if($a->department) ({{ $a->department }})@endif</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="applyAllBtn">Apply to all</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th class="text-end">Total</th>
                                <th style="min-width:240px;">Approving manager <span class="text-danger">*</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($claim->items as $i => $item)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td class="text-nowrap">{{ $item->expense_date->format('d/m/Y') }}</td>
                                <td>{{ $item->description }}</td>
                                <td><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">{{ $item->category->name ?? '—' }}</span></td>
                                <td class="text-end fw-semibold">RM {{ number_format($item->total_with_gst, 2) }}</td>
                                <td>
                                    <select name="approvers[{{ $item->id }}]" class="form-select form-select-sm approver-select" required>
                                        <option value="">— choose approver —</option>
                                        @foreach($approvers as $a)
                                        <option value="{{ $a->id }}" {{ (string) old("approvers.$item->id", $defaultApproverId) === (string) $a->id ? 'selected' : '' }}>
                                            {{ $a->full_name }}@if($a->department) ({{ $a->department }})@endif
                                        </option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">TOTAL</td>
                                <td class="text-end text-primary">RM {{ number_format($claim->total_with_gst, 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="alert alert-info py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>Once submitted, items are locked. Each manager approves the items routed to them; the claim goes to HR after every item has been decided.
                </div>

                <div class="text-end">
                    <a href="{{ route('user.claims.index', ['month' => $claim->month, 'year' => $claim->year]) }}" class="btn btn-outline-secondary me-2">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit for Approval</button>
                </div>
            </form>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    document.getElementById('applyAllBtn')?.addEventListener('click', function () {
        const val = document.getElementById('setAllApprover').value;
        if (!val) return;
        document.querySelectorAll('.approver-select').forEach(s => { s.value = val; });
    });
</script>
@endpush
