{{-- User-Manual content: My Claims (employee self-service).
     Single source of truth — included by BOTH the in-app modal (_user-manual-claims.blade.php)
     and the public help page (help/claims.blade.php). Keep company names generic. --}}
@include('partials._user-manual-styles')

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-info-circle"></i> What this page is for</div>
    <p>
        <strong>My Expense Claims</strong> is where you file the money you spent for work and get it
        reimbursed. You build a claim, attach your receipts, and send it to your manager for approval —
        it then goes to HR for the final approval.
    </p>
    <div class="um-tip">
        <i class="bi bi-lightbulb"></i><strong>The one rule to remember:</strong> file
        <strong>one claim per event or project</strong> — not one claim per month. You can have several
        claims open at the same time (e.g. one for a client event, one for general office spend).
    </div>
    <div class="um-tip">
        <i class="bi bi-cloud-check"></i><strong>Nothing is ever lost.</strong> Everything auto-saves as
        you type. You can close the tab or log out — your work stays as a <strong>Draft</strong> in the
        list below, and you can reopen it any time with <em>Continue editing</em>.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-grid-1x2"></i> The page at a glance</div>

    <div class="um-mockup">
        <div class="um-stat-row">
            <div class="um-stat" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);"><span class="um-stat-n">2</span><span class="um-stat-l">Draft</span></div>
            <div class="um-stat um-stat-amber"><span class="um-stat-n">1</span><span class="um-stat-l">Awaiting Manager Approval</span></div>
            <div class="um-stat um-stat-blue"><span class="um-stat-n">3</span><span class="um-stat-l">Awaiting HR Approval</span></div>
            <div class="um-stat um-stat-green"><span class="um-stat-n">9</span><span class="um-stat-l">Completed: Approved by Manager &amp; HR</span></div>
        </div>
        <div class="um-pills">
            <span class="um-pill is-active">All <b>15</b></span>
            <span class="um-pill">Draft <b>2</b></span>
            <span class="um-pill">Awaiting Manager <b>1</b></span>
            <span class="um-pill">Awaiting HR <b>3</b></span>
            <span class="um-pill">Completed <b>9</b></span>
            <span class="um-pill">Rejected <b>0</b></span>
        </div>
        <div class="um-mockup-caption">Your four count cards, and the status pills you filter the list with.</div>
    </div>

    <p>From top to bottom:</p>
    <div class="um-fields">
        <div><span class="um-fname">Four count cards</span><span class="um-fdesc"><strong>Draft</strong>, <strong>Awaiting Manager Approval</strong>, <strong>Awaiting HR Approval</strong>, and <strong>Completed</strong> — a running count of all your claims.</span></div>
        <div><span class="um-fname">Important Reminders</span><span class="um-fdesc">The claim rules in short. Worth reading once.</span></div>
        <div><span class="um-fname">The claim builder</span><span class="um-fdesc">Where you pick the claim month and press <strong>Add New Claim</strong>, then fill in the claim.</span></div>
        <div><span class="um-fname">Filter by status</span><span class="um-fdesc">Pills to narrow the list: <strong>All</strong>, <strong>Draft</strong>, <strong>Awaiting Manager</strong>, <strong>Awaiting HR</strong>, <strong>Completed</strong>, <strong>Rejected</strong>.</span></div>
        <div><span class="um-fname">Your claims list</span><span class="um-fdesc">Grouped <strong>Year → Month → Claim</strong>. Click a year to see its months, a month to see its claims. The current year and its newest month open automatically.</span></div>
    </div>
    <div class="um-mockup">
        <div class="um-acc">
            <div class="um-acc-header"><i class="bi bi-chevron-down"></i> 2026 <span class="um-count">15 reports</span></div>
            <div class="um-acc-body">
                <div class="um-sub-header"><i class="bi bi-chevron-down"></i> July 2026 <span class="um-count">3 reports</span></div>
                <div style="padding:4px 8px;">
                    <div class="um-claim-row">
                        <span class="badge bg-secondary">Draft</span>
                        <span class="um-ev">Parentcraft Event</span>
                        <span class="um-amt">RM 320.50</span>
                        <span class="um-btn um-btn-grey" style="border-color:#93c5fd;color:#1d4ed8;"><i class="bi bi-pencil"></i> Continue editing</span>
                        <span class="um-btn um-btn-grey"><i class="bi bi-printer"></i> Preview Report</span>
                        <span class="um-btn um-btn-grey" style="border-color:#fca5a5;color:#dc2626;"><i class="bi bi-trash"></i></span>
                    </div>
                    <div class="um-claim-row">
                        <span class="badge bg-warning text-dark">Pending Manager</span>
                        <span class="badge bg-warning text-dark">Pending HR</span>
                        <span class="um-ev">Petty Cash – Office</span>
                        <span class="um-amt">RM 88.00</span>
                    </div>
                    <div class="um-claim-row">
                        <span class="badge bg-success">Manager Approved</span>
                        <span class="badge bg-success">HR Approved</span>
                        <span class="um-ev">Client Visit – MCA</span>
                        <span class="um-amt">RM 145.00</span>
                    </div>
                </div>
                <div class="um-sub-header"><i class="bi bi-chevron-right"></i> June 2026 <span class="um-count">4 reports</span></div>
            </div>
        </div>
        <div class="um-mockup-caption">Your claims, grouped <strong>Year → Month</strong>. A <strong>Draft</strong> shows <em>Continue editing</em>; submitted claims show two badges — the manager stage and the HR stage.</div>
    </div>

    <div class="um-tip">
        <i class="bi bi-cash-coin"></i>The <strong>RM total</strong> shown next to a year or month counts
        only claims that are <strong>fully approved</strong> (Manager <em>and</em> HR). So it can read
        RM 0.00 while claims are still pending — that's normal, not a bug.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-plus-circle"></i> Step 1 — Start a claim</div>

    <div class="um-mockup">
        <div class="um-banner um-banner-green">
            <i class="bi bi-cloud-check"></i> <strong>Everything auto-saves as you go.</strong> You can safely refresh or log out — your claim is kept as a <strong>Draft</strong> in the list below. Reopen it any time with <em>Continue editing</em>.
        </div>
        <div class="um-form" style="margin-top:9px;">
            <div class="um-frow c4">
                <span class="um-flabel">Claim month</span>
                <div class="um-fmock">July 2026 <i class="bi bi-calendar3 um-caret"></i></div>
                <div class="um-fhelp">The reporting month a new claim is filed under. A receipt must be claimed under its own month.</div>
            </div>
            <div class="um-frow c8" style="display:flex;align-items:flex-end;justify-content:flex-end;">
                <span class="um-btn um-btn-green" style="padding:6px 14px;font-size:12px;"><i class="bi bi-plus-lg"></i> Add New Claim</span>
            </div>
        </div>
        <div class="um-mockup-caption">Pick the month, then <strong>Add New Claim</strong> — the draft opens straight away. There's no pop-up to fill in.</div>
    </div>

    <ol class="um-step-list">
        <li>Set <strong>Claim month</strong> — the month you're claiming <em>for</em>. Every receipt you
            add must be dated inside this month.</li>
        <li>Click <strong>Add New Claim</strong>. A draft opens straight away — there's no pop-up to fill in.</li>
    </ol>
    <div class="um-warn">
        <i class="bi bi-calendar-x"></i><strong>A receipt must be claimed under its own month.</strong>
        A June receipt belongs in a June claim, not a July one. If you mix months, the system will block
        you at submit time and tell you which receipts are in the wrong claim.
    </div>
    <div class="um-tip">
        <i class="bi bi-arrow-repeat"></i>Clicking <strong>Add New Claim</strong> twice won't create two
        blanks — an empty draft is reused.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-card-list"></i> Step 2 — Fill in the claim details</div>
    <p>These describe the claim as a whole. They save automatically — there's no Save button.</p>

    <div class="um-mockup">
        <div class="um-form">
            <div class="um-frow c6">
                <span class="um-flabel">Name of the Event <span class="req">*</span></span>
                <div class="um-fmock">Parentcraft Event</div>
            </div>
            <div class="um-frow c6">
                <span class="um-flabel">Approving PIC / Manager <span class="req">*</span></span>
                <div class="um-fmock is-ph">Search by name or nickname… <i class="bi bi-search um-caret"></i></div>
            </div>
            <div class="um-frow c6">
                <span class="um-flabel">Date of the Event</span>
                <div class="um-fmock">12-07-2026 <i class="bi bi-calendar3 um-caret"></i></div>
            </div>
            <div class="um-frow c6">
                <span class="um-flabel">Project / Client Name <span class="req">*</span></span>
                <div class="um-fmock is-ph">e.g. Parentcraft, MCA, internal</div>
            </div>
        </div>
        <div class="um-mockup-caption">The claim-level details. These auto-save — no Save button.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Name of the Event <span class="text-danger">*</span></span><span class="um-fdesc">What this claim is for, e.g. <em>Parentcraft Event</em>, or <em>Petty Cash – [project]</em> for small cash you fronted.</span></div>
        <div><span class="um-fname">Approving PIC / Manager <span class="text-danger">*</span></span><span class="um-fdesc">Who signs this off. Search by name or nickname. Usually your reporting manager — but for an event run by someone else, pick that manager. You cannot pick yourself.</span></div>
        <div><span class="um-fname">Date of the Event</span><span class="um-fdesc">When it happened. Can't be in the future.</span></div>
        <div><span class="um-fname">Project / Client Name <span class="text-danger">*</span></span><span class="um-fdesc">The project or client this belongs to, e.g. <em>Parentcraft, MCA, internal</em>. Optional for Sales.</span></div>
    </div>
    <div class="um-tip">
        <i class="bi bi-building"></i>Running an event for a <strong>different company</strong> in the
        group? A company dropdown appears next to the approver — switch it to pick that company's
        approving manager.
    </div>
    <div class="um-warn">
        <i class="bi bi-person-exclamation"></i>An approver marked <span class="um-callout">no login yet</span>
        hasn't created their account, so they can't action your claim. Pick someone else.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-receipt"></i> Step 3 — Add your expense items</div>
    <p>One line per receipt. Fill the <strong>Add expense item</strong> form and click <strong>Add to list</strong>. Repeat for each receipt.</p>

    <div class="um-mockup">
        <div style="font-size:12px;font-weight:600;color:#1e293b;margin-bottom:7px;"><i class="bi bi-plus-circle" style="color:#16a34a;"></i> Add expense item</div>
        <div class="um-form">
            <div class="um-frow c8">
                <span class="um-flabel">Expense Description <span class="req">*</span></span>
                <div class="um-fmock">Refreshments for attendees</div>
            </div>
            <div class="um-frow c4">
                <span class="um-flabel">Date of Expense <span class="req">*</span></span>
                <div class="um-fmock">12-07-2026 <i class="bi bi-calendar3 um-caret"></i></div>
                <div class="um-banner um-banner-blue" style="margin-top:4px;">
                    <i class="bi bi-calendar-check"></i> This is a <strong>July 2026</strong> claim — every receipt added here must be dated in <strong>July 2026</strong>. A receipt from another month should be claimed under that month's own claim.
                </div>
            </div>

            <div class="um-frow">
                <span class="um-flabel"><i class="bi bi-paperclip"></i> Upload attachment <span style="font-weight:400;color:#64748b;">— upload one or more files, then Scan to auto-fill. One image with several receipts, or several files at once, opens a review list.</span></span>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <span class="um-file"><span class="um-file-btn">Choose File</span><span class="um-file-name">receipt-cafe.jpg</span></span>
                    <span class="um-btn um-btn-blue"><i class="bi bi-magic"></i> Scan</span>
                </div>
            </div>

            <div class="um-frow">
                <span class="um-flabel"><i class="bi bi-folder-plus"></i> Supporting documents <span style="font-weight:400;color:#64748b;">— optional; attach extra files (e.g. approval, MC, cost breakdown). Multiple allowed; these are not scanned.</span></span>
                <span class="um-file"><span class="um-file-btn">Choose File</span><span class="um-file-name">No file chosen</span></span>
            </div>

            <div class="um-frow c4">
                <span class="um-flabel">Expense Type (Category) <span class="req">*</span></span>
                <div class="um-fmock">922-000: Office Food &amp; Refreshment <i class="bi bi-chevron-down um-caret"></i></div>
            </div>
            <div class="um-frow c3">
                <span class="um-flabel">Amount (w/o SST) <span class="req">*</span></span>
                <div class="um-fmock">196.50</div>
            </div>
            <div class="um-frow c2">
                <span class="um-flabel">SST (RM)</span>
                <div class="um-fmock">0</div>
            </div>
            <div class="um-frow c3">
                <span class="um-flabel">Amount (w/ SST)</span>
                <div class="um-fmock is-ro">196.50</div>
            </div>

            <div class="um-frow">
                <span class="um-flabel"><i class="bi bi-receipt-cutoff" style="color:#0dcaf0;"></i> Receipt details <span style="font-weight:400;color:#64748b;">(read from the attachment — for the report)</span></span>
            </div>
            <div class="um-frow c3"><span class="um-flabel">Company</span><div class="um-fmock is-ro">Company A Café</div></div>
            <div class="um-frow c4"><span class="um-flabel">Item description</span><div class="um-fmock is-ro">Refreshments &amp; drinks</div></div>
            <div class="um-frow c2"><span class="um-flabel">Date</span><div class="um-fmock is-ro">12 Jul 2026</div></div>
            <div class="um-frow c3"><span class="um-flabel">Who paid</span><div class="um-fmock is-ro">Aisha Rahman</div></div>
            <div class="um-frow c3"><span class="um-flabel">Total paid (RM)</span><div class="um-fmock is-ro">196.50</div></div>

            <div class="um-frow" style="display:flex;gap:6px;">
                <span class="um-btn um-btn-green" style="padding:5px 12px;"><i class="bi bi-plus-circle"></i> Add to list</span>
                <span class="um-btn um-btn-grey" style="padding:5px 12px;"><i class="bi bi-eraser"></i> Clear</span>
            </div>
        </div>
        <div class="um-mockup-caption">The <strong>Add expense item</strong> form. The greyed-out <strong>Receipt details</strong> fill themselves in when you press <strong>Scan</strong> — they're read-only and go onto the report.</div>
    </div>

    <p class="mt-3"><strong>Mileage row</strong> — this extra row only appears when you pick the Petrol/mileage category:</p>
    <div class="um-mockup">
        <div class="um-form">
            <div class="um-frow c3">
                <span class="um-flabel">Vehicle <span class="req">*</span></span>
                <div class="um-fmock">Car — RM0.70/km <i class="bi bi-chevron-down um-caret"></i></div>
            </div>
            <div class="um-frow c3">
                <span class="um-flabel">Distance (km) <span class="req">*</span></span>
                <div class="um-fmock">12.4</div>
            </div>
            <div class="um-frow c6" style="display:flex;align-items:flex-end;gap:7px;flex-wrap:wrap;">
                <span class="um-btn um-btn-grey" style="border-color:#93c5fd;color:#1d4ed8;"><i class="bi bi-signpost-2"></i> Calculate distance</span>
                <span style="font-size:9.5px;color:#64748b;">Amount pre-fills from distance × vehicle rate — you can lower it.</span>
            </div>
        </div>
        <div class="um-mockup-caption">Pick the Petrol category and this row appears. The <strong>Amount</strong> above pre-fills as distance × rate; you can lower it if you're claiming less, but you can't claim more than the calculated figure.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Expense Description <span class="text-danger">*</span></span><span class="um-fdesc">What you bought, in plain words.</span></div>
        <div><span class="um-fname">Date of Expense <span class="text-danger">*</span></span><span class="um-fdesc">The date on the receipt. Must fall inside the claim's month.</span></div>
        <div><span class="um-fname">Upload attachment</span><span class="um-fdesc">Your receipt — <code>.jpg .jpeg .png .pdf</code>, up to 5&nbsp;MB each, several at once. A <strong>Scan</strong> button appears once you pick a file.</span></div>
        <div><span class="um-fname">Supporting documents</span><span class="um-fdesc">Optional extras (approval email, MC, cost breakdown). These are <em>not</em> scanned.</span></div>
        <div><span class="um-fname">Expense Type (Category) <span class="text-danger">*</span></span><span class="um-fdesc">Pick the account this belongs to. Listed as <em>GL code: Name</em>.</span></div>
        <div><span class="um-fname">Amount (w/o SST) <span class="text-danger">*</span></span><span class="um-fdesc">The amount before tax.</span></div>
        <div><span class="um-fname">SST (RM)</span><span class="um-fdesc">Tax, if the receipt shows any. Usually 0.</span></div>
        <div><span class="um-fname">Amount (w/ SST)</span><span class="um-fdesc">Filled in for you — amount + SST.</span></div>
    </div>
    <div class="um-tip">
        <i class="bi bi-magic"></i><strong>Let the scan do the typing.</strong> Upload the receipt, click
        <strong>Scan</strong>, and the date, amount, vendor and category are filled in for you. Always
        glance over what it read before adding — you're responsible for the final numbers.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-stars"></i> Scanning receipts — what can pop up</div>
    <div class="um-fields">
        <div><span class="um-fname">Password-protected PDF</span><span class="um-fdesc">Bank/card statements are often locked. Enter the PDF password and click <strong>Unlock &amp; scan</strong>.</span></div>
        <div><span class="um-fname">Multiple receipts found</span><span class="um-fdesc">One image with several transactions opens a review list. <strong>Check and edit every line</strong>, tick the ones you're claiming, then <strong>Add selected</strong>.</span></div>
        <div><span class="um-fname">Possible repeat trip</span><span class="um-fdesc">The system thinks you already claimed this. Check it isn't a duplicate — then <strong>Add anyway</strong> if it's genuinely a separate expense.</span></div>
    </div>
    <div class="um-mockup">
        <div class="um-modal">
            <div class="um-modal-head"><span><i class="bi bi-collection me-1"></i> Multiple receipts found</span><span class="um-x"><i class="bi bi-x-lg"></i></span></div>
            <div class="um-modal-body">
                <div class="um-banner um-banner-amber" style="margin-bottom:8px;">
                    <i class="bi bi-exclamation-triangle"></i> The AI read these transactions from your upload. <strong>Check &amp; edit every line</strong> if needed, then tick the ones to add. Each line keeps its source image as proof.
                </div>
                <table class="um-itable">
                    <thead><tr>
                        <th style="width:26px;"><i class="bi bi-check-square-fill" style="color:#2563eb;"></i></th>
                        <th style="width:88px;">Date</th>
                        <th>Expense Description <span style="color:#dc2626;">*</span></th>
                        <th style="width:150px;">Category</th>
                        <th style="width:82px;" class="ta-r">Amount (RM)</th>
                    </tr></thead>
                    <tbody>
                        <tr>
                            <td><i class="bi bi-check-square-fill" style="color:#2563eb;"></i></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;">12-07-2026</div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;">Refreshments</div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9px;">922-000: Office Food… <i class="bi bi-chevron-down um-caret"></i></div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;justify-content:flex-end;">196.50</div></td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-check-square-fill" style="color:#2563eb;"></i></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;">12-07-2026</div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;">Bottled water</div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9px;">922-000: Office Food… <i class="bi bi-chevron-down um-caret"></i></div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;justify-content:flex-end;">18.00</div></td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-square" style="color:#94a3b8;"></i></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;">12-07-2026</div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;">Personal snack</div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9px;">922-000: Office Food… <i class="bi bi-chevron-down um-caret"></i></div></td>
                            <td><div class="um-fmock" style="min-height:22px;padding:2px 5px;font-size:9.5px;justify-content:flex-end;">6.50</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="um-modal-foot">
                <span class="um-btn um-btn-grey">Cancel</span>
                <span class="um-btn um-btn-blue"><i class="bi bi-plus-lg"></i> Add selected</span>
            </div>
        </div>
        <div class="um-mockup-caption">One image with several transactions opens this list. <strong>Every cell is editable</strong> — fix any misread date, description, category or amount. Untick anything you're not claiming (like the personal snack), then <strong>Add selected</strong>.</div>
    </div>

    <div class="um-tip">
        <i class="bi bi-crop"></i>Claiming a few rows off a long statement? <strong>Highlight or screenshot
        just those rows</strong> before uploading — the scan is far more accurate on a small, clear image.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-signpost-split"></i> Special categories</div>

    <p><strong>Mileage (Petrol)</strong> — claimed by <em>distance</em>, not by receipt.</p>
    <div class="um-fields">
        <div><span class="um-fname">Vehicle <span class="text-danger">*</span></span><span class="um-fdesc">Car or Motorcycle — each has its own rate per km.</span></div>
        <div><span class="um-fname">Distance (km) <span class="text-danger">*</span></span><span class="um-fdesc">How far. <strong>Calculate distance</strong> works it out for you.</span></div>
    </div>
    <p style="font-size:13px;color:#475569;">The amount is worked out as <strong>distance × vehicle rate</strong> — you can't type it yourself. State the route (From → To). <strong>No receipt needed</strong> — the distance is the proof. Toll and parking are <em>separate</em> lines.</p>

    <p class="mt-3"><strong>Extra hours</strong> — pick the <strong>914(b)-000: Transportation</strong> category.</p>
    <p style="font-size:13px;color:#475569;">
        Extra working hours are claimed under this category, and this category is used <em>only</em> for
        extra hours. State the hours you worked (e.g. <em>Parentcraft Event, 8am–6pm</em>). It pays in
        bands — <strong>4 hours = RM50, 8 hours = RM100</strong> — and <strong>no receipt is required</strong>.
    </p>

    <p class="mt-3"><strong>Capped categories</strong> (e.g. Medical for interns, monthly allowances).</p>
    <p style="font-size:13px;color:#475569;">
        A green hint tells you how much of your allowance is left. If your receipt is bigger than what's
        left, the claim is <strong>automatically reduced</strong> to the remaining amount — it isn't
        rejected. When the allowance is fully used, the hint turns red and you can't add more this period.
    </p>

    <p class="mt-3"><strong>Season parking</strong> — shown as <em>“— Season pass (flat RM80)”</em>.</p>
    <p style="font-size:13px;color:#475569;">
        Pays a flat RM80 per month regardless of what the receipt says. Attach the season pass as proof.
        Casual day-to-day parking is a different, normal receipt line.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-exclamation-octagon"></i> Two checks that can stop you</div>

    <div class="um-mockup">
        <div class="um-banner um-banner-red" style="margin-bottom:7px;">
            <strong>You can't claim more than the receipt</strong> — you're claiming RM120.00 but the receipt only shows RM100.00. Lower the amount to RM100.00 or less to add this item.
        </div>
        <div class="um-banner um-banner-amber" style="margin-bottom:7px;">
            <strong>Heads up</strong> — you're claiming RM80.00 but the receipt shows RM100.00. That's fine if it's intentional (e.g. a partial claim); otherwise please double-check before adding.
        </div>
        <div class="um-banner um-banner-green" style="margin-bottom:7px;">
            RM 40.00 of your RM 100.00 monthly Medical allowance is left — a bigger receipt is auto-capped to this.
        </div>
        <div class="um-banner um-banner-red">
            RM 100.00 monthly Medical allowance fully used this period.
        </div>
        <div class="um-mockup-caption">Red = <strong>blocks</strong> the item. Amber = warning only. Green = your remaining allowance on a capped category.</div>
    </div>

    <div class="um-stop">
        <i class="bi bi-x-octagon"></i><strong>You can't claim more than the receipt.</strong> If you type
        RM 120 but the receipt shows RM 100, the item won't be added. Lower the amount to match the receipt.
    </div>
    <div class="um-warn">
        <i class="bi bi-exclamation-triangle"></i><strong>Claiming less than the receipt</strong> only gives
        a warning, not a block — that's fine if it's deliberate (a partial claim), but double-check it isn't
        a typo.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-list-check"></i> The Items list</div>
    <p>
        Everything you've added appears in the <strong>Items</strong> table with its date, description,
        type, amounts, and a paperclip to view the attachment. The <strong>Claim total</strong> sits at
        the bottom.
    </p>

    <div class="um-mockup">
        <div style="font-size:12px;font-weight:600;color:#1e293b;margin-bottom:6px;"><i class="bi bi-list-check"></i> Items <span class="badge bg-secondary" style="font-size:9px;">3</span></div>
        <div class="um-banner um-banner-amber" style="margin-bottom:7px;">
            <i class="bi bi-info-circle"></i> Edit of any item in the list require deletion.
        </div>
        <table class="um-itable">
            <thead>
                <tr><th>Date</th><th>Description</th><th>Expense Type</th><th class="ta-r">w/o SST</th><th class="ta-r">SST</th><th class="ta-r">w/ SST</th><th>Attachment</th><th></th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>12/07/2026</td><td>Grab to venue</td><td><span class="um-cat-pill">Travelling Expenses (Local)</span></td>
                    <td class="ta-r">RM 24.00</td><td class="ta-r">RM 0.00</td><td class="ta-r" style="font-weight:600;">RM 24.00</td>
                    <td><i class="bi bi-paperclip" style="color:#198754;"></i></td>
                    <td class="ta-r"><i class="bi bi-trash" style="color:#dc2626;"></i></td>
                </tr>
                <tr>
                    <td>12/07/2026</td><td>Refreshments for attendees</td><td><span class="um-cat-pill">Office Food &amp; Refreshment</span></td>
                    <td class="ta-r">RM 196.50</td><td class="ta-r">RM 0.00</td><td class="ta-r" style="font-weight:600;">RM 196.50</td>
                    <td><i class="bi bi-paperclip" style="color:#198754;"></i></td>
                    <td class="ta-r"><i class="bi bi-trash" style="color:#dc2626;"></i></td>
                </tr>
                <tr>
                    <td>12/07/2026</td><td>Extra hours (8am&ndash;6pm)</td><td><span class="um-cat-pill">Transportation</span></td>
                    <td class="ta-r">RM 100.00</td><td class="ta-r">RM 0.00</td><td class="ta-r" style="font-weight:600;">RM 100.00</td>
                    <td><span style="color:#94a3b8;">&mdash;</span></td>
                    <td class="ta-r"><i class="bi bi-trash" style="color:#dc2626;"></i></td>
                </tr>
            </tbody>
            <tfoot><tr><td colspan="5" class="ta-r">Claim total</td><td class="ta-r">RM 320.50</td><td></td><td></td></tr></tfoot>
        </table>
        <div class="um-mockup-caption">Your items. The green paperclip opens that receipt; the bin deletes the line. Note the extra-hours line needs <strong>no attachment</strong> (&mdash;).</div>
    </div>
    <div class="um-warn">
        <i class="bi bi-pencil-slash"></i><strong>To change an item, delete it and add it again.</strong>
        There's no in-place edit. Deleting a line that shares a receipt with other lines removes
        <em>all</em> of them — you'd re-upload the receipt and re-add them.
    </div>
    <p style="font-size:13px;color:#475569;">
        If a reviewer flagged something, an orange note appears under that line saying
        <strong>“Reviewer flagged this item”</strong> with their comment. Fix that line in your correction.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-send"></i> Step 4 — Submit</div>

    <div class="um-mockup">
        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
            <span style="font-size:10.5px;color:#16a34a;"><i class="bi bi-check2-circle"></i> All changes saved automatically</span>
            <span style="margin-left:auto;"></span>
            <span class="um-btn um-btn-grey"><i class="bi bi-printer"></i> Preview Report</span>
            <span class="um-btn um-btn-grey" style="color:#dc2626;border-color:#fca5a5;"><i class="bi bi-trash"></i> Delete Whole Claim</span>
            <span class="um-btn um-btn-green"><i class="bi bi-send"></i> Submit claim</span>
        </div>
        <div class="um-mockup-caption">The draft's action row. <strong>Submit claim</strong> stays greyed out until you have at least one item.</div>
    </div>

    <div class="um-mockup">
        <div class="um-modal">
            <div class="um-modal-head"><span><i class="bi bi-send-check me-1" style="color:#16a34a;"></i> Submit claim</span><span class="um-x"><i class="bi bi-x-lg"></i></span></div>
            <div class="um-modal-body">
                <div style="color:#64748b;margin-bottom:6px;">Please double-check before submitting:</div>
                <div style="margin-bottom:3px;"><strong>Event:</strong> Parentcraft Event</div>
                <div style="margin-bottom:7px;"><strong>Approving manager:</strong> Siti Nurhaliza</div>
                <div class="um-banner um-banner-amber"><i class="bi bi-lock"></i> Once submitted you can't edit this claim.</div>
            </div>
            <div class="um-modal-foot">
                <span class="um-btn um-btn-grey">Cancel</span>
                <span class="um-btn um-btn-green"><i class="bi bi-send"></i> Submit claim</span>
            </div>
        </div>
        <div class="um-mockup-caption">The confirmation — check the event and the approving manager, then submit.</div>
    </div>

    <ol class="um-step-list">
        <li>Check the claim with <strong>Preview Report</strong> — this is exactly what your manager sees.</li>
        <li>Click <strong>Submit claim</strong> (it stays disabled until you have at least one item).</li>
        <li>Confirm the <strong>Event</strong> and <strong>Approving manager</strong> shown, then submit.</li>
    </ol>
    <div class="um-warn">
        <i class="bi bi-lock"></i><strong>Once submitted you can't edit the claim.</strong> The items lock
        and the whole claim goes to your manager, then to HR.
    </div>
    <p><strong>Submit blocked?</strong> These are the usual reasons:</p>
    <div class="um-fields">
        <div><span class="um-fname">Missing receipt</span><span class="um-fdesc">“Attach a receipt before submitting these item(s)…” — you can <em>save</em> a draft without receipts, but not <em>submit</em>. (Mileage and extra hours are exempt.)</span></div>
        <div><span class="um-fname">Wrong month</span><span class="um-fdesc">“This report has receipt(s) dated in another month” — move each receipt to a claim for its own month.</span></div>
        <div><span class="um-fname">No items</span><span class="um-fdesc">A claim needs at least one item.</span></div>
        <div><span class="um-fname">Approver problem</span><span class="um-fdesc">You can't be your own approver, and you must choose one.</span></div>
        <div><span class="um-fname">Period closed</span><span class="um-fdesc">That month is no longer open for filing.</span></div>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-calendar-check"></i> Deadlines</div>
    <p>
        Submit to your manager by the <strong>20th</strong> of the month. If a draft is
        <strong>complete</strong> (all receipts attached) but still unsubmitted on the 20th, the system
        <strong>auto-submits it for you</strong> — so a finished claim is never missed.
    </p>
    <div class="um-warn">
        <i class="bi bi-clock-history"></i>Past the 20th you can still submit as normal — but it may be
        <strong>processed together with next month's claims</strong>.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-signpost-2"></i> What happens after you submit</div>
    <div class="um-flow">
        <span class="um-node">Draft</span><i class="bi bi-arrow-right"></i>
        <span class="um-node">Pending Manager</span><i class="bi bi-arrow-right"></i>
        <span class="um-node">Manager Approved</span><i class="bi bi-arrow-right"></i>
        <span class="um-node">Pending HR</span><i class="bi bi-arrow-right"></i>
        <span class="um-node is-end">HR Approved</span>
    </div>
    <p>A submitted claim shows <strong>two</strong> badges — the manager stage and the HR stage:</p>
    <div class="um-table-wrap">
        <table class="um-table">
            <thead><tr><th>Badge</th><th>What it means</th></tr></thead>
            <tbody>
                <tr><td><span class="badge bg-secondary">Draft</span></td><td>Still yours. Not sent to anyone yet.</td></tr>
                <tr><td><span class="badge bg-warning text-dark">Pending Manager</span></td><td>Waiting for your approving manager.</td></tr>
                <tr><td><span class="badge bg-success">Manager Approved</span></td><td>Your manager signed off. Now with HR.</td></tr>
                <tr><td><span class="badge bg-warning text-dark">Pending HR</span></td><td>Waiting for HR's final approval.</td></tr>
                <tr><td><span class="badge bg-success">HR Approved</span></td><td>Fully approved — the final step in the system.</td></tr>
                <tr><td><span class="badge bg-danger">Manager Rejected</span></td><td>Sent back by your manager. You can correct it.</td></tr>
                <tr><td><span class="badge bg-danger">HR Rejected</span></td><td>Sent back by HR. You can correct it.</td></tr>
                <tr><td><span class="badge bg-warning text-dark">Reversed</span></td><td>HR un-approved a claim that had been approved. You can correct it.</td></tr>
                <tr><td><span class="badge bg-primary">Resubmitted</span></td><td>This claim is a correction of an earlier one.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-arrow-counterclockwise"></i> If your claim comes back</div>
    <p>
        A rejected or reversed claim stays in your list as history, with a banner at the top of the card
        explaining who sent it back and why. Any lines they flagged show their comment.
    </p>

    <div class="um-mockup">
        <div class="um-banner um-banner-red">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span><i class="bi bi-x-circle-fill"></i> <strong>Rejected by manager.</strong> The KLCC mileage isn't a business trip — please remove it and resubmit.</span>
                <span class="um-btn um-btn-red" style="margin-left:auto;"><i class="bi bi-arrow-counterclockwise"></i> Make a correction</span>
            </div>
        </div>
        <table class="um-itable" style="margin-top:7px;">
            <thead><tr><th>Date</th><th>Description</th><th>Expense Type</th><th class="ta-r">w/ SST</th></tr></thead>
            <tbody>
                <tr><td>12/07/2026</td><td>Grab to venue</td><td><span class="um-cat-pill">Travelling Expenses (Local)</span></td><td class="ta-r">RM 24.00</td></tr>
                <tr><td></td><td colspan="3" style="background:#fff7ed;color:#9a3412;font-size:10px;"><i class="bi bi-flag-fill"></i> <strong>Reviewer flagged this item:</strong> This trip wasn't for a client — please remove it.</td></tr>
                <tr><td>12/07/2026</td><td>Refreshments for attendees</td><td><span class="um-cat-pill">Office Food &amp; Refreshment</span></td><td class="ta-r">RM 196.50</td></tr>
            </tbody>
        </table>
        <div class="um-mockup-caption">The banner gives the overall reason; any line your reviewer flagged shows <strong>Reviewer flagged this item:</strong> with their comment right under it. Click <strong>Make a correction</strong> to get a new pre-filled draft.</div>
    </div>
    <ol class="um-step-list">
        <li>Read the reason in the banner, and any per-item flags.</li>
        <li>Click <strong>Make a correction</strong>. A <strong>new draft</strong> opens, pre-filled with
            everything from the old claim (event, month, items, attachments).</li>
        <li>Fix what was flagged, then submit it like any other claim.</li>
    </ol>
    <div class="um-stop">
        <i class="bi bi-1-circle"></i><strong>You get one correction per claim.</strong> Once you've filed
        it, the button is gone for good — so make it count. The window also closes at year-end.
    </div>
    <div class="um-tip">
        <i class="bi bi-clock"></i>Rejected by <strong>HR</strong>? You don't wait for anyone — you can
        correct it straight away.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-printer"></i> Printing &amp; PDF</div>
    <p>
        <strong>Preview Report</strong> (drafts) or <strong>View Report</strong> (submitted) opens the real
        claim form in a new tab — the same document your manager and HR see, with the company letterhead,
        the itemised table, the digital sign-offs, and your receipts attached.
    </p>
    <p style="font-size:13px;color:#475569;">
        From there you can <strong>Print</strong> or <strong>Download PDF</strong>. The PDF includes the
        receipt images. The sign-offs are digital — <strong>no physical signature is needed</strong>.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-trash"></i> Deleting a claim</div>
    <p>
        You can delete a <strong>draft</strong> only — with the trash icon on its row, or
        <strong>Delete Whole Claim</strong> inside the builder. This removes the entire claim and can't be
        undone.
    </p>
    <div class="um-warn">
        <i class="bi bi-info-circle"></i>Once a claim is <strong>submitted</strong>, you can't withdraw it
        yourself. Ask your manager to reject it, then file a correction.
    </div>
</div>
