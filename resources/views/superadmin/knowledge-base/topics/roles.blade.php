@extends('superadmin.knowledge-base.topic-layout')

@section('topic-content')
{{-- ── Hierarchy ── --}}
<div class="kb-section card" id="hierarchy">
    <div class="card-body">
        <h4><i class="bi bi-diagram-3 me-2"></i>Role Hierarchy</h4>
        <div class="diagram-container">
            <div class="mermaid">
flowchart TD
    SA["SuperAdmin\n(Full system access)"] --> HR_M["HR Manager\n(Full HR module)"]
    SA --> IT_M["IT Manager\n(Full IT module)"]
    SA --> FIN_M["Finance Manager\n(Full Accounting)"]
    SA --> SYS["System Admin\n(≈ HR Manager)"]

    HR_M --> HR_E["HR Executive\n(Limited HR)"]
    HR_E --> HR_I["HR Intern\n(View only)"]

    IT_M --> IT_E["IT Executive\n(Limited IT)"]
    IT_E --> IT_I["IT Intern\n(View only)"]

    FIN_M --> FIN_E["Finance Executive\n(View Accounting)"]

    EMP["Employee\n(Self-service only)"]

    style SA fill:#dc3545,color:#fff
    style HR_M fill:#0d6efd,color:#fff
    style IT_M fill:#0dcaf0,color:#fff
    style FIN_M fill:#6f42c1,color:#fff
    style SYS fill:#495057,color:#fff
    style EMP fill:#198754,color:#fff
            </div>
        </div>
    </div>
</div>

{{-- ── All Roles ── --}}
<div class="kb-section card" id="roles">
    <div class="card-body">
        <h4><i class="bi bi-people me-2"></i>All System Roles (11)</h4>
        <table class="table table-sm relation-table">
            <thead><tr><th>Role</th><th>Group</th><th>Key Capabilities</th></tr></thead>
            <tbody>
                <tr><td><code>superadmin</code></td><td>Admin</td><td>Everything — all modules, role management, company registration, KB access</td></tr>
                <tr><td><code>system_admin</code></td><td>Admin</td><td>≈ HR Manager permissions across most views</td></tr>
                <tr><td><code>hr_manager</code></td><td>HR</td><td>Full CRUD onboarding/offboarding/employees, approve payroll, manage leave</td></tr>
                <tr><td><code>hr_executive</code></td><td>HR</td><td>View + limited edit on HR module, manage leave applications</td></tr>
                <tr><td><code>hr_intern</code></td><td>HR</td><td>View-only access to HR module</td></tr>
                <tr><td><code>it_manager</code></td><td>IT</td><td>Full CRUD assets, AARF management, IT task assignment</td></tr>
                <tr><td><code>it_executive</code></td><td>IT</td><td>Asset management, limited AARF access</td></tr>
                <tr><td><code>it_intern</code></td><td>IT</td><td>View-only IT module</td></tr>
                <tr><td><code>finance_manager</code></td><td>Finance</td><td>Full accounting module, AI tools</td></tr>
                <tr><td><code>finance_executive</code></td><td>Finance</td><td>View accounting reports and records</td></tr>
                <tr><td><code>employee</code></td><td>User</td><td>Self-service: leave, payslips, attendance, claims, profile</td></tr>
            </tbody>
        </table>
    </div>
</div>

{{-- ── Capability Methods ── --}}
<div class="kb-section card" id="methods">
    <div class="card-body">
        <h4><i class="bi bi-code-slash me-2"></i>Capability Methods (User Model)</h4>
        <p class="text-muted small">Always use these methods instead of raw role string comparisons.</p>
        <div class="row g-3">
            <div class="col-md-6">
                <h6>Coarse Checks</h6>
                <ul class="small mb-0">
                    <li><code>isHr()</code> — any HR role</li>
                    <li><code>isIt()</code> — any IT role</li>
                    <li><code>isSuperadmin()</code></li>
                    <li><code>isSystemAdmin()</code></li>
                    <li><code>isFinance()</code> — any finance role</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6>Fine-Grained</h6>
                <ul class="small mb-0">
                    <li><code>canViewOnboarding()</code></li>
                    <li><code>canEditOnboarding()</code></li>
                    <li><code>canViewAssets()</code></li>
                    <li><code>canEditAsset()</code></li>
                    <li><code>canManageLeave()</code></li>
                    <li><code>canManagePayroll()</code></li>
                    <li><code>canManageAccounting()</code></li>
                    <li><code>canViewAllClaims()</code></li>
                    <li><code>canManageClaims()</code></li>
                    <li><code>canUseAiChat()</code></li>
                </ul>
            </div>
        </div>
    </div>
