{{-- One purchased asset, inside its invoice group. Expects $asset.

     Mirrors _asset-row-rental: the same four identity/spec columns, then the two that mean
     something for kit we own instead of the rental pair. Keep the two files in step — two
     tables on one page that describe an asset differently is the thing this shape avoids. --}}
<tr class="{{ $asset->decommissioned_at ? 'vnd-row-off' : '' }}">
    <td class="ps-3">
        <a href="{{ route('assets.show', $asset) }}" class="ewx-code text-decoration-none">{{ $asset->asset_tag }}</a>
        @if($asset->serial_number)
        <div class="vnd-pic-meta">S/N {{ $asset->serial_number }}</div>
        @endif
    </td>
    <td class="small">{{ $asset->asset_type ?: '—' }}</td>
    <td class="small">{{ trim($asset->brand.' '.$asset->model) ?: '—' }}</td>
    <td class="small">{{ $asset->specSummary() ?: '—' }}</td>
    <td class="small text-nowrap">{{ fmt_date($asset->purchase_date) }}</td>
    <td class="text-end text-nowrap">{{ $asset->purchase_cost !== null ? number_format((float) $asset->purchase_cost, 2) : '—' }}</td>
</tr>
