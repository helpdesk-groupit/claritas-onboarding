<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Employee Portal')</title>
    {{-- Apply saved theme before page renders to prevent flash --}}
    <script>
        (function() {
            var t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', t);
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --sidebar-w: 255px; --primary: #2684FE; }
        body { background: #f1f5f9; font-family: 'Segoe UI', sans-serif; }
        .modal { z-index: 1055; }
        .modal-backdrop { z-index: 1050; }
        .modal-dialog-scrollable { max-height: calc(100vh - 56px); }
        .modal-dialog-scrollable .modal-body { overflow-y: auto; max-height: calc(100vh - 200px); }
        /* Fix: when <form> wraps modal-body + modal-footer, footer must stay visible */
        .modal-dialog-scrollable .modal-content > form {
            display: flex; flex-direction: column; flex: 1 1 auto; overflow: hidden; min-height: 0;
        }
        .sidebar {
            width: var(--sidebar-w); min-height: 100vh; position: fixed; top: 0; left: 0; z-index: 100;
            background: linear-gradient(180deg, #1A6FE8 0%, #4B9EFF 100%);
            display: flex; flex-direction: column;
        }
        .sidebar-brand { padding: 20px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.13); }
        .sidebar-brand h5 { color: #fff; font-weight: 700; margin: 0; font-size: 16px; }
        .sidebar-brand small { color: rgba(255,255,255,0.55); font-size: 11px; }
        .sidebar-section {
            padding: 14px 18px 4px; font-size: 10px; text-transform: uppercase;
            letter-spacing: 1.2px; color: rgba(255,255,255,0.4); font-weight: 600;
        }
        .sidebar-nav { padding: 6px 0; flex: 1; overflow-y: auto; }
        .sidebar-nav .nav-item { margin: 1px 10px; }
        .sidebar-nav .nav-link {
            color: rgba(255,255,255,0.75); border-radius: 8px; padding: 9px 14px;
            display: flex; align-items: center; gap: 10px; transition: all 0.15s; font-size: 14px;
        }
        .sidebar-nav .nav-link:hover, .sidebar-nav .nav-link.active {
            background: rgba(255,255,255,0.16); color: #fff;
        }
        .sidebar-nav .nav-link i { font-size: 17px; width: 20px; flex-shrink: 0; }
        .sidebar-footer { padding: 14px 10px; border-top: 1px solid rgba(255,255,255,0.13); }
        .user-chip { background: rgba(255,255,255,0.1); border-radius: 10px; padding: 11px 12px; }
        .user-avatar {
            width: 34px; height: 34px; background: rgba(255,255,255,0.2); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .role-badge {
            font-size: 10px; padding: 2px 7px; border-radius: 20px;
            background: rgba(255,255,255,0.18); color: #fff; display: inline-block; margin-top: 2px;
        }
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; }
        .topbar {
            background: #fff; border-bottom: 1px solid #e2e8f0; padding: 12px 24px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .topbar h4 { margin: 0; font-weight: 600; color: #1e293b; font-size: 18px; }
        .content-area { padding: 22px 24px; }

        /* ── Mobile responsiveness ── */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            z-index: 99;
        }
        .sidebar-overlay.active { display: block; }
        .hamburger-btn {
            display: none; background: none; border: none; padding: 4px 8px;
            font-size: 22px; color: #1e293b; cursor: pointer; line-height: 1;
        }
        @media (max-width: 767.98px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.25s ease;
                z-index: 200;
            }
            .sidebar.sidebar-open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger-btn { display: inline-flex; align-items: center; }
            .topbar { padding: 10px 16px; }
            .content-area { padding: 16px 14px; }
        }
        .card { border: none; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.09); }
        .section-header {
            background: #f8fafc; border-left: 4px solid var(--primary);
            padding: 9px 14px; border-radius: 0 8px 8px 0; margin-bottom: 18px;
        }
        .section-header h6 { margin: 0; font-weight: 600; color: #1e293b; }
        /* ── Dark Mode overrides ── */
        [data-theme="dark"] body { background: #0f172a; }
        [data-theme="dark"] .main-content { background: #0f172a; }
        [data-theme="dark"] .topbar { background: #1e293b; border-color: #334155; }
        [data-theme="dark"] .topbar h4 { color: #f1f5f9; }
        [data-theme="dark"] .topbar .text-muted { color: #94a3b8 !important; }
        [data-theme="dark"] .card { background: #1e293b; border-color: #334155; }
        [data-theme="dark"] .card-header { background: #1e293b !important; border-color: #334155; color: #f1f5f9; }
        [data-theme="dark"] .card-body { color: #e2e8f0; }
        [data-theme="dark"] .table { color: #e2e8f0; border-color: #334155; }
        [data-theme="dark"] .table thead th { background: #0f172a; color: #94a3b8; border-color: #334155; }
        [data-theme="dark"] .table tbody td { border-color: #334155; }
        [data-theme="dark"] .table-hover tbody tr:hover { background: #334155; }
        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select { background: #0f172a; border-color: #475569; color: #e2e8f0; }
        [data-theme="dark"] .form-control:focus,
        [data-theme="dark"] .form-select:focus { background: #0f172a; border-color: #2684FE; color: #e2e8f0; }
        [data-theme="dark"] .form-control::placeholder { color: #64748b; }
        [data-theme="dark"] .input-group-text { background: #1e293b; border-color: #475569; color: #94a3b8; }
        [data-theme="dark"] .section-header { background: #1e293b; }
        [data-theme="dark"] .section-header h6 { color: #f1f5f9; }
        [data-theme="dark"] .text-muted { color: #94a3b8 !important; }
        [data-theme="dark"] .fw-semibold, [data-theme="dark"] .fw-bold { color: #f1f5f9; }
        [data-theme="dark"] label { color: #cbd5e1; }
        [data-theme="dark"] h1,[data-theme="dark"] h2,[data-theme="dark"] h3,
        [data-theme="dark"] h4,[data-theme="dark"] h5,[data-theme="dark"] h6 { color: #f1f5f9; }
        [data-theme="dark"] .modal-content { background: #1e293b; color: #e2e8f0; }
        [data-theme="dark"] .modal-body, [data-theme="dark"] .modal-footer { border-color: #334155; }
        [data-theme="dark"] .dropdown-menu { background: #1e293b; border-color: #334155; }
        [data-theme="dark"] .dropdown-item { color: #e2e8f0; }
        [data-theme="dark"] .dropdown-item:hover { background: #334155; }
        [data-theme="dark"] hr { border-color: #334155; }
        [data-theme="dark"] .alert { border-color: #334155; }
        [data-theme="dark"] .pagination .page-link { background: #1e293b; border-color: #334155; color: #94a3b8; }
        [data-theme="dark"] .pagination .page-item.active .page-link { background: #2684FE; border-color: #2684FE; }
    </style>
    @stack('styles')
</head>
<body>
@auth
<nav class="sidebar">
    <div class="sidebar-brand">
        <h5><i class="bi bi-people-fill me-2"></i>Employee Portal</h5>
    </div>

    <div class="sidebar-nav">

        {{-- ══════════════════════════════════════════════════
             HR / SUPERADMIN / SYSTEM ADMIN MENU
             Order: Dashboard → Onboarding → Offboarding → Employee Listing → [extras] → Profile → Account
             ══════════════════════════════════════════════════ --}}
        @if(Auth::user()->isHr() || Auth::user()->isSuperadmin() || Auth::user()->isSystemAdmin())
        <div class="sidebar-section">
            @if(Auth::user()->isSuperadmin()) Superadmin Menu
            @elseif(Auth::user()->isSystemAdmin()) System Admin Menu
            @else HR Menu
            @endif
        </div>

        {{-- 1. Dashboard --}}
        <div class="nav-item">
            <a href="{{ route('hr.dashboard') }}"
               class="nav-link {{ request()->routeIs('hr.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </div>

        {{-- 2. Onboarding --}}
        <div class="nav-item">
            <a href="{{ route('onboarding.index') }}"
               class="nav-link {{ request()->routeIs('onboarding.*') ? 'active' : '' }}">
                <i class="bi bi-person-plus"></i> Onboarding
            </a>
        </div>

        {{-- 3. Offboarding --}}
        <div class="nav-item">
            <a href="{{ route('hr.offboarding.index') }}"
               class="nav-link {{ request()->routeIs('hr.offboarding.*') || request()->routeIs('offboarding.*') ? 'active' : '' }}">
                <i class="bi bi-box-arrow-right"></i> Offboarding
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('employees.index') }}"
               class="nav-link {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Employee Listing
            </a>
        </div>

        {{-- Asset Listing + Company Registration (HR Manager + HR Executive) --}}
        @if(Auth::user()->isHrManager() || Auth::user()->isHrExecutive())
        <div class="nav-item">
            <a href="{{ route('assets.index') }}"
               class="nav-link {{ request()->routeIs('assets.*') ? 'active' : '' }}">
                <i class="bi bi-laptop"></i> Asset Listing
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('superadmin.companies.index') }}"
               class="nav-link {{ request()->routeIs('superadmin.companies.*') ? 'active' : '' }}">
                <i class="bi bi-building"></i> Company Registration
            </a>
        </div>
        @endif

        {{-- Announcements — HR Manager + Superadmin + System Admin + IT Manager + Manager --}}
        @if(Auth::user()->isHrManager() || Auth::user()->isSuperadmin() || Auth::user()->isSystemAdmin() || Auth::user()->isItManager() || Auth::user()->employee?->work_role === 'manager')
        <div class="nav-item">
            <a href="{{ route('announcements.index') }}"
               class="nav-link {{ request()->routeIs('announcements.*') ? 'active' : '' }}">
                <i class="bi bi-megaphone"></i> Announcements
            </a>
        </div>
        @endif

        {{-- Superadmin extras (above Profile, below Employee Listing) --}}
        @if(Auth::user()->isSuperadmin())
        <div class="nav-item">
            <a href="{{ route('assets.index') }}"
               class="nav-link {{ request()->routeIs('assets.*') ? 'active' : '' }}">
                <i class="bi bi-laptop"></i> Asset Listing
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('it.tasks') }}"
               class="nav-link {{ request()->routeIs('it.tasks') ? 'active' : '' }}">
                <i class="bi bi-list-task"></i> Task Management
                @php $myTasks = \App\Models\ItTask::where('assigned_to', Auth::id())->where('status','!=','done')->count(); @endphp
                @if($myTasks > 0)
                    <span class="badge bg-warning text-dark ms-auto" style="font-size:10px;">{{ $myTasks }}</span>
                @endif
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('superadmin.roles.index') }}"
               class="nav-link {{ request()->routeIs('superadmin.roles.*') ? 'active' : '' }}">
                <i class="bi bi-shield-lock"></i> Role Management
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('superadmin.accounts.index') }}"
               class="nav-link {{ request()->routeIs('superadmin.accounts.*') ? 'active' : '' }}">
                <i class="bi bi-person-lock"></i> Account Management
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('superadmin.companies.index') }}"
               class="nav-link {{ request()->routeIs('superadmin.companies.*') ? 'active' : '' }}">
                <i class="bi bi-building"></i> Company Registration
            </a>
        </div>
        @endif

        {{-- 5. Profile --}}
        <div class="nav-item">
            <a href="{{ route('profile') }}"
               class="nav-link {{ request()->routeIs('profile') ? 'active' : '' }}">
                <i class="bi bi-person-circle"></i> Profile
            </a>
        </div>

        {{-- 6. Account --}}
        <div class="nav-item">
            <a href="{{ route('account') }}"
               class="nav-link {{ request()->routeIs('account') ? 'active' : '' }}">
                <i class="bi bi-gear"></i> Account
            </a>
        </div>

        {{-- ══════════════════════════════════════════════════
             IT MENU
             Order: Dashboard → Onboarding → Offboarding → Employee Listing → [extras] → Profile → Account
             ══════════════════════════════════════════════════ --}}
        @elseif(Auth::user()->isIt())
        <div class="sidebar-section">IT Menu</div>

        {{-- 1. Dashboard --}}
        <div class="nav-item">
            <a href="{{ route('it.dashboard') }}"
               class="nav-link {{ request()->routeIs('it.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </div>

        {{-- 2. Onboarding --}}
        <div class="nav-item">
            <a href="{{ route('it.onboarding') }}"
               class="nav-link {{ request()->routeIs('it.onboarding') ? 'active' : '' }}">
                <i class="bi bi-person-plus"></i> Onboarding
            </a>
        </div>

        {{-- 3. Offboarding --}}
        <div class="nav-item">
            <a href="{{ route('it.offboarding.index') }}"
               class="nav-link {{ request()->routeIs('it.offboarding.*') ? 'active' : '' }}">
                <i class="bi bi-box-arrow-right"></i> Offboarding
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('employees.index') }}"
               class="nav-link {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Employee Listing
            </a>
        </div>

        {{-- Extras: Assets, AARF, Tasks (above Profile) --}}
        <div class="nav-item">
            <a href="{{ route('assets.index') }}"
               class="nav-link {{ request()->routeIs('assets.*') ? 'active' : '' }}">
                <i class="bi bi-laptop"></i> Asset Listing
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('it.tasks') }}"
               class="nav-link {{ request()->routeIs('it.tasks') ? 'active' : '' }}">
                <i class="bi bi-list-task"></i> Task Management
                @php $myTasks = \App\Models\ItTask::where('assigned_to', Auth::id())->where('status','!=','done')->count(); @endphp
                @if($myTasks > 0)
                    <span class="badge bg-warning text-dark ms-auto" style="font-size:10px;">{{ $myTasks }}</span>
                @endif
            </a>
        </div>

        @if(Auth::user()->isItManager())
        <div class="nav-item">
            <a href="{{ route('announcements.index') }}"
               class="nav-link {{ request()->routeIs('announcements.*') ? 'active' : '' }}">
                <i class="bi bi-megaphone"></i> Announcements
            </a>
        </div>
        @endif

        {{-- 5. Profile --}}
        <div class="nav-item">
            <a href="{{ route('profile') }}"
               class="nav-link {{ request()->routeIs('profile') ? 'active' : '' }}">
                <i class="bi bi-person-circle"></i> Profile
            </a>
        </div>

        {{-- 6. Account --}}
        <div class="nav-item">
            <a href="{{ route('account') }}"
               class="nav-link {{ request()->routeIs('account') ? 'active' : '' }}">
                <i class="bi bi-gear"></i> Account
            </a>
        </div>

        {{-- ══════════════════════════════════════════════════
             STANDARD USER MENU
             ══════════════════════════════════════════════════ --}}
        @else
        <div class="sidebar-section">Menu</div>
        <div class="nav-item">
            <a href="{{ route('user.dashboard') }}"
               class="nav-link {{ request()->routeIs('user.dashboard') ? 'active' : '' }}">
                <i class="bi bi-house"></i> Dashboard
            </a>
        </div>
        @if(Auth::user()->employee?->work_role === 'manager')
        <div class="nav-item">
            <a href="{{ route('announcements.index') }}"
               class="nav-link {{ request()->routeIs('announcements.*') ? 'active' : '' }}">
                <i class="bi bi-megaphone"></i> Announcements
            </a>
        </div>
        @endif
        <div class="nav-item">
            <a href="{{ route('profile') }}"
               class="nav-link {{ request()->routeIs('profile') ? 'active' : '' }}">
                <i class="bi bi-person-circle"></i> Profile
            </a>
        </div>
        <div class="nav-item">
            <a href="{{ route('account') }}"
               class="nav-link {{ request()->routeIs('account') ? 'active' : '' }}">
                <i class="bi bi-gear"></i> Account
            </a>
        </div>
        @endif

        {{-- 7. Logout --}}
        <div class="sidebar-section">Session</div>
        <div class="nav-item">
            <form action="{{ route('logout') }}" method="POST" class="m-0">
                @csrf
                <button type="submit" class="nav-link w-100 border-0 bg-transparent text-start">
                    <i class="bi bi-box-arrow-left"></i> Logout
                </button>
            </form>
        </div>

    </div>

    <div class="sidebar-footer">
        <div class="user-chip d-flex align-items-center gap-2">
            <div class="user-avatar">
                <i class="bi bi-person-fill" style="color:#fff;font-size:16px;"></i>
            </div>
            <div style="overflow:hidden;flex:1;">
                <div style="color:#fff;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    {{ Auth::user()->name }}
                </div>
                <span class="role-badge">{{ str_replace('_', ' ', ucwords(Auth::user()->role)) }}</span>
            </div>
        </div>
    </div>
</nav>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="main-content">
    <div class="topbar">
        <div class="d-flex align-items-center gap-2">
            <button class="hamburger-btn" id="hamburgerBtn" onclick="openSidebar()" aria-label="Open menu">
                <i class="bi bi-list"></i>
            </button>
            <h4>@yield('page-title', 'Dashboard')</h4>
        </div>
        <span class="text-muted small">
            <i class="bi bi-calendar3 me-1"></i>{{ now()->format('d M Y') }}
        </span>
    </div>
    <div class="content-area">
        @foreach(['success','error','info','warning'] as $type)
            @if(session($type))
                <div class="alert alert-{{ $type === 'error' ? 'danger' : $type }} alert-dismissible fade show">
                    <i class="bi bi-{{ $type === 'success' ? 'check-circle' : ($type === 'error' ? 'exclamation-circle' : 'info-circle') }} me-2"></i>
                    {{ session($type) }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
        @endforeach
        @yield('content')
    </div>
</div>
@else
    @yield('content')
@endauth

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openSidebar() {
    document.querySelector('.sidebar')?.classList.add('sidebar-open');
    document.getElementById('sidebarOverlay')?.classList.add('active');
}
function closeSidebar() {
    document.querySelector('.sidebar')?.classList.remove('sidebar-open');
    document.getElementById('sidebarOverlay')?.classList.remove('active');
}
// Close sidebar when a nav link is clicked on mobile
document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth < 768) closeSidebar();
    });
});

// ── Theme switcher ────────────────────────────────────────────────────────
function setTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
    // Update active state on theme option cards if present
    document.querySelectorAll('.theme-option').forEach(function(el) {
        el.classList.remove('border-primary', 'shadow-sm');
    });
    var active = document.querySelector('.theme-option[onclick="setTheme(\'' + theme + '\')"]');
    if (active) active.classList.add('border-primary', 'shadow-sm');
}
</script>
@auth
{{-- ── Idle Session Timeout ───────────────────────────────────────────────
     Logs the user out after 60 seconds of inactivity.
     "Activity" = any mouse move, keypress, click, scroll, or touch.
     A 30-second warning modal appears before the logout fires, giving
     the user a chance to click "Stay Logged In" and reset the timer.
     The logout is performed via a real POST to /logout (with CSRF token)
     so the server-side session is fully invalidated — not just a redirect.
──────────────────────────────────────────────────────────────────────── --}}
<div class="modal fade" id="idleWarningModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="idleWarningLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0" style="background:#fff3cd;">
                <h6 class="modal-title fw-bold text-warning-emphasis" id="idleWarningLabel">
                    <i class="bi bi-clock-history me-2"></i>Session Expiring
                </h6>
            </div>
            <div class="modal-body pt-2 text-center">
                <p class="mb-1" style="font-size:14px;">You have been inactive. You will be logged out in</p>
                <div id="idleCountdown" style="font-size:36px;font-weight:700;color:#dc3545;line-height:1.1;">30</div>
                <p class="text-muted mt-1 mb-0" style="font-size:12px;">seconds</p>
            </div>
            <div class="modal-footer border-0 justify-content-center pt-0 pb-3">
                <button type="button" class="btn btn-primary btn-sm px-4" id="idleStayBtn">
                    <i class="bi bi-arrow-clockwise me-1"></i>Stay Logged In
                </button>
            </div>
        </div>
    </div>
</div>
<form id="idleLogoutForm" action="{{ route('logout') }}" method="POST" style="display:none;">
    @csrf
</form>
<script>
(function () {
    // ── Configuration ─────────────────────────────────────────────────────
    var IDLE_TIMEOUT_MS  = 15 * 60 * 1000;  // 15 min of inactivity → trigger warning
    var WARNING_DURATION = 30;          // seconds of countdown shown in modal
    // ─────────────────────────────────────────────────────────────────────

    var idleTimer      = null;
    var countdownTimer = null;
    var countdown      = WARNING_DURATION;
    var modal          = null;
    var modalEl        = document.getElementById('idleWarningModal');
    var countdownEl    = document.getElementById('idleCountdown');
    var stayBtn        = document.getElementById('idleStayBtn');

    // Lazy-init Bootstrap modal (Bootstrap is loaded after this script)
    function getModal() {
        if (!modal) modal = new bootstrap.Modal(modalEl);
        return modal;
    }

    // ── Reset idle timer on any user activity ────────────────────────────
    function resetTimer() {
        clearTimeout(idleTimer);
        // Only reset if the warning modal is NOT currently open
        if (!modalEl.classList.contains('show')) {
            idleTimer = setTimeout(showWarning, IDLE_TIMEOUT_MS);
        }
    }

    // ── Show the 30-second countdown warning modal ───────────────────────
    function showWarning() {
        countdown   = WARNING_DURATION;
        countdownEl.textContent = countdown;
        getModal().show();

        clearInterval(countdownTimer);
        countdownTimer = setInterval(function () {
            countdown--;
            countdownEl.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(countdownTimer);
                doLogout();
            }
        }, 1000);
    }

    // ── Perform server-side logout via form POST ─────────────────────────
    function doLogout() {
        getModal().hide();
        document.getElementById('idleLogoutForm').submit();
    }

    // ── "Stay Logged In" button — dismiss modal and restart timer ────────
    stayBtn.addEventListener('click', function () {
        clearInterval(countdownTimer);
        getModal().hide();
        resetTimer(); // restart the 60-second idle clock
    });

    // ── Activity events that reset the idle timer ────────────────────────
    ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll', 'click'].forEach(function (evt) {
        document.addEventListener(evt, resetTimer, { passive: true });
    });

    // ── Start the timer when the page loads ──────────────────────────────
    resetTimer();
})();
</script>
@endauth
@stack('scripts')
</body>
</html>