{{-- One AARF listing, rendered once per direction by the Report tab.

     The combined table this replaced carried a Type column. It is gone rather than
     repeated: the section heading above states the direction, and a column that can only
     hold one value in the table it sits on is noise. The direction still reaches every row
     through the reference itself — RRA- for a receipt, RTA- for a return — which is exactly
     why the two are numbered apart.

     Variables: $forms (Collection<RentalAssetAcknowledgement>), plus $vendor / $canManage
     from the profile page. --}}

<div class="table-responsive">
    <table class="table table-sm table-hover ewx-table align-middle">
        <thead>
            <tr>
                <th class="ps-3">Report No.</th>
                <th>Company Rented To</th>
                <th class="text-center">Assets</th>
                <th>Acknowledged</th>
                <th class="text-center">Status</th>
                <th class="text-end pe-3">Actions</th>
            </tr>
        </thead>
        <tbody>
        @foreach($forms as $aarf)
            @php
                $badge = $aarf->statusBadge();

                // WHO closed the document depends on the direction, and printing the
                // account as the signatory on a return would credit our own staff with the
                // vendor's declaration. On a return the closing signatory is the vendor's
                // collector, named in the collector details; `acknowledged_by` is only the
                // desk it was processed under. See RentalAssetAcknowledgement::acknowledger().
                //
                // Built in @php rather than glued into the markup: a directive stuck to the
                // end of a word compiles through as literal text (see the Blade gotcha in
                // CLAUDE.md), and this line is two conditionals deep.
                $ackName = $aarf->isReturn()
                    ? ($aarf->collector_name ?: '—')
                    : ($aarf->acknowledger?->name ?: '—');
                $ackMeta = fmt_datetime($aarf->acknowledged_at);
                if ($aarf->isReturn() && $aarf->acknowledger) {
                    $ackMeta .= ' · processed under '.$aarf->acknowledger->name;
                }
            @endphp
            <tr>
                <td class="ps-3">
                    <a href="{{ route('vendors.aarf.show', [$vendor, $aarf]) }}" class="ewx-code text-decoration-none">{{ $aarf->reference }}</a>
                </td>
                <td class="small">{{ $aarf->company_rented_to ?: '—' }}</td>
                <td class="text-center small">{{ $aarf->items_count ?? $aarf->items->count() }}</td>
                <td class="small">
                    @if($aarf->isAcknowledged())
                        {{ $ackName }}
                        <div class="vnd-pic-meta">{{ $ackMeta }}</div>
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
