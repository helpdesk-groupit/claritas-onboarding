@extends('layouts.app')
@section('title', 'Claim Reports')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i>Claim Reports</h3>
            <p class="text-muted mb-0">Track your submitted claims by month and approving manager, and print the forms.</p>
        </div>
        <a href="{{ route('user.claims.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-receipt-cutoff me-1"></i>My Claims</a>
    </div>

    @forelse($claims as $claim)
    @php $groups = $claim->items->groupBy('approver_id'); @endphp
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2" role="button" data-bs-toggle="collapse" data-bs-target="#rpt-{{ $claim->id }}">
            <div>
                <span class="fw-semibold"><i class="bi bi-calendar3 me-1 text-primary"></i>{{ \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y') }}</span>
                <span class="text-muted small ms-2">{{ $claim->claim_number }}@if($claim->event) &middot; {{ $claim->event }}@endif</span>
                <span class="text-muted small ms-2"><i class="bi bi-people me-1"></i>{{ $groups->count() }} approver{{ $groups->count() == 1 ? '' : 's' }}</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-{{ $claim->statusBadge()['class'] }}">{{ $claim->statusBadge()['label'] }}</span>
                <span class="fw-bold text-primary">RM {{ number_format($claim->total_with_gst, 2) }}</span>
                <i class="bi bi-chevron-down"></i>
            </div>
        </div>
        <div class="collapse" id="rpt-{{ $claim->id }}">
            <div class="card-body">
                {{-- Grouped by approving manager (one printable form each) --}}
                @foreach($groups as $approverId => $groupItems)
                @php $mgr = $groupItems->first()->approver; @endphp
                <div class="border rounded-3 p-2 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                        <span class="fw-semibold"><i class="bi bi-person-check me-1 text-success"></i>{{ $mgr->full_name ?? 'Unassigned' }}
                            <span class="text-muted small ms-1">— RM {{ number_format($groupItems->sum('total_with_gst'), 2) }}</span>
                        </span>
                        <a href="{{ route('user.claims.report-print', $claim) }}?approver={{ $approverId }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer me-1"></i>Print / PDF form</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem;">
                            <tbody>
                                @foreach($groupItems as $it)
                                <tr class="{{ $it->isRejected() ? 'table-danger' : '' }}">
                                    <td class="text-nowrap">{{ $it->expense_date->format('d/m/Y') }}</td>
                                    <td>{{ $it->description }}</td>
                                    <td><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">{{ $it->category->name ?? '—' }}</span></td>
                                    <td class="text-end fw-semibold">RM {{ number_format($it->total_with_gst, 2) }}</td>
                                    <td class="text-center">
                                        @if($it->manager_status === 'approved')<span class="badge bg-success">Approved</span>
                                        @elseif($it->manager_status === 'rejected')<span class="badge bg-danger">Rejected</span>
                                        @else<span class="badge bg-secondary">Pending</span>@endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endforeach

                {{-- Audit log --}}
                <h6 class="mt-3 mb-2"><i class="bi bi-clock-history me-1 text-muted"></i>Status Log</h6>
                @if($claim->logs->isEmpty())
                <p class="text-muted small mb-0">No activity recorded yet.</p>
                @else
                <ul class="list-unstyled small mb-0">
                    @foreach($claim->logs as $log)
                    <li class="mb-1 d-flex align-items-start gap-2">
                        <span class="badge bg-{{ $log->badgeClass() }} flex-shrink-0">{{ $log->label() }}</span>
                        <span>
                            <span class="text-muted">{{ $log->created_at?->format('d/m/Y H:i') }}</span> — {{ $log->detail }}
                            <span class="text-muted">({{ $log->actor_name }})</span>
                        </span>
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>
        </div>
    </div>
    @empty
    <div class="text-center text-muted py-5">
        <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
        <p class="mt-2 mb-0">No submitted claims yet. Submit a claim from <a href="{{ route('user.claims.index') }}">My Claims</a> to see it here.</p>
    </div>
    @endforelse
</div>
@endsection
