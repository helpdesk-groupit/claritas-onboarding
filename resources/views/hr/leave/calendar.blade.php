@extends('layouts.app')
@section('title', 'Leave Calendar')
@section('page-title', 'Team Leave Calendar')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>{{ \Carbon\Carbon::create($year, $month)->format('F Y') }}</h5>
        <div class="d-flex gap-2">
            @php
                $prev = \Carbon\Carbon::create($year, $month)->subMonth();
                $next = \Carbon\Carbon::create($year, $month)->addMonth();
            @endphp
            <a href="?month={{ $prev->month }}&year={{ $prev->year }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
            <a href="?month={{ now()->month }}&year={{ now()->year }}" class="btn btn-sm btn-outline-primary">Today</a>
            <a href="?month={{ $next->month }}&year={{ $next->year }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
        </div>
    </div>
    <div class="card-body">
        @php
            $startOfMonth = \Carbon\Carbon::create($year, $month, 1);
            $endOfMonth = $startOfMonth->copy()->endOfMonth();
            $startDay = $startOfMonth->dayOfWeek;
            $daysInMonth = $endOfMonth->day;
        @endphp
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d)
                        <th class="text-center" style="width:14.28%">{{ $d }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php $day = 1; $started = false; @endphp
                    @for($week = 0; $week < 6 && $day <= $daysInMonth; $week++)
                    <tr>
                        @for($dow = 0; $dow < 7; $dow++)
                            @if(!$started && $dow == $startDay)
                                @php $started = true; @endphp
                            @endif
                            @if($started && $day <= $daysInMonth)
                                @php
                                    $currentDate = \Carbon\Carbon::create($year, $month, $day)->format('Y-m-d');
                                    $dayLeaves = $leaves->filter(fn($l) => $currentDate >= $l->start_date && $currentDate <= $l->end_date);
                                    $isHoliday = $holidays->contains('date', $currentDate);
                                    $isToday = $currentDate === now()->format('Y-m-d');
                                @endphp
                                <td class="{{ $isToday ? 'bg-primary bg-opacity-10' : '' }} {{ $isHoliday ? 'bg-warning bg-opacity-10' : '' }}" style="min-height:80px;vertical-align:top">
                                    <div class="fw-bold {{ $isToday ? 'text-primary' : '' }}">{{ $day }}</div>
                                    @if($isHoliday)
                                        @php $hol = $holidays->firstWhere('date', $currentDate); @endphp
                                        <small class="badge bg-warning text-dark d-block mb-1">{{ $hol->name }}</small>
                                    @endif
                                    @foreach($dayLeaves->take(3) as $leave)
                                        <small class="badge bg-info d-block mb-1 text-truncate" style="max-width:100%">
                                            {{ $leave->employee->full_name ?? 'Unknown' }}
                                            <span class="opacity-75">({{ $leave->leaveType->code ?? '' }})</span>
                                        </small>
                                    @endforeach
                                    @if($dayLeaves->count() > 3)
                                        <small class="text-muted">+{{ $dayLeaves->count() - 3 }} more</small>
                                    @endif
                                </td>
                                @php $day++; @endphp
                            @else
                                <td class="bg-light"></td>
                            @endif
                        @endfor
                    </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
