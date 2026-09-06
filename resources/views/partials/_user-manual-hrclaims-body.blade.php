{{-- User-Manual content: HR Claims (final approval, reverse, export).
     Single source of truth — included by BOTH the in-app modal (_user-manual-hrclaims.blade.php)
     and the public help page (help/hr-claims.blade.php). Keep company names generic. --}}
@include('partials._user-manual-styles')

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-info-circle"></i> What this page is for</div>
    <p>
        <strong>Expense Claims</strong> is HR's final approval desk. Claims arrive here <em>after</em> the
        employee's manager has approved them. You do the last check, approve them, and export
        the approved forms for Finance.
    </p>
    <div class="um-tip">
        <i class="bi bi-funnel"></i>You only ever see claims that are <strong>manager-approved or beyond</strong>.
        Drafts and claims still sitting with a manager never reach this page.
    </div>
    <div class="um-flow">
        <span class="um-node">Manager Approved</span><i class="bi bi-arrow-right"></i>
        <span class="um-node">Pending HR — you are here</span><i class="bi bi-arrow-right"></i>
        <span class="um-node is-end">HR Approved</span>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-grid-1x2"></i> The page at a glance</div>

    <div class="um-mockup">
        <div class="um-stat-row">
            <div class="um-stat um-stat-amber"><span class="um-stat-n">6</span><span class="um-stat-l">Pending HR Approval</span></div>
            <div class="um-stat um-stat-green"><span class="um-stat-n">12</span><span class="um-stat-l">HR Approved</span></div>
            <div class="um-stat um-stat-red"><span class="um-stat-n">1</span><span class="um-stat-l">HR Rejected</span></div>
            <div class="um-stat" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);"><span class="um-stat-n">19</span><span class="um-stat-l">Total Claims (reached HR)</span></div>
        </div>
        <div class="um-pills">
            <span class="um-pill is-active">All <b>19</b></span>
            <span class="um-pill">Pending HR <b>6</b></span>
            <span class="um-pill">HR Approved <b>12</b></span>
            <span class="um-pill">HR Rejected <b>1</b></span>
            <span class="um-pill">Reversed <b>0</b></span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">
            <div class="um-fmock is-ph" style="flex:1;min-width:160px;">Search employee name or event… <i class="bi bi-search um-caret"></i></div>
            <div class="um-fmock" style="width:130px;">Jul 2026 <i class="bi bi-calendar3 um-caret"></i></div>
            <div class="um-fmock" style="width:140px;">12-07-2026 <i class="bi bi-calendar-date um-caret"></i></div>
            <span class="um-btn um-btn-grey" title="Clear filters"><i class="bi bi-x-lg"></i></span>
        </div>
        <div class="um-mockup-caption">The four count cards, the status pills, and the name/event search plus <strong>claim-month</strong> and <strong>submitted-date</strong> pickers. The <strong>X</strong> clears every filter.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Four count cards</span><span class="um-fdesc"><strong>Pending HR Approval</strong>, <strong>HR Approved</strong>, <strong>HR Rejected</strong>, <strong>Total Claims (reached HR)</strong>.</span></div>
        <div><span class="um-fname">Year selector</span><span class="um-fdesc">In the card header — pick a year and click <strong>View</strong>.</span></div>
        <div><span class="um-fname">Filter &amp; search</span><span class="um-fdesc">Pills: <strong>All</strong>, <strong>Pending HR</strong>, <strong>HR Approved</strong>, <strong>HR Rejected</strong>, <strong>Reversed</strong>. Plus a name/event search and month &amp; submitted-date pickers.</span></div>
        <div><span class="um-fname">The list</span><span class="um-fdesc">Grouped <strong>Year → Month → Employee</strong>.</span></div>
        <div><span class="um-fname">Approved PDFs (ZIP)</span><span class="um-fdesc">The bulk export for Finance — see below.</span></div>
    </div>
    <div class="um-tip">
        <i class="bi bi-cash-coin"></i>RM totals beside a year/month/person count only
        <strong>HR-approved</strong> claims — so pending work shows RM 0.00.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-box-arrow-up-right"></i> Opening a claim — two routes</div>
    <p>Each row gives you <strong>Review</strong> (when it's waiting on you) or <strong>View report</strong>.</p>

    <div class="um-mockup">
        <div class="um-acc">
            <div class="um-acc-header"><i class="bi bi-chevron-down"></i> 2026 <span class="um-count">19 claims</span></div>
            <div class="um-acc-body">
                <div class="um-sub-header"><i class="bi bi-chevron-down"></i> July 2026 <span class="um-count">4 staff · 7 claims</span></div>
                <div class="um-sub-header is-emp"><i class="bi bi-chevron-down"></i> <i class="bi bi-person"></i> Aisha Rahman <span style="color:#64748b;font-weight:400;">· Marketing</span> <span class="um-count">2 claims</span></div>
                <div style="padding:4px 8px;">
                    <div class="um-claim-row">
                        <span class="badge bg-success">Manager Approved</span>
                        <span class="badge bg-warning text-dark">Pending HR</span>
                        <span class="um-ev">Parentcraft Event</span>
                        <span class="um-amt">RM 320.50</span>
                        <span class="um-btn um-btn-blue"><i class="bi bi-eye"></i> Review</span>
                    </div>
                    <div class="um-claim-row">
                        <span class="badge bg-success">Manager Approved</span>
                        <span class="badge bg-success">HR Approved</span>
                        <span class="um-ev">Petty Cash – Office</span>
                        <span class="um-amt">RM 88.00</span>
                        <span class="um-btn um-btn-grey"><i class="bi bi-eye"></i> View report</span>
                    </div>
                </div>
                <div class="um-sub-header is-emp"><i class="bi bi-chevron-right"></i> <i class="bi bi-person"></i> Daniel Lim <span style="color:#64748b;font-weight:400;">· Sales</span> <span class="um-count">3 claims</span></div>
            </div>
        </div>
        <div class="um-mockup-caption">Click down through <strong>Year → Month → Employee</strong>. A blue <strong>Review</strong> button means that claim is waiting on you; grey <strong>View report</strong> is everything else.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Review page</span><span class="um-fdesc">The decision screen. Approve, reject, or reverse — and it lets you leave a <strong>comment on individual items</strong> so the employee knows exactly what to fix.</span></div>
        <div><span class="um-fname">Claim detail page</span><span class="um-fdesc">Carries the same Approve/Reject/Reverse actions <em>plus</em> the automatic <strong>reviewer checks</strong> and the <strong>Verify</strong> tools. But it has <em>no</em> per-item comments.</span></div>
    </div>
    <div class="um-tip">
        <i class="bi bi-signpost-split"></i><strong>Which should you use?</strong> Use the
        <strong>detail page</strong> to inspect (checks + verify), and the <strong>Review page</strong> to
        reject or reverse — so you can flag the exact lines at fault. For a clean approval, either works.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-shield-check"></i> The automatic checks (detail page)</div>
    <p>Before you read a single receipt, the system has already checked the claim. At the top you'll see either:</p>

    <div class="um-mockup">
        <div class="um-banner um-banner-green" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <i class="bi bi-shield-check" style="font-size:14px;"></i>
            <span>All automatic checks passed — no math, cap, or duplicate issues found.</span>
        </div>
        <div class="um-banner um-banner-amber">
            <div style="font-weight:700;margin-bottom:3px;"><i class="bi bi-exclamation-triangle"></i> 2 points to review before approving:</div>
            <ul style="margin:0;padding-left:18px;">
                <li>&ldquo;Clinic visit&rdquo;: over the monthly cap (RM 120.00 / RM 100.00)</li>
                <li>&ldquo;Mileage to client site&rdquo;: Possible duplicate of EC-2026-07-0002</li>
            </ul>
        </div>
        <div class="um-mockup-caption">One of these two banners sits at the very top of the claim. Green = nothing flagged. Amber = a short list of exactly what looks off.</div>
    </div>

    <p>Open <strong>Reviewer checks &amp; verify items</strong> to see it line by line:</p>

    <div class="um-mockup">
        <div class="um-panel">
            <div class="um-panel-title"><i class="bi bi-shield-check me-1"></i> Reviewer checks &amp; verify items</div>
            <div class="um-panel-body" style="display:block;">
                {{-- item over its monthly cap --}}
                <div style="padding-bottom:8px;border-bottom:1px solid #f1f5f9;">
                    <div style="font-size:11px;color:#1e293b;margin-bottom:5px;">
                        <strong>2. 12/07/2026 — Clinic visit</strong>
                        <span class="um-cat-pill">Medical</span>
                        <span style="color:#64748b;">RM 120.00</span>
                    </div>
                    <span class="um-chk um-chk-ok"><i class="bi bi-check-circle"></i> Total = amount + GST</span>
                    <span class="um-chk um-chk-ok"><i class="bi bi-check-circle"></i> Receipt attached</span>
                    <span class="um-chk um-chk-dup"><i class="bi bi-graph-up-arrow"></i> Over monthly cap</span>
                    <span class="um-chk um-chk-verify"><i class="bi bi-shield-check"></i> Verify receipt</span>
                </div>
                {{-- item merely NEAR a cap — an amber on-item hint that does NOT escalate to the banner --}}
                <div style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
                    <div style="font-size:11px;color:#1e293b;margin-bottom:5px;">
                        <strong>3. 12/07/2026 — Dental — filling</strong>
                        <span class="um-cat-pill">Optical &amp; Dental</span>
                        <span style="color:#64748b;">RM 460.00</span>
                    </div>
                    <span class="um-chk um-chk-ok"><i class="bi bi-check-circle"></i> Total = amount + GST</span>
                    <span class="um-chk um-chk-ok"><i class="bi bi-check-circle"></i> Receipt attached</span>
                    <span class="um-chk um-chk-warn"><i class="bi bi-graph-up-arrow"></i> Near annual cap</span>
                    <span class="um-chk um-chk-verify"><i class="bi bi-shield-check"></i> Verify receipt</span>
                </div>
                {{-- mileage item flagged as a possible duplicate --}}
                <div style="padding-top:8px;">
                    <div style="font-size:11px;color:#1e293b;margin-bottom:5px;">
                        <strong>4. 12/07/2026 — Mileage to client site</strong>
                        <span class="um-cat-pill">Petrol / Mileage</span>
                        <span style="color:#64748b;">RM 34.30</span>
                    </div>
                    <span class="um-chk um-chk-ok"><i class="bi bi-check-circle"></i> Amount &le; km &times; rate</span>
                    <span class="um-chk um-chk-dup"><i class="bi bi-files"></i> Possible duplicate of EC-2026-07-0002 <i class="bi bi-box-arrow-up-right"></i></span>
                    <span class="um-chk um-chk-verify"><i class="bi bi-shield-check"></i> Verify distance</span>
                </div>
            </div>
        </div>
        <div class="um-mockup-caption">Each line shows its own chips. <strong>Green</strong> = a check passed. <strong>Amber</strong> = <em>near</em> a monthly/annual cap. <strong>Red</strong> = <em>over</em> a cap, or a possible duplicate — click a duplicate to open the matching receipt to compare. The blue <strong>Verify</strong> button re-reads the receipt or re-measures the route on demand. A <em>near</em>-cap is a soft hint only — the banner above lists just over-caps, duplicates, and failed checks.</div>
    </div>

    <div class="um-fields">
        <div><span class="um-fname">Total = amount + GST</span><span class="um-fdesc">The arithmetic on the line adds up.</span></div>
        <div><span class="um-fname">Amount &le; km &times; rate</span><span class="um-fdesc">A mileage line doesn't exceed distance &times; the vehicle rate (under-claiming is allowed). Per-day lines read <em>Amount = day &times; rate</em>.</span></div>
        <div><span class="um-fname">Receipt attached</span><span class="um-fdesc">Proof is present (mileage and extra hours are exempt).</span></div>
        <div><span class="um-fname">Over / Near cap</span><span class="um-fdesc">The category is <strong>over</strong> (red) or <strong>near</strong> — within 90% (amber) — its monthly/annual limit. Only <em>over</em>-cap items reach the banner at the top.</span></div>
        <div><span class="um-fname">Duplicate badge</span><span class="um-fdesc">Red and clickable — reads <em>&ldquo;Same receipt as EC-…&rdquo;</em> or <em>&ldquo;Possible duplicate of EC-…&rdquo;</em> and opens the matching receipt so you can compare the two.</span></div>
        <div><span class="um-fname">Verify receipt / Verify distance</span><span class="um-fdesc">On-demand: re-reads the receipt, or re-measures the route, and tells you whether it agrees with what was claimed.</span></div>
    </div>
    <div class="um-warn">
        <i class="bi bi-robot"></i>The checks are an <strong>aid, not a verdict</strong>. A flag isn't proof
        of wrongdoing, and a clean pass isn't a guarantee. Your judgement decides.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-check2-circle"></i> HR Approve</div>

    <div class="um-mockup">
        <div class="um-panel">
            <div class="um-panel-title"><i class="bi bi-clipboard-check me-1"></i> Your decision — HR approval</div>
            <div class="um-panel-body">
                <span class="um-btn um-btn-green"><i class="bi bi-check-lg"></i> HR Approve</span>
                <span class="um-btn um-btn-grey" style="color:#dc2626;border-color:#fca5a5;"><i class="bi bi-x-octagon"></i> Reject Claim</span>
                <span style="font-size:11px;color:#64748b;margin-left:auto;">3 items · RM 320.50</span>
            </div>
        </div>
        <div class="um-mockup-caption">The decision row. <strong>HR Approve</strong> shows only while the claim is <em>Manager Approved</em>.</div>
    </div>

    <ol class="um-step-list">
        <li>Work through the checks and the report — receipts, amounts, categories, caps, duplicates.</li>
        <li>Click <strong>HR Approve</strong> (only shown while the claim is <em>Manager Approved</em>).</li>
        <li>Confirm the prompt to HR-approve the whole claim.</li>
    </ol>
    <p style="font-size:13px;color:#475569;">
        The claim becomes <strong>HR Approved</strong> — and from that moment it's
        included in the <strong>Approved PDFs (ZIP)</strong> export.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-x-circle"></i> Reject a claim</div>
    <div class="um-warn">
        <i class="bi bi-exclamation-triangle"></i>Rejecting returns the <strong>entire</strong> claim to the
        employee — there's no per-item reject.
    </div>

    <div class="um-mockup">
        <div class="um-panel">
            <div class="um-panel-title"><i class="bi bi-x-octagon me-1"></i> Reject claim</div>
            <div class="um-panel-body" style="display:block;">
                <div style="font-size:11px;color:#78350f;background:#fef3c7;border-radius:5px;padding:6px 8px;margin-bottom:7px;">
                    <i class="bi bi-exclamation-triangle"></i> Rejecting returns the <strong>whole claim</strong> to Aisha Rahman to fix and resubmit.
                    <div style="margin-top:3px;"><i class="bi bi-flag"></i> On the <strong>Review page</strong>, scroll up to the report and comment under any item that needs fixing — the employee sees those flags.</div>
                </div>
                <div style="font-size:10.5px;font-weight:600;color:#1e293b;margin-bottom:3px;">Reason (optional — the employee sees this)</div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="um-flag-input" style="flex:1;">e.g., Office Equipment receipt is missing — please attach and resubmit.</span>
                    <span class="um-btn um-btn-red"><i class="bi bi-x-circle"></i> Confirm Reject</span>
                </div>
            </div>
        </div>
        <div class="um-mockup-caption">The reject box. Add an overall reason here; on the <strong>Review page</strong> you can also flag the exact lines at fault.</div>
    </div>

    <ol class="um-step-list">
        <li>Click <strong>Reject Claim</strong>.</li>
        <li>Give a reason — optional, but the employee sees it, so always write one. E.g.
            <em>“Office Equipment receipt is missing — please attach and resubmit.”</em></li>
        <li>On the <strong>Review page</strong> you can also comment on the specific lines at fault. Do this
            where you can — it's the difference between a clean resubmission and a second rejection.</li>
        <li>Confirm.</li>
    </ol>
    <div class="um-tip">
        <i class="bi bi-person-check"></i>The employee can correct it <strong>immediately</strong> — nothing
        is held or gated. Their approving manager is notified for information.
    </div>
    <div class="um-stop">
        <i class="bi bi-1-circle"></i>They get <strong>one</strong> correction per claim. Make your feedback
        complete the first time.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-arrow-counterclockwise"></i> Reverse an approved claim</div>
    <p>
        <strong>Reverse Claim</strong> is the undo for a claim you've <em>already approved</em>. It appears
        only on <strong>HR Approved</strong> claims. Use it when something surfaces after the fact — a
        duplicate, a rejected expense, a management decision.
    </p>

    <div class="um-mockup">
        <div class="um-panel">
            <div class="um-panel-title"><i class="bi bi-arrow-counterclockwise me-1"></i> Reverse claim</div>
            <div class="um-panel-body" style="display:block;">
                <div style="font-size:11px;color:#92400e;margin-bottom:7px;">
                    <i class="bi bi-info-circle"></i> This claim is already fully approved. <strong>Reversing</strong> un-approves it and returns it to the employee to correct — the same as a rejection.
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="um-flag-input" style="flex:1;">e.g., Management advised this claim cannot be approved — please review and resubmit.</span>
                    <span class="um-btn um-btn-amber"><i class="bi bi-arrow-counterclockwise"></i> Confirm Reverse</span>
                </div>
            </div>
        </div>
        <div class="um-banner um-banner-amber" style="margin-top:8px;">
            <i class="bi bi-arrow-counterclockwise"></i> <strong>Reversed</strong> — Management advised this claim cannot be approved.
            <span style="display:block;color:#a16207;margin-top:2px;">on 24 Jul 2026, 15:02</span>
        </div>
        <div class="um-mockup-caption">The reverse box, then the amber banner the claim carries afterwards — your reason and a timestamp.</div>
    </div>

    <ol class="um-step-list">
        <li>Click <strong>Reverse Claim</strong> and confirm
            <em>“Reverse this already-approved claim? It returns to the employee to correct.”</em></li>
        <li>Give a reason — e.g. <em>“Management advised this claim cannot be approved…”</em></li>
        <li>Confirm. The claim becomes <strong>Reversed</strong> and shows an amber banner with your reason
            and timestamp.</li>
    </ol>
    <div class="um-fields">
        <div><span class="um-fname">What it does</span><span class="um-fdesc">Un-approves the claim and sends the whole report back to the employee to correct and resubmit — the same as a rejection.</span></div>
        <div><span class="um-fname">The history stays</span><span class="um-fdesc">The original manager and HR approvals remain on the sign-off; the reversal is recorded alongside them. Nothing is erased.</span></div>
        <div><span class="um-fname">It leaves the export</span><span class="um-fdesc">A reversed claim <strong>drops out of the Approved PDFs (ZIP)</strong>, so Finance won't pay it.</span></div>
    </div>
    <div class="um-warn">
        <i class="bi bi-cash-stack"></i><strong>Reversing only un-approves the claim in the system.</strong>
        It doesn't recover any money — if the reimbursement was already settled outside the system, sort that out with Finance separately.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-file-earmark-zip"></i> Approved PDFs (ZIP) — the Finance export</div>
    <p>The bulk hand-off: every approved claim as a properly-named PDF (receipts embedded), in one ZIP.</p>

    <div class="um-mockup">
        <div class="um-modal">
            <div class="um-modal-head"><span><i class="bi bi-file-earmark-zip me-1" style="color:#dc2626;"></i> Export approved PDFs (ZIP)</span><span class="um-x"><i class="bi bi-x-lg"></i></span></div>
            <div class="um-modal-body">
                <div style="color:#64748b;margin-bottom:8px;">Bundles <strong>processed (HR-approved)</strong> claims for the <strong>2026</strong> approval cycles into one ZIP of PDFs. Leave a filter on “All” to include everything.</div>
                <div style="margin-bottom:8px;">
                    <div style="font-size:10.5px;font-weight:600;color:#1e293b;margin-bottom:3px;">Approval cycle</div>
                    <div class="um-fmock">July (21 Jun – 20 Jul) <i class="bi bi-chevron-down um-caret"></i></div>
                </div>
                <div>
                    <div style="font-size:10.5px;font-weight:600;color:#1e293b;margin-bottom:3px;">Company <span style="font-weight:400;color:#64748b;">(tick one or more)</span></div>
                    <div style="border:1px solid #e2e8f0;border-radius:6px;padding:6px 8px;font-size:10.5px;color:#334155;background:#fff;">
                        <div><i class="bi bi-check-square-fill" style="color:#2563eb;"></i> Company A Sdn Bhd</div>
                        <div style="margin-top:3px;"><i class="bi bi-square" style="color:#94a3b8;"></i> Company B Sdn Bhd</div>
                        <div style="margin-top:3px;"><i class="bi bi-square" style="color:#94a3b8;"></i> Company C Sdn Bhd</div>
                    </div>
                    <div style="font-size:9.5px;color:#64748b;margin-top:4px;">Leave all unticked to include every company.</div>
                </div>
            </div>
            <div class="um-modal-foot">
                <span class="um-btn um-btn-grey">Cancel</span>
                <span class="um-btn um-btn-red"><i class="bi bi-download"></i> Download ZIP</span>
            </div>
        </div>
        <div class="um-mockup-caption">The export dialog. Pick an <strong>approval cycle</strong> and any <strong>companies</strong>, or leave both open to include everything, then <strong>Download ZIP</strong>.</div>
    </div>

    <ol class="um-step-list">
        <li>Click <strong>Approved PDFs (ZIP)</strong>.</li>
        <li>Pick an <strong>Approval cycle</strong> — e.g. <em>July (21 Jun – 20 Jul)</em> — or leave it on
            <strong>All cycles</strong>. Cycles run to each company's cutoff (the 20th by default) and are based on when a claim was fully approved, not when it was submitted.</li>
        <li>Tick one or more <strong>companies</strong>, or leave them all unticked to include every company.</li>
        <li>Click <strong>Download ZIP</strong>. You get <code>approved-claims-YYYY-MM-DD.zip</code>.</li>
    </ol>
    <div class="um-fields">
        <div><span class="um-fname">Only fully-approved</span><span class="um-fdesc">Only <strong>HR-approved</strong> claims are included. Pending and reversed ones are not.</span></div>
        <div><span class="um-fname">200 claim limit</span><span class="um-fdesc">One ZIP holds up to 200 claims — narrow by cycle or company if you hit it.</span></div>
        <div><span class="um-fname">Nothing to export</span><span class="um-fdesc">If no claim qualifies yet, the button is disabled and it says so.</span></div>
    </div>
    <p style="font-size:13px;color:#475569;">
        For a single claim, use <strong>Download PDF</strong> on its page, or
        <strong>Print / full report</strong> to open the printable form in a new tab.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-bookmark-check"></i> Badges you'll see</div>
    <div class="um-table-wrap">
        <table class="um-table">
            <thead><tr><th>Badge</th><th>What it means</th><th>Your move</th></tr></thead>
            <tbody>
                <tr><td><span class="badge bg-success">Manager Approved</span> + <span class="badge bg-warning text-dark">Pending HR</span></td><td>Waiting on you.</td><td><strong>Review</strong> → approve or reject</td></tr>
                <tr><td><span class="badge bg-success">HR Approved</span></td><td>Fully approved — the final step in the system.</td><td>Include in the ZIP export</td></tr>
                <tr><td><span class="badge bg-danger">HR Rejected</span></td><td>You sent it back.</td><td>Wait for their correction</td></tr>
                <tr><td><span class="badge bg-warning text-dark">Reversed</span></td><td>You un-approved an approved claim.</td><td>Wait for their correction</td></tr>
                <tr><td><span class="badge bg-primary">Resubmitted</span></td><td>A correction of an earlier claim.</td><td>Review as normal</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-calendar-check"></i> The monthly rhythm</div>
    <div class="um-fields">
        <div><span class="um-fname">The 20th</span><span class="um-fdesc">The cutoff. A claim must be <strong>manager-approved</strong> by then to make that cycle.</span></div>
        <div><span class="um-fname">Auto-submit</span><span class="um-fdesc">Complete drafts left unsubmitted are auto-submitted on the cutoff day, so nothing finished is missed.</span></div>
        <div><span class="um-fname">Reminders</span><span class="um-fdesc">Employees and approving managers are emailed automatically as the cutoff approaches — you don't need to chase them by hand.</span></div>
        <div><span class="um-fname">Late claims</span><span class="um-fdesc">Nothing is auto-approved and nothing is lost — late claims simply roll into the next cycle.</span></div>
    </div>
</div>
