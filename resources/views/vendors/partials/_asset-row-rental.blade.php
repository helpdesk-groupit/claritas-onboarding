{{-- One rented asset, inside its invoice group. Expects $asset.

     Six columns and no more (2026-08-13). The serial number rides under the tag rather than
     taking a column of its own: without it the row names a MODEL we rent several of, not the
     machine this line is about, and the rental period and charge below belong to one machine.

     The row still dims when the asset is decommissioned — the Status column is gone, so this
     is the only thing left saying the rental has ended, and a returned machine listed exactly
     like a live one would overstate what this vendor still holds of ours. --}}
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
    <td class="small text-nowrap">{{ fmt_date($asset->rental_start_date) }} &rarr; {{ fmt_date($asset->rental_end_date) }}</td>
    <td class="text-end text-nowrap">{{ $asset->rental_cost_per_month !== null ? number_format((float) $asset->rental_cost_per_month, 2) : '—' }}</td>
</tr>
