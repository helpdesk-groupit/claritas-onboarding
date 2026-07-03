@extends('layouts.app')
@section('title', 'Submit Claim — ' . $claim->claim_number)

@section('content')
<div class="container-fluid py-4">
    <a href="{{ route('user.claims.index', ['open' => $claim->id]) }}" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to claim
    </a>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header border-0" style="background:linear-gradient(135deg,#f8fafc,#eef2ff);">
            <h5 class="mb-0"><i class="bi bi-send-check me-2 text-primary"></i>Submit claim: {{ $claim->event ?: 'Untitled' }}</h5>
            <small class="text-muted">{{ $claim->claim_number }} — choose the approving manager, then submit.</small>
        </div>
        <div class="card-body">
            @if($approvers->isEmpty())
            <div class="alert alert-danger">No approving managers are available in the system. Please contact HR.</div>
            @else
            <form action="{{ route('user.claims.submit', $claim) }}" method="POST">
                @csrf

                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">Approving manager <span class="text-danger">*</span></label>
                        <select name="approver_id" class="form-select @error('approver_id') is-invalid @enderror" required>
                            <option value="">— choose the approver for this claim —</option>
                            @foreach($approvers as $a)
                            <option value="{{ $a->id }}" {{ (string) old('approver_id', $defaultApproverId) === (string) $a->id ? 'selected' : '' }}>
                                {{ $a->full_name }}@if($a->department) ({{ $a->department }})@endif
                            </option>
                            @endforeach
                        </select>
                        @error('approver_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Usually your reporting manager — for an event run by someone else, pick that manager.
                            @if(!$defaultApproverId)
                            <span class="text-danger d-block"><i class="bi bi-exclamation-triangle me-1"></i>Your reporting manager isn't linked in the system — please choose one.</span>
                            @endif
                        </div>
                    </div>
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
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">TOTAL</td>
                                <td class="text-end text-primary">RM {{ number_format($claim->total_with_gst, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="alert alert-info py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>Once submitted, the items are locked and the whole claim goes to the manager above, then to HR.
                </div>

                <div class="text-end">
                    <a href="{{ route('user.claims.index', ['open' => $claim->id]) }}" class="btn btn-outline-secondary me-2">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit for Approval</button>
                </div>
            </form>
            @endif
        </div>
    </div>
</div>
@endsection
