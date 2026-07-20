{{-- User-Manual content: HR Claims (final approval, reverse, export).
     Single source of truth — included by BOTH the in-app modal (_user-manual-hrclaims.blade.php)
     and the public help page (help/hr-claims.blade.php). Keep company names generic. --}}
@include('partials._user-manual-styles')

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-info-circle"></i> What this page is for</div>
    <p>
        <strong>Expense Claims</strong> is HR's final approval desk. Claims arrive here <em>after</em> the
        employee's manager has approved them. You do the last check, approve them for payout, and export
        the approved forms for Finance.
    </p>
    <div class="um-tip">
        <i class="bi bi-funnel"></i>You only ever see claims that are <strong>manager-approved or beyond</strong>.
        Drafts and claims still sitting with a manager never reach this page.
    </div>
    <div class="um-flow">
        <span class="um-node">Manager Approved</span><i class="bi bi-arrow-right"></i>
        <span class="um-node">Pending HR — you are here</span><i class="bi bi-arrow-right"></i>
        <span class="um-node is-end">HR Approved → Paid</span>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-grid-1x2"></i> The page at a glance</div>
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
    <div class="um-tip"><i class="bi bi-check2-all"></i>“All automatic checks passed — no math, cap, or duplicate issues found.”</div>
    <div class="um-warn"><i class="bi bi-exclamation-triangle"></i>“N points to review before approving:” — with a list of exactly what looks off.</div>
    <p>Open <strong>Reviewer checks &amp; verify items</strong> to see it line by line:</p>
    <div class="um-fields">
        <div><span class="um-fname">Total = amount + GST</span><span class="um-fdesc">The arithmetic on the line adds up.</span></div>
        <div><span class="um-fname">Amount = km × rate</span><span class="um-fdesc">A mileage line was calculated at the correct rate.</span></div>
        <div><span class="um-fname">Receipt attached</span><span class="um-fdesc">Proof is present (mileage and extra hours are exempt).</span></div>
        <div><span class="um-fname">Over / Near cap</span><span class="um-fdesc">The claim is at or past the category's monthly/annual limit.</span></div>
        <div><span class="um-fname">Duplicate badge</span><span class="um-fdesc">Red and clickable — opens the matching receipt so you can compare the two side by side.</span></div>
        <div><span class="um-fname">Verify receipt / Verify distance</span><span class="um-fdesc">On-demand: re-reads the receipt, or re-measures the route, and tells you whether it agrees with what was claimed.</span></div>
    </div>
    <div class="um-warn">
        <i class="bi bi-robot"></i>The checks are an <strong>aid, not a verdict</strong>. A flag isn't proof
        of wrongdoing, and a clean pass isn't a guarantee. Your judgement decides.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-check2-circle"></i> HR Approve</div>
    <ol class="um-step-list">
        <li>Work through the checks and the report — receipts, amounts, categories, caps, duplicates.</li>
        <li>Click <strong>HR Approve</strong> (only shown while the claim is <em>Manager Approved</em>).</li>
        <li>Confirm <em>“HR approve this entire claim for payout?”</em>.</li>
    </ol>
    <p style="font-size:13px;color:#475569;">
        The claim becomes <strong>HR Approved</strong> and is ready for payout — and from that moment it's
        included in the <strong>Approved PDFs (ZIP)</strong> export.
    </p>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-x-circle"></i> Reject a claim</div>
    <div class="um-warn">
        <i class="bi bi-exclamation-triangle"></i>Rejecting returns the <strong>entire</strong> claim to the
        employee — there's no per-item reject.
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
        <i class="bi bi-cash-stack"></i><strong>Check whether it's already been paid.</strong> Reversing
        doesn't claw money back — if the payout has run, sort that out with Finance/payroll separately.
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-file-earmark-zip"></i> Approved PDFs (ZIP) — the Finance export</div>
    <p>The bulk hand-off: every approved claim as a properly-named PDF (receipts embedded), in one ZIP.</p>
    <ol class="um-step-list">
        <li>Click <strong>Approved PDFs (ZIP)</strong>.</li>
        <li>Pick a <strong>Submission cycle</strong> — e.g. <em>July (21 Jun – 20 Jul)</em> — or leave it on
            <strong>All cycles</strong>. Cycles run to each company's cutoff (the 20th by default).</li>
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
                <tr><td><span class="badge bg-success">HR Approved</span></td><td>Fully approved, ready for payout.</td><td>Include in the ZIP export</td></tr>
                <tr><td><span class="badge bg-danger">HR Rejected</span></td><td>You sent it back.</td><td>Wait for their correction</td></tr>
                <tr><td><span class="badge bg-warning text-dark">Reversed</span></td><td>You un-approved an approved claim.</td><td>Wait for their correction</td></tr>
                <tr><td><span class="badge bg-primary">Resubmitted</span></td><td>A correction of an earlier claim.</td><td>Review as normal</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-wallet2"></i> Payroll linkage</div>
    <p>
        Once a claim has been paid, its page shows a <strong>Payroll Linkage</strong> section — the pay run,
        a <strong>Processed</strong> badge, the payslip, and the <strong>Reimbursement Amount</strong>.
    </p>
    <div class="um-tip">
        <i class="bi bi-info-circle"></i>A reimbursement is <strong>non-taxable</strong> — it's excluded from
        statutory calculations. It's repaying money the employee already spent, not income.
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
