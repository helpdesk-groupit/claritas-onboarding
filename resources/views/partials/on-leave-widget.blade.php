{{-- On Leave This Week Widget --}}
{{-- Usage: @include('partials.on-leave-widget') --}}
{{-- Access control: HR/SuperAdmin/SystemAdmin see all companies, others see own company only --}}
@php
    $user = Auth::user();
    $isAdmin = $user->isHr() || $user->isSuperadmin() || $user->isSystemAdmin();
    $companyFilter = $isAdmin ? null : $user->employee?->company;
    $onLeaveData = \App\Http\Controllers\LeaveController::getOnLeaveThisWeek($companyFilter);
    $weekStart = now()->startOfWeek();
    $weekEnd = now()->endOfWeek();
@endphp

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between py-2" style="background:#f8fafc;">
        <div>
            <i class="bi bi-calendar2-week text-primary me-1"></i>
            <span class="fw-semibold">On Leave This Week</span>
            <small class="text-muted ms-1">({{ $weekStart->format('d M') }} — {{ $weekEnd->format('d M Y') }})</small>
        </div>
        @if(!$isAdmin && $companyFilter)
        <span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:11px;">
            <i class="bi bi-building me-1"></i>{{ $companyFilter }}
        </span>
        @elseif($isAdmin)
        <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:11px;">
            <i class="bi bi-globe me-1"></i>All Companies
        </span>
        @endif
    </div>
    <div class="card-body p-0">
        @if(empty($onLeaveData))
        <div class="text-center py-4 text-muted">
            <i class="bi bi-emoji-smile" style="font-size:28px;"></i>
            <div class="mt-2 small">No one is on leave this week.</div>
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:140px;">Day</th>
                        <th>Who's On Leave</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($onLeaveData as $day)
                    <tr @if($day['is_today']) style="background:#eff6ff;" @endif>
                        <td>
                            <div class="fw-semibold {{ $day['is_today'] ? 'text-primary' : '' }}" style="font-size:13px;">
                                {{ $day['day_name'] }}
                                @if($day['is_today'])
                                <span class="badge bg-primary ms-1" style="font-size:9px;">TODAY</span>
                                @endif
                            </div>
                            <small class="text-muted">{{ $day['date_formatted'] }}</small>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                @foreach($day['leaves'] as $leave)
                                <span class="badge bg-warning bg-opacity-10 text-dark border" style="font-size:11px;font-weight:500;">
                                    <i class="bi bi-person-fill me-1" style="font-size:10px;"></i>{{ $leave['employee_name'] }}
                                    <span class="text-muted">· {{ $leave['leave_type'] }}</span>
                                    @if($leave['is_half_day'])
                                    <span class="text-info">({{ ucfirst($leave['half_day_period']) }})</span>
                                    @endif
                                    @if($isAdmin && $leave['company'])
                                    <span class="text-secondary ms-1">[{{ $leave['company'] }}]</span>
                                    @endif
                                </span>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
