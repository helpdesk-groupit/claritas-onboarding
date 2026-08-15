{{-- Assets linked to this vendor. `ownership_type` is what decides the meaning of the
     link: rental = we rent it from them, company = we bought it from them. --}}

@php
    // Which of the two asset sections apply. A rental vendor has no "purchased from them"
    // story and a supplier has no "rented from them" one, so showing both put an empty
    // panel on every profile that could never fill.
    //
    // A section is hidden only when it is BOTH irrelevant by type AND empty. Vendor types
    // are editable at any time, so keying purely off the tag would mean untagging a vendor
    // silently hides assets that really are linked to it — on the only page that lists
    // them per vendor. An asset on the "wrong" side is a data problem to see, not to bury.
    $showRented = $vendor->isRental() || $rented->isNotEmpty();
    $showPurchased = $vendor->isAssetSupplier() || $purchased->isNotEmpty();

    // The AARF forms themselves moved to the Report tab on 2026-08-13. What is left of them
    // here is a pointer, and it reads the SAME flag the page used to decide whether the
    // register is rendered — re-deriving the condition would eventually point at something
    // that is not there.
    //
    // That flag is $showAarfRegister, NOT $showReportTab: since the Report tab also carries
    // e-waste collections, an e-waste-only vendor reaches the tab with no AARF register on
    // it, and pointing them at forms that are not there is worse than not pointing at all.
    //
    // The per-asset acknowledgement column went with them when this tab was cut to six
    // columns, so this pointer and its count are now the ONLY thing on the tab that says
    // anything is unsigned. That is why it carries the count rather than just the link.
    $showAarf = $showAarfRegister;
    // Built here rather than glued onto the link: the sentence continues straight after
    // the </a>, and a directive stuck to the end of a word compiles through as literal
    // text (the Blade gotcha in CLAUDE.md).
    $aarfPointerTail = $pendingAssets->isNotEmpty()
        ? ' — '.$pendingAssets->count().' rental asset'.($pendingAssets->count() === 1 ? ' is' : 's are').' awaiting acknowledgement.'
        : '.';

    // Both tables group through the SAME function — the difference between them is which
    // row partial renders and which total the header prints, not how they are organised.
    $rentedGroups = \App\Models\AssetInventory::groupByOriginInvoice($rented);
    $purchasedGroups = \App\Models\AssetInventory::groupByOriginInvoice($purchased);
@endphp

{{-- ── Where the acknowledgement forms went ─────────────────────────────────────
     One line, not the panel that used to be here. The Generate Receipt AARF button moved
     with the forms, so an operator who came to this tab for it would otherwise find no
     trace of either — and a control that vanishes without explanation reads as a feature
     that broke. The pending count comes along because it is the call to action. --}}
@if($showAarf)
<div class="small text-muted mb-3">
    <i class="bi bi-clipboard-check me-1 text-success"></i>
    Acknowledgement forms (AARF) for this vendor are on the
    <a href="{{ route('vendors.show', [$vendor, 'tab' => 'report']) }}" class="fw-semibold text-decoration-none">Report tab</a>{{ $aarfPointerTail }}
</div>
@endif

@if($showRented)
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
        <div>
            <div class="fw-semibold"><i class="bi bi-laptop me-1 text-primary"></i>Assets we rent from them</div>
            <div class="text-muted small">
                Linked on the asset record via Ownership Type &ldquo;Rental / Leased&rdquo; + this vendor,
                and grouped by the invoice they arrived on.
            </div>
        </div>
        @if($summary['monthly_rental'] > 0)
        <div class="text-end">
            <div class="vnd-label">Monthly commitment</div>
            <div class="ewx-amt">RM {{ number_format($summary['monthly_rental'], 2) }}</div>
        </div>
        @endif
    </div>

    @if($rented->isEmpty())
        <div class="ewx-empty"><i class="bi bi-laptop"></i>No rented assets linked to this vendor.</div>
    @else
        @foreach($rentedGroups as $group)
            @include('vendors.partials._asset-invoice-group', ['group' => $group, 'mode' => 'rental'])
        @endforeach
    @endif
</div>
@endif

@if($showPurchased)
<div>
    <div class="fw-semibold mb-2"><i class="bi bi-bag-check me-1 text-warning"></i>Assets we purchased from them</div>
    <div class="text-muted small mb-2">
        Company-owned assets bought from this vendor, grouped by the invoice they were bought on.
    </div>

    @if($purchased->isEmpty())
        <div class="ewx-empty"><i class="bi bi-bag"></i>No purchased assets linked to this vendor.</div>
    @else
        @foreach($purchasedGroups as $group)
            @include('vendors.partials._asset-invoice-group', ['group' => $group, 'mode' => 'purchase'])
        @endforeach
    @endif
</div>
@endif

{{-- Both sections can be hidden at once — an e-waste or repair-only vendor with nothing
     linked either way. Say so, rather than leaving the tab blank as if it had failed.
     The AARF pointer above is deliberately not part of this test: it is a signpost, not
     content, and a tab holding only a link to another tab is still an empty tab. --}}
@if(! $showRented && ! $showPurchased)
<div class="ewx-empty">
    <i class="bi bi-box-seam"></i>
    No assets are linked to this vendor, and they are not registered for asset rental or supply.
</div>
@endif
