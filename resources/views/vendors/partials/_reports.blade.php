{{-- Report tab — every AARF filed against this vendor, split by the direction the assets
     moved.

     It lived inside the Assets tab until 2026-08-13, above the two asset tables. That tab
     answers "what kit sits against this vendor"; these are the signed DOCUMENTS proving the
     kit changed hands, which is a different question and the one an audit asks. The per-asset
     AARF state stays on the rental rows over there — this is the form-level register.

     The split is by `type`, which is the fact that makes the two documents different: the
     parties swap, so a receipt and a return are not two rows of one list. Nothing here is
     derived — the two directions are `RentalAssetAcknowledgement::TYPE_RECEIPT` / `TYPE_RETURN`
     and their references are numbered apart (RRA- / RTA-) for the same reason. --}}

@php
    $receiptForms = $acknowledgements->filter->isReceipt()->values();
    $returnForms = $acknowledgements->filter->isReturn()->values();
    $companiesPending = $pendingAssets->pluck('company_supplied_to')
        ->map(fn ($c) => $c ?: 'Unspecified')->unique()->values();

    // The form's own "Type of process" wording, printed as the caption of the table it
    // describes. Read from the model so the tab and the document can never disagree about
    // what a direction is called, and only when that table has rows — a caption for an
    // absent table describes nothing.
    $receiptCaption = $receiptForms->isNotEmpty()
        ? \App\Models\RentalAssetAcknowledgement::TYPE_LABELS[\App\Models\RentalAssetAcknowledgement::TYPE_RECEIPT]
        : null;
    $returnCaption = $returnForms->isNotEmpty()
        ? \App\Models\RentalAssetAcknowledgement::TYPE_LABELS[\App\Models\RentalAssetAcknowledgement::TYPE_RETURN]
        : null;
@endphp

{{-- ── The AARF register ─────────────────────────────────────────────────────
     Rendered only for a vendor the forms can apply to — see $showAarfRegister in
     show.blade.php for why, and why the flag is resolved there rather than here. --}}
@if($showAarfRegister)

{{-- ── Header ───────────────────────────────────────────────────────────────── --}}
<div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
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
<div class="alert alert-warning py-2 px-3 small mb-3">
    <i class="bi bi-exclamation-circle me-1"></i>
    <strong>{{ $pendingAssets->count() }}</strong> rental asset{{ $pendingAssets->count() === 1 ? ' is' : 's are' }}
    awaiting acknowledgement
    @if($companiesPending->count() > 1)
        across {{ $companiesPending->count() }} companies ({{ $companiesPending->implode(', ') }}) &mdash;
        generating will create {{ $companiesPending->count() }} separate forms.
    @else
        for {{ $companiesPending->first() }}.
    @endif
    @if(! $canManage)
        <span class="text-muted">You do not have permission to generate one.</span>
    @endif
</div>
@endif

{{-- ── Assets accepted (receipts, RRA) ──────────────────────────────────────── --}}
<div class="mb-4">
    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
        <div class="fw-semibold">
            <i class="bi bi-box-arrow-in-down me-1 text-primary"></i>Assets Accepted
        </div>
        <span class="badge rounded-pill bg-secondary">{{ $receiptForms->count() }}</span>
    </div>
    <div class="text-muted small mb-2">
        Kit the vendor delivered to us. Reference <span class="fw-semibold">RRA-</span>.
        @if($receiptCaption)
            Type of process: {{ $receiptCaption }}.
        @endif
    </div>

    @if($receiptForms->isEmpty())
        <div class="ewx-empty">
            <i class="bi bi-box-arrow-in-down"></i>
            No receipt AARF has been raised for this vendor yet.
        </div>
    @else
        @include('vendors.partials._aarf-table', ['forms' => $receiptForms])
    @endif
</div>

{{-- ── Assets returned (RTA) ────────────────────────────────────────────────── --}}
<div>
    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
        <div class="fw-semibold">
            <i class="bi bi-box-arrow-up me-1 text-warning"></i>Assets Returned
        </div>
        <span class="badge rounded-pill bg-secondary">{{ $returnForms->count() }}</span>
    </div>
    <div class="text-muted small mb-2">
        Kit we handed back to the vendor. Reference <span class="fw-semibold">RTA-</span>.
        @if($returnCaption)
            Type of process: {{ $returnCaption }}.
        @endif
    </div>

    @if($returnForms->isEmpty())
        <div class="ewx-empty">
            <i class="bi bi-box-arrow-up"></i>
            No return AARF has been raised for this vendor yet &mdash; a return starts on the
            IT asset listing&rsquo;s Decommissioning tab, once the assets are marked Returned.
        </div>
    @else
        @include('vendors.partials._aarf-table', ['forms' => $returnForms])
    @endif
</div>

@endif

{{-- E-waste collections this vendor was awarded (Phase 6). Same tab as the AARFs because it
     answers the same question — what documents prove what this vendor did for us — even
     though a disposal is not an acknowledgement form.

     Only for a vendor the question can apply to. An "Asset Rental" vendor is never awarded a
     disposal, so the block could never fill on their profile and read as a rental vendor whose
     disposals had gone missing rather than one that does no disposals.

     Hidden only when it is BOTH irrelevant by type AND empty — the same rule the two asset
     sections follow, for the same reason: `vendor_types` is editable at any time, so keying
     purely off the tag would silently bury a collection this vendor really did carry out, on
     the only page that lists it per vendor. A cycle awarded to an untagged vendor is a data
     problem to see, not to hide. `$showReportTab` in show.blade.php already counts
     $ewasteCycles, so an e-waste-only vendor with cycles and no AARFs still reaches this tab. --}}
@if($vendor->isEwaste() || $ewasteCycles->isNotEmpty())
    @include('vendors.partials._ewaste-cycles', ['ewasteCycles' => $ewasteCycles, 'vendor' => $vendor])
@endif
