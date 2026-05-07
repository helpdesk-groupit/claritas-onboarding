@once
@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    /* Shared User-Manual styles */
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
    .um-callout { display: inline-flex; align-items: center; gap: 4px; background: #fef9c3; color: #854d0e; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .um-badge-row { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 6px; }
    .um-badge-row .badge { font-size: 11px; padding: 5px 10px; }

    /* Mini accordion mockup */
    .um-acc { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; }
    .um-acc-header { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #e0f2fe; font-weight: 600; color: #075985; font-size: 13px; }
    .um-acc-header i.bi-chevron-right { font-size: 11px; }
    .um-acc-header .um-count { margin-left: auto; background: #0369a1; color: #fff; padding: 1px 8px; border-radius: 999px; font-size: 11px; }
    .um-acc-body { padding: 8px; background: #fff; }
    .um-dept-header { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #f1f5f9; border-radius: 6px; margin-top: 4px; font-size: 12.5px; color: #1e293b; font-weight: 500; }
    .um-dept-header i.bi-tag { color: #475569; }
    .um-dept-header .um-count { margin-left: auto; background: #64748b; color: #fff; padding: 1px 7px; border-radius: 999px; font-size: 11px; }
    .um-ticket-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
    .um-ticket-row:last-child { border-bottom: none; }
    .um-ticket-row .um-tnum { color: #2563eb; font-weight: 600; flex: 0 0 110px; }

    /* Annotated form diagram */
    .um-newbtn-mockup { display: flex; align-items: center; gap: 6px; padding: 8px 10px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; margin: 8px 0; }
    .um-diagram {
        display: grid;
        grid-template-columns: minmax(0, 290px) 44px minmax(0, 1fr);
        gap: 14px 12px;
        align-items: center;
        margin: 14px 0;
        padding: 16px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
    }
    .um-diagram .um-col-header {
        font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
        color: #64748b; border-bottom: 2px solid #cbd5e1; padding-bottom: 6px;
    }
    .um-diagram .um-form-row { min-width: 0; }
    .um-diagram .um-form-row label.um-flabel {
        font-size: 11.5px; font-weight: 600; color: #1e293b; margin-bottom: 4px; display: block;
    }
    .um-diagram .um-form-row .um-fmock {
        font-size: 12.5px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px;
        padding: 7px 10px; color: #1e293b; display: flex; align-items: center;
        justify-content: space-between; min-height: 32px;
    }
    .um-diagram .um-form-row .um-fmock .um-caret { color: #94a3b8; }
    .um-diagram .um-form-row .um-fmock.um-textarea {
        height: 56px; align-items: flex-start;
        background-image:
            linear-gradient(to bottom, transparent 22px, #f1f5f9 22px, #f1f5f9 23px, transparent 23px),
            linear-gradient(to bottom, transparent 38px, #f1f5f9 38px, #f1f5f9 39px, transparent 39px);
    }
    .um-diagram .um-form-row .um-fmock.um-fileinput { background:#fff; color:#64748b; font-style:italic; }
    .um-diagram .um-form-row .um-submitbtn { background:#2563eb; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:13px; font-weight:500; display:inline-flex; align-items:center; gap:5px; }
    .um-diagram .um-arrow-cell { display: flex; align-items: center; justify-content: center; }
    .um-diagram .um-arrow-cell i { font-size: 22px; color: #2563eb; }
    .um-diagram .um-instr-row {
        display: flex; gap: 10px; align-items: flex-start;
        padding: 4px 0; font-size: 13px; color: #334155; line-height: 1.55; min-width: 0;
    }
    .um-diagram .um-instr-row .um-num {
        flex: 0 0 26px; height: 26px; background: #2563eb; color: white;
        border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 12px; margin-top: 1px;
    }
    .um-diagram .um-instr-row > .um-itext { flex: 1; min-width: 0; word-wrap: break-word; overflow-wrap: anywhere; }
    .um-diagram .um-instr-row strong { color: #1e293b; }

    /* Detail-page section mockup cards */
    .um-detail-card {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
        padding: 9px 11px; font-size: 11px; color: #1e293b; line-height: 1.4;
    }
    .um-detail-card .um-dc-title { font-weight: 700; font-size: 11.5px; display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
    .um-detail-card .um-dc-row   { display: flex; justify-content: space-between; align-items: center; padding: 1px 0; font-size: 10.5px; }
    .um-detail-card .um-dc-row .um-dc-label { color: #64748b; }
    .um-detail-card.um-chat-header { background: linear-gradient(180deg, #dbeafe 0%, #f0f9ff 100%); border-color: #bfdbfe; }
    .um-detail-card.um-chat-input  { display: flex; align-items: center; gap: 8px; background: #f8fafc; }
    .um-detail-card.um-chat-input .um-chat-placeholder { flex: 1; color: #94a3b8; font-style: italic; }
    .um-detail-card.um-chat-input .bi-send-fill { color: #2563eb; font-size: 14px; }
    .um-detail-card.um-chat-input .bi-paperclip { color: #64748b; font-size: 14px; }

    [data-theme="dark"] .um-diagram { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-diagram .um-form-row label.um-flabel { color: #f1f5f9; }
    [data-theme="dark"] .um-diagram .um-form-row .um-fmock { background: #1e293b; border-color: #475569; color: #cbd5e1; }
    [data-theme="dark"] .um-diagram .um-instr-row { color: #cbd5e1; }
    [data-theme="dark"] .um-diagram .um-instr-row strong { color: #f1f5f9; }
    [data-theme="dark"] .um-newbtn-mockup { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-detail-card { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .um-detail-card .um-dc-row .um-dc-label { color: #94a3b8; }
    [data-theme="dark"] .um-detail-card.um-chat-header { background: #1e3a8a; border-color: #1e40af; color: #dbeafe; }
    [data-theme="dark"] .um-detail-card.um-chat-input  { background: #0f172a; }

    /* Modal title (used only when this body is rendered inside the in-app modal) */
    .um-modal .modal-title { font-weight: 700; color: #1e40af; }
    .um-modal .modal-title i { margin-right: 6px; }

    [data-theme="dark"] .um-section-title { color: #93c5fd; border-color: #334155; }
    [data-theme="dark"] .um-section p, [data-theme="dark"] .um-step-list > li { color: #cbd5e1; }
    [data-theme="dark"] .um-mockup { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-acc { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .um-acc-body { background: #1e293b; }
    [data-theme="dark"] .um-dept-header { background: #0f172a; color: #cbd5e1; }
</style>
@endpush
@endonce

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-info-circle-fill"></i> What is this page?</div>
    <p>
        Use this page to <strong>raise tickets</strong> for any company's department and to <strong>track tickets you've raised</strong>.
        Only tickets that you raised appear here. Tickets that someone else raised which are routed to you sit on the
        Ticket Management page (different page, available only if you are a department manager or PIC).
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-plus-circle-fill"></i> Raising a new ticket</div>
    <p>Open the form by clicking the <strong>New Ticket</strong> button at the top-right of the page:</p>
    <div class="um-newbtn-mockup">
        <span class="btn btn-primary btn-sm disabled" style="pointer-events:none;">
            <i class="bi bi-plus-lg me-1"></i> New Ticket
        </span>
        <span class="text-muted small">&larr; you'll find this button in the page header</span>
    </div>

    <p class="mt-3">The form has 7 fields. Each arrow connects a form field to its instruction:</p>

    <div class="um-diagram">
        <div class="um-col-header">Form field (what you see)</div>
        <div></div>
        <div class="um-col-header">What to do</div>

        <div class="um-form-row">
            <label class="um-flabel">Company <span class="text-danger">*</span></label>
            <div class="um-fmock"><span>Company A Sdn Bhd</span><i class="bi bi-chevron-down um-caret"></i></div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">1</span>
            <div class="um-itext"><strong>Pick the company</strong> the ticket is for. You can raise a ticket against any registered company &mdash; not just your own. The default selection is your own company; change it if needed.</div>
        </div>

        <div class="um-form-row">
            <label class="um-flabel">Priority <span class="text-danger">*</span></label>
            <div class="um-fmock"><span>Medium</span><i class="bi bi-chevron-down um-caret"></i></div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">2</span>
            <div class="um-itext"><strong>Pick the priority</strong> &mdash; Low / Medium / High / Urgent. Pick what reflects real impact; the PIC sees this on their inbox.</div>
        </div>

        <div class="um-form-row">
            <label class="um-flabel">Department <span class="text-danger">*</span></label>
            <div class="um-fmock"><span>Tech</span><i class="bi bi-chevron-down um-caret"></i></div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">3</span>
            <div class="um-itext"><strong>Pick the department.</strong> The dropdown only shows departments configured to serve the company you chose. If a department is missing, that company isn't covered &mdash; ask superadmin to update Department Settings.</div>
        </div>

        <div class="um-form-row">
            <label class="um-flabel">Subject <span class="text-danger">*</span></label>
            <div class="um-fmock"><span>Bug Report</span><i class="bi bi-chevron-down um-caret"></i></div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">4</span>
            <div class="um-itext"><strong>Pick a subject</strong> from the standardised list (so analytics aggregate cleanly), or pick <em>Other</em> and describe it briefly in the extra text box that appears.</div>
        </div>

        <div class="um-form-row">
            <label class="um-flabel">Description <span class="text-danger">*</span></label>
            <div class="um-fmock um-textarea"><span class="text-muted">Login button is unresponsive on Chrome 120...</span></div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">5</span>
            <div class="um-itext"><strong>Write a clear description.</strong> Include what you tried, what you expected, and what actually happened. Screenshots help &mdash; attach them in the next field.</div>
        </div>

        <div class="um-form-row">
            <label class="um-flabel">Attachments <span class="text-muted small">(optional)</span></label>
            <div class="um-fmock um-fileinput">
                <span><i class="bi bi-paperclip me-1"></i>Choose files&hellip;</span>
                <span class="text-muted small">No file chosen</span>
            </div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">6</span>
            <div class="um-itext"><strong>Attach files</strong> if needed &mdash; up to 10 images or PDFs, 10&nbsp;MB each. Images are auto-compressed and EXIF-stripped on upload.</div>
        </div>

        <div class="um-form-row">
            <label class="um-flabel">&nbsp;</label>
            <div><button type="button" class="um-submitbtn" disabled><i class="bi bi-send"></i> Submit Ticket</button></div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">7</span>
            <div class="um-itext"><strong>Click Submit Ticket.</strong> You'll be taken to the ticket detail page; the new ticket also appears under the chosen company's section here on My Tickets.</div>
        </div>
    </div>

    <div class="um-tip"><i class="bi bi-lightbulb"></i><strong>Tip:</strong> the company you pick determines which manager team gets notified. Pick the company the ticket is <em>about</em>, not the company you happen to work at.</div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-list-task"></i> Reading your ticket list</div>
    <p>Tickets are split into three tabs:</p>
    <div class="um-mockup">
        <ul class="nav nav-tabs" style="margin:0;">
            <li class="nav-item"><span class="nav-link active fw-semibold">Active Tickets <span class="badge bg-primary ms-1">3</span></span></li>
            <li class="nav-item"><span class="nav-link">Assigned to Me <span class="badge bg-secondary ms-1">1</span></span></li>
            <li class="nav-item"><span class="nav-link">Archived <span class="badge bg-secondary ms-1">2</span></span></li>
        </ul>
        <div class="um-mockup-caption">
            <strong>Active</strong> = tickets you raised that are Open / In Progress / Pending.
            <strong>Assigned to Me</strong> = tickets where you're the PIC (any status; Resolved / Closed sit at the bottom).
            <strong>Archived</strong> = tickets you raised that are Resolved / Closed.
        </div>
    </div>
    <p>Within each tab, tickets are <strong>grouped by Company &rarr; Department</strong>. Click a company row to see its departments; click a department row to see the tickets:</p>
    <div class="um-mockup">
        <div class="um-acc">
            <div class="um-acc-header"><i class="bi bi-chevron-down"></i><i class="bi bi-building"></i> Company A Sdn Bhd <span class="um-count">2</span></div>
            <div class="um-acc-body">
                <div class="um-dept-header"><i class="bi bi-chevron-down"></i><i class="bi bi-tag"></i> Tech <span class="um-count">1</span></div>
                <div style="padding:6px 10px;">
                    <div class="um-ticket-row">
                        <span class="um-tnum">TIC-2026-0012</span>
                        <span class="badge bg-light text-dark border">Feature Request</span>
                        <span class="badge bg-secondary ms-auto">Open</span>
                    </div>
                </div>
                <div class="um-dept-header"><i class="bi bi-chevron-right"></i><i class="bi bi-tag"></i> HRA <span class="um-count">1</span></div>
            </div>
        </div>
        <div class="um-mockup-caption">Click a ticket number to open its detail page.</div>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-flag-fill"></i> What does each status mean?</div>
    <div class="um-badge-row">
        <span class="badge bg-secondary">Open</span>
        <span class="badge bg-warning text-dark">In Progress</span>
        <span class="badge bg-info text-dark">Pending</span>
        <span class="badge bg-success">Resolved</span>
        <span class="badge bg-dark">Closed</span>
    </div>
    <ul class="um-step-list">
        <li><strong>Open</strong> &mdash; raised, no PIC assigned yet. Department managers can see it on their inbox.</li>
        <li><strong>In Progress</strong> &mdash; a PIC has been assigned and is working on it.</li>
        <li><strong>Pending</strong> &mdash; auto-flagged when an Open ticket has had no PIC for 24 hours. The system also reminds the dept managers.</li>
        <li><strong>Resolved</strong> &mdash; PIC marked it solved (auto-archived; can't reopen).</li>
        <li><strong>Closed</strong> &mdash; closed without resolution (e.g. duplicate, can't reproduce). Also archived.</li>
    </ul>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-card-text"></i> The ticket detail page</div>
    <p>Click any ticket number to open it. The page has two sides &mdash; a left column with the ticket info, and a right column with the live chat. Each section is labelled below:</p>

    <div class="um-diagram">
        <div class="um-col-header">Section (what you see)</div>
        <div></div>
        <div class="um-col-header">What it shows / what you can do</div>

        <div class="um-form-row">
            <div class="um-detail-card">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong>TIC-2026-0012</strong>
                    <span class="badge bg-secondary" style="font-size:10px;">Open</span>
                </div>
                <div style="font-weight:600; font-size:12px;">Feature Request</div>
                <div style="color:#64748b; font-size:10.5px;">Add testing feature</div>
                <div style="color:#64748b; font-size:10.5px; margin-top:5px;"><i class="bi bi-paperclip"></i> 3 attachments</div>
            </div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">1</span>
            <div class="um-itext"><strong>Ticket header</strong> &mdash; your ticket number, current status, subject and description, plus any attachments you uploaded when raising the ticket. Click an attachment to view it.</div>
        </div>

        <div class="um-form-row">
            <div class="um-detail-card">
                <div class="um-dc-title"><i class="bi bi-info-circle"></i> Details</div>
                <div class="um-dc-row"><span class="um-dc-label">Company</span><span class="badge bg-light text-dark border" style="font-size:10px;">Company A</span></div>
                <div class="um-dc-row"><span class="um-dc-label">Department</span><span class="badge bg-light text-dark border" style="font-size:10px;">Tech</span></div>
                <div class="um-dc-row"><span class="um-dc-label">Priority</span><span class="badge bg-primary" style="font-size:10px;">Medium</span></div>
                <div class="um-dc-row"><span class="um-dc-label">Created by</span><span>Jane Smith</span></div>
                <div class="um-dc-row"><span class="um-dc-label">Created at</span><span>05 May 2026, 14:33</span></div>
            </div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">2</span>
            <div class="um-itext"><strong>Details card</strong> &mdash; the metadata of your ticket. The <em>Company</em> row confirms which company you raised it for; the <em>Department</em> row shows where it was routed; the <em>Created by</em>/<em>at</em> rows are an audit trail.</div>
        </div>

        <div class="um-form-row">
            <div class="um-detail-card">
                <div class="um-dc-title"><i class="bi bi-person-badge"></i> PIC (Person In Charge)</div>
                <div style="color:#64748b; font-size:10.5px; font-style:italic; margin-top:3px;">No PIC assigned yet.</div>
            </div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">3</span>
            <div class="um-itext"><strong>PIC card</strong> &mdash; shows who is currently handling your ticket. It will say <em>"No PIC assigned yet"</em> until a manager picks it up; once assigned, you'll see their name and role here, and the ticket's status flips to <em>In&nbsp;Progress</em>.</div>
        </div>

        <div class="um-form-row">
            <div class="um-detail-card um-chat-header">
                <div class="um-dc-title"><i class="bi bi-chat-dots"></i> Conversation</div>
                <div style="font-size:10px; color:#475569;">Live updates every 3 seconds.</div>
                <div style="text-align:center; font-style:italic; color:#94a3b8; font-size:10.5px; padding:10px 0 4px;">No messages yet. Start the conversation below.</div>
            </div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">4</span>
            <div class="um-itext"><strong>Conversation panel</strong> &mdash; live chat thread between you and the PIC. The thread auto-refreshes every 3 seconds, so you don't need to reload the page. New messages from the PIC appear here in real time.</div>
        </div>

        <div class="um-form-row">
            <div class="um-detail-card um-chat-input">
                <i class="bi bi-paperclip"></i>
                <span class="um-chat-placeholder">Type a message&hellip;</span>
                <i class="bi bi-send-fill"></i>
            </div>
        </div>
        <div class="um-arrow-cell"><i class="bi bi-arrow-right-circle-fill"></i></div>
        <div class="um-instr-row">
            <span class="um-num">5</span>
            <div class="um-itext"><strong>Message input</strong> &mdash; type your reply here. Click the paperclip <i class="bi bi-paperclip"></i> to attach images or PDFs to the message, then click the send button <i class="bi bi-send-fill text-primary"></i> to post it. The PIC is notified instantly.</div>
        </div>
    </div>

    <div class="um-tip"><i class="bi bi-shield-check"></i><strong>Privacy:</strong> only you (the raiser), the assigned PIC, the department's managers, and superadmins can see your ticket and its attachments. No one else.</div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-bell-fill"></i> Notifications</div>
    <p>The bell icon (top right) lights up with a count when something happens on one of your tickets:</p>
    <ul class="um-step-list">
        <li>A PIC has been assigned.</li>
        <li>The PIC sent you a new chat message.</li>
        <li>The ticket status changed (e.g. it's been Resolved).</li>
    </ul>
    <p>Click a bell entry to jump straight to that ticket. "Mark all as read" clears the badge.</p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-question-circle-fill"></i> Common questions</div>
    <ul class="um-step-list">
        <li><strong>"My department isn't in the dropdown when I pick this company."</strong> &mdash; the department isn't configured to serve this company. Ask superadmin to add it on Department Settings.</li>
        <li><strong>"Why is my ticket not appearing under the company I picked?"</strong> &mdash; refresh the page. If it's still wrong, hover the ticket detail's <em>Company</em> field to confirm what was actually saved.</li>
        <li><strong>"Can I edit a ticket after submitting?"</strong> &mdash; not directly. Use the conversation thread to add follow-ups; the PIC can see all of it.</li>
        <li><strong>"Can I cancel a ticket?"</strong> &mdash; ask the PIC to mark it Closed.</li>
    </ul>
</div>
