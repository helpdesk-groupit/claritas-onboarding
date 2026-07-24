{{-- Shared User-Manual styling, pushed once no matter how many manual partials include it.
     Included by the claim-module manual bodies (_user-manual-claims-body, -teamclaims-body,
     -hrclaims-body). The older ticket manuals carry their own inline copy of these rules. --}}
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
    .um-stop  { background: #fee2e2; border-left: 4px solid #ef4444; padding: 10px 14px; border-radius: 6px; margin: 10px 0; font-size: 13px; color: #7f1d1d; }
    .um-tip i, .um-warn i, .um-stop i { margin-right: 6px; }
    .um-mockup { border: 1px dashed #cbd5e1; border-radius: 10px; padding: 14px; background: #f8fafc; margin: 12px 0; }
    .um-mockup-caption { font-size: 11px; color: #64748b; font-style: italic; text-align: center; margin: 6px 0 0; }
    .um-callout { display: inline-flex; align-items: center; gap: 4px; background: #fef9c3; color: #854d0e; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .um-badge-row { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 6px; }
    .um-badge-row .badge { font-size: 11px; padding: 5px 10px; }

    /* Field reference list (label → what to put in it) */
    .um-fields { margin: 10px 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    .um-fields > div { display: grid; grid-template-columns: minmax(0, 200px) minmax(0, 1fr); gap: 12px; padding: 9px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .um-fields > div:last-child { border-bottom: none; }
    .um-fields > div:nth-child(odd) { background: #f8fafc; }
    .um-fields .um-fname { font-weight: 600; color: #1e293b; }
    .um-fields .um-fdesc { color: #475569; line-height: 1.5; }
    @media (max-width: 575px) { .um-fields > div { grid-template-columns: 1fr; gap: 2px; } }

    /* Status reference table */
    .um-table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 12.5px; }
    .um-table th { background: #f1f5f9; color: #334155; text-align: left; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #e2e8f0; font-size: 11.5px; text-transform: uppercase; letter-spacing: .3px; }
    .um-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: top; }
    .um-table tr:last-child td { border-bottom: none; }
    .um-table-wrap { overflow-x: auto; }

    /* Simple flow strip (draft -> submitted -> ...) */
    .um-flow { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 12px 0; padding: 12px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; }
    .um-flow .um-node { background: #fff; border: 1px solid #cbd5e1; border-radius: 999px; padding: 4px 11px; font-size: 12px; font-weight: 600; color: #1e293b; white-space: nowrap; }
    .um-flow .um-node.is-end { background: #dcfce7; border-color: #86efac; color: #166534; }
    .um-flow .um-node.is-bad { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
    .um-flow i { color: #94a3b8; font-size: 11px; }

    /* ── Mockups: hand-built replicas of the real screens (no screenshots to go stale) ── */

    /* Stat-card row */
    .um-stat-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
    .um-stat { border-radius: 8px; padding: 8px 10px; color: #fff; min-width: 0; }
    .um-stat .um-stat-n { display: block; font-size: 17px; font-weight: 800; line-height: 1.1; }
    .um-stat .um-stat-l { display: block; font-size: 10px; opacity: .95; line-height: 1.25; }
    .um-stat-amber { background: linear-gradient(135deg,#f59e0b,#d97706); }
    .um-stat-green { background: linear-gradient(135deg,#22c55e,#15803d); }
    .um-stat-red   { background: linear-gradient(135deg,#ef4444,#b91c1c); }
    .um-stat-blue  { background: linear-gradient(135deg,#3b82f6,#1d4ed8); }
    .um-stat-grey  { background: linear-gradient(135deg,#64748b,#334155); }
    @media (max-width: 575px) { .um-stat-row { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    /* Filter pills */
    .um-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
    .um-pill { display: inline-flex; align-items: center; gap: 5px; border: 2px solid #cbd5e1; background: #fff; color: #1e293b; border-radius: 999px; font-weight: 600; font-size: 11.5px; padding: 3px 10px; }
    .um-pill b { background: #e2e8f0; color: #334155; border-radius: 999px; font-size: 10px; padding: 0 5px; }
    .um-pill.is-active { background: linear-gradient(135deg,#3b82f6,#1d4ed8); color: #fff; border-color: transparent; }
    .um-pill.is-active b { background: rgba(255,255,255,.25); color: #fff; }

    /* Accordion (Year → Month → Employee → claim) */
    .um-acc { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; }
    .um-acc-header { display: flex; align-items: center; gap: 8px; padding: 9px 12px; background: #e0f2fe; font-weight: 700; color: #075985; font-size: 13px; }
    .um-acc-header .um-count { margin-left: auto; background: #0369a1; color: #fff; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .um-acc-body { padding: 8px; background: #fff; }
    .um-sub-header { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: #f1f5f9; border-radius: 6px; margin-top: 4px; font-size: 12.5px; color: #1e293b; font-weight: 600; }
    .um-sub-header .um-count { margin-left: auto; background: #64748b; color: #fff; padding: 1px 7px; border-radius: 999px; font-size: 10.5px; }
    .um-sub-header.is-emp { background: #f8fafc; border: 1px solid #eef2f7; font-weight: 500; }
    .um-claim-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
    .um-claim-row:last-child { border-bottom: none; }
    .um-claim-row .um-ev { font-weight: 600; color: #1e293b; }
    .um-claim-row .um-amt { font-weight: 700; color: #1d4ed8; margin-left: auto; white-space: nowrap; }

    /* Fake buttons */
    .um-btn { display: inline-flex; align-items: center; gap: 4px; border-radius: 6px; padding: 3px 10px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
    .um-btn-blue  { background: #2563eb; color: #fff; }
    .um-btn-grey  { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
    .um-btn-green { background: #16a34a; color: #fff; }
    .um-btn-red   { background: #dc2626; color: #fff; }
    .um-btn-amber { background: #f59e0b; color: #fff; }

    /* Decision panel */
    .um-panel { border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; overflow: hidden; }
    .um-panel-title { background: #f1f5f9; padding: 8px 12px; font-size: 12.5px; font-weight: 700; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
    .um-panel-body { padding: 10px 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

    /* ── Claim report replica — mirrors partials/claim-report-form.blade.php exactly ── */
    .um-report { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; }
    .um-lh { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
    .um-lh b { font-size: 11.5px; color: #1e293b; display: block; }
    .um-lh span { font-size: 10px; color: #64748b; line-height: 1.3; }
    .um-lh .um-logo { border: 1px dashed #cbd5e1; border-radius: 4px; padding: 6px 12px; font-size: 9px; color: #94a3b8; letter-spacing: .5px; white-space: nowrap; }
    .um-report-title { text-align: center; font-weight: 700; font-size: 12.5px; color: #1e293b; text-transform: uppercase; letter-spacing: 1px; margin: 10px 0; }
    .um-meta { display: grid; grid-template-columns: 2fr 1fr; gap: 5px 16px; font-size: 10.5px; color: #334155; margin-bottom: 10px; }
    .um-meta > div { display: flex; gap: 6px; }
    .um-meta .um-mlabel { font-weight: 600; flex: 0 0 78px; }
    .um-meta .um-mline { border-bottom: 1px solid #cbd5e1; flex: 1; min-width: 0; }
    .um-rtable { width: 100%; border-collapse: collapse; font-size: 10px; table-layout: fixed; }
    .um-rtable th, .um-rtable td { border: 1px solid #cbd5e1; padding: 3px 5px; text-align: left; color: #334155; word-wrap: break-word; overflow-wrap: anywhere; }
    .um-rtable th { background: #f8fafc; font-weight: 700; text-align: center; font-size: 9.5px; }
    .um-rtable td.ta-r { text-align: right; white-space: nowrap; }
    .um-rtable .um-gt td { font-weight: 700; background: #e2e8f0; text-align: right; }
    .um-rtable .um-pad td { color: #cbd5e1; }
    /* Attachment + receipt-details row */
    .um-attrow > td { background: #fafafa; padding: 6px 8px !important; }
    .um-att-inner { display: grid; grid-template-columns: 62% 38%; gap: 10px; }
    .um-att-img { border: 1px solid #e2e8f0; background: #fff; border-radius: 3px; padding: 14px 8px; text-align: center; font-size: 9.5px; color: #94a3b8; }
    .um-rd { font-size: 9.5px; color: #334155; line-height: 1.5; }
    .um-rd .um-rd-title { font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #475569; margin-bottom: 3px; }
    @media (max-width: 575px) { .um-att-inner { grid-template-columns: 1fr; } .um-meta { grid-template-columns: 1fr; } }
    /* Sign-offs */
    .um-signoffs { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; font-size: 10.5px; color: #334155; }
    .um-signoffs .um-sub { color: #64748b; font-size: 9.5px; }
    .um-signoffs .um-await { font-style: italic; color: #64748b; margin-top: 3px; }
    .um-signoffs .um-ok { color: #16a34a; margin-top: 3px; }
    .um-signnote { font-size: 9.5px; color: #64748b; font-style: italic; margin-top: 8px; }

    /* Inline reviewer flag rows — mirrors .review-flag-row on the real report */
    .um-rtable .um-flagrow > td { background: #fff7ed; }
    .um-flag-input { display: inline-block; width: 100%; background: #fff; border: 1px solid #fdba74; border-radius: 4px; padding: 3px 6px; font-size: 9.5px; color: #9a3412; font-style: italic; }
    .um-flag-input.is-filled { font-style: normal; color: #7c2d12; border-color: #f97316; background: #fffbeb; }

    /* ── Form replicas (label + field) ── */
    .um-form { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 8px; }
    .um-form .um-frow { grid-column: span 12; min-width: 0; }
    .um-form .um-frow.c8 { grid-column: span 8; }
    .um-form .um-frow.c6 { grid-column: span 6; }
    .um-form .um-frow.c4 { grid-column: span 4; }
    .um-form .um-frow.c3 { grid-column: span 3; }
    .um-form .um-frow.c2 { grid-column: span 2; }
    .um-flabel { font-size: 10.5px; font-weight: 600; color: #1e293b; margin-bottom: 3px; display: block; }
    .um-flabel .req { color: #dc2626; }
    .um-fmock { font-size: 11px; background: #fff; border: 1px solid #cbd5e1; border-radius: 5px; padding: 5px 8px; color: #1e293b; display: flex; align-items: center; justify-content: space-between; gap: 6px; min-height: 28px; }
    .um-fmock.is-ph { color: #94a3b8; font-style: italic; }
    .um-fmock.is-ro { background: #f1f5f9; color: #475569; font-weight: 600; }
    .um-fmock .um-caret { color: #94a3b8; font-size: 9px; }
    .um-fhelp { font-size: 9.5px; color: #64748b; margin-top: 2px; line-height: 1.35; }
    @media (max-width: 575px) { .um-form .um-frow, .um-form .um-frow.c6, .um-form .um-frow.c4, .um-form .um-frow.c3, .um-form .um-frow.c8 { grid-column: span 12; } }

    /* ── Banners / hints — colours copied from the real screens ── */
    .um-banner { border-radius: 6px; padding: 7px 10px; font-size: 10.5px; line-height: 1.45; }
    .um-banner-green { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
    /* matches the app's amber alert: background:#fffbeb;border:1px solid #fcd34d;color:#92400e */
    .um-banner-amber { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
    /* matches the app's date note: background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af */
    .um-banner-blue  { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
    .um-banner-red   { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
    /* File-input replica (the real form uses <input type="file">, not a text box) */
    .um-file { display: inline-flex; align-items: stretch; border: 1px solid #cbd5e1; border-radius: 5px; overflow: hidden; background: #fff; font-size: 10.5px; max-width: 340px; }
    .um-file .um-file-btn { background: #f1f5f9; border-right: 1px solid #cbd5e1; padding: 4px 8px; color: #334155; white-space: nowrap; }
    .um-file .um-file-name { padding: 4px 8px; color: #64748b; }
    [data-theme="dark"] .um-file { background: #1e293b; border-color: #475569; }
    [data-theme="dark"] .um-file .um-file-btn { background: #0f172a; border-color: #475569; color: #cbd5e1; }

    /* ── Modal replica ── */
    .um-modal { border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; background: #fff; box-shadow: 0 4px 14px rgba(15,23,42,.1); }
    .um-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 8px 11px; border-bottom: 1px solid #e2e8f0; font-size: 12px; font-weight: 700; color: #1e293b; }
    .um-modal-head .um-x { color: #94a3b8; font-size: 11px; }
    .um-modal-body { padding: 10px 11px; font-size: 11px; color: #334155; }
    .um-modal-foot { display: flex; justify-content: flex-end; gap: 6px; padding: 8px 11px; border-top: 1px solid #e2e8f0; background: #f8fafc; }

    /* ── Items table (the draft's item list) ── */
    .um-itable { width: 100%; border-collapse: collapse; font-size: 10.5px; }
    .um-itable th { background: #f1f5f9; color: #334155; font-weight: 700; text-align: left; padding: 5px 7px; border-bottom: 2px solid #e2e8f0; font-size: 9.5px; }
    .um-itable td { padding: 5px 7px; border-bottom: 1px solid #f1f5f9; color: #334155; }
    .um-itable tfoot td { font-weight: 700; background: #f8fafc; border-top: 2px solid #e2e8f0; }
    .um-itable .ta-r { text-align: right; white-space: nowrap; }
    /* matches the real pill: .badge.rounded-pill.bg-secondary-subtle.text-secondary-emphasis */
    .um-cat-pill { background: #e2e3e5; color: #3f464b; border-radius: 999px; padding: 1px 7px; font-size: 9px; font-weight: 600; white-space: nowrap; }
    [data-theme="dark"] .um-cat-pill { background: #334155; color: #e2e8f0; }

    /* Reviewer check chips — mirror partials/claim-item-checks.blade.php (HR detail page) */
    .um-chk { display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 2px 8px; font-size: 9.5px; font-weight: 600; margin: 0 4px 4px 0; white-space: nowrap; }
    .um-chk i { font-size: 9px; }
    .um-chk-ok     { background: #d1e7dd; color: #0a3622; }            /* bg-success-subtle text-success-emphasis */
    .um-chk-warn   { background: #fff3cd; color: #664d03; }            /* bg-warning-subtle text-warning-emphasis */
    .um-chk-dup    { background: #dc3545; color: #fff; }               /* bg-danger text-white */
    .um-chk-verify { background: #fff; border: 1px solid #6edff6; color: #087990; }  /* btn-outline-info */
    [data-theme="dark"] .um-chk-ok     { background: #0f3d2e; color: #a7f3d0; }
    [data-theme="dark"] .um-chk-warn   { background: #422006; color: #fcd34d; }
    [data-theme="dark"] .um-chk-verify { background: #1e293b; border-color: #0e7490; color: #67e8f9; }

    /* Dark theme — form/banner/modal/items */
    [data-theme="dark"] .um-fmock { background: #1e293b; border-color: #475569; color: #e2e8f0; }
    [data-theme="dark"] .um-fmock.is-ro { background: #0f172a; color: #cbd5e1; }
    [data-theme="dark"] .um-flabel { color: #f1f5f9; }
    [data-theme="dark"] .um-fhelp { color: #94a3b8; }
    [data-theme="dark"] .um-modal { background: #1e293b; border-color: #475569; }
    [data-theme="dark"] .um-modal-head { color: #f1f5f9; border-color: #334155; }
    [data-theme="dark"] .um-modal-body { color: #cbd5e1; }
    [data-theme="dark"] .um-modal-foot { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .um-itable th { background: #0f172a; color: #e2e8f0; border-color: #334155; }
    [data-theme="dark"] .um-itable td { color: #cbd5e1; border-color: #334155; }
    [data-theme="dark"] .um-itable tfoot td { background: #0f172a; }

    /* Dark theme */
    [data-theme="dark"] .um-mockup { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-acc, [data-theme="dark"] .um-acc-body,
    [data-theme="dark"] .um-panel, [data-theme="dark"] .um-report { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .um-acc-header { background: #1e3a8a; color: #dbeafe; }
    [data-theme="dark"] .um-sub-header { background: #0f172a; color: #e2e8f0; border-color: #334155; }
    [data-theme="dark"] .um-sub-header.is-emp { background: #172033; }
    [data-theme="dark"] .um-claim-row { border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .um-claim-row .um-ev { color: #f1f5f9; }
    [data-theme="dark"] .um-pill { background: #1e293b; border-color: #475569; color: #cbd5e1; }
    [data-theme="dark"] .um-btn-grey { background: #1e293b; border-color: #475569; color: #cbd5e1; }
    [data-theme="dark"] .um-panel-title { background: #0f172a; color: #f1f5f9; border-color: #334155; }
    [data-theme="dark"] .um-rtable th { background: #0f172a; }
    [data-theme="dark"] .um-rtable th, [data-theme="dark"] .um-rtable td { border-color: #475569; color: #cbd5e1; }
    [data-theme="dark"] .um-rtable tfoot td { background: #0f172a; }
    [data-theme="dark"] .um-letterhead b, [data-theme="dark"] .um-report-title { color: #f1f5f9; }
    [data-theme="dark"] .um-letterhead { border-color: #94a3b8; }
    [data-theme="dark"] .um-report-meta, [data-theme="dark"] .um-signoffs { color: #cbd5e1; }
    [data-theme="dark"] .um-attach-strip { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-section-title { color: #93c5fd; border-color: #334155; }
    [data-theme="dark"] .um-section p, [data-theme="dark"] .um-step-list > li { color: #cbd5e1; }
    [data-theme="dark"] .um-fields { border-color: #334155; }
    [data-theme="dark"] .um-fields > div { border-color: #334155; }
    [data-theme="dark"] .um-fields > div:nth-child(odd) { background: #0f172a; }
    [data-theme="dark"] .um-fields .um-fname { color: #f1f5f9; }
    [data-theme="dark"] .um-fields .um-fdesc { color: #cbd5e1; }
    [data-theme="dark"] .um-table th { background: #0f172a; color: #e2e8f0; border-color: #334155; }
    [data-theme="dark"] .um-table td { color: #cbd5e1; border-color: #334155; }
    [data-theme="dark"] .um-flow { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .um-flow .um-node { background: #1e293b; border-color: #475569; color: #e2e8f0; }
</style>
@endpush
@endonce
