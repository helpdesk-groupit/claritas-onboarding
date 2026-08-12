{{-- One invoice group on the vendor's Assets tab: the header that says WHICH invoice, and
     the assets that arrived on it.

     Driven entirely by the shape AssetInventory::groupByOriginInvoice() returns, so the
     rental and purchased tables share this file and a fourth kind of group later is a new
     `state` here rather than another table somewhere else.

     Expects:
       $group  one entry from groupByOriginInvoice()
       $mode   'rental' | 'purchase' — which row partial and which total apply
       $vendor, $canManage
     Rental mode additionally passes $assetFormStatus + $pendingIds through to its rows. --}}
@php
    $doc = $group['document'];
    $isRental = $mode === 'rental';
@endphp

<div class="vnd-invgroup mb-3" id="{{ $group['anchor'] }}">
    <div class="vnd-invgroup-head d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div class="min-w-0">
            @if($group['state'] === 'registered')
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="vnd-type vnd-type-purchase">Invoice</span>
                    <span class="ewx-code">{{ $doc->doc_number ?: 'No number' }}</span>
                    @if($doc->doc_date)
                        <span class="text-muted small">{{ fmt_date($doc->doc_date) }}</span>
                    @endif
                    @if($doc->total !== null)
                        <span class="text-muted small">{{ $doc->currency }} {{ number_format((float) $doc->total, 2) }}</span>
                    @endif
                </div>
                <div class="vnd-pic-meta mt-1">
                    @if($doc->contract)
                        <i class="bi bi-file-earmark-text me-1"></i>Under contract: {{ $doc->contract->title }}
                        <span class="mx-1">·</span>
                    @endif
                    {{-- The REGISTERED document, deliberately not the copy stored on the asset:
                         asset invoice files live under `invoices/`, which SecureFileController
                         gates to HR + IT only, so a Finance reader — the audience this tab's
                         figures exist for — would get a 403 on the asset's copy. --}}
                    @if($doc->file_path)
                        <a href="{{ secure_file_url($doc->file_path) }}" target="_blank" class="ewx-quote-link text-decoration-none">
                            <i class="bi bi-{{ str_ends_with(strtolower((string) $doc->original_filename), '.pdf') ? 'file-earmark-pdf text-danger' : 'file-earmark-image text-primary' }} me-1"></i>View document
                        </a>
                        <span class="mx-1">·</span>
                    @endif
                    <a href="{{ route('vendors.show', [$vendor, 'tab' => 'billing']) }}" class="text-decoration-none">Open in Billing</a>
                </div>

            @elseif($group['state'] === 'unregistered')
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="vnd-type">Reference</span>
                    <span class="ewx-code">{{ $group['reference'] }}</span>
                    <span class="badge rounded-pill bg-light text-muted border">Not in the billing register</span>
                </div>
                <div class="vnd-pic-meta mt-1">
                    Typed on the asset record. Registering files it as an invoice against this
                    vendor and links {{ $group['count'] === 1 ? 'this asset' : 'all '.$group['count'].' assets' }} to it.
                </div>

            @else
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="vnd-type">No invoice recorded</span>
                </div>
                <div class="vnd-pic-meta mt-1">
                    Neither an invoice nor a reference is recorded on
                    {{ $group['count'] === 1 ? 'this asset' : 'these assets' }}.
                </div>
            @endif
        </div>

        <div class="text-end text-nowrap d-flex align-items-center gap-3">
            <div>
                <div class="vnd-label">{{ $group['count'] }} asset{{ $group['count'] === 1 ? '' : 's' }}</div>
                @if($isRental && $group['monthly'] > 0)
                    <div class="ewx-amt">RM {{ number_format($group['monthly'], 2) }}<span class="text-muted small fw-normal">/mo</span></div>
                @elseif(! $isRental && $group['purchased'] > 0)
                    <div class="ewx-amt">RM {{ number_format($group['purchased'], 2) }}</div>
                @endif
            </div>

            @if($group['state'] === 'unregistered' && $canManage)
            <form action="{{ route('vendors.billing.register-from-assets', $vendor) }}" method="POST" class="js-confirm"
                  data-confirm="File &quot;{{ $group['reference'] }}&quot; as an invoice in this vendor's billing register and link the {{ $group['count'] }} asset{{ $group['count'] === 1 ? '' : 's' }} grouped under it? Any invoice document already uploaded on those assets is copied onto the new record. No amounts are filled in — type them on the Billing tab."
                  data-confirm-title="Register this invoice"
                  data-confirm-ok="Register"
                  data-confirm-variant="primary">
                @csrf
                <input type="hidden" name="reference" value="{{ $group['reference'] }}">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-journal-plus me-1"></i>Register
                </button>
            </form>
            @endif
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover ewx-table align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Asset Tag</th>
                    <th>Description</th>
                    <th>Assigned To</th>
                    @if($isRental)
                        <th>Rental Period</th>
                        <th class="text-end">Monthly</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">AARF</th>
                    @else
                        <th>Purchased</th>
                        <th class="text-end">Cost</th>
                        <th>Warranty</th>
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
