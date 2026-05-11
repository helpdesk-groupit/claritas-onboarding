@once
@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .um-section { margin-bottom: 26px; }
    .um-section-title { font-weight: 700; color: #1e40af; font-size: 15px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
    .um-section-title i { color: #2563eb; }
    .um-section p { font-size: 13.5px; color: #334155; line-height: 1.6; }
    .um-step-list { padding-left: 0; list-style: none; counter-reset: step; margin: 8px 0; }
    .um-step-list > li { counter-increment: step; padding-left: 38px; position: relative; margin-bottom: 12px; line-height: 1.55; font-size: 13.5px; color: #334155; }
    .um-step-list > li::before { content: counter(step); position: absolute; left: 0; top: -1px; width: 26px; height: 26px; background: #2563eb; color: white; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; }
    .um-step-list strong { color: #1e293b; }
    .um-tip   { background: #ecfeff; border-left: 4px solid #06b6d4; padding: 10px 14px; border-radius: 6px; margin: 10px 0; font-size: 13px; color: #164e63; }
    .um-warn  { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 6px; margin: 10px 0; font-size: 13px; color: #78350f; }
    .um-tip i, .um-warn i { margin-right: 6px; }
    .um-mockup { border: 1px dashed #cbd5e1; border-radius: 10px; padding: 14px; background: #f8fafc; margin: 12px 0; }
    .um-mockup-caption { font-size: 11px; color: #64748b; font-style: italic; text-align: center; margin: 6px 0 0; }

    .um-card-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .um-mini-card { border-radius: 8px; padding: 12px; color: #fff; font-size: 12px; }
    .um-mini-card .um-num { font-size: 22px; font-weight: 700; }
    .um-mini-card .um-lab { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; opacity: .9; margin-bottom: 6px; }
    .um-mini-card.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
    .um-mini-card.green  { background: linear-gradient(135deg, #059669, #10b981); }
    .um-mini-card.orange { background: linear-gradient(135deg, #d97706, #f59e0b); }

    .um-acc { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; }
    .um-acc-header { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #e0f2fe; font-weight: 600; color: #075985; font-size: 13px; }
    .um-acc-header .um-count { margin-left: auto; background: #0369a1; color: #fff; padding: 1px 8px; border-radius: 999px; font-size: 11px; }
    .um-acc-body { padding: 8px; background: #fff; }
    .um-dept-header { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #f1f5f9; border-radius: 6px; margin-top: 4px; font-size: 12.5px; color: #1e293b; font-weight: 500; }
    .um-dept-header .um-count { margin-left: auto; background: #64748b; color: #fff; padding: 1px 7px; border-radius: 999px; font-size: 11px; }
    .um-ticket-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
    .um-ticket-row:last-child { border-bottom: none; }
    .um-ticket-row .um-tnum { color: #2563eb; font-weight: 600; flex: 0 0 110px; }

    .um-lifecycle {
        display: grid; grid-template-columns: 100px 80px 130px 70px 120px 80px;
        gap: 14px 8px; align-items: center; justify-items: center;
        margin: 14px 0; padding: 18px 12px;
        background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px;
        overflow-x: auto;
    }
    .um-lifecycle .um-lc-cell { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 0; }
    .um-lifecycle .um-lc-box {
        color: #fff; font-weight: 600; padding: 8px 12px; border-radius: 6px;
        text-align: center; min-width: 88px; font-size: 12.5px; line-height: 1.2;
    }
    .um-lifecycle .um-lc-box.lc-gray   { background: #94a3b8; }
    .um-lifecycle .um-lc-box.lc-orange { background: #f59e0b; }
    .um-lifecycle .um-lc-box.lc-green  { background: #10b981; }
    .um-lifecycle .um-lc-box.lc-blue   { background: #0ea5e9; }
    .um-lifecycle .um-lc-box.lc-dark   { background: #1e293b; }
    .um-lifecycle .um-lc-box.small     { padding: 5px 10px; font-size: 11.5px; min-width: 78px; }
    .um-lifecycle .um-lc-arrow { flex-direction: column; gap: 2px; color: #64748b; }
    .um-lifecycle .um-lc-arrow .um-lc-arrow-label { font-size: 10px; color: #475569; font-style: italic; white-space: nowrap; }
    .um-lifecycle .um-lc-arrow i.bi-arrow-right { font-size: 18px; }
    .um-lifecycle .um-lc-down i { color: #64748b; font-size: 18px; }
    .um-lifecycle .um-lc-cap { font-size: 10.5px; color: #64748b; font-style: italic; text-align: center; line-height: 1.35; max-width: 110px; word-wrap: break-word; }
    .um-lifecycle .um-lc-tag { color: #10b981; font-style: italic; font-size: 11px; white-space: nowrap; }
    .um-lifecycle .um-lc-empty { visibility: hidden; }
    .um-lifecycle-footer { font-size: 11px; color: #475569; font-style: italic; text-align: center; margin: -6px 0 14px; }

    [data-theme="dark"] .um-lifecycle { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-lifecycle .um-lc-cap,
    [data-theme="dark"] .um-lifecycle .um-lc-arrow .um-lc-arrow-label { color: #94a3b8; }
    [data-theme="dark"] .um-lifecycle-footer { color: #94a3b8; }

    .um-modal .modal-title { font-weight: 700; color: #1e40af; }
    .um-modal .modal-title i { margin-right: 6px; }

    [data-theme="dark"] .um-section-title { color: #93c5fd; border-color: #334155; }
    [data-theme="dark"] .um-section p, [data-theme="dark"] .um-step-list > li { color: #cbd5e1; }
    [data-theme="dark"] .um-mockup { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-acc { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .um-acc-body { background: #1e293b; }
    [data-theme="dark"] .um-dept-header { background: #0f172a; color: #cbd5e1; }

    /* ── Mobile responsive overrides ── */
    @media (max-width: 768px) {
        .um-section-title { font-size: 14px; }
        .um-section p, .um-step-list > li, .um-tip, .um-warn { font-size: 13px; }

        /* 3-card analytics row: stack vertically */
        .um-card-row { grid-template-columns: 1fr; }

        /* Lifecycle: keep its 6-column grid (it's a 2D diagram) but tighten
           padding and rely on the existing overflow-x: auto so users can
           swipe sideways to see the full flow on phones. */
        .um-lifecycle { padding: 12px 8px; gap: 12px 6px; }
        .um-lifecycle .um-lc-box { font-size: 11.5px; padding: 6px 10px; }
        .um-lifecycle .um-lc-box.small { font-size: 10.5px; padding: 4px 8px; }
        .um-lifecycle .um-lc-cap { font-size: 10px; max-width: 95px; }
        .um-lifecycle .um-lc-arrow .um-lc-arrow-label { font-size: 9.5px; }
        .um-lifecycle-footer { font-size: 10.5px; padding: 0 8px; }

        .um-acc-header { font-size: 12px; padding: 8px 10px; }
        .um-dept-header { font-size: 12px; padding: 6px 8px; }
        .um-ticket-row { font-size: 11px; flex-wrap: wrap; gap: 6px; }
        .um-mockup { padding: 10px; }
    }
    @media (max-width: 480px) {
        .um-section { margin-bottom: 18px; }
        .um-step-list > li { padding-left: 32px; }
        .um-step-list > li::before { width: 22px; height: 22px; font-size: 11px; }
    }
</style>
@endpush
@endonce

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-info-circle-fill"></i> What is this page?</div>
    <p>
        This is the <strong>inbox</strong> for tickets routed to the department(s) you manage. You can see the
        whole department's queue, take ownership as PIC, chat with the raiser, and close tickets out.
        Superadmins and system admins see <strong>every</strong> ticket across the organisation here.
    </p>
    <div class="um-tip"><i class="bi bi-people-fill"></i><strong>Who sees what:</strong> a Tech manager at Company A &mdash; when Tech is configured to serve Company A + Company B + Company C &mdash; sees Tech tickets from all three companies. Visibility follows the dept's served-companies cluster, not just your own company.</div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-arrow-down-circle-fill"></i> How tickets reach you</div>
    <ol class="um-step-list">
        <li>An employee raises a ticket on the My Tickets page and picks a <strong>Company</strong>.</li>
        <li>They pick a <strong>Department</strong> from the dropdown (filtered to departments serving that company).</li>
        <li>If your department serves the chosen company, the ticket lands here. You receive an <strong>email</strong> and an in-app <strong>bell notification</strong>.</li>
        <li>If no PIC is assigned within 24 hours, the system auto-flags the ticket as <strong>Pending</strong> and re-emails the dept managers (throttled to one reminder per 24h).</li>
    </ol>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-bar-chart-fill"></i> Reading the dashboard cards</div>
    <div class="um-mockup">
        <div class="um-card-row">
            <div class="um-mini-card blue">
                <div class="um-lab">By Priority</div>
                <div class="um-num">3</div>
                <div>Active Tickets</div>
            </div>
            <div class="um-mini-card green">
                <div class="um-lab">Avg Resolution by PIC</div>
                <div class="um-num">2d 4h</div>
                <div>Resolved tickets only</div>
            </div>
            <div class="um-mini-card orange">
                <div class="um-lab">Department Health</div>
                <div class="um-num">1 Good</div>
                <div>Avg resolution time</div>
            </div>
        </div>
        <div class="um-mockup-caption">The three cards summarise your department(s) at a glance.</div>
    </div>
    <ul class="um-step-list">
        <li><strong>By Priority</strong> &mdash; counts active tickets across your managed dept(s) by Low / Medium / High / Urgent. <em>Span:</em> all served companies for the dept (cross-company benchmarking).</li>
        <li><strong>Avg Resolution by PIC</strong> &mdash; how quickly each PIC closes tickets. Filter by company to compare team members. Only Resolved tickets count (Closed ones are excluded).</li>
        <li><strong>Department Health</strong> &mdash; tier per department: <span class="badge bg-success">Good</span> &le; 24h, <span class="badge bg-warning text-dark">Amber</span> &le; 72h, <span class="badge bg-danger">Poor</span> &gt; 72h, <span class="badge bg-secondary">No data</span> if nothing resolved yet.</li>
    </ul>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-collection-fill"></i> Tabs and filters</div>
    <div class="um-mockup">
        <ul class="nav nav-tabs" style="margin:0;">
            <li class="nav-item"><span class="nav-link active fw-semibold"><i class="bi bi-collection me-1"></i>All Tickets <span class="badge bg-primary ms-1">3</span></span></li>
            <li class="nav-item"><span class="nav-link"><i class="bi bi-inbox-fill me-1"></i>Assigned to Me <span class="badge bg-secondary ms-1">1</span></span></li>
            <li class="nav-item"><span class="nav-link"><i class="bi bi-archive-fill me-1"></i>Archived <span class="badge bg-secondary ms-1">5</span></span></li>
        </ul>
    </div>
    <ul class="um-step-list">
        <li><strong>All Tickets</strong> &mdash; every active ticket your dept(s) cover, including ones with no PIC yet.</li>
        <li><strong>Assigned to Me</strong> &mdash; only tickets where you're the PIC. Use this as your personal worklist.</li>
        <li><strong>Archived</strong> &mdash; Resolved + Closed tickets, kept for reference and analytics.</li>
    </ul>
    <p>Above the accordion you also have a <strong>status</strong> dropdown and a <strong>department</strong> dropdown for filtering, plus <strong>Expand all</strong> / <strong>Collapse all</strong> bulk controls.</p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-list-task"></i> The ticket inbox (accordion)</div>
    <p>Tickets are grouped by <strong>Company &rarr; Department &rarr; Tickets</strong>:</p>
    <div class="um-mockup">
        <div class="um-acc">
            <div class="um-acc-header"><i class="bi bi-chevron-down"></i><i class="bi bi-building"></i> Company A Sdn Bhd <span class="um-count">2</span></div>
            <div class="um-acc-body">
                <div class="um-dept-header"><i class="bi bi-chevron-down"></i><i class="bi bi-tag"></i> Tech <span class="um-count">1</span></div>
                <div style="padding:6px 10px;">
                    <div class="um-ticket-row">
                        <span class="um-tnum">TIC-2026-0012</span>
                        <span>Feature Request</span>
                        <span class="badge bg-secondary ms-auto">Open</span>
                        <span class="badge bg-light text-dark border">Medium</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="um-mockup-caption">The number badge on each row is the count of tickets at that level.</div>
    </div>
    <p>Click a ticket number to open the detail page where you'll do the actual work.</p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-pencil-square"></i> Working a ticket</div>
    <ol class="um-step-list">
        <li><strong>Open the ticket.</strong> Read the description and any attachments.</li>
        <li><strong>Assign a PIC.</strong> Use the <em>PIC (Person In Charge)</em> dropdown on the left side. The pool includes any active employee whose department matches the ticket's department at one of the served companies, plus all department managers and superadmins. Assigning auto-changes the status to <em>In Progress</em> and emails the PIC.</li>
        <li><strong>Reply via the conversation panel.</strong> Updates auto-refresh every 3 seconds; the raiser is notified of new messages. Attach files via the paperclip if needed.</li>
        <li><strong>Update the status</strong> as you progress. When done: pick <strong>Resolved</strong> (success) or <strong>Closed</strong> (e.g. duplicate / can't reproduce).</li>
    </ol>
    <div class="um-warn"><i class="bi bi-exclamation-triangle-fill"></i><strong>Reassignment:</strong> to hand a ticket off to a different PIC, click <em>Remove PIC</em> first, then assign the new person. This keeps the audit trail clean.</div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-diagram-3"></i> Status lifecycle</div>
    <p>The five statuses and how a ticket moves between them:</p>
    <div class="um-lifecycle">
        <div class="um-lc-cell"><div class="um-lc-box lc-gray">Open</div></div>
        <div class="um-lc-cell um-lc-arrow">
            <span class="um-lc-arrow-label">PIC assigned</span>
            <i class="bi bi-arrow-right"></i>
        </div>
        <div class="um-lc-cell"><div class="um-lc-box lc-orange">In Progress</div></div>
        <div class="um-lc-cell um-lc-arrow">
            <span class="um-lc-arrow-label">solved</span>
            <i class="bi bi-arrow-right"></i>
        </div>
        <div class="um-lc-cell"><div class="um-lc-box lc-green">Resolved</div></div>
        <div class="um-lc-cell"><span class="um-lc-tag">terminal</span></div>

        <div class="um-lc-cell um-lc-down"><i class="bi bi-arrow-down"></i></div>
        <div class="um-lc-empty"></div>
        <div class="um-lc-cell um-lc-down"><i class="bi bi-arrow-down"></i></div>
        <div class="um-lc-empty"></div>
        <div class="um-lc-empty"></div>
        <div class="um-lc-empty"></div>

        <div class="um-lc-cell">
            <div class="um-lc-box lc-blue small">Pending</div>
            <div class="um-lc-cap">auto, after 24h with no PIC</div>
        </div>
        <div class="um-lc-empty"></div>
        <div class="um-lc-cell">
            <div class="um-lc-box lc-dark small">Closed</div>
            <div class="um-lc-cap">manual close without resolution</div>
        </div>
        <div class="um-lc-empty"></div>
        <div class="um-lc-empty"></div>
        <div class="um-lc-cell"><span class="um-lc-tag">terminal</span></div>
    </div>
    <div class="um-lifecycle-footer">
        Both <strong>Resolved</strong> and <strong>Closed</strong> are terminal &mdash; the ticket is auto-archived to the Archived tab.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-question-circle-fill"></i> Why you might not see a ticket you expect</div>
    <ul class="um-step-list">
        <li><strong>Department Settings doesn't list your department for that company.</strong> Open Department Settings (superadmin only) and add it as an Auto-served (via member) or Extra row.</li>
        <li><strong>You're a work-role-gated dept manager (Tech / Marketing / etc.) but your employee record doesn't say so.</strong> HR must set <code>employees.work_role = 'manager'</code> and <code>employees.department = &lt;your dept&gt;</code>.</li>
        <li><strong>Card count says 1 but list says 0:</strong> the ticket exists but its <code>company_id</code> is outside the dept's served cluster (likely a data drift &mdash; ask superadmin to check).</li>
        <li><strong>Ticket says wrong company:</strong> the raiser may have left the Company dropdown on the default. Open the ticket detail; the Company row in the Details card shows what was actually saved. Superadmin can re-route via SQL if needed.</li>
    </ul>
</div>
