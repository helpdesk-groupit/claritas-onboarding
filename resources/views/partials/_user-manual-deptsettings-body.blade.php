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

    .um-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; line-height: 1.4; }
    .um-pill.auto { background: #dcfce7; color: #15803d; }

    .um-acc { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; }
    .um-acc-header { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #e0f2fe; font-weight: 600; color: #075985; font-size: 13px; }
    .um-acc-header .um-count { margin-left: auto; background: #0369a1; color: #fff; padding: 1px 8px; border-radius: 999px; font-size: 11px; }
    .um-acc-body { padding: 8px; background: #fff; }

    .um-mock-deptrow { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; margin: 4px 0; }
    .um-mock-deptrow .um-mock-header { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px; }
    .um-mock-deptrow .um-mock-name { font-weight: 600; font-size: 12.5px; color: #1e293b; }
    .um-mock-deptrow .um-mock-extras { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px; }
    .um-mock-deptrow .um-mock-extras-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; }
    .um-mock-deptrow .um-mock-chips { display: flex; flex-wrap: wrap; gap: 5px; }
    .um-mock-chip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; background: #f1f5f9; border: 1px solid #e2e8f0; font-size: 11px; color: #475569; }
    .um-mock-chip.on { background: #dbeafe; border-color: #93c5fd; color: #1e40af; }

    .um-modal .modal-title { font-weight: 700; color: #1e40af; }
    .um-modal .modal-title i { margin-right: 6px; }

    [data-theme="dark"] .um-section-title { color: #93c5fd; border-color: #334155; }
    [data-theme="dark"] .um-section p, [data-theme="dark"] .um-step-list > li { color: #cbd5e1; }
    [data-theme="dark"] .um-mockup { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-acc { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .um-acc-body { background: #1e293b; }
    [data-theme="dark"] .um-mock-deptrow { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .um-mock-deptrow .um-mock-name { color: #f1f5f9; }
    [data-theme="dark"] .um-mock-deptrow .um-mock-extras { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .um-pill.auto { background:#14532d; color:#86efac; }
    [data-theme="dark"] .um-mock-chip { background: #0f172a; border-color: #475569; color: #cbd5e1; }
    [data-theme="dark"] .um-mock-chip.on { background: #1e3a8a; border-color: #3b82f6; color: #bfdbfe; }
</style>
@endpush
@endonce

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-info-circle-fill"></i> What is this page?</div>
    <p>
        This page shows, per company, the departments that <strong>actually exist there</strong> based on
        employee records. For each existing department it also lets you decide which <em>other</em>
        companies' tickets that department should also handle (Extras).
    </p>
    <p>
        This page <strong>cannot fabricate</strong> a department for a company. If HR hasn't hired
        anyone in a department at a company, that department won't show up there &mdash; only the actual
        employee records make a department "exist". Only superadmin and system_admin can see or edit this page.
    </p>
    <div class="um-warn"><i class="bi bi-exclamation-triangle-fill"></i><strong>Wide blast radius:</strong> changes here affect every user immediately. There's no "draft" mode &mdash; <em>Save All Changes</em> commits live.</div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-key-fill"></i> How department existence is counted</div>
    <p>"Exists at this company" is determined entirely by member presence:</p>
    <ul class="um-step-list">
        <li><strong>Work-role-gated departments</strong> (Tech, Marketing, Sales, Production, Community, Consulting, Content, Design, Digital, Ecommerce, KOL, Management, Media, Projects) &mdash; the dept exists at company X if any employee record has <code>department = '&lt;dept&gt;'</code> AND <code>company = X</code>. The <code>work_role</code> doesn't matter for existence; just being in the dept is enough.</li>
        <li><strong>App-role-gated departments</strong> (HRA, Group IT, Finance, Admin) &mdash; the dept exists at company X if any user with one of the dept's roles (e.g. <code>hr_manager</code>/<code>hr_executive</code>/<code>hr_intern</code> for HRA) has a linked employee at company X. <code>superadmin</code> and <code>system_admin</code> are excluded since they're system-wide.</li>
        <li><strong>Admin's special case</strong> &mdash; its role list contains only sysadmins, who are excluded. So Admin never auto-derives at any company. To route Admin tickets through this UI, configure another department's row to also serve those companies, or rely on superadmin handling them directly.</li>
    </ul>
    <div class="um-tip"><i class="bi bi-lightbulb"></i><strong>To make a department appear at a company:</strong> add an employee in that department/role to that company on the HR Employee page. The department will show up here automatically next page load.</div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-eye-fill"></i> What you'll see under each company</div>
    <p>Each company section lists only the departments that exist at that company. Each row carries:</p>
    <div class="um-mockup">
        <div class="um-acc">
            <div class="um-acc-header"><i class="bi bi-chevron-down"></i><i class="bi bi-building"></i> Company A Sdn Bhd <span class="um-count">2</span></div>
            <div class="um-acc-body">
                <div class="um-mock-deptrow">
                    <div class="um-mock-header">
                        <span class="um-mock-name"><i class="bi bi-tag me-1 text-muted"></i>Tech</span>
                        <span class="um-pill auto"><i class="bi bi-magic"></i> Auto-served (member of this dept works here)</span>
                    </div>
                    <div class="um-mock-extras">
                        <div class="um-mock-extras-label"><i class="bi bi-share me-1"></i>Also serves these other companies (Extras)</div>
                        <div class="um-mock-chips">
                            <span class="um-mock-chip">Company B Sdn Bhd</span>
                            <span class="um-mock-chip on">Company C Sdn Bhd</span>
                            <span class="um-mock-chip">Company D Sdn Bhd</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="um-mockup-caption">Tech exists at Company A (Auto). The blue chip means Tech also handles Company C's tickets via an Extra.</div>
    </div>
    <ul class="um-step-list">
        <li><strong>Department name</strong> (e.g. Tech) plus a green <span class="um-pill auto"><i class="bi bi-magic"></i> Auto-served</span> pill confirming the dept exists here because a member works here.</li>
        <li><strong>Also serves these other companies (Extras)</strong> &mdash; a chip per other registered company. Click a chip to toggle it on/off.
            <ul style="font-size:12.5px; color:#475569; padding-left:18px; margin-top:4px;">
                <li>Chip <strong>off</strong> (gray) = this dept doesn't handle that company's tickets.</li>
                <li>Chip <strong>on</strong> (blue) = this dept also handles that company's tickets &mdash; an Extra row gets saved in <code>department_company_access</code>.</li>
            </ul>
        </li>
    </ul>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-toggles"></i> Configuring Extras (cross-company assignment)</div>
    <ol class="um-step-list">
        <li><strong>Find the source company</strong> &mdash; the one where the department exists (Auto-served). Expand its section.</li>
        <li><strong>Find the dept row</strong> you want to extend coverage for.</li>
        <li><strong>Click the chip</strong> for the target company under <em>Also serves these other companies</em>. Chip turns blue, the dept now also handles that company's tickets.</li>
        <li>Repeat for as many (dept, other-company) pairs as you need. You can change multiple rows at once.</li>
        <li><strong>Click "Save All Changes"</strong> at the bottom of the page. The system wipes the existing pivot rows and rebuilds from the current chip state.</li>
    </ol>
    <div class="um-tip"><i class="bi bi-info-circle"></i><strong>Where you don't see Extras:</strong> at the receiving company's section. If Tech (from Company A) is configured to also serve Company C, you won't see "Tech" listed under Company C's expanded section &mdash; Company C doesn't have its own Tech members. The Extra is a routing rule from the source, not a record of existence at the destination. Company C's users will still see Tech in the New Ticket dropdown when they pick Company C as the company.</div>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-broadcast"></i> Effects across the system</div>
    <ul class="um-step-list">
        <li><strong>New Ticket form (the Department dropdown)</strong> &mdash; when picking a company, only departments existing there + departments where this company is in another dept's Extras list will show. No more, no less.</li>
        <li><strong>New-ticket emails &amp; bell notifications</strong> &mdash; for a new ticket on (Tech, Company C), recipients = Tech managers across Tech's served cluster (Company A auto + Company C extra). Adding/removing extras here changes that recipient list immediately.</li>
        <li><strong>24-hour stale-ticket reminder</strong> &mdash; same routing as new-ticket notifications. Pulled by the hourly cron.</li>
        <li><strong>Manager visibility on Ticket Management</strong> &mdash; a Tech manager sees Tech tickets across all served companies (including Extras), not just their own.</li>
        <li><strong>PIC assignment dropdown</strong> &mdash; the eligible-PIC pool is the same dept-served cluster.</li>
    </ul>
</div>

<div class="um-section">
    <div class="um-section-title"><i class="bi bi-question-circle-fill"></i> Common pitfalls</div>
    <ul class="um-step-list">
        <li><strong>"My department is missing under this company."</strong> No one with that dept/role works there. Hire/reassign on the HR Employee page; the dept will appear here automatically.</li>
        <li><strong>"How do I assign Tech to handle Company C's tickets?"</strong> Expand <em>Company A</em> (where Tech exists), find the Tech row, click the <em>Company C Sdn Bhd</em> chip under <em>Also serves</em>. Then Save.</li>
        <li><strong>Duplicate company names.</strong> The system treats <code>"Company A Sdn Bhd"</code> and <code>"Company A Sdn. Bhd."</code> (with the period) as different companies. Audit and merge near-duplicates periodically.</li>
        <li><strong>Save All Changes is wipe-and-rebuild.</strong> The current pivot is cleared and re-inserted from the form. If two superadmins have the page open, last save wins.</li>
    </ul>
</div>
