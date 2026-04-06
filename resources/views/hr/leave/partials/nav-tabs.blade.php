@php
    $canManage = Auth::user()->canManageLeave();
@endphp
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('hr.leave.index') ? 'active' : '' }}"
           href="{{ route('hr.leave.index') }}">
            <i class="bi bi-list-check me-1"></i>Applications
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('hr.leave.balances') ? 'active' : '' }}"
           href="{{ route('hr.leave.balances') }}">
            <i class="bi bi-pie-chart me-1"></i>Balances
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('hr.leave.calendar') ? 'active' : '' }}"
           href="{{ route('hr.leave.calendar') }}">
            <i class="bi bi-calendar3 me-1"></i>Calendar
        </a>
    </li>
    @if($canManage)
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('hr.leave.types') ? 'active' : '' }}"
           href="{{ route('hr.leave.types') }}">
            <i class="bi bi-tags me-1"></i>Leave Types
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('hr.leave.entitlements') ? 'active' : '' }}"
           href="{{ route('hr.leave.entitlements') }}">
            <i class="bi bi-award me-1"></i>Entitlements
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('hr.leave.holidays') ? 'active' : '' }}"
           href="{{ route('hr.leave.holidays') }}">
            <i class="bi bi-calendar-heart me-1"></i>Public Holidays
        </a>
    </li>
    @endif
</ul>
