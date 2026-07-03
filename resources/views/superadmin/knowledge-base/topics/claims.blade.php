@extends('superadmin.knowledge-base.topic-layout')

@section('topic-content')
{{-- ── Pipeline ── --}}
<div class="kb-section card" id="pipeline">
    <div class="card-body">
        <h4><i class="bi bi-signpost-split me-2"></i>Expense Claim Pipeline</h4>
        <div class="diagram-container">
            <div class="mermaid">
flowchart TD
    A["Employee Adds First Item\ngetOrCreateDraft creates\nEC-YYYY-MM-NNNN"] --> B{"Validation Checks"}
    B -->|"Dup item\n(date+desc+amount)"| X1["Rejected"]
    B -->|"Dup receipt\n(SHA-256 hash)"| X2["Rejected"]
    B -->|"Category limit"| X3["Rejected"]
    B -->|"Pass"| C["Item saved to Draft\nReceipt stored\nTotals recalculated"]
    C --> D["Employee Submits\nStatus: submitted\nItems LOCKED"]
    D --> E["ClaimSubmittedMail → Manager"]

    E --> F{"Manager Review"}
    F -->|"Approve"| G["Status: manager_approved\nStaleness check performed"]
    F -->|"Reject"| H["Status: manager_rejected\nItems UNLOCKED\nRemarks required"]
    H -.->|"Edit & resubmit"| D

    G --> I["ClaimApprovedMail → Employee\nClaimSubmittedMail → All HR"]
    I --> J{"HR Review"}
    J -->|"Approve / Bulk"| K["Status: hr_approved"]
    J -->|"Reject"| L["Status: hr_rejected\nItems UNLOCKED"]
    L -.->|"Edit & resubmit"| D

    K --> M["Pay Run Generation\nclaim_reimbursement on Payslip\nStatus → paid"]

    style A fill:#e2e3e5,stroke:#6c757d,color:#000
    style D fill:#cfe2ff,stroke:#0d6efd,color:#000
    style G fill:#fef3cd,stroke:#ffc107,color:#000
    style K fill:#d1e7dd,stroke:#198754,color:#000
    style M fill:#d1e7dd,stroke:#198754,color:#000
    style H fill:#f8d7da,stroke:#dc3545,color:#000
    style L fill:#f8d7da,stroke:#dc3545,color:#000
            </div>
        </div>
    </div>
</div>

{{-- ── Status Flow ── --}}
<div class="kb-section card" id="status">
    <div class="card-body">
        <h4><i class="bi bi-tag me-2"></i>Status Transitions (8 States)</h4>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <span class="status-badge" style="background:#e2e3e5;color:#41464b;">draft</span><span>→</span>
            <span class="status-badge" style="background:#cfe2ff;color:#084298;">submitted</span><span>→</span>
            <span class="status-badge" style="background:#fef3cd;color:#856404;">manager_approved</span><span>→</span>
            <span class="status-badge" style="background:#d1e7dd;color:#0f5132;">hr_approved</span><span>→</span>
            <span class="status-badge" style="background:#d1e7dd;color:#0f5132;">paid</span>
        </div>
        <table class="table table-sm relation-table">
            <thead><tr><th>Status</th><th>Editable?</th><th>Items</th><th>Who Acts</th></tr></thead>
            <tbody>
                <tr><td><code>draft</code></td><td>Yes</td><td>Unlocked</td><td>Employee</td></tr>
                <tr><td><code>submitted</code></td><td>No</td><td>Locked</td><td>Manager reviews</td></tr>
                <tr><td><code>manager_approved</code></td><td>No</td><td>Locked</td><td>HR reviews</td></tr>
                <tr><td><code>manager_rejected</code></td><td>Yes</td><td>Unlocked</td><td>Employee edits</td></tr>
                <tr><td><code>hr_approved</code></td><td>No</td><td>Locked</td><td>Awaits payroll</td></tr>
                <tr><td><code>hr_rejected</code></td><td>Yes</td><td>Unlocked</td><td>Employee edits</td></tr>
                <tr><td><code>paid</code></td><td>No</td><td>Locked</td><td>Done</td></tr>
                <tr><td><code>cancelled</code></td><td>No</td><td>—</td><td>—</td></tr>
            </tbody>
        </table>
    </div>
