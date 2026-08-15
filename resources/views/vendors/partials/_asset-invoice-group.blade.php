{{-- One invoice group on the vendor's Assets tab: the header that says WHICH invoice, and
     the assets that arrived on it.

     Driven entirely by the shape AssetInventory::groupByOriginInvoice() returns, so the
     rental and purchased tables share this file and a fourth kind of group later is a new
     `state` here rather than another table somewhere else.

     The header names the invoice and NOTHING ELSE. This tab deliberately carries no tie to
     the billing register any more (2026-08-13): no "Open in Billing", no document link, no
     Register button, and the Billing tab no longer links back. The invoice each asset
     arrived on is recorded on the asset itself, so that is what is printed — a registered
     document and a typed reference read the same here because the statement being made
     ("these assets arrived on invoice X") is equally true either way, and there is no longer
     an action on this page for which the difference would matter.

     Expects:
       $group  one entry from groupByOriginInvoice()
       $mode   'rental' | 'purchase' — which row partial and which total apply
     It no longer needs $vendor or $canManage: with the links and the Register button gone
     there is nothing here to route or to gate. --}}
@php
    $isRental = $mode === 'rental';
@endphp

<div class="vnd-invgroup mb-3" id="{{ $group['anchor'] }}">
    <div class="vnd-invgroup-head d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div class="min-w-0">
            @if($group['state'] === 'none')
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="vnd-type">No invoice recorded</span>
                </div>
                <div class="vnd-pic-meta mt-1">
                    Neither an invoice nor a reference is recorded on
                    {{ $group['count'] === 1 ? 'this asset' : 'these assets' }}.
                </div>
            @else
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="vnd-type vnd-type-purchase">Invoice</span>
                    <span class="ewx-code">{{ $group['reference'] ?: 'No number' }}</span>
                </div>
            @endif
        </div>

        <div class="text-end text-nowrap">
            <div class="vnd-label">{{ $group['count'] }} asset{{ $group['count'] === 1 ? '' : 's' }}</div>
            @if($isRental && $group['monthly'] > 0)
                <div class="ewx-amt">RM {{ number_format($group['monthly'], 2) }}<span class="text-muted small fw-normal">/mo</span></div>
            @elseif(! $isRental && $group['purchased'] > 0)
                <div class="ewx-amt">RM {{ number_format($group['purchased'], 2) }}</div>
            @endif
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover ewx-table align-middle mb-0">
            <thead>
                {{-- Six columns, the same four identity/spec ones on both sides so the two
                     tables read alike, then the pair that only makes sense for that
                     ownership type. Who holds the asset, whether it is available and whether
                     it has been signed for are properties of the asset rather than of this
                     vendor's kit list, and live on the asset record and the Report tab. --}}
                <tr>
                    <th class="ps-3">Asset Tag</th>
                    <th>Asset Type</th>
                    <th>Model</th>
                    <th>Spec</th>
                    @if($isRental)
                        <th>Rental Period</th>
                        <th class="text-end">Monthly Charge</th>
                    @else
                        <th>Purchased</th>
                        <th class="text-end">Cost</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @foreach($group['assets'] as $asset)
                @include('vendors.partials._asset-row-'.$mode, ['asset' => $asset])
            @endforeach
            </tbody>
        </table>
    </div>
</div>
