{{-- The registered invoice this asset arrived on, shown beside the free-text Contract Ref
     and the asset's own uploaded copies. Both ownership branches include this, so the two
     cannot come to describe the link differently.

     The number always shows; the LINK to the vendor profile is gated, because an it_intern
     can read the asset but not Vendor Management, and a link that 403s reads as broken. --}}
<tr>
    <td class="text-muted">Registered Invoice</td>
    <td>
        @if($asset->originInvoice)
            @php $originInv = $asset->originInvoice; @endphp
            @if(Auth::user()?->canViewVendors() && $asset->vendor_id)
                <a href="{{ route('vendors.show', [$asset->vendor_id, 'tab' => 'assets']) }}#inv-doc-{{ $originInv->id }}"
                   class="text-decoration-none">{{ $originInv->doc_number ?: 'No number' }}</a>
            @else
                {{ $originInv->doc_number ?: 'No number' }}
            @endif
            <span class="text-muted small">
                @if($originInv->doc_date) · {{ fmt_date($originInv->doc_date) }} @endif
                @if($originInv->total !== null) · {{ $originInv->currency }} {{ number_format((float) $originInv->total, 2) }} @endif
            </span>
        @else
            <span class="text-muted">Not linked</span>
        @endif
    </td>
</tr>
