{{-- One purchased asset, inside its invoice group. Expects $asset. --}}
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
    <td class="small text-nowrap">{{ fmt_date($asset->purchase_date) }}</td>
    <td class="text-end text-nowrap">{{ $asset->purchase_cost !== null ? number_format((float) $asset->purchase_cost, 2) : '—' }}</td>
    <td class="small text-nowrap">
        {{ fmt_date($asset->warranty_expiry_date) }}
        @if($asset->warranty_expiry_date && $asset->warranty_expiry_date->isPast())
            <span class="badge bg-secondary ms-1">Expired</span>
        @endif
    </td>
</tr>
