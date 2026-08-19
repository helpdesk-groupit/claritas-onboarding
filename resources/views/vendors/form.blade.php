@extends('layouts.app')
@section('title', isset($vendor) ? 'Edit Vendor' : 'Register Vendor')
@section('page-title', isset($vendor) ? 'Edit Vendor' : 'Register Vendor')

@section('content')
@include('partials.vendor-ui-style')
@php
    $selectedTypes = old('vendor_types', $vendor->vendor_types ?? []);

    $sstCategories = \App\Models\Vendor::sstCategories();
    $selectedSst = (array) old('sst_categories', isset($vendor) ? $vendor->sstCategoryList() : []);
    $nonServiceSst = \App\Models\Vendor::NON_SERVICE_TAX_CATEGORIES;
    // The 12 taxable-service groups, then the two answers that say the vendor holds no
    // service tax registration — they are not groups and must not read as one.
    $sstGroups = array_diff_key($sstCategories, array_flip($nonServiceSst));
    $sstNonService = array_intersect_key($sstCategories, array_flip($nonServiceSst));
    // Anything this vendor already holds that the list no longer offers, rendered ticked so
    // an ordinary save cannot silently drop a category nobody looked at.
    $sstRetired = collect(array_diff($selectedSst, array_keys($sstCategories)))
        ->mapWithKeys(fn ($k) => [$k => \App\Models\Vendor::sstLabelFor($k)])->all();

    $ownSst = \App\Models\Vendor::ownSstCategories();
