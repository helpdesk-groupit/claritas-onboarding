{{-- Unified "Management" sidebar section. Each tab keeps its OWN role gate, so the section shows
     only the tabs the current user may access. Included once per role branch (branches are mutually
     exclusive, so the single Automation collapse id below never collides). Order is fixed:
     Task Management · Automation · Ticket Management · Team Leave · Team Claims · Company Registration. --}}
@php
    $u = Auth::user();
    $mCanTask       = $u->isSuperadmin() || $u->isIt();                                   // IT work items
    $mCanAutomation = $u->isSuperadmin() || $u->isIt();                                   // Email workflow automation
    $mCanTicket     = $u->canAccessTicketManagement();
    $mCanTeamLeave  = $u->isSuperadmin();                                                 // Team Leave (approver view)
    $mCanTeamClaims = $u->canViewTeamClaims();
    $mCanCompanyReg = $u->isSuperadmin() || $u->isHrManager() || $u->isHrExecutive();
    $mShow = $mCanTask || $mCanAutomation || $mCanTicket || $mCanTeamLeave || $mCanTeamClaims || $mCanCompanyReg;
    $mAutomationOpen = request()->routeIs('it.automation.*');
@endphp
@if($mShow)
<div class="sidebar-section">Management</div>

@if($mCanTask)
<div class="nav-item">
    <a href="{{ route('it.tasks') }}" class="nav-link {{ request()->routeIs('it.tasks') ? 'active' : '' }}">
        <i class="bi bi-list-task"></i> Task Management
        @php $myTasks = \App\Models\ItTask::where('assigned_to', Auth::id())->where('status', '!=', 'done')->count(); @endphp
        @if($myTasks > 0)<span class="badge bg-warning text-dark ms-auto" style="font-size:10px;">{{ $myTasks }}</span>@endif
    </a>
</div>
@endif

@if($mCanAutomation)
<div class="nav-item">
    <a href="#automationMenu" data-bs-toggle="collapse" role="button"
       aria-expanded="{{ $mAutomationOpen ? 'true' : 'false' }}"
       class="nav-link {{ $mAutomationOpen ? 'active' : '' }}">
        <i class="bi bi-robot"></i> Automation
        <i class="bi bi-chevron-down ms-auto" style="font-size:12px;"></i>
    </a>
    <div class="collapse {{ $mAutomationOpen ? 'show' : '' }}" id="automationMenu">
        <a href="{{ route('it.automation.email-workflow.index') }}"
           class="nav-link {{ request()->routeIs('it.automation.email-workflow.*') ? 'active' : '' }}"
           style="padding-left:38px;">
            <i class="bi bi-envelope-paper"></i> Email Workflow
        </a>
    </div>
</div>
@endif

@if($mCanTicket)
<div class="nav-item">
    <a href="{{ route('tickets.manage') }}" class="nav-link {{ request()->routeIs('tickets.manage') ? 'active' : '' }}">
        <i class="bi bi-gear-wide-connected"></i> Ticket Management
    </a>
</div>
@endif

@if($mCanTeamLeave)
<div class="nav-item">
    <a href="{{ route('user.leave.team') }}" class="nav-link {{ request()->routeIs('user.leave.team*') ? 'active' : '' }}">
        <i class="bi bi-people"></i> Team Leave
        @php $__pendingTeam = $u->employee ? \App\Models\LeaveApplication::whereIn('employee_id', \App\Models\Employee::where('manager_id', $u->employee->id)->pluck('id'))->where('status', 'pending')->count() : 0; @endphp
        @if($__pendingTeam > 0)<span class="badge bg-warning text-dark ms-auto" style="font-size:10px;">{{ $__pendingTeam }}</span>@endif
    </a>
</div>
@endif

@if($mCanTeamClaims)
<div class="nav-item">
    <a href="{{ route('user.claims.team') }}" class="nav-link {{ request()->routeIs('user.claims.team*') ? 'active' : '' }}">
        <i class="bi bi-people"></i> Team Claims
        @if($u->employee)
            @php $__pendingTeamClaims = \App\Models\ExpenseClaim::where('status', 'submitted')->whereHas('items', fn ($q) => $q->where('approver_id', $u->employee->id))->count(); @endphp
            @if($__pendingTeamClaims > 0)<span class="badge bg-warning text-dark ms-auto" style="font-size:10px;">{{ $__pendingTeamClaims }}</span>@endif
        @endif
    </a>
</div>
@endif

@if($mCanCompanyReg)
<div class="nav-item">
    <a href="{{ route('superadmin.companies.index') }}" class="nav-link {{ request()->routeIs('superadmin.companies.*') ? 'active' : '' }}">
        <i class="bi bi-building"></i> Company Registration
    </a>
</div>
@endif
@endif
