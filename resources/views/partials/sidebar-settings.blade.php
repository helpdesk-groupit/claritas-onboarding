{{-- Unified "Settings" sidebar section. Each tab keeps its OWN role gate. Included in the
     Superadmin branch and the System-Admin (HR) branch so a System Admin sees the tabs their
     role allows. Order: System Overview · Role Management · System Logic · Cross Ticket Settings
     · User – Company Setting · Account Management. --}}
@php
    $u = Auth::user();
    $sSuper  = $u->isSuperadmin();                        // superadmin-only tabs
    $sSysAdm = $u->isSuperadmin() || $u->isSystemAdmin(); // superadmin OR system_admin tabs
@endphp
@if($sSuper || $sSysAdm)
<div class="sidebar-section">Settings</div>

@if($sSuper)
<div class="nav-item">
    <a href="{{ route('superadmin.system-overview') }}" class="nav-link {{ request()->routeIs('superadmin.system-overview') ? 'active' : '' }}">
        <i class="bi bi-diagram-3"></i> System Overview
    </a>
</div>
@endif

@if($sSuper)
<div class="nav-item">
    <a href="{{ route('superadmin.roles.index') }}" class="nav-link {{ request()->routeIs('superadmin.roles.*') ? 'active' : '' }}">
        <i class="bi bi-shield-lock"></i> Role Management
    </a>
</div>
@endif

@if($sSuper)
<div class="nav-item">
    <a href="{{ route('superadmin.kb.gate') }}" class="nav-link {{ request()->routeIs('superadmin.kb.*') ? 'active' : '' }}">
        <i class="bi bi-book"></i> System Logic
    </a>
</div>
@endif

@if($sSysAdm)
<div class="nav-item">
    <a href="{{ route('superadmin.department-settings.index') }}" class="nav-link {{ request()->routeIs('superadmin.department-settings.*') ? 'active' : '' }}">
        <i class="bi bi-diagram-2"></i> Cross Ticket Settings
    </a>
</div>
@endif

@if($sSuper)
<div class="nav-item">
    <a href="{{ route('superadmin.user-company-settings.index') }}" class="nav-link {{ request()->routeIs('superadmin.user-company-settings.*') ? 'active' : '' }}">
        <i class="bi bi-building-gear"></i> User – Company Setting
    </a>
</div>
@endif

@if($sSysAdm)
<div class="nav-item">
    <a href="{{ route('superadmin.accounts.index') }}" class="nav-link {{ request()->routeIs('superadmin.accounts.*') ? 'active' : '' }}">
        <i class="bi bi-person-lock"></i> Account Management
    </a>
</div>
@endif
@endif
