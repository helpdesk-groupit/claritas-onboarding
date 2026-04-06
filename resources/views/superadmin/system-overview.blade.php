@extends('layouts.app')
@section('title', 'System Overview')
@section('page-title', 'System Overview')

@section('content')
<style>
    .overview-hero {
        background: linear-gradient(135deg, #0b5ed7 0%, #0d6efd 40%, #6610f2 100%);
        border-radius: 1rem;
        color: #fff;
        padding: 3rem 2rem;
        margin-bottom: 2rem;
        position: relative;
        overflow: hidden;
    }
    .overview-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 500px;
        height: 500px;
        background: rgba(255,255,255,0.05);
        border-radius: 50%;
    }
    .overview-hero::after {
        content: '';
        position: absolute;
        bottom: -30%;
        left: 10%;
        width: 300px;
        height: 300px;
        background: rgba(255,255,255,0.03);
        border-radius: 50%;
    }
    .overview-hero h1 { font-size: 2.2rem; font-weight: 800; letter-spacing: -0.5px; }
    .overview-hero .lead { font-size: 1.1rem; opacity: 0.9; max-width: 700px; }
    .stat-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255,255,255,0.15);
        backdrop-filter: blur(10px);
        border-radius: 2rem;
        padding: 0.5rem 1.2rem;
        font-size: 0.85rem;
        font-weight: 600;
        border: 1px solid rgba(255,255,255,0.2);
    }
    .stat-pill .num { font-size: 1.3rem; font-weight: 800; }

    .module-card {
        border: none;
        border-radius: 1rem;
        transition: all 0.3s ease;
        overflow: hidden;
        height: 100%;
    }
    .module-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.12);
    }
    .module-card .card-header {
        padding: 1.25rem 1.5rem;
        border-bottom: none;
        font-weight: 700;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .module-card .card-header i { font-size: 1.5rem; }
    .module-card .card-body { padding: 1rem 1.5rem 1.5rem; }
    .module-card .feature-list { list-style: none; padding: 0; margin: 0; }
    .module-card .feature-list li {
        padding: 0.35rem 0;
        font-size: 0.875rem;
        color: #495057;
        display: flex;
        align-items: flex-start;
        gap: 0.5rem;
    }
    .module-card .feature-list li::before {
        content: '✓';
        color: #198754;
        font-weight: 700;
        flex-shrink: 0;
        margin-top: 1px;
    }

    .mc-onboarding .card-header  { background: linear-gradient(135deg, #0d6efd, #0b5ed7); color: #fff; }
    .mc-employee .card-header    { background: linear-gradient(135deg, #198754, #157347); color: #fff; }
    .mc-offboarding .card-header { background: linear-gradient(135deg, #dc3545, #b02a37); color: #fff; }
    .mc-assets .card-header      { background: linear-gradient(135deg, #fd7e14, #e8590c); color: #fff; }
    .mc-leave .card-header       { background: linear-gradient(135deg, #20c997, #0ca678); color: #fff; }
    .mc-payroll .card-header     { background: linear-gradient(135deg, #6610f2, #520dc2); color: #fff; }
    .mc-attendance .card-header  { background: linear-gradient(135deg, #0dcaf0, #0aa3c4); color: #fff; }
    .mc-claims .card-header      { background: linear-gradient(135deg, #d63384, #ab296a); color: #fff; }
    .mc-reports .card-header     { background: linear-gradient(135deg, #1a237e, #283593); color: #fff; }
    .mc-accounting .card-header  { background: linear-gradient(135deg, #00695c, #00897b); color: #fff; }

    .flow-section {
        background: #f8f9fa;
        border-radius: 1rem;
        padding: 2rem;
        margin-bottom: 2rem;
    }
    .flow-section h3 { color: #0b5ed7; font-weight: 700; margin-bottom: 1rem; }

    .flow-step {
        text-align: center;
        position: relative;
    }
    .flow-step .step-circle {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 0.75rem;
        font-size: 1.5rem;
        color: #fff;
        font-weight: 700;
    }
    .flow-step .step-label { font-weight: 600; font-size: 0.9rem; color: #212529; }
    .flow-step .step-desc { font-size: 0.8rem; color: #6c757d; margin-top: 0.25rem; }

    .flow-arrow {
        display: flex;
        align-items: center;
        justify-content: center;
        padding-top: 1rem;
    }
    .flow-arrow i { font-size: 1.5rem; color: #adb5bd; }

    .role-card {
        border-radius: 0.75rem;
        padding: 1.25rem;
        text-align: center;
        transition: all 0.3s ease;
        height: 100%;
    }
    .role-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    .role-card i { font-size: 2rem; margin-bottom: 0.5rem; display: block; }
    .role-card h6 { font-weight: 700; margin-bottom: 0.5rem; }
    .role-card .role-perms { font-size: 0.78rem; color: #6c757d; text-align: left; }

    .security-badge {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.25rem;
        background: #fff;
        border-radius: 0.75rem;
        border: 1px solid #e9ecef;
        height: 100%;
    }
    .security-badge i { font-size: 1.5rem; flex-shrink: 0; }
    .security-badge .sb-title { font-weight: 600; font-size: 0.9rem; }
    .security-badge .sb-desc { font-size: 0.78rem; color: #6c757d; }

    .compliance-stamp {
        background: linear-gradient(135deg, #198754, #0f5132);
        color: #fff;
        border-radius: 1rem;
        padding: 2rem;
        text-align: center;
    }
    .compliance-stamp .score {
        font-size: 4rem;
        font-weight: 900;
        line-height: 1;
    }
    .compliance-stamp .score-label {
        font-size: 1rem;
        opacity: 0.8;
    }

    .email-flow-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .email-flow-item:last-child { border-bottom: none; }
    .email-count {
        background: #0d6efd;
        color: #fff;
        border-radius: 50%;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    .tech-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.4rem 0.8rem;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 2rem;
        font-size: 0.82rem;
        font-weight: 600;
        color: #495057;
    }

    @media print {
        .overview-hero { background: #0d6efd !important; -webkit-print-color-adjust: exact; }
        .module-card:hover { transform: none; box-shadow: none; }
    }
</style>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- HERO SECTION --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="overview-hero">
    <div class="position-relative" style="z-index:1;">
        <h1><i class="bi bi-diagram-3 me-2"></i>HRM &amp; Finance System</h1>
        <p class="lead mb-4">
            Complete multi-role HR management &amp; AI-powered accounting platform covering the entire employee
            lifecycle — from pre-hire onboarding through payroll, leave, attendance, and expense claims to exit
            offboarding — plus full double-entry accounting with AI invoice scanning and chatbot.
            Built for Malaysian companies with full statutory compliance.
        </p>
        <div class="d-flex flex-wrap gap-3">
            <span class="stat-pill"><span class="num">{{ \App\Models\Employee::count() }}</span> Employees</span>
            <span class="stat-pill"><span class="num">10</span> Modules</span>
            <span class="stat-pill"><span class="num">87</span> Database Tables</span>
            <span class="stat-pill"><span class="num">310+</span> Endpoints</span>
            <span class="stat-pill"><span class="num">24</span> Automated Emails</span>
            <span class="stat-pill"><span class="num">94/100</span> Security Score</span>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- EMPLOYEE LIFECYCLE FLOW --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="flow-section">
    <h3><i class="bi bi-arrow-right-circle me-2"></i>Employee Lifecycle Flow</h3>
    <p class="text-muted mb-4">The complete journey of an employee through the system — each stage is fully automated with email notifications and task tracking.</p>

    <div class="row g-0 align-items-start">
        <div class="col flow-step">
            <div class="step-circle" style="background:#0d6efd;">1</div>
            <div class="step-label">Onboarding</div>
            <div class="step-desc">HR creates record,<br>invite email sent</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#6610f2;">2</div>
            <div class="step-label">Registration</div>
            <div class="step-desc">New hire fills form,<br>sets password</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#198754;">3</div>
            <div class="step-label">Active Employee</div>
            <div class="step-desc">Full HRM services:<br>payroll, leave, claims</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#dc3545;">4</div>
            <div class="step-label">Offboarding</div>
            <div class="step-desc">Exit process, asset<br>return, IT cleanup</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#212529;">5</div>
            <div class="step-label">Archived</div>
            <div class="step-desc">Permanent record<br>retained</div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- 8 MODULE CARDS --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<h3 class="fw-bold mb-3"><i class="bi bi-grid-3x3-gap text-primary me-2"></i>System Modules</h3>
<p class="text-muted mb-4">Ten integrated modules covering human resource management and AI-powered accounting.</p>

<div class="row g-4 mb-5">
    {{-- Onboarding --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-onboarding">
            <div class="card-header"><i class="bi bi-person-plus"></i> Onboarding</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Automated invite email with secure token</li>
                    <li>Multi-step self-service form (9 sections)</li>
                    <li>Staging JSON for pre-hire data</li>
                    <li>Auto-activation on start date</li>
                    <li>IT task auto-generation</li>
                    <li>Consent tracking & re-acknowledgement</li>
                    <li>CSV import/export</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Employee Management --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-employee">
            <div class="card-header"><i class="bi bi-people"></i> Employee Mgmt</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Central employee record (50+ fields)</li>
                    <li>Personal, work, education, family details</li>
                    <li>Employment contracts management</li>
                    <li>NRIC/passport multi-file upload</li>
                    <li>Edit log with re-consent flow</li>
                    <li>Manager hierarchy tracking</li>
                    <li>Self-service profile editing</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Offboarding --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-offboarding">
            <div class="card-header"><i class="bi bi-person-dash"></i> Offboarding</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Automated exit process tracking</li>
                    <li>10+ status fields per exit step</li>
                    <li>Calendar invites (ICS attachments)</li>
                    <li>Timed email reminders (1 month → 1 week → 3 days)</li>
                    <li>Asset return coordination</li>
                    <li>Separate HR & IT views</li>
                    <li>Employee history archival snapshot</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- IT Assets --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-assets">
            <div class="card-header"><i class="bi bi-laptop"></i> IT Assets</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Full asset inventory tracking</li>
                    <li>Assignment & provisioning workflow</li>
                    <li>AARF with dual acknowledgement (email token)</li>
                    <li>Rental & warranty tracking</li>
                    <li>Asset disposal management</li>
                    <li>Photo documentation (multi-file)</li>
                    <li>CSV import/export</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Leave Management --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-leave">
            <div class="card-header"><i class="bi bi-calendar-check"></i> Leave</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>9 Malaysian statutory leave types</li>
                    <li>Tenure-based entitlement engine</li>
                    <li>Two-tier approval (Manager → HR)</li>
                    <li>Balance tracking with carry-forward</li>
                    <li>Half-day leave support</li>
                    <li>Public holiday management</li>
                    <li>Automated manager reminders</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Payroll --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-payroll">
            <div class="card-header"><i class="bi bi-cash-stack"></i> Payroll</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Malaysian statutory deductions (EPF, SOCSO, EIS, PCB)</li>
                    <li>Pay run workflow (draft → approve → paid)</li>
                    <li>Auto payslip generation</li>
                    <li>Salary management & adjustments</li>
                    <li>Borang EA / CP.8D tax forms</li>
                    <li>HRDF employer contribution</li>
                    <li>Expense claim auto-integration</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Attendance --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-attendance">
            <div class="card-header"><i class="bi bi-clock-history"></i> Attendance</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Clock in/out with IP logging</li>
                    <li>Auto work hours calculation</li>
                    <li>Multiple work schedules per company</li>
                    <li>Overtime request & approval</li>
                    <li>Multiplier-based OT calculation</li>
                    <li>Status tracking (present, late, absent, etc.)</li>
                    <li>HR attendance reports</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Expense Claims --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-claims">
            <div class="card-header"><i class="bi bi-receipt-cutoff"></i> eClaims</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Monthly claim submission</li>
                    <li>13 expense categories with auto-detect</li>
                    <li>GST handling (configurable rate)</li>
                    <li>Receipt upload & management</li>
                    <li>Two-tier approval + bulk approve</li>
                    <li>CSV export with security protection</li>
                    <li>Auto payroll integration on approval</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- C-Suite Reports --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-reports">
            <div class="card-header"><i class="bi bi-graph-up-arrow"></i> C-Suite Reports</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Executive dashboard with 10 KPIs</li>
                    <li>Workforce analytics & demographics</li>
                    <li>Financial & payroll trend reports</li>
                    <li>Statutory contribution summaries</li>
                    <li>Leave & attendance analytics</li>
                    <li>Asset portfolio overview</li>
                    <li>Interactive Chart.js visualizations</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- AI Accounting SaaS --}}
    <div class="col-md-6 col-lg-3">
        <div class="card module-card mc-accounting">
            <div class="card-header"><i class="bi bi-calculator"></i> AI Accounting</div>
            <div class="card-body">
                <ul class="feature-list">
                    <li>Double-entry bookkeeping engine</li>
                    <li>Chart of Accounts (Malaysian CoA)</li>
                    <li>AR/AP: invoices, bills, purchase orders</li>
                    <li>Banking, reconciliation & transfers</li>
                    <li>SST/WHT tax codes & returns</li>
                    <li>Fixed assets & depreciation</li>
                    <li>Budgets with 12-month variance</li>
                    <li>8 financial reports (TB, P&L, BS, CF…)</li>
                    <li>AI invoice OCR (OpenAI Vision)</li>
                    <li>AI finance chatbot</li>
                    <li>Executive financial dashboard</li>
                </ul>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- TWO-TIER APPROVAL FLOW --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="flow-section">
    <h3><i class="bi bi-check2-circle me-2"></i>Two-Tier Approval Workflow</h3>
    <p class="text-muted mb-4">Leave applications, expense claims, and overtime requests follow a consistent two-tier approval process.</p>

    <div class="row g-0 align-items-start">
        <div class="col flow-step">
            <div class="step-circle" style="background:#6c757d;">
                <i class="bi bi-pencil-square" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Employee Submits</div>
            <div class="step-desc">Fills details and<br>uploads documents</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#ffc107;color:#212529;">
                <i class="bi bi-person-check" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Manager Review</div>
            <div class="step-desc">Approve or reject<br>with remarks</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#0d6efd;">
                <i class="bi bi-shield-check" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">HR Review</div>
            <div class="step-desc">Final approval<br>or bulk approve</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#198754;">
                <i class="bi bi-check-lg" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Approved</div>
            <div class="step-desc">Auto-notifications<br>and balance updates</div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- ROLE ACCESS OVERVIEW --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<h3 class="fw-bold mb-3"><i class="bi bi-shield-lock text-primary me-2"></i>Role-Based Access</h3>
<p class="text-muted mb-4">Five role groups with granular sub-roles controlling access to every feature.</p>

<div class="row g-3 mb-5">
    <div class="col-md-6 col-lg-3">
        <div class="role-card" style="background: linear-gradient(180deg, #fce4ec 0%, #fff 100%); border: 1px solid #f8bbd0;">
            <i class="bi bi-star-fill text-danger"></i>
            <h6 class="text-danger">SuperAdmin</h6>
            <div class="role-perms">
                <div>✓ Full system access</div>
                <div>✓ Company management</div>
                <div>✓ Role & permission assignment</div>
                <div>✓ Account activation</div>
                <div>✓ All HR Manager capabilities</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="role-card" style="background: linear-gradient(180deg, #e3f2fd 0%, #fff 100%); border: 1px solid #bbdefb;">
            <i class="bi bi-person-badge text-primary"></i>
            <h6 class="text-primary">HR Group</h6>
            <div class="role-perms">
                <div><strong>Manager:</strong> Full HR + HRM operations</div>
                <div><strong>Executive:</strong> Restricted editing</div>
                <div><strong>Intern:</strong> View-only access</div>
                <div>✓ Onboarding / Offboarding</div>
                <div>✓ Payroll, Leave, Attendance, Claims</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="role-card" style="background: linear-gradient(180deg, #e8f5e9 0%, #fff 100%); border: 1px solid #c8e6c9;">
            <i class="bi bi-gear text-success"></i>
            <h6 class="text-success">IT Group</h6>
            <div class="role-perms">
                <div><strong>Manager:</strong> Full IT operations</div>
                <div><strong>Executive:</strong> Asset management</div>
                <div><strong>Intern:</strong> View-only access</div>
                <div>✓ Asset inventory & provisioning</div>
                <div>✓ IT task management</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="role-card" style="background: linear-gradient(180deg, #f3e5f5 0%, #fff 100%); border: 1px solid #e1bee7;">
            <i class="bi bi-person text-purple" style="color:#6610f2 !important;"></i>
            <h6 style="color:#6610f2;">Employee</h6>
            <div class="role-perms">
                <div>✓ Self-service profile</div>
                <div>✓ Leave applications</div>
                <div>✓ Payslip & EA form viewing</div>
                <div>✓ Clock in/out & overtime</div>
                <div>✓ Expense claim submission</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="role-card" style="background: linear-gradient(180deg, #e0f2f1 0%, #fff 100%); border: 1px solid #b2dfdb;">
            <i class="bi bi-calculator" style="color:#00695c;"></i>
            <h6 style="color:#00695c;">Finance Group</h6>
            <div class="role-perms">
                <div><strong>Manager:</strong> Full accounting access + AI</div>
                <div><strong>Executive:</strong> View accounting data</div>
                <div>✓ Chart of Accounts & General Ledger</div>
                <div>✓ AR/AP, Banking, Tax, Budgets</div>
                <div>✓ Financial reports & dashboards</div>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- SECURITY & COMPLIANCE --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="row g-4 mb-5">
    <div class="col-lg-4">
        <div class="compliance-stamp h-100">
            <div class="score">94<span style="font-size:2rem;">/100</span></div>
            <div class="score-label mt-2">OWASP Security Score</div>
            <hr style="border-color:rgba(255,255,255,0.2);">
            <div class="d-flex flex-column gap-2 text-start" style="font-size:0.85rem;">
                <div><i class="bi bi-check-circle-fill me-2"></i>OWASP Top 10 Compliant</div>
                <div><i class="bi bi-check-circle-fill me-2"></i>6-Layer Defense Architecture</div>
                <div><i class="bi bi-check-circle-fill me-2"></i>Encrypted Secrets at Rest</div>
                <div><i class="bi bi-check-circle-fill me-2"></i>Malaysian PDPA Aligned</div>
                <div><i class="bi bi-check-circle-fill me-2"></i>Employment Act 1955 Compliant</div>
                <div><i class="bi bi-check-circle-fill me-2"></i>Encrypted Backups (AES-256)</div>
                <div><i class="bi bi-check-circle-fill me-2"></i>HMAC Log Integrity Chain</div>
                <div><i class="bi bi-check-circle-fill me-2"></i>Real-time Threat Detection</div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <h4 class="fw-bold mb-3"><i class="bi bi-shield-fill-check text-success me-2"></i>Security Architecture</h4>
        <div class="row g-2">
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-lock-fill text-danger"></i>
                    <div>
                        <div class="sb-title">Security Headers</div>
                        <div class="sb-desc">HSTS, CSP, X-Frame-Options, X-Content-Type, Permissions-Policy</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-speedometer2 text-warning"></i>
                    <div>
                        <div class="sb-title">Rate Limiting</div>
                        <div class="sb-desc">Login: 30/min, Uploads: 10/min, AI: 10-30/min, Password Reset: 5/min</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-key-fill text-primary"></i>
                    <div>
                        <div class="sb-title">Custom Authentication</div>
                        <div class="sb-desc">Work email provider, single-session enforcement, timing-safe hash</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-eye-slash-fill text-success"></i>
                    <div>
                        <div class="sb-title">XSS / CSRF / SQLi Prevention</div>
                        <div class="sb-desc">Blade escaping, CSRF tokens, Eloquent parameterized queries</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-file-earmark-lock2 text-info"></i>
                    <div>
                        <div class="sb-title">Secure File Serving</div>
                        <div class="sb-desc">MIME validation, role-based directory permissions, magic-byte checks</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-journal-text text-secondary"></i>
                    <div>
                        <div class="sb-title">Audit Logging</div>
                        <div class="sb-desc">SecurityAuditLog + AccountingAuditTrail: logins, lockouts, financial operations, IP tracking</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-shield-lock-fill text-danger"></i>
                    <div>
                        <div class="sb-title">Encrypted Backups</div>
                        <div class="sb-desc">AES-256-CBC encryption, HMAC integrity verification, automated daily/6-hourly snapshots</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-image text-info"></i>
                    <div>
                        <div class="sb-title">Image Metadata Stripping</div>
                        <div class="sb-desc">GD pixel-copy reprocessing removes all EXIF/GPS data from uploads</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-fingerprint text-primary"></i>
                    <div>
                        <div class="sb-title">HMAC Log Integrity</div>
                        <div class="sb-desc">SHA-256 chained entries with sequence numbers, tamper-evident audit trail</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                    <div>
                        <div class="sb-title">Real-time Threat Detection</div>
                        <div class="sb-desc">Brute force, privilege escalation, rate anomaly detection with instant email alerts</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="security-badge">
                    <i class="bi bi-globe2 text-success"></i>
                    <div>
                        <div class="sb-title">TLS/HTTPS Enforcement</div>
                        <div class="sb-desc">Forced HTTPS redirect with HSTS headers, URL scheme enforcement</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- EMAIL AUTOMATION --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="flow-section">
    <h3><i class="bi bi-envelope-open me-2"></i>Automated Email System — 24 Workflows</h3>
    <div class="row g-4">
        <div class="col-md-4">
            <h6 class="fw-bold text-primary mb-3">Onboarding & Employee</h6>
            @php
                $onb_mails = [
                    'Onboarding Invite (token link)',
                    'Welcome New Hire (start date)',
                    'Edit Notification (HR changes)',
                    'Consent Request (re-acknowledgement)',
                    'Employee Consent (profile edits)',
                    'Announcement Broadcast',
                ];
            @endphp
            @foreach($onb_mails as $i => $mail)
            <div class="email-flow-item">
                <span class="email-count">{{ $i + 1 }}</span>
                <span style="font-size:0.85rem;">{{ $mail }}</span>
            </div>
            @endforeach
        </div>
        <div class="col-md-4">
            <h6 class="fw-bold text-danger mb-3">Offboarding & Assets</h6>
            @php
                $off_mails = [
                    'Offboarding Notice (1 month)',
                    'Offboarding Reminder (3 days)',
                    'Week Reminder (1 week)',
                    'Sendoff Email (exit day)',
                    'Calendar Invite (ICS)',
                    'AARF Acknowledgement (token)',
                ];
            @endphp
            @foreach($off_mails as $i => $mail)
            <div class="email-flow-item">
                <span class="email-count" style="background:#dc3545;">{{ $i + 7 }}</span>
                <span style="font-size:0.85rem;">{{ $mail }}</span>
            </div>
            @endforeach
        </div>
        <div class="col-md-4">
            <h6 class="fw-bold text-success mb-3">HRM Modules</h6>
            @php
                $hrm_mails = [
                    'Leave Application Notify (manager)',
                    'Leave Approval Notify (employee)',
                    'Pending Leave Reminder (daily)',
                    'Claim Submitted (manager + HR)',
                    'Claim Approved / Rejected',
                    'Claim Deadline Reminder',
                    'Payslip Ready',
                    'EA Form Ready',
                    'Security Audit Report',
                    'Suspicious Activity Alert',
                ];
            @endphp
            @foreach($hrm_mails as $i => $mail)
            <div class="email-flow-item">
                <span class="email-count" style="background:#198754;">{{ $i + 13 }}</span>
                <span style="font-size:0.85rem;">{{ $mail }}</span>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- PAYROLL FLOW --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="flow-section">
    <h3><i class="bi bi-cash-coin me-2"></i>Payroll Processing Flow</h3>
    <p class="text-muted mb-4">Malaysian statutory-compliant payroll with automatic EPF, SOCSO, EIS, and PCB deductions.</p>

    <div class="row g-0 align-items-start">
        <div class="col flow-step">
            <div class="step-circle" style="background:#6c757d;">
                <i class="bi bi-currency-dollar" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Salary Setup</div>
            <div class="step-desc">Basic salary,<br>allowances, items</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#0d6efd;">
                <i class="bi bi-file-earmark-plus" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Create Pay Run</div>
            <div class="step-desc">Select month,<br>company, dates</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#ffc107;color:#212529;">
                <i class="bi bi-calculator" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Generate Payslips</div>
            <div class="step-desc">Auto EPF/SOCSO/<br>EIS/PCB calculation</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#198754;">
                <i class="bi bi-check-circle" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Approve & Pay</div>
            <div class="step-desc">Manager approval,<br>payslip emails sent</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#6610f2;">
                <i class="bi bi-file-earmark-text" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">EA Form</div>
            <div class="step-desc">Annual tax form<br>auto-generated</div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- TECHNOLOGY STACK --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<h3 class="fw-bold mb-3"><i class="bi bi-code-slash text-primary me-2"></i>Technology Stack</h3>
<div class="d-flex flex-wrap gap-2 mb-5">
    <span class="tech-badge"><i class="bi bi-filetype-php text-primary"></i> PHP 8.3</span>
    <span class="tech-badge"><i class="bi bi-box text-danger"></i> Laravel 12</span>
    <span class="tech-badge"><i class="bi bi-database text-warning"></i> MySQL 8.4</span>
    <span class="tech-badge"><i class="bi bi-palette text-info"></i> Tailwind CSS v4</span>
    <span class="tech-badge"><i class="bi bi-bootstrap text-purple" style="color:#6610f2 !important;"></i> Bootstrap 5.3</span>
    <span class="tech-badge"><i class="bi bi-lightning text-success"></i> Vite 7</span>
    <span class="tech-badge"><i class="bi bi-braces text-secondary"></i> Alpine.js</span>
    <span class="tech-badge"><i class="bi bi-bar-chart-line text-primary"></i> Chart.js 4.4</span>
    <span class="tech-badge"><i class="bi bi-robot text-success"></i> OpenAI GPT-4o (Vision + Chat)</span>
    <span class="tech-badge"><i class="bi bi-envelope text-primary"></i> SMTP Mail (23 classes)</span>
    <span class="tech-badge"><i class="bi bi-clock text-warning"></i> Task Scheduler (10 commands)</span>
    <span class="tech-badge"><i class="bi bi-shield-check text-success"></i> OWASP Compliant</span>
    <span class="tech-badge"><i class="bi bi-flag text-danger"></i> Malaysian Statutory (EPF/SOCSO/EIS/PCB)</span>
    <span class="tech-badge"><i class="bi bi-journal-bookmark text-info"></i> Double-Entry Bookkeeping</span>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- LIVE STATS --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<h3 class="fw-bold mb-3"><i class="bi bi-bar-chart text-primary me-2"></i>Live System Statistics</h3>
<div class="row g-3 mb-4">
    @php
        $stats = [
            ['icon' => 'bi-people-fill', 'color' => '#0d6efd', 'label' => 'Active Employees', 'value' => \App\Models\Employee::where('employment_status', 'active')->count()],
            ['icon' => 'bi-person-plus-fill', 'color' => '#198754', 'label' => 'Onboarding', 'value' => \App\Models\Onboarding::where('status', 'in_progress')->count()],
            ['icon' => 'bi-person-dash-fill', 'color' => '#dc3545', 'label' => 'Offboarding', 'value' => \App\Models\Offboarding::where('deactivation_status', '!=', 'done')->count()],
            ['icon' => 'bi-laptop', 'color' => '#fd7e14', 'label' => 'Total Assets', 'value' => \App\Models\AssetInventory::count()],
            ['icon' => 'bi-person-lock', 'color' => '#6610f2', 'label' => 'User Accounts', 'value' => \App\Models\User::count()],
            ['icon' => 'bi-building', 'color' => '#0dcaf0', 'label' => 'Companies', 'value' => \App\Models\Company::count()],
            ['icon' => 'bi-journal-text', 'color' => '#00695c', 'label' => 'Accounts (CoA)', 'value' => \App\Models\Accounting\ChartOfAccount::where('is_active', true)->count()],
            ['icon' => 'bi-receipt', 'color' => '#ab47bc', 'label' => 'Invoices', 'value' => \App\Models\Accounting\SalesInvoice::count()],
        ];
    @endphp
    @foreach($stats as $stat)
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center p-3" style="border-radius:0.75rem;">
            <i class="bi {{ $stat['icon'] }}" style="font-size:2rem; color:{{ $stat['color'] }};"></i>
            <div class="fw-bold fs-3 mt-2" style="color:{{ $stat['color'] }};">{{ $stat['value'] }}</div>
            <div class="text-muted" style="font-size:0.8rem;">{{ $stat['label'] }}</div>
        </div>
    </div>
    @endforeach
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- MALAYSIAN COMPLIANCE --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="flow-section" style="background: linear-gradient(135deg, #fff3cd 0%, #fff 100%); border: 1px solid #ffc107;">
    <h3 style="color:#856404;"><i class="bi bi-flag me-2"></i>Malaysian Statutory Compliance</h3>
    <div class="row g-4">
        <div class="col-md-3">
            <div class="text-center">
                <div class="fw-bold fs-5" style="color:#856404;">EPF / KWSP</div>
                <div class="text-muted small">Employee & employer contributions with 4 categories (A-D) based on age and nationality</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="text-center">
                <div class="fw-bold fs-5" style="color:#856404;">SOCSO / PERKESO</div>
                <div class="text-muted small">Employee & employer social security contributions auto-calculated</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="text-center">
                <div class="fw-bold fs-5" style="color:#856404;">EIS / SIP</div>
                <div class="text-muted small">Employment Insurance System deductions for both parties</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="text-center">
                <div class="fw-bold fs-5" style="color:#856404;">PCB / MTD</div>
                <div class="text-mutable small">Monthly Tax Deduction with Borang EA / CP.8D annual reporting</div>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- ACCOUNTING FLOW --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="flow-section">
    <h3><i class="bi bi-calculator me-2"></i>AI Accounting Processing Flow</h3>
    <p class="text-muted mb-4">Full double-entry accounting with AI-powered invoice scanning and conversational finance chatbot.</p>

    <div class="row g-0 align-items-start">
        <div class="col flow-step">
            <div class="step-circle" style="background:#00695c;">
                <i class="bi bi-diagram-3" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Chart of Accounts</div>
            <div class="step-desc">Malaysian standard<br>CoA (75+ accounts)</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#0d6efd;">
                <i class="bi bi-receipt" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Invoices & Bills</div>
            <div class="step-desc">AR/AP with<br>auto-numbering</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#6610f2;">
                <i class="bi bi-robot" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">AI Invoice OCR</div>
            <div class="step-desc">OpenAI Vision API<br>auto-extract data</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#198754;">
                <i class="bi bi-journal-check" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Journal Entries</div>
            <div class="step-desc">Balanced debit/credit<br>auto-posted</div>
        </div>
        <div class="col-auto flow-arrow"><i class="bi bi-chevron-right"></i></div>
        <div class="col flow-step">
            <div class="step-circle" style="background:#dc3545;">
                <i class="bi bi-file-earmark-bar-graph" style="font-size:1.3rem;"></i>
            </div>
            <div class="step-label">Financial Reports</div>
            <div class="step-desc">TB, P&L, BS,<br>Cash Flow, Aged AR/AP</div>
        </div>
    </div>
</div>

<div class="text-center text-muted py-4" style="font-size:0.8rem;">
    <i class="bi bi-info-circle me-1"></i>
    HRM &amp; Finance System v3.0 &mdash; Architecture overview generated April 2026
</div>

@endsection
