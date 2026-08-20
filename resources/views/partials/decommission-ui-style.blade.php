{{-- Shared visual language for the asset-decommissioning surfaces:
       • Accounting → Assets → status "Disposed"  (accounting/fixed-assets/index)
       • C-Suite Reports → Assets → Decommissioning (reports/decommission)
     Mirrors the modern pages (user/claims/index, dashboard widgets): gradient chips,
     soft section heads, uppercase micro-labels. Prefixed `ewx-` so nothing leaks.
     @once-guarded — safe to include from a page that also includes it via a partial. --}}
@once
<style>
    .ewx-card { overflow: hidden; }
    .ewx-head {
        display: flex; align-items: center; gap: .85rem; flex-wrap: wrap;
        padding: .85rem 1.15rem;
        background: linear-gradient(135deg, #f8fafc, #eef2f7);
        border-bottom: 1px solid #e9eef5;
    }
    .ewx-chip {
        width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0; color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.05rem;
    }
    .ewx-chip-warn  { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 3px 7px rgba(217,119,6,.3); }
    .ewx-chip-green { background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 3px 7px rgba(21,128,61,.3); }
    .ewx-chip-blue  { background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 3px 7px rgba(37,99,235,.3); }
    .ewx-chip-slate { background: linear-gradient(135deg, #64748b, #334155); box-shadow: 0 3px 7px rgba(51,65,85,.3); }
    .ewx-title { display: block; font-weight: 700; color: #1e293b; font-size: 1rem; line-height: 1.25; }
    .ewx-sub   { font-size: .75rem; color: #64748b; }
    .ewx-count {
        background: #e2e8f0; color: #334155; border-radius: 999px;
        font-size: .75rem; font-weight: 700; padding: .12rem .6rem; min-width: 1.8em; text-align: center;
    }
    .ewx-count-warn { background: #fef3c7; color: #92400e; }

    .ewx-table { margin-bottom: 0; font-size: 13px; }
    .ewx-table thead th {
        background: #f8fafc; color: #64748b; font-weight: 700;
        font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
        border-bottom: 1px solid #e9eef5; padding: .65rem .75rem; white-space: nowrap;
    }
    .ewx-table tbody td { padding: .8rem .75rem; border-color: #f1f5f9; vertical-align: middle; }
    .ewx-table tbody tr:last-child td { border-bottom: 0; }
    .ewx-code { font-weight: 700; color: #1e293b; letter-spacing: .01em; text-decoration: none; }
    a.ewx-code:hover { color: #1d4ed8; text-decoration: underline; }
    .ewx-amt  { font-weight: 700; color: #15803d; }
    .ewx-empty { padding: 2.4rem 1rem; text-align: center; color: #94a3b8; }
    .ewx-empty i { font-size: 30px; opacity: .5; display: block; margin-bottom: .5rem; }

    .ewx-btn-approve {
        background: linear-gradient(135deg, #22c55e, #15803d); border: none; color: #fff;
        font-weight: 600; box-shadow: 0 2px 6px rgba(21,128,61,.28);
    }
    .ewx-btn-approve:hover, .ewx-btn-approve:focus { color: #fff; filter: brightness(1.07); }
    .ewx-quote-link { font-weight: 600; text-decoration: none; }

    /* Flow pill — distinguishes the two decommissioning routes at a glance. */
    .ewx-flow {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .72rem; font-weight: 700; padding: .2rem .6rem; border-radius: 999px;
    }
    .ewx-flow-ewaste { background: #dcfce7; color: #166534; }
    .ewx-flow-return { background: #e0f2fe; color: #075985; }

    /* .ewx-section / .ewx-subsection are used throughout the vendor-quotations + decision
       panel (it/decommission/_quotation-comparison.blade.php — the IT cycle page, the Finance/
       management review page, and the collapsed review row below) but never had rules of
       their own, so "Finance's decision" / "Your decision" rendered as bare uppercase text
       flush against the page background on every one of those surfaces. */
    .ewx-subsection {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        padding: .95rem 1.1rem;
    }
    .ewx-section > .ewx-subsection:first-child { margin-top: 0; }

    /* Compact overview strip — replaces three full-height dash-widget cards on the
       "Company Asset Decommissioning" tab, which is a working page, not a dashboard. */
    .ewx-stat-strip { display: flex; flex-wrap: wrap; }
    .ewx-stat-item {
        flex: 1 1 220px; display: flex; align-items: center; gap: .75rem;
        padding: .9rem 1.15rem; border-right: 1px solid #eef2f7;
    }
    .ewx-stat-item:last-child { border-right: 0; }
    .ewx-stat-icon {
        width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0; color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: .95rem;
    }
    .ewx-stat-icon-slate { background: linear-gradient(135deg, #64748b, #334155); }
    .ewx-stat-icon-green { background: linear-gradient(135deg, #22c55e, #15803d); }
    .ewx-stat-icon-blue  { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
    .ewx-stat-number { font-size: 1.15rem; font-weight: 800; color: #1e293b; line-height: 1.1; }
    .ewx-stat-label { font-size: .7rem; color: #64748b; font-weight: 600; }
    @media (max-width: 700px) {
        .ewx-stat-item { border-right: 0; border-bottom: 1px solid #eef2f7; }
        .ewx-stat-item:last-child { border-bottom: 0; }
    }

    /* Collapsed pending-decision rows — a compact summary line with a Review & Decide
       toggle, so the full vendor-comparison + decision form only renders open when asked
       for instead of stacking every pending cycle's whole form on page load. Keep this
       comment free of the literal review-panel heading text: this stylesheet is included
       unconditionally, and tests assert its absence for viewers with nothing to decide. */
    .ewx-review-item { padding: .9rem 1.15rem; }
    .ewx-review-item + .ewx-review-item { border-top: 1px solid #eef2f7; }
    .ewx-review-summary { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .6rem; }
    .ewx-review-main { display: flex; flex-direction: column; gap: .15rem; }
    .ewx-review-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
    .ewx-review-body { background: #f8fafc; padding: 0 1.15rem 1.1rem; }
    .ewx-review-body-head {
        display: flex; align-items: center; justify-content: space-between; gap: .6rem;
        padding: .8rem 0; border-bottom: 1px solid #e9eef5; margin-bottom: 1rem;
    }
    .ewx-chevron { transition: transform .15s ease; }
    .ewx-review-toggle:not(.collapsed) .ewx-chevron { transform: rotate(180deg); }

    /* Reports tab — Year → Month → Company accordion. Same nesting the claim/ticket report
       listings use elsewhere in the app, styled in this module's own gradient/chip language
       rather than imported wholesale, so it reads as part of this page. Company is a plain
       sub-heading rather than a third collapsible level — three nested toggles per row is more
       clicking than the shallow per-company lists this page actually has. */
    .ewx-year-group { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: .85rem; background: #fff; }
    .ewx-year-group:last-child { margin-bottom: 0; }
    .ewx-year-head {
        width: 100%; border: 0; text-align: left; cursor: pointer;
        display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap;
        padding: .9rem 1.15rem; background: linear-gradient(135deg, #1e293b, #334155); color: #fff;
    }
    .ewx-year-head-left { display: flex; align-items: center; gap: .8rem; }
    .ewx-year-title { font-weight: 800; font-size: 1.05rem; letter-spacing: .02em; color: #fff; }
    .ewx-year-sub { font-size: .75rem; color: #cbd5e1; }
    .ewx-year-head-right { display: flex; align-items: center; gap: .85rem; }
    .ewx-year-total { font-weight: 700; }
    .ewx-year-chevron { transition: transform .15s ease; }
    .ewx-year-head[aria-expanded="false"] .ewx-year-chevron { transform: rotate(-90deg); }
    .ewx-year-body { padding: .75rem; background: #f8fafc; }

    .ewx-month-group { border: 1px solid #e9eef5; border-radius: 10px; overflow: hidden; background: #fff; margin-bottom: .6rem; }
    .ewx-month-group:last-child { margin-bottom: 0; }
    .ewx-month-head {
        width: 100%; border: 0; text-align: left; cursor: pointer;
        display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap;
        padding: .7rem 1rem; background: #eef2f7;
    }
    .ewx-month-title { font-weight: 700; color: #1e293b; font-size: .92rem; }
    .ewx-month-sub { font-size: .72rem; color: #64748b; }
    .ewx-month-total { font-weight: 700; color: #1d4ed8; font-size: .85rem; }
    .ewx-month-chevron { transition: transform .15s ease; color: #94a3b8; }
    .ewx-month-head[aria-expanded="false"] .ewx-month-chevron { transform: rotate(-90deg); }
    .ewx-month-body { padding: .6rem .75rem; }

    .ewx-company-group { margin-bottom: .6rem; }
    .ewx-company-group:last-child { margin-bottom: 0; }
    .ewx-company-head {
        display: flex; align-items: center; gap: .4rem; padding: .35rem .25rem;
        font-size: .78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .04em;
    }
    .ewx-company-head i { color: #94a3b8; }

    /* Company as its own collapsible level — used ONLY by the "Cycles by Company" accordion
       (it/assets/_decommission-review-by-company.blade.php, in-flight cycles). The Reports
       tab's Year → Month → Company nesting above deliberately keeps Company as the plain,
       non-clickable .ewx-company-head — this is a separate button wrapping the same label
       styling, for the in-flight working list. */
    .ewx-company-toggle {
        width: 100%; border: 0; background: transparent; cursor: pointer; text-align: left;
        display: flex; justify-content: space-between; align-items: center; gap: .5rem;
        padding: 0; margin-bottom: .35rem;
    }
    .ewx-company-toggle .ewx-company-head { padding: .35rem 0; margin-bottom: 0; }
    .ewx-company-chevron { transition: transform .15s ease; color: #94a3b8; font-size: .8rem; flex-shrink: 0; }
    .ewx-company-toggle[aria-expanded="false"] .ewx-company-chevron { transform: rotate(-90deg); }

    /* Dark mode — the layout ships [data-theme="dark"] overrides; keep these in step. */
    [data-theme="dark"] .ewx-head { background: linear-gradient(135deg, #1e293b, #0f172a); border-bottom-color: #334155; }
    [data-theme="dark"] .ewx-title { color: #f1f5f9; }
    [data-theme="dark"] .ewx-sub { color: #94a3b8; }
    [data-theme="dark"] .ewx-count { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .ewx-table thead th { background: #0f172a; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .ewx-table tbody td { border-color: #334155; }
    [data-theme="dark"] .ewx-code { color: #e2e8f0; }
    [data-theme="dark"] .ewx-flow-ewaste { background: rgba(34,197,94,.18); color: #86efac; }
    [data-theme="dark"] .ewx-flow-return { background: rgba(56,189,248,.18); color: #7dd3fc; }
    [data-theme="dark"] .ewx-stat-item { border-right-color: #334155; }
    [data-theme="dark"] .ewx-stat-item { border-bottom-color: #334155; }
    [data-theme="dark"] .ewx-stat-number { color: #f1f5f9; }
    [data-theme="dark"] .ewx-stat-label { color: #94a3b8; }
    [data-theme="dark"] .ewx-review-item + .ewx-review-item { border-top-color: #334155; }
    [data-theme="dark"] .ewx-review-body { background: #0f172a; }
    [data-theme="dark"] .ewx-review-body-head { border-bottom-color: #334155; }
    {{-- Lighter than .ewx-review-body's dark background, mirroring the light-mode pairing
         (white subsection on a light-slate drawer) so the panel still stands out when the
         drawer is dark too — not the same shade as .ewx-review-item's own dark bg. --}}
    [data-theme="dark"] .ewx-subsection { background: #1e293b; border-color: #334155; }

    [data-theme="dark"] .ewx-year-group { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .ewx-year-body { background: #0f172a; }
    [data-theme="dark"] .ewx-month-group { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .ewx-month-head { background: #0f172a; }
    [data-theme="dark"] .ewx-month-title { color: #e2e8f0; }
    [data-theme="dark"] .ewx-month-sub { color: #94a3b8; }
    [data-theme="dark"] .ewx-company-head { color: #94a3b8; }
    [data-theme="dark"] .ewx-company-chevron { color: #64748b; }
</style>
@endonce
