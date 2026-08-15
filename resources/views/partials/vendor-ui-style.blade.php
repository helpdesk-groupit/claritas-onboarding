@once
{{-- Vendor Management look, shared by the directory, the registration form and the vendor
     profile. `vnd-` prefixed and @once-guarded so multiple includes on one page are free.
     Sits on top of partials/decommission-ui-style (ewx-*), which supplies the card/table
     chrome; keep new vendor styling here rather than inline per page. --}}
<style>
    /* ── KPI tiles ─────────────────────────────────────────────────────────────
       The vendor pages use `dash-widget` tiles with NO white `.widget-body` under the
       gradient — but the shared dashboard styles were written assuming one, so the
       header is sized to its own content. In an equal-height row (`h-100`) the CARD
       stretches to match the tallest sibling while the gradient does not, which paints
       a white band under every tile whose label happens to wrap. It only shows up once
       the column is narrow enough to wrap a label — i.e. on a resize — which is exactly
       when it looks broken.

       Fixed here rather than in partials/dashboard-widgets-style: that file is shared
       with the accounting dashboards, where the white body below the header is the
       intended design and stretching the gradient would cover it. */
    .vnd-kpi { min-height: 0; height: 100%; display: flex; flex-direction: column; }
    .vnd-kpi .widget-header {
        flex: 1 1 auto; display: flex; flex-direction: column; justify-content: center;
    }
    /* "RM 198" broke across two lines once the sidebar squeezed the column. */
    .vnd-kpi .widget-number { white-space: nowrap; }
    .vnd-kpi .widget-label { line-height: 1.25; }

    /* Below this the four tiles share the row with an expanded sidebar and the label
       wraps; scale the tile down rather than let it grow a third line. */
    @media (max-width: 1500px) {
        .vnd-kpi .widget-header { padding: 16px 16px 14px; }
        .vnd-kpi .widget-number { font-size: 26px; }
        .vnd-kpi .widget-label { font-size: 11.5px; }
        .vnd-kpi .widget-icon { width: 42px; height: 42px; border-radius: 12px; }
        .vnd-kpi .widget-icon i { font-size: 19px; }
    }
    @media (max-width: 575.98px) {
        .vnd-kpi .widget-header { padding: 14px 14px 12px; }
        .vnd-kpi .widget-number { font-size: 22px; }
        .vnd-kpi .widget-icon { width: 36px; height: 36px; border-radius: 10px; }
        .vnd-kpi .widget-icon i { font-size: 16px; }
    }

    .vnd-hero {
        border: none; border-radius: 16px; overflow: hidden; position: relative;
        background: linear-gradient(135deg, #0f172a, #1e3a5f);
    }
    /* Decorative only. It is absolutely positioned and .card-body is not, so it paints ON TOP
       of the hero's content and its background swallows clicks — which killed the top-right
       action button on both the directory and the vendor profile. pointer-events:none lets
       the clicks through; z-index keeps it behind the text as well. */
    .vnd-hero::before {
        content: ''; position: absolute; top: -50px; right: -40px;
        width: 180px; height: 180px; border-radius: 50%; background: rgba(255,255,255,.05);
        pointer-events: none; z-index: 0;
    }
    .vnd-hero > .card-body { position: relative; z-index: 1; }
    .vnd-hero-icon {
        width: 46px; height: 46px; border-radius: 13px; flex-shrink: 0;
        background: rgba(255,255,255,.16); color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.3rem;
    }
    .vnd-avatar {
        width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
        background: linear-gradient(135deg, #64748b, #334155); color: #fff;
        display: inline-flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: .78rem; letter-spacing: .02em;
    }
    .vnd-avatar-lg { width: 56px; height: 56px; border-radius: 16px; font-size: 1.1rem; }

    /* Type chips. The three decommissioning types keep their own colours because the
       e-waste/rental flows are what most readers are scanning for; everything else
       shares one neutral chip so a 13-entry palette never has to be invented. */
    .vnd-type {
        font-size: .68rem; font-weight: 700; padding: .18rem .55rem; border-radius: 999px;
        display: inline-flex; align-items: center; gap: .3rem; margin-right: .25rem;
        background: #f1f5f9; color: #475569; white-space: nowrap;
    }
    /* SST group labels are full sentences ("Group G — Professional services (…)"), so they
       need the chip WITHOUT its nowrap — one that runs off the column says less than none. */
    .vnd-type-wrap {
        white-space: normal; display: inline-block; line-height: 1.35;
        margin-bottom: .25rem; text-align: left;
    }
    .vnd-type-rental,
    .vnd-type-leasing { background: #e0f2fe; color: #075985; }
    .vnd-type-repair { background: #ede9fe; color: #5b21b6; }
    .vnd-type-ewaste { background: #dcfce7; color: #166534; }
    .vnd-type-purchase { background: #fef3c7; color: #92400e; }

    .vnd-pic { font-weight: 600; color: #1e293b; line-height: 1.2; }
    .vnd-pic-meta { font-size: .72rem; color: #64748b; }
    .vnd-pic-meta a { color: inherit; text-decoration: none; }
    .vnd-pic-meta a:hover { color: #1d4ed8; text-decoration: underline; }
    .vnd-row-off { opacity: .55; }

    /* ── Assets grouped by the invoice they arrived on ──────────────────────────
       The header carries the document; the table under it carries the assets. The
       group is drawn as one block so it reads as "these assets came in together"
       rather than as a table with an unusually loud row in it. */
    .vnd-invgroup {
        border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #fff;
    }
    .vnd-invgroup-head {
        padding: .6rem .9rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
    }
    .vnd-invgroup .table { margin-bottom: 0; }

    [data-theme="dark"] .vnd-invgroup { background: transparent; border-color: #334155; }
    [data-theme="dark"] .vnd-invgroup-head { background: rgba(148,163,184,.08); border-bottom-color: #334155; }

    /* Uppercase micro-label + value, the profile page's field pattern. */
    .vnd-label {
        font-size: .66rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
        color: #94a3b8; margin-bottom: .15rem;
    }
    .vnd-value { font-size: .88rem; color: #1e293b; font-weight: 500; word-break: break-word; }
    .vnd-value-muted { color: #94a3b8; font-weight: 400; }

    /* SST verdict banner — the one place the B2B exemption is stated in words. */
    .vnd-sst {
        border-radius: 12px; padding: .7rem .9rem; font-size: .8rem;
        display: flex; align-items: flex-start; gap: .6rem; border: 1px solid transparent;
    }
    .vnd-sst-exempt, .vnd-sst-not_registered { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    .vnd-sst-chargeable { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
    .vnd-sst-unknown { background: #f8fafc; border-color: #e2e8f0; color: #475569; }

    .vnd-tabs .nav-link {
        font-size: .82rem; font-weight: 600; color: #64748b; border: none;
        border-bottom: 2px solid transparent; padding: .6rem .95rem;
    }
    .vnd-tabs .nav-link.active { color: #1d4ed8; border-bottom-color: #1d4ed8; background: transparent; }
    .vnd-tabs .nav-link .badge { font-size: .62rem; }

    .vnd-doc-name { font-weight: 600; font-size: .85rem; color: #1e293b; }
    /* The AI reading's status, inline on a document row (_ai-chip). Was .vnd-ocr-chip until
       the per-field OCR was removed on 2026-08-11 — the name outlived what it described. */
    .vnd-ai-chip {
        font-size: .62rem; font-weight: 700; padding: .12rem .45rem; border-radius: 6px;
        background: #eef2ff; color: #4338ca; text-transform: uppercase; letter-spacing: .04em;
    }
    .vnd-ai-chip-warn { background: #fef3c7; color: #92400e; }

    [data-theme="dark"] .vnd-pic,
    [data-theme="dark"] .vnd-value,
    [data-theme="dark"] .vnd-doc-name { color: #e2e8f0; }
    [data-theme="dark"] .vnd-pic-meta { color: #94a3b8; }
    [data-theme="dark"] .vnd-label { color: #64748b; }
    [data-theme="dark"] .vnd-value-muted { color: #64748b; }
    [data-theme="dark"] .vnd-type { background: rgba(148,163,184,.16); color: #cbd5e1; }
    [data-theme="dark"] .vnd-type-rental,
    [data-theme="dark"] .vnd-type-leasing { background: rgba(56,189,248,.18); color: #7dd3fc; }
    [data-theme="dark"] .vnd-type-repair { background: rgba(139,92,246,.18); color: #c4b5fd; }
    [data-theme="dark"] .vnd-type-ewaste { background: rgba(34,197,94,.18); color: #86efac; }
    [data-theme="dark"] .vnd-type-purchase { background: rgba(251,191,36,.18); color: #fcd34d; }
    [data-theme="dark"] .vnd-sst-exempt,
    [data-theme="dark"] .vnd-sst-not_registered { background: rgba(16,185,129,.12); border-color: rgba(16,185,129,.3); color: #6ee7b7; }
    [data-theme="dark"] .vnd-sst-chargeable { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.3); color: #93c5fd; }
    [data-theme="dark"] .vnd-sst-unknown { background: rgba(148,163,184,.1); border-color: rgba(148,163,184,.25); color: #cbd5e1; }
    [data-theme="dark"] .vnd-tabs .nav-link { color: #94a3b8; }
    [data-theme="dark"] .vnd-tabs .nav-link.active { color: #93c5fd; border-bottom-color: #93c5fd; }
    [data-theme="dark"] .vnd-ai-chip { background: rgba(99,102,241,.18); color: #a5b4fc; }
    [data-theme="dark"] .vnd-ai-chip-warn { background: rgba(251,191,36,.18); color: #fcd34d; }

    /* ── AI reading of a document ───────────────────────────────────────────────
       The summary sits in a full-width row under its document, collapsed behind a
       toggle. Visually quieter than the record itself on purpose: it is a reading
       OF the document, never a substitute for it. */
    .vnd-ai-chipline { display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
    .vnd-ai-chipnote { font-size: .7rem; color: #64748b; }
    .vnd-ai-toggle {
        border: none; background: transparent; padding: 0; font-size: .7rem;
        font-weight: 700; color: #1d4ed8; cursor: pointer;
    }
    .vnd-ai-toggle:hover { color: #1e40af; text-decoration: underline; }
    .vnd-ai-toggle[aria-expanded="true"] .bi-chevron-down { transform: rotate(180deg); }
    .vnd-ai-toggle .bi-chevron-down { display: inline-block; transition: transform .15s ease; }

    .vnd-ai-row > td { background: transparent; }
    .vnd-ai-panel {
        margin: 0 .75rem .6rem; padding: .7rem .9rem; border-radius: 10px;
        background: #f8fafc; border: 1px solid #e2e8f0; font-size: .78rem; color: #334155;
    }
    .vnd-ai-panel-warn { background: #fffbeb; border-color: #fde68a; }
    .vnd-ai-note { font-size: .72rem; font-weight: 600; color: #92400e; margin-bottom: .4rem; }
    .vnd-ai-md p { margin-bottom: .45rem; }
    .vnd-ai-md ul, .vnd-ai-md ol { margin-bottom: .45rem; padding-left: 1.1rem; }
    .vnd-ai-md :last-child { margin-bottom: 0; }
    .vnd-ai-points { margin: .5rem 0 0; padding-left: 1.1rem; font-size: .74rem; }
    .vnd-ai-points li { margin-bottom: .2rem; }
    .vnd-ai-foot {
        margin-top: .6rem; padding-top: .5rem; border-top: 1px dashed #e2e8f0;
        font-size: .68rem; color: #94a3b8;
        display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap;
    }
    .vnd-ai-actions { display: inline-flex; align-items: center; gap: .75rem; }
    .vnd-ai-ask {
        border: none; background: transparent; padding: 0; font-size: .7rem;
        font-weight: 700; color: #1d4ed8; text-decoration: none; cursor: pointer;
    }
    .vnd-ai-ask:hover { color: #1e40af; text-decoration: underline; }

    /* ── The Summary column ─────────────────────────────────────────────────────
       The summary is the row now, so it gets real weight in the cell — but clamped:
       a 200-word summary rendered in full makes every row a screenful and the table
       unreadable. The rest is one click away in the panel below (.vnd-ai-panel), so
       the clamp hides nothing that cannot be reached. */
    .vnd-sum-cell {
        margin-top: .3rem; font-size: .76rem; line-height: 1.45; color: #475569;
        max-width: 560px;
        display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .vnd-sum-foot {
        display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
        margin-top: .25rem; font-size: .68rem; color: #94a3b8;
    }
    .vnd-sum-prov { display: inline-flex; align-items: center; }
    .vnd-sum-partial { color: #b45309; font-weight: 600; }

    /* One party per line: these are legal entity names, and running them together on
       one wrapped line makes two companies read as one. */
    .vnd-party {
        font-size: .78rem; font-weight: 600; color: #1e293b; line-height: 1.35;
        max-width: 260px;
    }
    .vnd-party + .vnd-party { margin-top: .15rem; }

    /* ── Scan state inside the Add/Edit document modal ──────────────────────── */
    .vnd-scan-state {
        display: flex; align-items: center; gap: .1rem;
        margin-bottom: .9rem; padding: .55rem .75rem; border-radius: 8px;
        background: #eff6ff; border: 1px solid #bfdbfe;
        font-size: .76rem; color: #1e40af;
    }
    .vnd-scan-state.vnd-scan-ok { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .vnd-scan-state.vnd-scan-warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
    .vnd-scan-points {
        margin-top: .2rem; padding: .6rem .8rem; border-radius: 8px;
        background: #f8fafc; border: 1px solid #e2e8f0; font-size: .74rem; color: #334155;
    }
    .vnd-scan-points-head {
        font-size: .65rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .04em; color: #64748b; margin-bottom: .35rem;
    }
    .vnd-scan-points ul { padding-left: 1.1rem; }
    .vnd-scan-points li { margin-bottom: .2rem; }

    /* ── Ask AI: the floating button ────────────────────────────────────────── */
    /* Below Bootstrap's offcanvas (1045) and its backdrop (1040) on purpose: while the
       panel is open the button dims behind it rather than floating over its own panel. */
    .vnd-fab {
        position: fixed; right: 24px; bottom: 24px; z-index: 1035;
        display: inline-flex; align-items: center; justify-content: center;
        height: 54px; min-width: 54px; padding: 0 15px;
        border: none; border-radius: 999px; color: #fff;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        box-shadow: 0 10px 24px rgba(29, 78, 216, .38);
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .vnd-fab:hover, .vnd-fab:focus-visible {
        color: #fff; transform: translateY(-2px); box-shadow: 0 14px 30px rgba(29, 78, 216, .45);
    }
    .vnd-fab .bi { font-size: 1.35rem; line-height: 1; }
    /* The label is what stops a bare robot icon being a guess. Revealed on hover AND on
       keyboard focus — CSS only, so it cannot break under CSP. */
    .vnd-fab-text {
        max-width: 0; opacity: 0; overflow: hidden; white-space: nowrap;
        font-size: .84rem; font-weight: 700;
        transition: max-width .18s ease, opacity .18s ease, margin-left .18s ease;
    }
    .vnd-fab:hover .vnd-fab-text, .vnd-fab:focus-visible .vnd-fab-text {
        max-width: 8rem; opacity: 1; margin-left: .5rem;
    }
    .vnd-fab-badge {
        position: absolute; top: -3px; right: -3px; min-width: 21px; height: 21px;
        display: inline-flex; align-items: center; justify-content: center; padding: 0 5px;
        border-radius: 999px; border: 2px solid #fff; background: #f59e0b; color: #7c2d12;
        font-size: .64rem; font-weight: 800; line-height: 1;
    }

    /* ── Ask AI: the panel ──────────────────────────────────────────────────── */
    /* A conversation, so the thread takes whatever room is left and the ask box stays
       put. The body keeps its own overflow as a fallback for a very short viewport. */
    .vnd-ask-panel { width: 460px; max-width: 100%; }
    .vnd-ask-panel .offcanvas-header { border-bottom: 1px solid #e2e8f0; }
    .vnd-ask-panel-sub { font-size: .72rem; color: #94a3b8; }
    .vnd-ask-panel .offcanvas-body { display: flex; flex-direction: column; }
    .vnd-ask-panel .vnd-ask-thread { flex: 1 1 auto; max-height: none; min-height: 200px; }
    .vnd-ask-panel .vnd-ask-form { flex: 0 0 auto; }

    /* This is the app's first offcanvas, so nothing in the layout dark-themes it. */
    [data-theme="dark"] .vnd-ask-panel { background: #1e293b; color: #e2e8f0; }
    [data-theme="dark"] .vnd-ask-panel .offcanvas-header { border-bottom-color: #334155; }
    [data-theme="dark"] .vnd-ask-panel .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
    [data-theme="dark"] .vnd-fab-badge { border-color: #0f172a; }

    /* ── Ask AI panel internals ─────────────────────────────────────────────── */
    /* One row: what the assistant is reading, and the only action that is not a question. */
    .vnd-ask-toolbar { display: flex; align-items: stretch; gap: .5rem; margin-bottom: .75rem; }
    .vnd-ask-scopebar {
        flex: 1 1 auto; display: flex; align-items: center; gap: .45rem;
        padding: .4rem .7rem; border-radius: 10px; text-align: left;
        background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; font-size: .74rem;
    }
    .vnd-ask-scopebar:hover { border-color: #93c5fd; color: #1e40af; }
    .vnd-ask-scopebar strong { color: #1e40af; }
    /* The scope is a sentence naming the document, not a count, so it is the one part of
       this bar that can be long. It takes the free space and ellipses rather than wrapping
       the chevron onto a second line — the full label is a click away on the chip below. */
    .vnd-ask-subject {
        flex: 1 1 auto; min-width: 0;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .vnd-ask-scopebar-warn {
        font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
        background: #fef3c7; color: #92400e; padding: .1rem .4rem; border-radius: 5px;
    }
    .vnd-ask-chev { margin-left: auto; display: inline-block; transition: transform .15s ease; }
    .vnd-ask-scopebar[aria-expanded="true"] .vnd-ask-chev { transform: rotate(180deg); }
    .vnd-ask-tool {
        padding: .4rem .7rem; border-radius: 10px; font-size: .72rem; font-weight: 700;
        background: #fff; border: 1px solid #cbd5e1; color: #475569; white-space: nowrap;
    }
    .vnd-ask-tool:hover { border-color: #93c5fd; color: #1e40af; }

    /* Each blocked document sits beside its reason and, for someone who can act on it, the
       button that reads it — the row's own Re-summarise is behind this panel's backdrop. */
    .vnd-ask-blocked-sep { border-top: 1px dashed #e2e8f0; }
    .vnd-ask-blocked-row {
        display: flex; align-items: flex-start; justify-content: space-between; gap: .6rem;
        padding: .35rem 0; font-size: .74rem; color: #334155;
    }
    .vnd-ask-blocked-row + .vnd-ask-blocked-row { border-top: 1px solid #f1f5f9; }
    .vnd-ask-blocked-why { font-size: .7rem; color: #92400e; }
    .vnd-ask-read {
        flex: 0 0 auto; padding: .22rem .55rem; border-radius: 7px; font-size: .68rem; font-weight: 700;
        background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; white-space: nowrap;
    }
    .vnd-ask-read:hover { background: #dbeafe; border-color: #93c5fd; }

    .vnd-ask-scope {
        padding: .75rem .9rem; border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0;
    }
    .vnd-ask-chip {
        display: inline-flex; align-items: center; gap: .4rem; cursor: pointer;
        font-size: .73rem; padding: .3rem .65rem; border-radius: 999px;
        background: #fff; border: 1px solid #cbd5e1; color: #334155;
    }
    .vnd-ask-chip:has(input:checked) { background: #eff6ff; border-color: #93c5fd; color: #1e40af; }
    .vnd-ask-chip input { margin: 0; }
    .vnd-ask-partial {
        font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
        background: #fef3c7; color: #92400e; padding: .05rem .35rem; border-radius: 5px;
    }
    .vnd-ask-scope-actions { display: flex; gap: .9rem; }
    /* Never hidden: a document you can see on the page but cannot ask about has to say
       why, or an answer that never read it looks like an answer that did. */
    .vnd-ask-blocked { font-size: .72rem; }

    .vnd-ask-thread {
        max-height: 520px; overflow-y: auto; padding: .25rem;
        display: flex; flex-direction: column; gap: .75rem;
    }
    .vnd-ask-msg { border-radius: 12px; padding: .65rem .85rem; font-size: .82rem; }
    .vnd-ask-user { background: #eff6ff; border: 1px solid #dbeafe; color: #1e293b; align-self: flex-end; max-width: 78%; }
    .vnd-ask-assistant { background: #f8fafc; border: 1px solid #e2e8f0; color: #1e293b; }
    .vnd-ask-failed { background: #fff1f2; border-color: #fecdd3; color: #9f1239; }
    .vnd-ask-who {
        font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
        color: #64748b; margin-bottom: .3rem;
    }
    .vnd-ask-when { font-weight: 400; text-transform: none; letter-spacing: 0; margin-left: .5rem; opacity: .8; }
    .vnd-ask-body p { margin-bottom: .45rem; }
    .vnd-ask-body ul, .vnd-ask-body ol { margin-bottom: .45rem; padding-left: 1.1rem; }
    .vnd-ask-body :last-child { margin-bottom: 0; }
    .vnd-ask-body blockquote {
        margin: .4rem 0; padding: .25rem .7rem; border-left: 3px solid #cbd5e1; color: #475569;
    }
    .vnd-ask-body code { font-size: .78rem; }
    .vnd-ask-cites { margin-top: .5rem; font-size: .68rem; color: #64748b; }
    .vnd-ask-cites-warn { color: #92400e; }
    .vnd-ask-divider {
        display: flex; align-items: center; gap: .6rem; color: #94a3b8; font-size: .66rem;
        text-transform: uppercase; letter-spacing: .06em; font-weight: 700;
    }
    .vnd-ask-divider::before, .vnd-ask-divider::after {
        content: ''; flex: 1; height: 1px; background: #e2e8f0;
    }
    .vnd-ask-empty { text-align: center; padding: 1.5rem .75rem; color: #94a3b8; font-size: .82rem; }
    .vnd-ask-empty .bi { font-size: 1.6rem; display: block; margin-bottom: .4rem; }
    .vnd-ask-empty-sub { font-size: .73rem; margin-top: .3rem; }
    .vnd-ask-suggest { display: flex; flex-direction: column; align-items: center; gap: .4rem; margin-top: .8rem; }
    .vnd-ask-seed {
        border: 1px solid #cbd5e1; background: #fff; border-radius: 999px;
        padding: .3rem .8rem; font-size: .73rem; color: #334155; cursor: pointer; max-width: 100%;
    }
    .vnd-ask-seed:hover { border-color: #93c5fd; color: #1e40af; }
    .vnd-ask-hint { font-size: .7rem; color: #94a3b8; }

    [data-theme="dark"] .vnd-ai-panel { background: rgba(148,163,184,.08); border-color: rgba(148,163,184,.2); color: #cbd5e1; }
    [data-theme="dark"] .vnd-ai-panel-warn { background: rgba(251,191,36,.1); border-color: rgba(251,191,36,.28); }
    [data-theme="dark"] .vnd-ai-note { color: #fcd34d; }
    [data-theme="dark"] .vnd-ai-foot { border-top-color: rgba(148,163,184,.2); color: #64748b; }
    [data-theme="dark"] .vnd-ai-chipnote { color: #94a3b8; }
    [data-theme="dark"] .vnd-ai-toggle,
    [data-theme="dark"] .vnd-ai-ask { color: #93c5fd; }
    [data-theme="dark"] .vnd-ask-scopebar,
    [data-theme="dark"] .vnd-ask-tool { background: rgba(15,23,42,.5); border-color: rgba(148,163,184,.3); color: #cbd5e1; }
    [data-theme="dark"] .vnd-ask-scopebar strong { color: #93c5fd; }
    [data-theme="dark"] .vnd-ask-scopebar-warn { background: rgba(251,191,36,.18); color: #fcd34d; }
    [data-theme="dark"] .vnd-ask-blocked-sep { border-top-color: rgba(148,163,184,.2); }
    [data-theme="dark"] .vnd-ask-blocked-row { color: #cbd5e1; }
    [data-theme="dark"] .vnd-ask-blocked-row + .vnd-ask-blocked-row { border-top-color: rgba(148,163,184,.14); }
    [data-theme="dark"] .vnd-ask-blocked-why { color: #fcd34d; }
    [data-theme="dark"] .vnd-ask-read { background: rgba(59,130,246,.18); border-color: rgba(147,197,253,.4); color: #93c5fd; }
    [data-theme="dark"] .vnd-ask-scope { background: rgba(148,163,184,.08); border-color: rgba(148,163,184,.2); }
    [data-theme="dark"] .vnd-ask-chip { background: rgba(15,23,42,.5); border-color: rgba(148,163,184,.3); color: #cbd5e1; }
    [data-theme="dark"] .vnd-ask-chip:has(input:checked) { background: rgba(59,130,246,.18); border-color: rgba(147,197,253,.5); color: #93c5fd; }
    [data-theme="dark"] .vnd-ask-partial { background: rgba(251,191,36,.18); color: #fcd34d; }
    [data-theme="dark"] .vnd-ask-blocked { color: #fcd34d; }
    [data-theme="dark"] .vnd-ask-user { background: rgba(59,130,246,.16); border-color: rgba(59,130,246,.3); color: #e2e8f0; }
    [data-theme="dark"] .vnd-ask-assistant { background: rgba(148,163,184,.1); border-color: rgba(148,163,184,.2); color: #e2e8f0; }
    [data-theme="dark"] .vnd-ask-failed { background: rgba(244,63,94,.14); border-color: rgba(244,63,94,.32); color: #fda4af; }
    [data-theme="dark"] .vnd-ask-divider::before,
    [data-theme="dark"] .vnd-ask-divider::after { background: rgba(148,163,184,.2); }
    [data-theme="dark"] .vnd-ask-seed { background: rgba(15,23,42,.5); border-color: rgba(148,163,184,.3); color: #cbd5e1; }
    [data-theme="dark"] .vnd-sum-cell { color: #cbd5e1; }
    [data-theme="dark"] .vnd-sum-foot { color: #64748b; }
    [data-theme="dark"] .vnd-sum-partial { color: #fcd34d; }
    [data-theme="dark"] .vnd-party { color: #e2e8f0; }
    [data-theme="dark"] .vnd-scan-state { background: rgba(59,130,246,.16); border-color: rgba(59,130,246,.32); color: #93c5fd; }
    [data-theme="dark"] .vnd-scan-state.vnd-scan-ok { background: rgba(34,197,94,.14); border-color: rgba(34,197,94,.32); color: #86efac; }
    [data-theme="dark"] .vnd-scan-state.vnd-scan-warn { background: rgba(251,191,36,.14); border-color: rgba(251,191,36,.32); color: #fcd34d; }
    [data-theme="dark"] .vnd-scan-points { background: rgba(148,163,184,.08); border-color: rgba(148,163,184,.2); color: #cbd5e1; }
    [data-theme="dark"] .vnd-scan-points-head { color: #94a3b8; }

    /* ── Bulk import preview ────────────────────────────────────────────────────
       This page is the one screen in the module where NOTHING has happened yet, and
       every element on it exists to say so. The chrome is therefore explanatory rather
       than decorative: a trail showing where the operator stands in a three-step flow,
       a plain sentence naming the outcome, and warnings that cannot be mistaken for the
       grey data dump they used to sit inside. */
    .vnd-imp-trail {
        display: flex; align-items: center; gap: .4rem; flex-wrap: wrap;
        font-size: .76rem; font-weight: 600; color: #64748b;
    }
    .vnd-imp-trail-step {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .3rem .7rem; border-radius: 999px;
        background: #f1f5f9; border: 1px solid #e2e8f0;
    }
    .vnd-imp-trail-step .vnd-imp-trail-no {
        display: inline-flex; align-items: center; justify-content: center;
        width: 18px; height: 18px; border-radius: 50%;
        background: #cbd5e1; color: #fff; font-size: .62rem; font-weight: 700;
    }
    .vnd-imp-trail-done { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
    .vnd-imp-trail-done .vnd-imp-trail-no { background: #10b981; }
    .vnd-imp-trail-now { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
    .vnd-imp-trail-now .vnd-imp-trail-no { background: #2563eb; }
    .vnd-imp-trail-sep { color: #cbd5e1; }

    /* The headline sentence. Deliberately prose, not a metric: the tiles beside it already
       carry the numbers, and a number nobody can turn into a sentence is what made this
       page read as a dashboard rather than a confirmation. */
    .vnd-imp-outcome {
        border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 12px;
        padding: .75rem 1rem; font-size: .86rem; color: #1e3a5f;
    }
    .vnd-imp-outcome strong { color: #1d4ed8; }

    .vnd-kpi-note {
        font-size: 10.5px; line-height: 1.3; margin-top: 2px;
        color: rgba(255,255,255,.78); font-weight: 500;
    }

    /* Everything the importer could not read, gathered above the tables. Without it the
       warnings are only findable by scrolling every row, which on a 200-row sheet means
       they are not findable at all. */
    .vnd-imp-attention { border: 1px solid #fde68a; background: #fffbeb; border-radius: 12px; }
    .vnd-imp-attention-head {
        display: flex; align-items: center; gap: .5rem;
        padding: .7rem 1rem; border-bottom: 1px solid #fde68a;
        font-size: .82rem; font-weight: 700; color: #92400e;
    }
    .vnd-imp-attention-list { margin: 0; padding: .5rem 1rem .7rem; list-style: none; }
    .vnd-imp-attention-list li { font-size: .78rem; color: #78350f; padding: .18rem 0; }
    .vnd-imp-attention-list a { color: #92400e; font-weight: 700; text-decoration: none; }
    .vnd-imp-attention-list a:hover { text-decoration: underline; }

    /* A warning under its row. Amber and iconed so it never reads as part of the muted
       "everything else read from this row" line it used to be printed beside. */
    .vnd-imp-note {
        display: flex; align-items: flex-start; gap: .35rem;
        font-size: .74rem; color: #92400e; padding: .1rem 0;
    }
    .vnd-imp-note-bad { color: #b91c1c; }
    .vnd-imp-more-btn {
        background: none; border: 0; padding: .1rem 0;
        font-size: .72rem; font-weight: 600; color: #64748b; text-decoration: underline;
    }
    .vnd-imp-more-btn:hover { color: #1d4ed8; }
    /* The rest of what a row holds, laid out as FIELDS rather than a run of text (2026-08-15).
       Deliberately the profile's own `.vnd-label` / `.vnd-value` pattern: this panel is a
       preview of the record about to be created, so it should read like the page it is
       about to become. As one `·`-joined sentence the labels and the values were the same
       size, weight and colour, which left nothing to scan — a reader could not answer "what
       is the bank account number" without reading the whole line. */
    .vnd-imp-fields {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr));
        gap: .65rem 1.4rem; margin-top: .4rem; padding: .8rem 1rem;
        background: #f8fafc; border: 1px solid #e8edf3; border-radius: 10px;
    }
    .vnd-imp-fields .vnd-value { font-size: .8rem; font-weight: 500; white-space: pre-line; }
    /* Long values get the full width instead of a 215px column, where an address would wrap
       to five lines beside four mostly-empty neighbours. */
    .vnd-imp-field-wide { grid-column: 1 / -1; }
    .vnd-imp-arrow { color: #94a3b8; font-size: .9rem; }
    /* The duplicate-handling choice, when this file contains no duplicate for it to act on.
       Kept on screen and still submitted — it is part of the form — but visibly inert, so it
       stops reading as a decision the operator has to make. */
    .vnd-imp-inert { opacity: .55; }

    [data-theme="dark"] .vnd-imp-trail { color: #94a3b8; }
    [data-theme="dark"] .vnd-imp-trail-step { background: rgba(148,163,184,.1); border-color: rgba(148,163,184,.22); }
    [data-theme="dark"] .vnd-imp-trail-step .vnd-imp-trail-no { background: #475569; }
    [data-theme="dark"] .vnd-imp-trail-done { background: rgba(16,185,129,.14); border-color: rgba(16,185,129,.34); color: #6ee7b7; }
    [data-theme="dark"] .vnd-imp-trail-done .vnd-imp-trail-no { background: #059669; }
    [data-theme="dark"] .vnd-imp-trail-now { background: rgba(59,130,246,.16); border-color: rgba(59,130,246,.4); color: #93c5fd; }
    [data-theme="dark"] .vnd-imp-trail-now .vnd-imp-trail-no { background: #2563eb; }
    [data-theme="dark"] .vnd-imp-trail-sep { color: #475569; }
    [data-theme="dark"] .vnd-imp-outcome { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.3); color: #dbeafe; }
    [data-theme="dark"] .vnd-imp-outcome strong { color: #93c5fd; }
    [data-theme="dark"] .vnd-imp-attention { background: rgba(251,191,36,.1); border-color: rgba(251,191,36,.3); }
    [data-theme="dark"] .vnd-imp-attention-head { color: #fcd34d; border-bottom-color: rgba(251,191,36,.3); }
    [data-theme="dark"] .vnd-imp-attention-list li { color: #fde68a; }
    [data-theme="dark"] .vnd-imp-attention-list a { color: #fcd34d; }
    [data-theme="dark"] .vnd-imp-note { color: #fcd34d; }
    [data-theme="dark"] .vnd-imp-note-bad { color: #fda4af; }
    [data-theme="dark"] .vnd-imp-more-btn { color: #94a3b8; }
    [data-theme="dark"] .vnd-imp-more-btn:hover { color: #93c5fd; }
    [data-theme="dark"] .vnd-imp-fields { background: rgba(148,163,184,.07); border-color: rgba(148,163,184,.18); }
</style>
@endonce