@endphp
<div class="card" style="max-width:960px;">
    <div class="card-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
        <h6 class="text-white mb-0 fw-bold"><i class="bi bi-shop me-2"></i>{{ isset($vendor) ? 'Edit Vendor' : 'Register Vendor' }}</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ isset($vendor) ? route('vendors.update', $vendor) : route('vendors.store') }}">
            @csrf
            @if(isset($vendor)) @method('PUT') @endif

            {{-- ── Company ─────────────────────────────────────────────────── --}}
            <div class="section-header mb-3">
                <h6 class="mb-0"><i class="bi bi-building me-2 text-primary"></i>Company</h6>
            </div>

            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $vendor->name ?? '') }}" placeholder="e.g. TechLease Sdn Bhd" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Company Registration No.</label>
                    <input type="text" name="company_registration_no" class="form-control @error('company_registration_no') is-invalid @enderror"
                           value="{{ old('company_registration_no', $vendor->company_registration_no ?? '') }}" placeholder="e.g. 202301012345 (1234567-A)">
                    @error('company_registration_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Company Address</label>
                    <textarea name="address" rows="2" class="form-control @error('address') is-invalid @enderror"
                              placeholder="Street, city, postcode, state">{{ old('address', $vendor->address ?? '') }}</textarea>
                    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" value="{{ old('contact_number', $vendor->contact_number ?? '') }}" placeholder="Main office line">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Company Email</label>
                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $vendor->email ?? '') }}">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Website</label>
                    <input type="text" name="website" class="form-control" value="{{ old('website', $vendor->website ?? '') }}" placeholder="example.com">
                </div>
            </div>

            {{-- ── Classification ──────────────────────────────────────────── --}}
            <div class="section-header mb-3 mt-4">
                <h6 class="mb-0"><i class="bi bi-tags me-2 text-primary"></i>Classification</h6>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Type of Service <span class="text-danger">*</span></label>
                <div class="row g-2 @error('vendor_types') is-invalid @enderror">
                    @foreach(\App\Models\Vendor::TYPES as $key => $label)
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="form-check">
                                <input class="form-check-input js-vendor-type" type="checkbox" name="vendor_types[]"
                                       value="{{ $key }}" id="vtype_{{ $key }}"
                                       {{ in_array($key, (array) $selectedTypes, true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="vtype_{{ $key }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="form-text text-muted small">
                    Tick every service we engage them for. <strong>Asset Rental</strong>, <strong>Leasing</strong> and
                    <strong>Asset Supply / Purchase</strong> decide whether the vendor appears in the asset registration
                    picker; <strong>E-waste Disposal</strong> feeds the quarterly cycle.
                </div>
                @error('vendor_types')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Industry</label>
                    <select name="industry" class="form-select @error('industry') is-invalid @enderror">
                        <option value="">— Select industry —</option>
                        @foreach(\App\Models\Vendor::INDUSTRIES as $key => $label)
                            <option value="{{ $key }}" {{ old('industry', $vendor->industry ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('industry')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- ── Tax ─────────────────────────────────────────────────────── --}}
            <div class="section-header mb-3 mt-4">
                <h6 class="mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Tax</h6>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">TIN (Tax Identification No.)</label>
                    <input type="text" name="tin_number" class="form-control @error('tin_number') is-invalid @enderror"
                           value="{{ old('tin_number', $vendor->tin_number ?? '') }}" placeholder="e.g. C12345678901">
                    @error('tin_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text text-muted small">The vendor&rsquo;s LHDN tax identification number.</div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">SST Number</label>
                    <input type="text" name="sst_number" class="form-control @error('sst_number') is-invalid @enderror"
                           value="{{ old('sst_number', $vendor->sst_number ?? '') }}" placeholder="Service tax registration number">
                    @error('sst_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">SST Group/Category</label>
                    <div class="form-text text-muted small mt-0 mb-2">
                        Tick <strong>every</strong> service tax group the vendor is registered under &mdash; a vendor can hold
                        more than one. Leave them all blank if it has not been checked yet; that reads as
                        <em>not recorded</em>, which is not the same as telling us they are not registered.
                    </div>

                    <div class="row g-2">
                        @foreach($sstGroups as $key => $label)
                            <div class="col-12 col-lg-6">
                                <div class="form-check">
                                    <input class="form-check-input js-sst-cat" type="checkbox" name="sst_categories[]"
                                           value="{{ $key }}" id="sst_{{ $key }}"
                                           {{ in_array($key, $selectedSst, true) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="sst_{{ $key }}">{{ $label }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($sstNonService)
                        <div class="vnd-label mt-3 mb-1">Not a service tax group</div>
                        <div class="row g-2">
                            @foreach($sstNonService as $key => $label)
                                <div class="col-12 col-lg-6">
                                    <div class="form-check">
                                        {{-- "Not SST-registered" is exclusive: it is the absence of a
                                             registration, so it clears and locks the rest. --}}
                                        <input class="form-check-input js-sst-cat" type="checkbox" name="sst_categories[]"
                                               value="{{ $key }}" id="sst_{{ $key }}"
                                               @if($key === 'not_registered') data-sst-exclusive="1" @endif
                                               {{ in_array($key, $selectedSst, true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="sst_{{ $key }}">{{ $label }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($sstRetired)
                        <div class="vnd-label mt-3 mb-1">Recorded previously</div>
                        <div class="row g-2">
                            @foreach($sstRetired as $key => $label)
                                <div class="col-12 col-lg-6">
                                    <div class="form-check">
                                        <input class="form-check-input js-sst-cat" type="checkbox" name="sst_categories[]"
                                               value="{{ $key }}" id="sst_{{ $key }}" checked>
                                        <label class="form-check-label text-muted" for="sst_{{ $key }}">{{ $label }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text text-muted small">
                            Kept so nothing is lost. Untick it and tick the group it belongs to now.
                        </div>
                    @endif

                    @error('sst_categories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('sst_categories.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Only the CONFIGURED case gets a note. The "our own SST category isn't set yet"
                     banner was removed at the operator's request: it is a deployment detail aimed
                     at whoever edits config/vendors.php, and it sat on a form used by people who
                     can't act on it. The verdict itself still reads "not determined" until it's set. --}}
                @if($ownSst)
                    <div class="col-12">
                        <div class="alert alert-info py-2 mb-0" style="font-size:12.5px;">
                            <i class="bi bi-info-circle me-1"></i>
                            Our own SST {{ count($ownSst) === 1 ? 'category is' : 'categories are' }}
                            <strong>{{ implode(', ', array_map([\App\Models\Vendor::class, 'sstLabelFor'], $ownSst)) }}</strong>.
                            A vendor registered under the same category <strong>cannot charge us SST</strong> on services in it
                            (B2B exemption) &mdash; the vendor profile says so, and any invoice carrying an SST line is flagged.
                        </div>
                    </div>
                @endif
            </div>

            {{-- ── Banking ─────────────────────────────────────────────────── --}}
            <div class="section-header mb-3 mt-4">
                <h6 class="mb-0"><i class="bi bi-bank me-2 text-primary"></i>Bank Details</h6>
            </div>

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Bank Name</label>
                    {{-- Free text with suggestions, not a fixed list: a foreign vendor banks
                         somewhere no Malaysian dropdown carries. See Vendor::BANK_SUGGESTIONS. --}}
                    <input type="text" name="bank_name" list="vendorBankNames"
                           class="form-control @error('bank_name') is-invalid @enderror"
                           value="{{ old('bank_name', $vendor->bank_name ?? '') }}" placeholder="e.g. Maybank" autocomplete="off">
                    <datalist id="vendorBankNames">
                        @foreach(\App\Models\Vendor::BANK_SUGGESTIONS as $bank)
                            <option value="{{ $bank }}"></option>
                        @endforeach
                    </datalist>
                    @error('bank_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Account Holder Name</label>
                    <input type="text" name="bank_account_name" class="form-control @error('bank_account_name') is-invalid @enderror"
                           value="{{ old('bank_account_name', $vendor->bank_account_name ?? '') }}" placeholder="Name exactly as it appears on the account">
                    @error('bank_account_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text text-muted small">Often differs from the trading name &mdash; a transfer to a mismatched name is rejected.</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Bank Account Number</label>
                    <input type="text" name="bank_account_number" class="form-control @error('bank_account_number') is-invalid @enderror"
                           value="{{ old('bank_account_number', $vendor->bank_account_number ?? '') }}" placeholder="Account number">
                    @error('bank_account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Bank Branch</label>
                    <input type="text" name="bank_branch" class="form-control @error('bank_branch') is-invalid @enderror"
                           value="{{ old('bank_branch', $vendor->bank_branch ?? '') }}" placeholder="Branch, if stated">
                    @error('bank_branch')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">SWIFT / BIC</label>
                    <input type="text" name="bank_swift" class="form-control @error('bank_swift') is-invalid @enderror"
                           value="{{ old('bank_swift', $vendor->bank_swift ?? '') }}" placeholder="e.g. MBBEMYKL">
                    @error('bank_swift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text text-muted small">Needed for overseas payments.</div>
                </div>
            </div>

            {{-- ── Contacts ────────────────────────────────────────────────── --}}
            <div class="section-header mb-3 mt-4">
                <h6 class="mb-0"><i class="bi bi-person-badge me-2 text-primary"></i>Contacts</h6>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">PIC Name</label>
                    <input type="text" name="pic_name" class="form-control" value="{{ old('pic_name', $vendor->pic_name ?? '') }}">
                    <div class="form-text text-muted small">The commercial contact.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">PIC Email</label>
                    <input type="email" name="pic_email" class="form-control @error('pic_email') is-invalid @enderror" value="{{ old('pic_email', $vendor->pic_email ?? '') }}">
                    @error('pic_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text text-muted small">Receives e-waste RFQs and collection acknowledgements.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">PIC Number</label>
                    <input type="text" name="pic_phone" class="form-control" value="{{ old('pic_phone', $vendor->pic_phone ?? '') }}">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Technical Person</label>
                    <input type="text" name="technical_person_name" class="form-control" value="{{ old('technical_person_name', $vendor->technical_person_name ?? '') }}">
                    <div class="form-text text-muted small">Who IT calls when it breaks.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Technical Person Email</label>
                    <input type="email" name="technical_person_email" class="form-control @error('technical_person_email') is-invalid @enderror" value="{{ old('technical_person_email', $vendor->technical_person_email ?? '') }}">
                    @error('technical_person_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Technical Person Number</label>
                    <input type="text" name="technical_person_phone" class="form-control" value="{{ old('technical_person_phone', $vendor->technical_person_phone ?? '') }}">
                </div>
            </div>

            {{-- ── Other ───────────────────────────────────────────────────── --}}
            <div class="mb-3 mt-4">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" rows="2" class="form-control">{{ old('notes', $vendor->notes ?? '') }}</textarea>
            </div>

            {{-- There is deliberately no "primary e-waste vendor" toggle: the quarterly sweep
                 RFQs EVERY active e-waste vendor with a PIC email so the offers can be
                 compared, and nominating one is what made a cycle only ever show one price. --}}
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                       {{ old('is_active', $vendor->is_active ?? true) ? 'checked' : '' }}>
                <label class="form-check-label" for="is_active">Active</label>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-2"></i>{{ isset($vendor) ? 'Update Vendor' : 'Save Vendor' }}</button>
                <a href="{{ isset($vendor) ? route('vendors.show', $vendor) : route('vendors.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    // The e-waste type checkbox no longer reveals anything: the "primary e-waste vendor"
    // toggle it used to show/hide was retired when the sweep started RFQ'ing the whole
    // market, so the .js-ewaste-only block and its listener went with it.

    // "Not SST-registered" is the absence of a registration, so it cannot sit beside a
    // group: ticking it clears and locks the others. The server refuses the combination
    // as well — a disabled checkbox is a courtesy, not a rule.
    (function () {
        const boxes = Array.from(document.querySelectorAll('.js-sst-cat'));
        const exclusive = boxes.filter(b => b.dataset.sstExclusive === '1');
        if (!exclusive.length) return;

        function sync() {
            const on = exclusive.some(b => b.checked);
            boxes.forEach(b => {
                if (exclusive.includes(b)) return;
                if (on) b.checked = false;
                b.disabled = on;
                const label = b.closest('.form-check')?.querySelector('.form-check-label');
                if (label) label.classList.toggle('text-muted', on);
            });
        }
        boxes.forEach(b => b.addEventListener('change', sync));
        sync();
    })();
</script>
@endpush
