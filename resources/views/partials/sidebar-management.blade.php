{{-- Unified "Management" sidebar section. Each tab keeps its OWN role gate, so the section shows
     only the tabs the current user may access. Included once per role branch (branches are mutually
     exclusive, so the single Automation collapse id below never collides). Order is fixed:
     Task Management · Automation · Ticket Management · Team Leave · Team Claims · Company Registration. --}}
@php
    $u = Auth::user();
    $mCanTask       = $u->isSuperadmin() || $u->isIt();                                   // IT work items
    $mCanAutomation = $u->isSuperadmin() || $u->isIt();                                   // Email workflow automation (IT-only)
    $mCanStrategist = $u->canUseSocialStrategist();                                       // Social Media AI Strategist (IT + admins + HR managers)
    $mCanTicket     = $u->canAccessTicketManagement();
    $mCanTeamLeave  = $u->isSuperadmin();                                                 // Team Leave (approver view)
    $mCanTeamClaims = $u->canViewTeamClaims();
    $mCanVendors    = $u->canViewVendors();                                               // Vendor Management (IT + Finance + admins)
    // Decommissioning — the e-waste review surface AND the archive, now on the Asset Listing's
    // Company Asset Decommissioning tab (C-Suite, IT mgr, Finance, and anyone named as a
    // management approver). Suppressed for anyone who ALSO holds canViewAssets() — for them
    // the identical assets.index?tab=company-decom link already sits under "Asset Listing"
    // above; showing both would be a literal duplicate nav item. Finance/named approvers see
    // it labelled "Asset Listing", matching every other role's entry point into the same route.
    $mCanDecomm     = $u->canViewDecommissionReports() && ! $u->canViewAssets();
    $mCanCompanyReg = $u->isSuperadmin() || $u->isHrManager() || $u->isHrExecutive();
    $mCanKol        = $u->canAccessKolPortal() && ! empty(config('services.kol_portal.url'));  // ADM-06 KOL Management Portal (SSO handoff)
    $mShow = $mCanTask || $mCanAutomation || $mCanStrategist || $mCanTicket || $mCanTeamLeave || $mCanTeamClaims || $mCanVendors || $mCanDecomm || $mCanCompanyReg || $mCanKol;
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

@if($mCanAutomation || $mCanStrategist)
<div class="nav-item">
    <a href="#automationMenu" data-bs-toggle="collapse" role="button"
       aria-expanded="{{ $mAutomationOpen ? 'true' : 'false' }}"
       class="nav-link {{ $mAutomationOpen ? 'active' : '' }}">
        <i class="bi bi-robot"></i> Automation
        <i class="bi bi-chevron-down ms-auto" style="font-size:12px;"></i>
    </a>
    <div class="collapse {{ $mAutomationOpen ? 'show' : '' }}" id="automationMenu">
        @if($mCanAutomation)
        <a href="{{ route('it.automation.email-workflow.index') }}"
           class="nav-link {{ request()->routeIs('it.automation.email-workflow.*') ? 'active' : '' }}"
           style="padding-left:38px;">
            <i class="bi bi-envelope-paper"></i> Email Workflow
        </a>
        @endif
        @if($mCanStrategist)
        <a href="{{ route('it.automation.social-media-strategist.index') }}"
           class="nav-link {{ request()->routeIs('it.automation.social-media-strategist.*') ? 'active' : '' }}"
           style="padding-left:38px;">
            <i class="bi bi-megaphone"></i> Social Media Strategist
        </a>
        @endif
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

@if($mCanVendors)
<div class="nav-item">
    <a href="{{ route('vendors.index') }}" class="nav-link {{ request()->routeIs('vendors.*') ? 'active' : '' }}">
        <i class="bi bi-shop"></i> Vendor Management
    </a>
</div>
@endif

@if($mCanDecomm)
<div class="nav-item">
    <a href="{{ route('assets.index', ['tab' => 'company-decom']) }}" class="nav-link {{ request()->routeIs('assets.index') && request('tab') === 'company-decom' ? 'active' : '' }}">
        <i class="bi bi-recycle"></i> Asset Listing
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

{{-- ADM-06 — opens the KOL Management Portal in a NEW TAB, as the requirement
     specifies. It is a separate application on its own hostname, so this is a
     plain anchor to the SSO-minting route rather than a routeIs() highlight:
     the user never "is on" this page, they pass through it. --}}
@if($mCanKol)
<div class="nav-item">
    <a href="{{ route('kol-portal.redirect') }}" target="_blank" rel="noopener" class="nav-link">
        <i class="bi bi-megaphone"></i> KOL Management
        <i class="bi bi-box-arrow-up-right ms-auto" style="font-size:11px;"></i>
    </a>
</div>
@endif
@endif
