@extends('layouts.app')
@section('title', 'Leave Balances')
@section('page-title', 'Leave Balances')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Leave Balances — {{ $year }}</h5>
        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('hr.leave.balances.initialize') }}">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">
                <button class="btn btn-sm btn-success" onclick="return confirm('Initialize leave balances for all employees for {{ $year }}? Existing balances will not be overwritten.')">
                    <i class="bi bi-plus-circle me-1"></i>Initialize Balances
                </button>
            </form>
            <form method="GET" class="d-flex gap-2">
                <select name="year" class="form-select form-select-sm" style="width:120px" onchange="this.form.submit()">
                    @for($y = now()->year - 1; $y <= now()->year + 1; $y++)
                        <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        @foreach($leaveTypes as $lt)<th class="text-center">{{ $lt->code }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $emp)
                    @php $empBalances = $balances->get($emp->id, collect()); @endphp
                    <tr>
                        <td>{{ $emp->full_name }}</td>
                        @foreach($leaveTypes as $lt)
                        @php $bal = $empBalances->firstWhere('leave_type_id', $lt->id); @endphp
                        <td class="text-center">
                            @if($bal)
                                <span class="badge bg-{{ $bal->available > 0 ? 'success' : 'danger' }}">{{ $bal->available }}</span>
                                <small class="text-muted d-block">{{ $bal->taken }}/{{ $bal->entitled }}</small>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        @endforeach
                    </tr>
                    @empty
                    <tr><td colspan="{{ $leaveTypes->count() + 1 }}" class="text-center text-muted py-4">No employees found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
