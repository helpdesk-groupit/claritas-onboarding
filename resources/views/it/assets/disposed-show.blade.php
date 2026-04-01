@extends('layouts.app')
@section('title','Damaged Asset Detail')
@section('page-title','Damaged Asset Detail')
@section('content')

<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="{{ route('assets.index', ['tab' => 'damaged']) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Decommissioning Assets
    </a>
    @if(Auth::user()->canEditAsset())
    <a href="{{ route('assets.edit', $asset) }}" class="btn btn-sm btn-warning">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
    @endif
    <span class="badge bg-danger align-self-center">Not Good — Decommissioning</span>
</div>

@php $sc = ['available'=>'success','assigned'=>'primary','unavailable'=>'warning text-dark','retired'=>'secondary']; @endphp

<div class="row g-3">
    {{-- Section A --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-tag me-2 text-primary"></i>Section A — Identification</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:45%">Asset Tag</td><td><code>{{ $asset->asset_tag }}</code></td></tr>
                    <tr><td class="text-muted">Type</td><td>{{ ucfirst(str_replace('_',' ',$asset->asset_type)) }}</td></tr>
                    <tr><td class="text-muted">Brand</td><td>{{ $asset->brand }}</td></tr>
                    <tr><td class="text-muted">Model</td><td>{{ $asset->model }}</td></tr>
                    <tr><td class="text-muted">Serial Number</td><td>{{ $asset->serial_number ?? '—' }}</td></tr>
                    <tr><td class="text-muted">Status</td><td>
                        <span class="badge bg-{{ $sc[$asset->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_',' ',$asset->status)) }}</span>
                    </td></tr>
                    <tr><td class="text-muted">Condition</td><td><span class="badge bg-danger">Not Good</span></td></tr>
                </table>
            </div>
        </div>
    </div>

    {{-- Section B --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-cpu me-2 text-primary"></i>Section B — Specification</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:45%">Processor</td><td>{{ $asset->processor ?? '—' }}</td></tr>
                    <tr><td class="text-muted">RAM</td><td>{{ $asset->ram_size ?? '—' }}</td></tr>
                    <tr><td class="text-muted">Storage</td><td>{{ $asset->storage ?? '—' }}</td></tr>
                    <tr><td class="text-muted">OS</td><td>{{ $asset->operating_system ?? '—' }}</td></tr>
                    <tr><td class="text-muted">Screen Size</td><td>{{ $asset->screen_size ?? '—' }}</td></tr>
                    <tr><td class="text-muted">Others</td><td>{{ $asset->spec_others ?? '—' }}</td></tr>
                </table>
            </div>
        </div>
    </div>

    {{-- Section C --}}
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-receipt me-2 text-primary"></i>Section C — Procurement</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:45%">Ownership</td><td>
                        @if(($asset->ownership_type ?? 'company') === 'rental')
                            <span class="badge bg-warning text-dark"><i class="bi bi-truck me-1"></i>Rental / Leased</span>
                        @else
                            <span class="badge bg-primary"><i class="bi bi-building me-1"></i>Company Owned</span>
                        @endif
                    </td></tr>
                    @if(($asset->ownership_type ?? 'company') === 'company')
                        <tr><td class="text-muted">Company Name</td><td>{{ $asset->company_name ?? '—' }}</td></tr>
                        <tr><td class="text-muted">Purchase Date</td><td>{{ $asset->purchase_date?->format('d M Y') ?? '—' }}</td></tr>
                        <tr><td class="text-muted">Vendor</td><td>{{ $asset->purchase_vendor ?? '—' }}</td></tr>
                        <tr><td class="text-muted">Cost</td><td>{{ $asset->purchase_cost ? 'RM '.number_format($asset->purchase_cost,2) : '—' }}</td></tr>
                        <tr><td class="text-muted">Warranty Expiry</td><td>{{ $asset->warranty_expiry_date?->format('d M Y') ?? '—' }}</td></tr>
                    @else
                        <tr><td class="text-muted">Rental Vendor</td><td>{{ $asset->rental_vendor ?? '—' }}</td></tr>
                        <tr><td class="text-muted">Monthly Cost</td><td>{{ $asset->rental_cost_per_month ? 'RM '.number_format($asset->rental_cost_per_month,2) : '—' }}</td></tr>
                        <tr><td class="text-muted">Rental Period</td><td>
                            {{ $asset->rental_start_date?->format('d M Y') ?? '—' }} — {{ $asset->rental_end_date?->format('d M Y') ?? '—' }}
                        </td></tr>
                        <tr><td class="text-muted">Contract Ref</td><td>{{ $asset->rental_contract_reference ?? '—' }}</td></tr>
                    @endif
                </table>
            </div>
        </div>
    </div>

    {{-- Section E — Condition & Status --}}
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-clipboard-check me-2 text-primary"></i>Section E — Condition & Status</h6>
            </div>
            <div class="card-body">
                @php
                    $decommReason = \App\Models\DisposedAsset::where('asset_inventory_id', $asset->id)->value('reason');
                @endphp
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:45%">Condition</td><td><span class="badge bg-danger">Not Good</span></td></tr>
                    @if($decommReason)
                    <tr><td class="text-muted">Decommission Reason</td><td>{{ $decommReason }}</td></tr>
                    @endif
                </table>

                {{-- Asset Photos --}}
                @php
                    $photos = $asset->asset_photos ?? ($asset->asset_photo ? [$asset->asset_photo] : []);
                @endphp
                @if(!empty($photos))
                <div class="border-top pt-3 mt-3">
                    <span class="text-muted small d-block mb-2">
                        <i class="bi bi-images me-1"></i>Photos ({{ count($photos) }})
                    </span>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($photos as $idx => $photo)
                        <a href="{{ asset('storage/'.$photo) }}" target="_blank"
                           data-bs-toggle="modal" data-bs-target="#photoLightbox"
                           data-photo-src="{{ asset('storage/'.$photo) }}"
                           data-photo-idx="{{ $idx + 1 }}"
                           data-photo-total="{{ count($photos) }}"
                           onclick="openPhotoLightbox(this); return false;">
                            <img src="{{ asset('storage/'.$photo) }}"
                                 style="width:100px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6;cursor:pointer;"
                                 title="Photo {{ $idx + 1 }}">
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Notes --}}
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-sticky me-2 text-primary"></i>Notes</h6>
            </div>
            <div class="card-body">
                @if($asset->notes)
                <p class="mb-0" style="font-size:13px;white-space:pre-wrap;">{{ $asset->notes }}</p>
                @else
                <p class="text-muted small mb-0">No notes added for this asset.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Remarks log --}}
    @if($asset->remarks)
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2 text-primary"></i>Remarks / Audit Log</h6>
            </div>
            <div class="card-body">
                <div class="bg-light border rounded p-3"
                     style="font-size:12px;font-family:monospace;white-space:pre-wrap;max-height:200px;overflow-y:auto;">{{ $asset->remarks }}</div>
            </div>
        </div>
    </div>
    @endif
</div>

{{-- Photo Lightbox Modal --}}
<div class="modal fade" id="photoLightbox" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="background:#111;">
            <div class="modal-header border-0 py-2" style="background:#111;">
                <span class="text-white small" id="photoLightboxLabel"></span>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img id="photoLightboxImg" src="" alt="Asset Photo"
                     style="max-width:100%;max-height:70vh;object-fit:contain;border-radius:4px;">
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function openPhotoLightbox(el) {
    document.getElementById('photoLightboxImg').src = el.dataset.photoSrc;
    document.getElementById('photoLightboxLabel').textContent =
        'Photo ' + el.dataset.photoIdx + ' of ' + el.dataset.photoTotal;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('photoLightbox')).show();
}
</script>
@endpush

@endsection