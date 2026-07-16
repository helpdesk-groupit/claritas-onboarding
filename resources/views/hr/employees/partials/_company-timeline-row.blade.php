{{-- One stint row. Expects $stint (EmployeeCompanyHistory). --}}
@php
    $start = $stint->started_on ? \Carbon\Carbon::parse($stint->started_on) : null;
    $end = $stint->ended_on ? \Carbon\Carbon::parse($stint->ended_on) : null;
    $isCurrent = $stint->ended_on === null;
    $from = fmt_date($start);
    $to = $isCurrent ? 'Present' : fmt_date($end);
    $dur = $start
        ? $start->diffForHumans($end ?? now(), ['parts' => 2, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE])
        : null;
    $dot = $isCurrent ? '#22c55e' : '#94a3b8';
@endphp
<li class="mb-1" style="position:relative;">
    <span style="position:absolute;left:calc(-.85rem - 6px);top:5px;width:9px;height:9px;border-radius:50%;background:{{ $dot }};box-shadow:0 0 0 2px #fff;"></span>
    <span class="fw-semibold">{{ $stint->company }}</span>
    <span class="text-muted">— {{ $from }} &rarr; {{ $to }}{{ $dur ? " ($dur)" : '' }}</span>
    @if($stint->office_location)
        <span class="d-block text-muted" style="font-size:.72rem;"><i class="bi bi-geo-alt me-1"></i>{{ $stint->office_location }}</span>
    @endif
    @if($stint->note)
        <span class="d-block fst-italic" style="font-size:.72rem;color:#b45309;"><i class="bi bi-info-circle me-1"></i>{{ $stint->note }}</span>
    @endif
</li>
