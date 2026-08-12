{{-- One rented asset, inside its invoice group. Expects $asset, plus $assetFormStatus and
     $pendingIds from the enclosing scope. --}}
<tr class="{{ $asset->decommissioned_at ? 'vnd-row-off' : '' }}">
    <td class="ps-3">
        <a href="{{ route('assets.show', $asset) }}" class="ewx-code text-decoration-none">{{ $asset->asset_tag }}</a>
    </td>
    <td>
        <div class="small">{{ trim($asset->brand.' '.$asset->model) ?: $asset->asset_type }}</div>
        @if($asset->serial_number)
        <div class="vnd-pic-meta">S/N {{ $asset->serial_number }}</div>
        @endif
    </td>
    <td class="small">{{ $asset->resolvedAssigneeName() }}</td>
    <td class="small text-nowrap">{{ fmt_date($asset->rental_start_date) }} &rarr; {{ fmt_date($asset->rental_end_date) }}</td>
    <td class="text-end text-nowrap">{{ $asset->rental_cost_per_month !== null ? number_format((float) $asset->rental_cost_per_month, 2) : '—' }}</td>
    <td class="text-center">
        @if($asset->decommissioned_at)
            <span class="badge rounded-pill bg-secondary">Returned</span>
        @else
            <span class="badge rounded-pill bg-{{ $asset->status === 'assigned' ? 'primary' : ($asset->status === 'available' ? 'success' : 'warning') }}">{{ ucfirst((string) $asset->status) }}</span>
        @endif
    </td>
    <td class="text-center">
        {{-- Distinct states, and none of them may be dressed as another. An asset sitting on
             an UNSIGNED form is not acknowledged, and one that predates the process was never
             asked for — showing either as a green tick would assert a signature that does not
             exist.

             The RETURN takes precedence when there is one, because it is the later event in
             the asset's life and it is the one that ended the rental. The receipt state stays
             underneath it rather than being replaced: "signed for on arrival, and signed
             back" is two facts, and collapsing them would lose whichever the reader needed. --}}
        @php
            $forms = $assetFormStatus[$asset->id] ?? null;
            $receiptForm = $forms[\App\Models\RentalAssetAcknowledgement::TYPE_RECEIPT] ?? null;
            $returnForm = $forms[\App\Models\RentalAssetAcknowledgement::TYPE_RETURN] ?? null;
            $ackd = \App\Models\RentalAssetAcknowledgement::STATUS_ACKNOWLEDGED;
        @endphp

        @if($returnForm)
            @if($returnForm->status === $ackd)
                <span class="badge rounded-pill bg-secondary" title="Returned on {{ $returnForm->reference }}">Return signed</span>
            @else
                <span class="badge rounded-pill bg-info text-dark" title="On return form {{ $returnForm->reference }}, not yet signed">On return draft</span>
            @endif
            <div class="vnd-pic-meta">{{ $returnForm->reference }}</div>
        @elseif($receiptForm && $receiptForm->status === $ackd)
            <i class="bi bi-check-circle-fill text-success" title="Receipt acknowledged on {{ $receiptForm->reference }}"></i>
        @elseif($receiptForm)
            <span class="badge rounded-pill bg-info text-dark" title="On draft receipt {{ $receiptForm->reference }}, not yet signed">On draft</span>
        @elseif($pendingIds->has($asset->id))
            <span class="badge rounded-pill bg-warning text-dark">Pending</span>
        @elseif($asset->decommissioned_at)
            <span class="text-muted small">&mdash;</span>
        @elseif(\App\Models\RentalAssetAcknowledgement::isPreExisting($asset))
            <span class="badge rounded-pill bg-light text-muted border"
                  title="Registered {{ fmt_date($asset->created_at) }}, before AARF tracking began — never required an acknowledgement">Pre-AARF</span>
        @else
            <span class="text-muted small">&mdash;</span>
        @endif
    </td>
</tr>