</div>

{{-- ── Duplicate Detection ── --}}
<div class="kb-section card" id="dedup">
    <div class="card-body">
        <h4><i class="bi bi-search me-2"></i>Dual Duplicate Detection</h4>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="border rounded p-3">
                    <h6 class="text-primary"><i class="bi bi-body-text me-1"></i>Content Duplicate</h6>
                    <p class="small mb-0">Same <code>expense_date</code> + <code>description</code> + <code>amount</code> across all employee's non-cancelled claims.</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3">
                    <h6 class="text-warning"><i class="bi bi-file-earmark-binary me-1"></i>Receipt Duplicate</h6>
                    <p class="small mb-0">SHA-256 hash of uploaded file matched against <code>receipt_hash</code> on all employee's claim items.</p>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Emails ── --}}
<div class="kb-section card" id="emails">
    <div class="card-body">
        <h4><i class="bi bi-envelope me-2"></i>Email Notifications (7 Triggers)</h4>
        <table class="table table-sm relation-table">
            <thead><tr><th>Event</th><th>Mail Class</th><th>Recipient</th></tr></thead>
            <tbody>
                <tr><td>Employee submits</td><td><code>ClaimSubmittedMail</code> (manager)</td><td>Manager</td></tr>
                <tr><td>Manager approves</td><td><code>ClaimApprovedMail</code> + <code>ClaimSubmittedMail</code> (hr)</td><td>Employee + All HR</td></tr>
                <tr><td>Manager rejects</td><td><code>ClaimRejectedMail</code></td><td>Employee</td></tr>
                <tr><td>HR approves</td><td><code>ClaimApprovedMail</code></td><td>Employee</td></tr>
                <tr><td>HR bulk approves</td><td><code>ClaimApprovedMail</code> (each)</td><td>Each employee</td></tr>
                <tr><td>HR rejects</td><td><code>ClaimRejectedMail</code></td><td>Employee</td></tr>
                <tr><td>Deadline reminder</td><td><code>ClaimReminderMail</code></td><td>Employees with draft items</td></tr>
            </tbody>
        </table>
    </div>
</div>

{{-- ── Manager Staleness ── --}}
<div class="kb-section card" id="staleness">
    <div class="card-body">
        <h4><i class="bi bi-arrow-repeat me-2"></i>Manager Staleness Check</h4>
        <div class="alert alert-info small mb-0">
            <i class="bi bi-info-circle me-1"></i>
            On approval/rejection, the system <strong>refreshes</strong> the employee's manager relationship and verifies the approver is still the <strong>current</strong> manager. This prevents a former manager from acting on claims after a reporting-line change.
        </div>
    </div>
</div>

{{-- ── Database ── --}}
<div class="kb-section card" id="database">
    <div class="card-body">
        <h4><i class="bi bi-database me-2"></i>Database Relations</h4>
        <div class="diagram-container">
            <div class="mermaid">
erDiagram
    EMPLOYEES ||--o{ EXPENSE_CLAIMS : submits
    EXPENSE_CLAIMS ||--o{ EXPENSE_CLAIM_ITEMS : contains
    EXPENSE_CATEGORIES ||--o{ EXPENSE_CLAIM_ITEMS : categorizes
    PAYSLIPS ||--o{ EXPENSE_CLAIMS : "reimburses in"
    PAY_RUNS ||--o{ EXPENSE_CLAIMS : links
    EXPENSE_CLAIMS {
        string claim_number
        string status
        decimal total_with_gst
        int payslip_id
        int pay_run_id
    }
    EXPENSE_CLAIM_ITEMS {
        date expense_date
        string receipt_hash
        boolean is_locked
    }
            </div>
        </div>
    </div>
</div>
@endsection
