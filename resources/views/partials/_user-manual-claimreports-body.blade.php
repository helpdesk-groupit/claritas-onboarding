{{-- User-Manual content: Claim Reports (Finance — approved-claim ledger + CSV export).
     Single source of truth — included by BOTH the in-app modal (_user-manual-claimreports.blade.php)
     and the public help page (help/claim-reports.blade.php). Keep company names generic. --}}
@include('partials._user-manual-styles')

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-info-circle"></i> What this page is for</div>
    <p>
        <strong>Claim Reports</strong> is Finance's read-only ledger of expense claims. It gathers every
        <strong>fully-approved</strong> claim, breaks it down to the individual expense lines with their
        <strong>GL codes</strong>, and groups them so you can post the spend into the accounting system —
        or hand it off as a CSV.
    </p>
    <div class="um-tip">
        <i class="bi bi-shield-check"></i>Only claims <strong>approved by both the Manager/PIC and HR</strong>
        appear here. Drafts, claims still pending, and rejected or reversed claims are never shown — this is
        the clean, ready-to-post list.
    </div>
    <div class="um-flow">
        <span class="um-node">Manager Approved</span><i class="bi bi-arrow-right"></i>
        <span class="um-node">HR Approved</span><i class="bi bi-arrow-right"></i>
        <span class="um-node is-end">Appears here — ready to post</span>
    </div>
    <p style="font-size:13px;color:#475569;">
        It's a <strong>view-only</strong> page — there's nothing to approve or edit here. Approvals happen on
        the <strong>HR Claims</strong> desk; this is where the money lands once they're done.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-grid-1x2"></i> The page at a glance</div>

    <div class="um-mockup">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
            <span style="font-weight:700;color:#1e293b;font-size:13px;"><i class="bi bi-file-earmark-spreadsheet" style="color:#2563eb;"></i> Claim Reports</span>
            <span style="background:#dbeafe;color:#1e40af;border-radius:999px;padding:3px 11px;font-size:11px;font-weight:600;">Grand total: RM 2,145.00</span>
        </div>
        <div class="um-form">
            <div class="um-frow c3"><span class="um-flabel">Year</span><div class="um-fmock">2026 <i class="bi bi-chevron-down um-caret"></i></div></div>
            <div class="um-frow c3"><span class="um-flabel">Month</span><div class="um-fmock">All months <i class="bi bi-chevron-down um-caret"></i></div></div>
            <div class="um-frow c3"><span class="um-flabel">Company</span><div class="um-fmock">All companies <i class="bi bi-chevron-down um-caret"></i></div></div>
            <div class="um-frow c3"><span class="um-flabel">Category</span><div class="um-fmock">All categories <i class="bi bi-chevron-down um-caret"></i></div></div>
            <div class="um-frow" style="display:flex;gap:6px;flex-wrap:wrap;">
                <span class="um-btn um-btn-blue"><i class="bi bi-funnel"></i> Filter</span>
                <span class="um-btn um-btn-grey" title="Reset filters"><i class="bi bi-x-lg"></i></span>
                <span class="um-btn um-btn-grey" style="color:#16a34a;border-color:#86efac;"><i class="bi bi-download"></i> Export CSV</span>
            </div>
        </div>
        <div class="um-mockup-caption">The header carries a live <strong>Grand total</strong> of everything shown; below it, the four filters, the <strong>Filter</strong>/reset controls, and <strong>Export CSV</strong>.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Grand total</span><span class="um-fdesc">A running RM total of <strong>every line currently shown</strong> — it moves as you change the filters.</span></div>
        <div><span class="um-fname">Four filters</span><span class="um-fdesc"><strong>Year</strong>, <strong>Month</strong>, <strong>Company</strong>, and <strong>Category</strong>. They stack — narrow by any combination.</span></div>
        <div><span class="um-fname">Filter / reset</span><span class="um-fdesc"><strong>Filter</strong> applies your choices; the <strong>X</strong> clears them all back to the defaults.</span></div>
        <div><span class="um-fname">Export CSV</span><span class="um-fdesc">Downloads exactly what the filters show as a spreadsheet — see below.</span></div>
        <div><span class="um-fname">The list</span><span class="um-fdesc">Nested <strong>Year → Month → Company → Employee → claim lines</strong>.</span></div>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-funnel"></i> The filters</div>
    <p>Set any combination, then click <strong>Filter</strong>. Both the <strong>Grand total</strong> and the <strong>Export CSV</strong> follow whatever you've filtered to.</p>
    <div class="um-fields">
        <div><span class="um-fname">Year</span><span class="um-fdesc">Reports load one year at a time. Only years that actually have approved claims are listed.</span></div>
        <div><span class="um-fname">Month</span><span class="um-fdesc"><strong>All months</strong>, or a single month. This is the claim's <em>reporting</em> month.</span></div>
        <div><span class="um-fname">Company</span><span class="um-fdesc"><strong>All companies</strong>, or one entity. Uses the company stamped on each claim, so a back-dated move lands under the right entity.</span></div>
        <div><span class="um-fname">Category</span><span class="um-fdesc"><strong>All categories</strong>, or one expense type — listed as <em>GL code: Name</em>.</span></div>
    </div>
    <div class="um-tip">
        <i class="bi bi-x-lg"></i>The <strong>X</strong> next to Filter resets everything at once — back to the current year, all months, all companies, all categories.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-diagram-3"></i> Reading the list</div>
    <p>The list drills down four levels — click any bar to open it. Every bar carries its own <strong>count</strong> and <strong>RM subtotal</strong>, so the numbers add up as you fold them open.</p>

    <div class="um-mockup">
        <div class="um-acc">
            <div class="um-acc-header"><i class="bi bi-chevron-down"></i> <i class="bi bi-calendar3"></i> 2026 <span class="um-count">24 lines · RM 4,280.50</span></div>
            <div class="um-acc-body">
                <div class="um-sub-header"><i class="bi bi-chevron-down"></i> <i class="bi bi-calendar3"></i> July 2026 <span class="um-count">2 companies · 12 lines · RM 2,145.00</span></div>
                <div class="um-sub-header is-emp"><i class="bi bi-chevron-down"></i> <i class="bi bi-building"></i> Company A Sdn Bhd <span class="um-count">2 staff · 5 lines · RM 980.50</span></div>
                <div class="um-sub-header is-emp" style="margin-left:16px;"><i class="bi bi-chevron-down"></i> <i class="bi bi-person"></i> Aisha Rahman <span class="um-count">3 claim lines · RM 320.50</span></div>
                <div style="padding:6px 8px;">
                    <table class="um-itable">
                        <thead><tr><th>GL Code</th><th>Category</th><th>Description</th><th class="ta-r">Amount</th></tr></thead>
                        <tbody>
                            <tr><td>905-000</td><td>Travelling Expenses (Local)</td><td>Grab to venue</td><td class="ta-r">RM 24.00</td></tr>
                            <tr><td>922-000</td><td>Office Food &amp; Refreshment</td><td>Refreshments for attendees</td><td class="ta-r">RM 196.50</td></tr>
                            <tr><td>914(b)-000</td><td>Transportation</td><td>Extra hours (8am&ndash;6pm)</td><td class="ta-r">RM 100.00</td></tr>
                        </tbody>
                        <tfoot><tr><td colspan="3" class="ta-r">Subtotal — Aisha Rahman</td><td class="ta-r">RM 320.50</td></tr></tfoot>
                    </table>
                </div>
                <div class="um-sub-header is-emp" style="margin-left:16px;"><i class="bi bi-chevron-right"></i> <i class="bi bi-person"></i> Daniel Lim <span class="um-count">2 claim lines · RM 660.00</span></div>
                <div class="um-sub-header is-emp"><i class="bi bi-chevron-right"></i> <i class="bi bi-building"></i> Company B Sdn Bhd <span class="um-count">1 staff · 7 lines · RM 1,164.50</span></div>
            </div>
        </div>
        <div class="um-mockup-caption">Four nested levels — <strong>Year → Month → Company → Employee</strong> — opening onto the employee's <strong>claim lines</strong>. The current year and its newest month open by default; deeper levels start collapsed.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Year</span><span class="um-fdesc">Dark bar. Total lines &amp; RM for the whole year.</span></div>
        <div><span class="um-fname">Month</span><span class="um-fdesc">Indigo bar. Companies, lines &amp; RM for that month.</span></div>
        <div><span class="um-fname">Company</span><span class="um-fdesc">Teal bar. Staff, lines &amp; RM for that entity.</span></div>
        <div><span class="um-fname">Employee</span><span class="um-fdesc">Purple bar. That person's claim lines &amp; RM.</span></div>
        <div><span class="um-fname">Claim lines</span><span class="um-fdesc">The leaf table — one row per expense item, ending in a per-person <strong>Subtotal</strong>.</span></div>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-list-columns"></i> The claim lines (GL breakdown)</div>
    <p>
        Each claim is exploded into <strong>one row per expense item</strong>, so a single claim with three
        receipts shows as three lines. This is the level Finance posts from.
    </p>
    <div class="um-fields">
        <div><span class="um-fname">GL Code</span><span class="um-fdesc">The account code the category maps to in the <strong>Chart of Accounts</strong> — the account to post this line against.</span></div>
        <div><span class="um-fname">Category</span><span class="um-fdesc">The expense type (e.g. <em>Office Food &amp; Refreshment</em>).</span></div>
        <div><span class="um-fname">Description</span><span class="um-fdesc">What the employee entered for that line.</span></div>
        <div><span class="um-fname">Amount</span><span class="um-fdesc">The line total <strong>including SST</strong> (the amount actually reimbursed).</span></div>
        <div><span class="um-fname">Subtotal</span><span class="um-fdesc">The sum of that employee's lines, at the foot of their table.</span></div>
    </div>
    <div class="um-tip">
        <i class="bi bi-cash-coin"></i>Every RM figure on the page — line, subtotal, each bar, and the Grand
        total — is the <strong>SST-inclusive</strong> reimbursed amount, so they roll straight up.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-download"></i> Export CSV</div>
    <p>
        <strong>Export CSV</strong> downloads exactly what your filters are showing — one row per claim line —
        as a spreadsheet you can import into the accounting system or a journal.
    </p>

    <div class="um-mockup">
        <table class="um-itable">
            <thead><tr><th>Year</th><th>Month</th><th>Company</th><th>Employee</th><th>GL Code</th><th>Category</th><th>Description</th><th class="ta-r">Amount (RM)</th></tr></thead>
            <tbody>
                <tr><td>2026</td><td>07</td><td>Company A Sdn Bhd</td><td>Aisha Rahman</td><td>905-000</td><td>Travelling Expenses (Local)</td><td>Grab to venue</td><td class="ta-r">24.00</td></tr>
                <tr><td>2026</td><td>07</td><td>Company A Sdn Bhd</td><td>Aisha Rahman</td><td>922-000</td><td>Office Food &amp; Refreshment</td><td>Refreshments for attendees</td><td class="ta-r">196.50</td></tr>
                <tr><td>2026</td><td>07</td><td>Company B Sdn Bhd</td><td>Daniel Lim</td><td>919-000</td><td>Petrol</td><td>Mileage to client site</td><td class="ta-r">34.30</td></tr>
            </tbody>
        </table>
        <div class="um-mockup-caption">The CSV columns: <strong>Year, Month, Company, Employee, GL Code, Category, Description, Amount (RM)</strong>. The file is named <code>claim_reports_&lt;year&gt;_&lt;timestamp&gt;.csv</code>.</div>
    </div>

    <div class="um-warn">
        <i class="bi bi-funnel"></i>The export honours the <strong>current filters</strong>. Narrow to a month
        or company first if you only want that slice — otherwise you get the whole selected year.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-check2-square"></i> What appears here — and what doesn't</div>
    <div class="um-table-wrap">
        <table class="um-table">
            <thead><tr><th>Claim state</th><th>Shown here?</th></tr></thead>
            <tbody>
                <tr><td><span class="badge bg-success">HR Approved</span></td><td><i class="bi bi-check-circle-fill text-success"></i> Yes — fully approved, ready to post.</td></tr>
                <tr><td><span class="badge bg-secondary">Draft</span> · <span class="badge bg-warning text-dark">Pending Manager</span> · <span class="badge bg-warning text-dark">Pending HR</span></td><td><i class="bi bi-x-circle text-muted"></i> No — not fully approved yet.</td></tr>
                <tr><td><span class="badge bg-success">Manager Approved</span> only</td><td><i class="bi bi-x-circle text-muted"></i> No — still needs HR's sign-off.</td></tr>
                <tr><td><span class="badge bg-danger">Rejected</span> · <span class="badge bg-warning text-dark">Reversed</span></td><td><i class="bi bi-x-circle text-muted"></i> No — sent back to the employee.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="um-tip">
        <i class="bi bi-arrow-right-circle"></i>Waiting for something to appear? Check the
        <strong>HR Claims</strong> desk — a claim reaches this page the moment HR approves it.
    </div>
    <p style="font-size:13px;color:#475569;">
        If nothing matches, the page shows <em>“No approved claims match these filters.”</em> — either the
        filters are too narrow, or nothing is approved for that period yet.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-people"></i> Who can open this page</div>
    <p>
        Claim Reports is available to <strong>Finance</strong> and <strong>HR</strong> staff (managers and
        executives) and to <strong>admins</strong>. It's read-only for everyone — a reporting view, not an
        approval queue.
    </p>
</div>