</div>

{{-- ── Custom Permissions ── --}}
<div class="kb-section card" id="custom">
    <div class="card-body">
        <h4><i class="bi bi-sliders me-2"></i>Custom Per-Resource Permissions</h4>
        <div class="diagram-container">
            <div class="mermaid">
flowchart LR
    SA["SuperAdmin\nRole Management page"] --> UP["UserPermission model\nper user + per resource"]
    UP --> LEVELS["Access Levels:\nfull / view / edit / none"]
    LEVELS --> CHECK["customPermission(resource)\ncanViewResource(resource)\ncanEditResource(resource)"]
            </div>
        </div>
        <div class="alert alert-info small mt-3 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Custom permissions override the role-based defaults. SuperAdmin can set per-user overrides for any resource via the "Manage Access" button in Role Management.
        </div>
    </div>
</div>

{{-- ── Access Matrix ── --}}
<div class="kb-section card" id="matrix">
    <div class="card-body">
        <h4><i class="bi bi-grid me-2"></i>Module Access Matrix</h4>
        <div class="table-responsive">
            <table class="table table-sm relation-table text-center">
                <thead>
                    <tr>
                        <th class="text-start">Module</th>
                        <th>Super Admin</th><th>HR Mgr</th><th>HR Exec</th><th>IT Mgr</th>
                        <th>Finance</th><th>Employee</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="text-start">Onboarding</td><td>Full</td><td>Full</td><td>View</td><td>View</td><td>—</td><td>—</td></tr>
                    <tr><td class="text-start">Offboarding</td><td>Full</td><td>Full</td><td>View</td><td>View</td><td>—</td><td>—</td></tr>
                    <tr><td class="text-start">Employee Listing</td><td>Full</td><td>Full</td><td>View</td><td>View</td><td>—</td><td>—</td></tr>
                    <tr><td class="text-start">Leave</td><td>Full</td><td>Full</td><td>Manage</td><td>—</td><td>—</td><td>Self</td></tr>
                    <tr><td class="text-start">Payroll</td><td>Full</td><td>Full</td><td>View</td><td>—</td><td>View</td><td>Self</td></tr>
                    <tr><td class="text-start">Claims</td><td>Full</td><td>Full</td><td>View</td><td>—</td><td>—</td><td>Self</td></tr>
                    <tr><td class="text-start">Attendance</td><td>Full</td><td>Full</td><td>View</td><td>—</td><td>—</td><td>Self</td></tr>
                    <tr><td class="text-start">Assets</td><td>Full</td><td>View</td><td>View</td><td>Full</td><td>—</td><td>—</td></tr>
                    <tr><td class="text-start">Accounting</td><td>Full</td><td>—</td><td>—</td><td>—</td><td>Full/View</td><td>—</td></tr>
                    <tr><td class="text-start">C-Suite Reports</td><td>Full</td><td>Full</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>
                    <tr><td class="text-start">KOL Management</td><td>Link</td><td>—</td><td>—</td><td>Link</td><td>—</td><td>—</td></tr>
                </tbody>
            </table>
        </div>
        <p class="text-muted small mb-0">
            <strong>KOL Management</strong> is a link into the KOL Management Portal, a separate system reached
            by SSO — so it is granted or withheld, never "view" or "edit". Beyond the roles marked above, any
            active employee whose department is <em>KOL</em> gets it by default. Either default can be overridden
            per person on Role Management → Manage Access → By Page.
        </p>
    </div>
</div>
@endsection
