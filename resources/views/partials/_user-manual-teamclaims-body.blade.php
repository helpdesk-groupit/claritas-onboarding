{{-- User-Manual content: Team Claims (manager / approving PIC).
     Single source of truth — included by BOTH the in-app modal (_user-manual-teamclaims.blade.php)
     and the public help page (help/team-claims.blade.php). Keep company names generic. --}}
@include('partials._user-manual-styles')

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-info-circle"></i> What this page is for</div>
    <p>
        <strong>Team Expense Claims</strong> is your approval inbox. Every claim where a staff member
        picked <strong>you</strong> as the approving PIC / manager lands here. You review it, then
        approve it (sending it on to HR) or reject it back to them.
    </p>
    <div class="um-tip">
        <i class="bi bi-diagram-2"></i>You're the <strong>first</strong> of two approvals. After you
        approve, the claim goes to <strong>HR</strong> for the final sign-off and payout.
    </div>
    <div class="um-tip">
        <i class="bi bi-person-check"></i>You only see claims routed to <strong>you</strong>. Your own
        claims live on the <strong>My Claims</strong> page (link at the top right).
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-grid-1x2"></i> The page at a glance</div>

    <div class="um-mockup">
        <div class="um-stat-row">
            <div class="um-stat um-stat-amber"><span class="um-stat-n">5</span><span class="um-stat-l">Pending Manager Approval</span></div>
            <div class="um-stat um-stat-green"><span class="um-stat-n">12</span><span class="um-stat-l">Manager Approved</span></div>
            <div class="um-stat um-stat-red"><span class="um-stat-n">1</span><span class="um-stat-l">Manager Rejected</span></div>
            <div class="um-stat um-stat-grey"><span class="um-stat-n">0</span><span class="um-stat-l">Manager Approved &amp; HR Rejected</span></div>
        </div>
        <div class="um-pills">
            <span class="um-pill is-active">All <b>18</b></span>
            <span class="um-pill">Pending Manager <b>5</b></span>
            <span class="um-pill">Manager Approved <b>12</b></span>
            <span class="um-pill">Manager Rejected <b>1</b></span>
            <span class="um-pill">HR Rejected <b>0</b></span>
        </div>
        <div class="um-mockup-caption">The four count cards, and the status pills you filter with. <strong>Pending Manager</strong> is your to-do list.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Four count cards</span><span class="um-fdesc"><strong>Pending Manager Approval</strong>, <strong>Manager Approved</strong>, <strong>Manager Rejected</strong>, <strong>Manager Approved &amp; HR Rejected</strong>.</span></div>
        <div><span class="um-fname">Filter &amp; search</span><span class="um-fdesc">Pills: <strong>All</strong>, <strong>Pending Manager</strong>, <strong>Manager Approved</strong>, <strong>Manager Rejected</strong>, <strong>HR Rejected</strong>.</span></div>
        <div><span class="um-fname">Search box</span><span class="um-fdesc">Type an employee name or event to find a claim fast.</span></div>
        <div><span class="um-fname">Two date pickers</span><span class="um-fdesc">Filter by <em>claim month</em>, or by <em>submitted date</em>. The <strong>X</strong> clears all filters.</span></div>
        <div><span class="um-fname">The list</span><span class="um-fdesc">Grouped <strong>Year → Month → Employee</strong>. Click down through each level. The current year and newest month open automatically; staff start collapsed.</span></div>
    </div>
    <div class="um-tip">
        <i class="bi bi-cash-coin"></i>The <strong>RM total</strong> beside a year, month, or person counts
        only <strong>HR-approved</strong> claims. It can read RM 0.00 while claims are still waiting on
        you or HR — that's expected.
    </div>
    <p style="font-size:13px;color:#475569;">
        Nothing waiting? You'll see <em>“No team claims yet — claims routed to you for approval will
        appear here.”</em>
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-box-arrow-up-right"></i> Opening a claim</div>

    <div class="um-mockup">
        <div class="um-acc">
            <div class="um-acc-header"><i class="bi bi-chevron-down"></i> 2026 <span class="um-count">18 claims</span></div>
            <div class="um-acc-body">
                <div class="um-sub-header"><i class="bi bi-chevron-down"></i> July 2026 <span class="um-count">3 staff · 7 claims</span></div>
                <div class="um-sub-header is-emp"><i class="bi bi-chevron-down"></i> <i class="bi bi-person"></i> Aisha Rahman <span style="color:#64748b;font-weight:400;">· Marketing</span> <span class="um-count">2 claims</span></div>
                <div style="padding:4px 8px;">
                    <div class="um-claim-row">
                        <span class="um-ev">Parentcraft Event</span>
                        <span class="badge bg-warning text-dark">Pending Manager</span>
                        <span class="um-amt">RM 320.50</span>
                        <span class="um-btn um-btn-blue"><i class="bi bi-eye"></i> Review</span>
                    </div>
                    <div class="um-claim-row">
                        <span class="um-ev">Petty Cash – Office</span>
                        <span class="badge bg-success">Manager Approved</span>
                        <span class="um-amt">RM 88.00</span>
                        <span class="um-btn um-btn-grey"><i class="bi bi-eye"></i> View report</span>
                    </div>
                </div>
                <div class="um-sub-header is-emp"><i class="bi bi-chevron-right"></i> <i class="bi bi-person"></i> Daniel Lim <span style="color:#64748b;font-weight:400;">· Sales</span> <span class="um-count">5 claims</span></div>
            </div>
        </div>
        <div class="um-mockup-caption">Click down through <strong>Year → Month → Employee</strong>. A blue <strong>Review</strong> button means that claim is waiting on you.</div>
    </div>

    <p>Each row has one button:</p>
    <div class="um-fields">
        <div><span class="um-fname">Review</span><span class="um-fdesc">Blue — shown when the claim is waiting on <strong>you</strong>. Opens the review page where you approve or reject.</span></div>
        <div><span class="um-fname">View report</span><span class="um-fdesc">Grey — for everything else. Opens the same report, read-only, so you can look back at a decision.</span></div>
    </div>
    <div class="um-warn">
        <i class="bi bi-hand-index"></i>All decisions happen on the <strong>review page</strong>, not on
        this list. There are no Approve/Reject buttons on the list itself.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-file-earmark-text"></i> What you're looking at</div>
    <p>
        The claim is shown as the <strong>real printable claim form</strong> — exactly the document that
        gets filed. From top to bottom:
    </p>

    <div class="um-mockup">
        <div class="um-report">
            {{-- Letterhead: company + address left, logo right --}}
            <div class="um-lh">
                <div>
                    <b>Company A Sdn Bhd</b>
                    <span>Level 8, Example Tower<br>Jalan Example, 50450 Kuala Lumpur</span>
                </div>
                <div class="um-logo">COMPANY LOGO</div>
            </div>

            <div class="um-report-title">Expenses Claims Form</div>

            <div class="um-meta">
                <div><span class="um-mlabel">Name :</span><span class="um-mline">Aisha Rahman</span></div>
                <div><span class="um-mlabel">Date :</span><span class="um-mline">12th July 2026</span></div>
                <div><span class="um-mlabel">Department :</span><span class="um-mline">Marketing</span></div>
                <div></div>
                <div><span class="um-mlabel">Event :</span><span class="um-mline">Parentcraft Event</span></div>
                <div></div>
            </div>

            <table class="um-rtable">
                <thead>
                    <tr>
                        <th style="width:13%;">Date</th>
                        <th style="width:22%;">Expense Description</th>
                        <th style="width:14%;">Project/Client Name</th>
                        <th style="width:23%;">Expense Type</th>
                        <th style="width:9%;">RM<br>(w/o SST)</th>
                        <th style="width:8%;">RM<br>(SST)</th>
                        <th style="width:11%;">Total<br>(w/ SST)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>12th Jul 2026</td><td>Grab to venue</td><td>Parentcraft</td>
                        <td>905-000: TRAVELLING EXPENSES (LOCAL)</td>
                        <td class="ta-r">RM24.00</td><td class="ta-r">-</td><td class="ta-r">RM24.00</td>
                    </tr>
                    <tr class="um-attrow">
                        <td colspan="7">
                            <div class="um-att-inner">
                                <div>
                                    <div style="font-size:9.5px;color:#64748b;margin-bottom:3px;"><i class="bi bi-paperclip"></i> Attachment for: Grab to venue</div>
                                    <div class="um-att-img"><i class="bi bi-image"></i> receipt image shown here</div>
                                </div>
                                <div class="um-rd">
                                    <div class="um-rd-title">Receipt details</div>
                                    <div><strong>Company:</strong> Grab Malaysia</div>
                                    <div><strong>Item:</strong> Trip to Example Hall</div>
                                    <div><strong>Date:</strong> 12 Jul 2026</div>
                                    <div><strong>Who paid:</strong> Aisha Rahman</div>
                                    <div><strong>Total paid:</strong> RM 24.00</div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>12th Jul 2026</td><td>Refreshments for attendees</td><td>Parentcraft</td>
                        <td>922-000: OFFICE FOOD &amp; REFRESHMENT</td>
                        <td class="ta-r">RM196.50</td><td class="ta-r">-</td><td class="ta-r">RM196.50</td>
                    </tr>
                    <tr>
                        <td>12th Jul 2026</td><td>Extra hours (8am&ndash;6pm)</td><td>Parentcraft</td>
                        <td>914(b)-000: TRANSPORTATION</td>
                        <td class="ta-r">RM100.00</td><td class="ta-r">-</td><td class="ta-r">RM100.00</td>
                    </tr>
                    <tr class="um-pad"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td class="ta-r">-</td></tr>
                    <tr class="um-pad"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td class="ta-r">-</td></tr>
                </tbody>
                <tfoot>
                    <tr class="um-gt"><td colspan="4">Grand Total</td><td>320.50</td><td>0.00</td><td>320.50</td></tr>
                </tfoot>
            </table>

            <div class="um-signoffs">
                <div>
                    <div>Approving Manager :- Siti Nurhaliza</div>
                    <div class="um-sub">Marketing Manager · Marketing · Company A</div>
                    <div class="um-await">Awaiting manager approval</div>
                </div>
                <div>
                    <div>Checked by :- (HR)</div>
                    <div class="um-await">Pending HR</div>
                </div>
            </div>
            <div class="um-signnote"><i class="bi bi-shield-check"></i> Digital sign-off &mdash; each action above is the recorded system action (name + timestamp), held in the claim's audit trail. No physical signature is required.</div>
        </div>
        <div class="um-mockup-caption">The real <strong>Expenses Claims Form</strong>. Each receipt sits under its own item, with the scanned <strong>Receipt details</strong> beside it — compare <em>Total paid</em> against the claimed amount. Blank rows pad the form out like the paper original.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Letterhead</span><span class="um-fdesc">The company name, address and logo.</span></div>
        <div><span class="um-fname">Header fields</span><span class="um-fdesc">Name, Department, Event, Date.</span></div>
        <div><span class="um-fname">Itemised table</span><span class="um-fdesc">Date, Expense Description, Project/Client, Expense Type, RM (w/o SST), RM (SST), Total (w/ SST) — ending in a <strong>Grand Total</strong>.</span></div>
        <div><span class="um-fname">Attachments inline</span><span class="um-fdesc">Each receipt is shown right under its item — images inline, PDFs in a viewer. Beside it, <strong>Receipt details</strong> (company, item, date, who paid, total paid) read from the scan, so you can compare at a glance.</span></div>
        <div><span class="um-fname">Sign-offs</span><span class="um-fdesc"><strong>Approving Manager</strong> and <strong>Checked by</strong>, each showing <em>Approved digitally</em> with a timestamp, or <em>Awaiting…</em> / <em>Pending HR</em>.</span></div>
        <div><span class="um-fname">Status log</span><span class="um-fdesc">The full history. On screen only — it never prints.</span></div>
    </div>
    <div class="um-tip">
        <i class="bi bi-collection"></i>Several items from <strong>one</strong> receipt collapse into a
        <em>Subtotal — N transactions</em> with a single shared attachment. That's one receipt claimed
        across several lines, not a duplicate.
    </div>
    <p style="font-size:13px;color:#475569;">
        The sign-offs are digital — <strong>no physical signature is required</strong>. Use
        <strong>Download PDF</strong> in the toolbar if you need a copy.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-check2-circle"></i> Approving a claim</div>

    <div class="um-mockup">
        <div class="um-panel">
            <div class="um-panel-title"><i class="bi bi-person-check me-1"></i> Your decision — Manager / PIC approval</div>
            <div class="um-panel-body">
                <span class="um-btn um-btn-green"><i class="bi bi-check2"></i> Approve Claim</span>
                <span class="um-btn um-btn-red"><i class="bi bi-x-lg"></i> Reject Claim</span>
                <span style="font-size:11px;color:#64748b;margin-left:auto;">3 items · RM 320.50</span>
            </div>
        </div>
        <div class="um-mockup-caption">The decision panel, at the bottom of the review page.</div>
    </div>

    <ol class="um-step-list">
        <li>Read down the report — check each item has a receipt that matches its amount, and that the
            spend is genuinely business-related and on the right project.</li>
        <li>In <strong>Your decision — Manager / PIC approval</strong>, click <strong>Approve Claim</strong>.</li>
        <li>Confirm the dialog (it shows the item count and total), then <strong>Approve</strong>.</li>
    </ol>
    <p style="font-size:13px;color:#475569;">
        You'll land back on Team Claims with <em>“All items approved — claim sent to HR.”</em> That's it —
        your approval sends the <strong>whole claim</strong> on to HR for the final sign-off. There's
        nothing else for you to do.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-x-circle"></i> Rejecting a claim</div>
    <div class="um-warn">
        <i class="bi bi-exclamation-triangle"></i><strong>Rejection is all-or-nothing.</strong> There's no
        per-item reject — rejecting returns the <strong>whole claim</strong> to the employee to fix and
        resubmit.
    </div>
    <ol class="um-step-list">
        <li>Click <strong>Reject Claim</strong>. This opens the reason panel <em>and</em> makes a comment box
            appear <strong>inside the report</strong>, under every item row.</li>
        <li><strong>Scroll up to the report</strong> and comment only on the lines that need fixing — leave the
            rest blank. A commented row turns amber, and the employee sees that flag against that exact item.
            This is the most useful thing you can do — it's how they know precisely what to change.</li>
        <li>Optionally add an <strong>Overall reason</strong> — e.g. <em>“The KLCC mileage isn't a business
            trip — please remove it and resubmit.”</em></li>
        <li>Click <strong>Confirm Reject</strong>.</li>
    </ol>

    <div class="um-mockup">
        <table class="um-rtable">
            <thead>
                <tr>
                    <th style="width:13%;">Date</th>
                    <th style="width:22%;">Expense Description</th>
                    <th style="width:14%;">Project/Client Name</th>
                    <th style="width:23%;">Expense Type</th>
                    <th style="width:9%;">RM<br>(w/o SST)</th>
                    <th style="width:8%;">RM<br>(SST)</th>
                    <th style="width:11%;">Total<br>(w/ SST)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>12th Jul 2026</td><td>Grab to venue</td><td>Parentcraft</td>
                    <td>905-000: TRAVELLING EXPENSES (LOCAL)</td>
                    <td class="ta-r">RM24.00</td><td class="ta-r">-</td><td class="ta-r">RM24.00</td>
                </tr>
                <tr class="um-flagrow">
                    <td></td>
                    <td colspan="6">
                        <i class="bi bi-flag text-danger"></i>
                        <span class="um-flag-input">Comment if this item needs fixing — the employee sees this (optional)</span>
                    </td>
                </tr>
                <tr>
                    <td>12th Jul 2026</td><td>Refreshments for attendees</td><td>Parentcraft</td>
                    <td>922-000: OFFICE FOOD &amp; REFRESHMENT</td>
                    <td class="ta-r">RM196.50</td><td class="ta-r">-</td><td class="ta-r">RM196.50</td>
                </tr>
                <tr class="um-flagrow">
                    <td></td>
                    <td colspan="6">
                        <i class="bi bi-flag-fill text-danger"></i>
                        <span class="um-flag-input is-filled">Receipt only shows RM 150 — please attach the full receipt or lower the amount.</span>
                    </td>
                </tr>
                <tr>
                    <td>12th Jul 2026</td><td>Extra hours (8am&ndash;6pm)</td><td>Parentcraft</td>
                    <td>914(b)-000: TRANSPORTATION</td>
                    <td class="ta-r">RM100.00</td><td class="ta-r">-</td><td class="ta-r">RM100.00</td>
                </tr>
                <tr class="um-flagrow">
                    <td></td>
                    <td colspan="6">
                        <i class="bi bi-flag text-danger"></i>
                        <span class="um-flag-input">Comment if this item needs fixing — the employee sees this (optional)</span>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="um-mockup-caption">Once you click <strong>Reject Claim</strong>, a comment box appears <strong>inside the report, under every item row</strong>. Fill in only the lines at fault — the second row here is flagged. Leave the rest blank.</div>
    </div>

    <div class="um-mockup">
        <div class="um-panel">
            <div class="um-panel-title"><i class="bi bi-x-circle me-1"></i> Reject claim</div>
            <div class="um-panel-body" style="display:block;">
                <div style="font-size:11px;color:#78350f;background:#fef3c7;border-radius:5px;padding:6px 8px;margin-bottom:7px;">
                    <i class="bi bi-exclamation-triangle"></i> Rejecting returns the <strong>whole claim</strong> to Aisha Rahman to fix and resubmit.
                    <div style="margin-top:3px;"><i class="bi bi-flag"></i> Scroll up to the report and add a comment under any item that needs fixing — the employee will see those flags.</div>
                </div>
                <div style="font-size:10.5px;font-weight:600;color:#1e293b;margin-bottom:3px;">Overall reason (optional — the employee sees this)</div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="um-flag-input" style="flex:1;">e.g., The KLCC mileage isn't a business trip — please remove it and resubmit.</span>
                    <span class="um-btn um-btn-red"><i class="bi bi-check2"></i> Confirm Reject</span>
                </div>
            </div>
        </div>
        <div class="um-mockup-caption">The reject panel below the report — the overall reason, then <strong>Confirm Reject</strong>.</div>
    </div>
    <div class="um-tip">
        <i class="bi bi-chat-left-text"></i>The reason is <strong>optional</strong> — but a rejection with
        no explanation just bounces back to you. Always say why.
    </div>
    <p style="font-size:13px;color:#475569;">
        You'll see <em>“Claim rejected — [name] can now file a correction.”</em> The employee gets a new
        pre-filled draft to fix and resubmit. They only get <strong>one</strong> correction, so be clear
        and complete the first time.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-bookmark-check"></i> Badges you'll see</div>
    <div class="um-table-wrap">
        <table class="um-table">
            <thead><tr><th>Badge</th><th>What it means</th></tr></thead>
            <tbody>
                <tr><td><span class="badge bg-warning text-dark">Pending Manager</span></td><td>Waiting on you. Use <strong>Review</strong>.</td></tr>
                <tr><td><span class="badge bg-success">Manager Approved</span></td><td>You approved it. Now with HR.</td></tr>
                <tr><td><span class="badge bg-danger">Manager Rejected</span></td><td>You sent it back.</td></tr>
                <tr><td><span class="badge bg-warning text-dark">Pending HR</span></td><td>With HR for final approval.</td></tr>
                <tr><td><span class="badge bg-success">HR Approved</span></td><td>Fully approved — heading for payout.</td></tr>
                <tr><td><span class="badge bg-danger">HR Rejected</span></td><td>HR sent it back to the employee. Shown to you for information.</td></tr>
                <tr><td><span class="badge bg-warning text-dark">Reversed</span></td><td>HR un-approved a claim that had already been approved.</td></tr>
                <tr><td><span class="badge bg-primary">Resubmitted</span></td><td>This claim is a correction of an earlier one.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="um-tip">
        <i class="bi bi-info-circle"></i>If <strong>HR</strong> rejects a claim you approved, you're notified
        for information only — the employee corrects it directly. There's nothing for you to release or
        action.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-clipboard-check"></i> A quick reviewer's checklist</div>
    <ol class="um-step-list">
        <li><strong>Receipt matches the amount</strong> — compare the line against the <em>Receipt details</em> shown beside it.</li>
        <li><strong>It's business spend</strong> — on the right project or client.</li>
        <li><strong>Right month</strong> — receipts should be dated inside the claim's month.</li>
        <li><strong>Mileage looks sane</strong> — the route is a real business trip; toll and parking are separate lines.</li>
        <li><strong>Nothing claimed twice</strong> — watch for the same receipt appearing on two claims.</li>
    </ol>
    <div class="um-warn">
        <i class="bi bi-clock-history"></i>Claims must be <strong>manager-approved by the 20th</strong> to
        make that month's payout run. Sitting on an approval pushes your team member's money to the next
        cycle — you'll get reminder emails as the cutoff nears.
    </div>
</div>
