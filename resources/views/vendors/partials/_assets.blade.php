{{-- Assets linked to this vendor. `ownership_type` is what decides the meaning of the
     link: rental = we rent it from them, company = we bought it from them. --}}

@php
    // Assets with no acknowledgement yet, as an id set — the rented table below flags
    // each row from this rather than re-querying per row.
    $pendingIds = $pendingAssets->pluck('id')->flip();

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
    $showAarf = $pendingAssets->isNotEmpty() || $acknowledgements->isNotEmpty();

    // Both tables group through the SAME function — the difference between them is which
    // row partial renders and which total the header prints, not how they are organised.
    $rentedGroups = \App\Models\AssetInventory::groupByOriginInvoice($rented);
    $purchasedGroups = \App\Models\AssetInventory::groupByOriginInvoice($purchased);
@endphp

{{-- ── AARF — rental asset acknowledgement ──────────────────────────────────
     Only meaningful for vendors we actually rent from. Hidden entirely when there is
     nothing to acknowledge and nothing has ever been acknowledged, so a pure supplier's
     profile does not carry a panel that will never apply to it. --}}
@if($showAarf)
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
        <div>
            <div class="fw-semibold">
                <i class="bi bi-clipboard-check me-1 text-success"></i>Rental Asset Acknowledgement (AARF)
            </div>
            <div class="text-muted small">
                Confirms rental assets physically changed hands, in both directions &mdash; one
                form per company rented to. <strong>Receipts</strong> are raised here when kit
                arrives; <strong>returns</strong> are raised from the IT asset listing&rsquo;s
                Decommissioning tab once the assets are marked Returned, and are archived here
                when signed.
            </div>
        </div>
        @if($canManage && $pendingAssets->isNotEmpty())
        <form action="{{ route('vendors.aarf.generate', $vendor) }}" method="POST" class="js-confirm"
              data-confirm="Generate a RECEIPT AARF for the {{ $pendingAssets->count() }} rental asset{{ $pendingAssets->count() === 1 ? '' : 's' }} not yet acknowledged? One form is created per company rented to. (Returns are raised from the Decommissioning tab instead.)"
              data-confirm-title="Generate receipt AARF"
              data-confirm-ok="Generate"
              data-confirm-variant="success">
            @csrf
            <button type="submit" class="btn btn-success btn-sm fw-semibold">
                <i class="bi bi-file-earmark-plus me-1"></i>Generate Receipt AARF
                <span class="badge bg-white text-success ms-1">{{ $pendingAssets->count() }}</span>
            </button>
        </form>
        @endif
    </div>

    @if($pendingAssets->isNotEmpty())
    <div class="alert alert-warning py-2 px-3 small mb-2">
        <i class="bi bi-exclamation-circle me-1"></i>
        <strong>{{ $pendingAssets->count() }}</strong> rental asset{{ $pendingAssets->count() === 1 ? ' is' : 's are' }}
        awaiting acknowledgement
        @php $pendingCompanies = $pendingAssets->pluck('company_supplied_to')->map(fn ($c) => $c ?: 'Unspecified')->unique()->values(); @endphp
        @if($pendingCompanies->count() > 1)
            across {{ $pendingCompanies->count() }} companies ({{ $pendingCompanies->implode(', ') }}) &mdash;
            generating will create {{ $pendingCompanies->count() }} separate forms.
        @else
            for {{ $pendingCompanies->first() }}.
        @endif
        @if(! $canManage)
            <span class="text-muted">You do not have permission to generate one.</span>
        @endif
    </div>
    @endif

    @if($acknowledgements->isEmpty())
        <div class="ewx-empty"><i class="bi bi-clipboard"></i>No AARF has been generated for this vendor yet.</div>
    @else
    <div class="table-responsive">
        <table class="table table-sm table-hover ewx-table align-middle">
            <thead>
                <tr>
                    <th class="ps-3">Report No.</th>
                    <th>Type</th>
                    <th>Company Rented To</th>
                    <th class="text-center">Assets</th>
                    <th>Acknowledged</th>
                    <th class="text-center">Status</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach($acknowledgements as $aarf)
                @php $badge = $aarf->statusBadge(); @endphp
                <tr>
                    <td class="ps-3">
                        <a href="{{ route('vendors.aarf.show', [$vendor, $aarf]) }}" class="ewx-code text-decoration-none">{{ $aarf->reference }}</a>
                    </td>
                    <td class="small">{{ $aarf->typeLabel() }}</td>
                    <td class="small">{{ $aarf->company_rented_to ?: '—' }}</td>
                    <td class="text-center small">{{ $aarf->items_count ?? $aarf->items->count() }}</td>
                    <td class="small">
                        @if($aarf->isAcknowledged())
                            {{ $aarf->acknowledger?->name ?: '—' }}
                            <div class="vnd-pic-meta">{{ fmt_datetime($aarf->acknowledged_at) }}</div>
                        @else
                            <span class="text-muted">Not yet</span>
                        @endif
                    </td>
                    <td class="text-center">
                        <span class="badge rounded-pill bg-{{ $badge['color'] }}">{{ $badge['label'] }}</span>
                    </td>
                    <td class="text-end pe-3 text-nowrap">
                        <a href="{{ route('vendors.aarf.show', [$vendor, $aarf]) }}" class="btn btn-sm btn-outline-primary" title="Open AARF">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="{{ route('vendors.aarf.pdf', [$vendor, $aarf]) }}" class="btn btn-sm btn-outline-secondary ms-1" title="Download PDF">
                            <i class="bi bi-file-earmark-pdf"></i>
                        </a>
                        @if($canManage && ! $aarf->isAcknowledged())
                        <form action="{{ route('vendors.aarf.destroy', [$vendor, $aarf]) }}" method="POST" class="d-inline js-confirm ms-1"
                              data-confirm="Discard draft AARF {{ $aarf->reference }}? Its assets go back to awaiting acknowledgement."
                              data-confirm-title="Discard draft AARF"
                              data-confirm-ok="Discard"
                              data-confirm-variant="danger">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Discard draft"><i class="bi bi-trash"></i></button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
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
     linked either way. Say so, rather than leaving the tab blank as if it had failed. --}}
@if(! $showAarf && ! $showRented && ! $showPurchased)
<div class="ewx-empty">
    <i class="bi bi-box-seam"></i>
    No assets are linked to this vendor, and they are not registered for asset rental or supply.
</div>
@endif
