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
</style>
@endonce
