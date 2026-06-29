{{--
    Shared month-accordion styling for the claim listings (My Claims + Claim Reports),
    so both pages group claims into the same month → claim card layout. @once-guarded
    so multiple includes on one page don't duplicate the CSS.
--}}
@once
@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .month-group { border: 1px solid #e9eef5; border-radius: 14px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
    .month-head {
        width: 100%; border: 0; text-align: left; cursor: pointer;
        display: flex; justify-content: space-between; align-items: center;
        padding: .8rem 1.1rem;
        background: linear-gradient(135deg, #eef2ff, #f8fafc);
        border-bottom: 1px solid transparent;
    }
    .month-head[aria-expanded="true"] { border-bottom-color: #eef2f7; }
    .month-head-left { display: flex; align-items: center; gap: .8rem; }
    .month-chip {
        width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem;
        box-shadow: 0 3px 7px rgba(37,99,235,.3);
    }
    .month-title { display: block; font-weight: 700; color: #1e293b; font-size: 1rem; line-height: 1.2; }
    .month-sub { font-size: .75rem; color: #64748b; }
    .month-head-right { display: flex; align-items: center; gap: .85rem; }
    .month-total { font-weight: 700; color: #1d4ed8; }
    .month-chevron { color: #94a3b8; transition: transform .2s ease; }
    .month-head[aria-expanded="false"] .month-chevron { transform: rotate(-90deg); }
    .month-body { padding: .45rem; display: flex; flex-direction: column; gap: .4rem; }
    .claim-row {
        display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap;
        text-decoration: none; border: 1px solid #f1f5f9; border-radius: 10px;
        padding: .7rem .9rem; background: #fff; transition: all .15s ease;
    }
    .claim-row:hover { border-color: #c7d7ec; background: #f8fafc; }
    .claim-row .ev-title { font-weight: 600; color: #1e293b; }
    .claim-row .ev-sub { font-size: .78rem; color: #64748b; }
    .claim-row-meta { display: flex; align-items: center; gap: .85rem; }
    .claim-row-meta .ev-amount { font-weight: 700; color: #1d4ed8; }
</style>
@endpush
@endonce
