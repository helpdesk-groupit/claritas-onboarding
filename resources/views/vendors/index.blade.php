@extends('layouts.app')
@section('title', 'Vendor Management')
@section('page-title', 'Vendor Management')

@section('content')
@include('partials.dashboard-widgets-style')
@include('partials.decommission-ui-style')
@include('partials.vendor-ui-style')

<div class="container-fluid px-0">
    {{-- Flash messages are rendered globally by layouts/app.blade.php — don't duplicate here. --}}

    <div class="card vnd-hero mb-3">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="vnd-hero-icon"><i class="bi bi-shop"></i></span>
                <div>
                    <h5 class="text-white mb-0 fw-bold">Vendor Directory</h5>
                    <small class="text-white-50">Every vendor the company deals with &mdash; suppliers, rentals, services and disposal.</small>
                </div>
            </div>
            @if($canManage)
            <a href="{{ route('vendors.create') }}" class="btn btn-light btn-sm fw-semibold"><i class="bi bi-plus-lg me-1"></i>Register Vendor</a>
            @endif
        </div>
    </div>

    {{-- Master-data health. Counts are of ACTIVE vendors across the whole master, not the
         current filter — they answer "what do we have", not "what did I search for". --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#64748b,#334155);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-shop"></i></div>
                        <div>
                            <div class="widget-number">{{ $stats['active'] }}</div>
                            {{-- Built in PHP rather than inline @if: Blade only treats @ as a
                                 directive when it is not preceded by a word character, so
                                 "vendors@if(...)" / "inactive@endif" silently fail to compile. --}}
                            @php
                                $vndActiveLabel = 'Active vendors'
                                    .($stats['inactive'] ? ' · '.$stats['inactive'].' inactive' : '');
                            @endphp
                            <div class="widget-label">{{ $vndActiveLabel }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#0ea5e9,#0369a1);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-file-earmark-text"></i></div>
                        <div>
                            <div class="widget-number">{{ $stats['contracts'] }}</div>
                            <div class="widget-label">Live contracts</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,{{ $stats['expiring'] ? '#f59e0b,#b45309' : '#94a3b8,#64748b' }});">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="widget-number">{{ $stats['expiring'] }}</div>
                            <div class="widget-label">Expiring in {{ \App\Models\VendorContract::EXPIRY_WARNING_DAYS }} days</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-recycle"></i></div>
                        <div>
                            <div class="widget-number">{{ $stats['ewaste'] }}</div>
                            @php
                                $vndRentalLabel = 'E-waste vendors · '.$stats['rental'].' rental';
                            @endphp
                            <div class="widget-label">{{ $vndRentalLabel }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- The quarterly sweep RFQs the PRIMARY e-waste vendor. With none set it can't send,
         only bells IT — so the cycle stalls quietly. Say so on the page that fixes it. --}}
    @if($stats['primary'])
    <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3" style="font-size:13px;">
        <i class="bi bi-star-fill"></i>
        <div>Quarterly e-waste RFQs go to <strong>{{ $stats['primary']->name }}</strong>@if($stats['primary']->pic_email) ({{ $stats['primary']->pic_email }})@endif.</div>
    </div>
    @else
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-3" style="font-size:13px;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>No primary e-waste vendor set.</strong>
            The quarterly sweep cannot send an RFQ and will only notify IT&nbsp;— flag one e-waste vendor as primary to restart the cycle.
        </div>
    </div>
    @endif

    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                <div class="position-relative">
                    <i class="bi bi-search position-absolute text-muted" style="left:10px;top:50%;transform:translateY(-50%);font-size:12px;"></i>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm ps-4" style="width:230px;" placeholder="Name, PIC, reg. no, SST/TIN no…">
                </div>
                <select name="type" class="form-select form-select-sm" style="width:190px;">
                    <option value="">All service types</option>
                    @foreach(\App\Models\Vendor::TYPES as $key => $label)
                        <option value="{{ $key }}" {{ request('type') === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="industry" class="form-select form-select-sm" style="width:180px;">
                    <option value="">All industries</option>
                    @foreach(\App\Models\Vendor::INDUSTRIES as $key => $label)
                        <option value="{{ $key }}" {{ request('industry') === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="status" class="form-select form-select-sm" style="width:130px;">
                    <option value="">All statuses</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                @if(request('search') || request('type') || request('status') || request('industry'))
                <a href="{{ route('vendors.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                @endif
            </form>
        </div>
    </div>

    <div class="card ewx-card">
        <div class="ewx-head">
            <span class="ewx-chip ewx-chip-slate"><i class="bi bi-people"></i></span>
            <div class="me-2">
                <span class="ewx-title">Registered Vendors</span>
                <span class="ewx-sub">Vendors are never deleted &mdash; deactivate instead, so past batches, assets and contracts keep their reference.</span>
            </div>
            @if($vendors->total())
            <span class="ewx-count">{{ $vendors->total() }}</span>
            @endif
        </div>
        <div class="card-body p-0">
            @if($vendors->isEmpty())
                <div class="ewx-empty">
                    <i class="bi bi-shop"></i>
                    No vendors {{ request('search') || request('type') || request('status') || request('industry') ? 'match this filter' : 'registered yet' }}.
                </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover ewx-table">
                    <thead>
                        <tr>
                            <th class="ps-3">Vendor</th>
                            <th>Type of Service</th>
                            <th>Person in Charge</th>
                            <th>SST</th>
                            <th class="text-center">Assets</th>
                            <th class="text-center">Contracts</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($vendors as $vendor)
                        @php $vndSst = $vendor->sstVerdict(); @endphp
                        <tr class="{{ $vendor->is_active ? '' : 'vnd-row-off' }}">
                            <td class="ps-3">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="vnd-avatar">{{ strtoupper(mb_substr($vendor->name, 0, 2)) }}</span>
                                    <div>
                                        <a href="{{ route('vendors.show', $vendor) }}" class="ewx-code text-decoration-none">{{ $vendor->name }}</a>
                                        <div class="vnd-pic-meta">
                                            @if($vendor->company_registration_no)Reg. {{ $vendor->company_registration_no }}@endif
                                            @if($vendor->industry)<span class="ms-2">{{ $vendor->industryLabel() }}</span>@endif
                                            @if($vendor->is_primary_ewaste)<span class="vnd-primary-star ms-2"><i class="bi bi-star-fill me-1"></i>RFQ</span>@endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @forelse($vendor->vendor_types ?? [] as $t)
                                    <span class="vnd-type vnd-type-{{ $t }}">{{ \App\Models\Vendor::TYPES[$t] ?? ucfirst($t) }}</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td>
                                <div class="vnd-pic">{{ $vendor->pic_name ?: '—' }}</div>
                                <div class="vnd-pic-meta">
                                    @if($vendor->pic_email)<a href="mailto:{{ $vendor->pic_email }}"><i class="bi bi-envelope me-1"></i>{{ $vendor->pic_email }}</a>@endif
                                    @if($vendor->pic_phone)<span class="ms-2"><i class="bi bi-telephone me-1"></i>{{ $vendor->pic_phone }}</span>@endif
                                </div>
                            </td>
                            <td class="text-nowrap">
                                @if($vndSst['state'] === 'exempt' || $vndSst['state'] === 'not_registered')
                                    <span class="badge rounded-pill bg-success-subtle text-success-emphasis" title="{{ $vndSst['reason'] }}">{{ $vndSst['label'] }}</span>
                                @elseif($vndSst['state'] === 'chargeable')
                                    <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis" title="{{ $vndSst['reason'] }}">{{ $vndSst['label'] }}</span>
                                @else
                                    <span class="text-muted" title="{{ $vndSst['reason'] }}">—</span>
                                @endif
                            </td>
                            <td class="text-center">{{ $vendor->assets_count ?: '—' }}</td>
                            <td class="text-center">{{ $vendor->contracts_count ?: '—' }}</td>
                            <td class="text-center">
                                <span class="badge rounded-pill bg-{{ $vendor->is_active ? 'success' : 'secondary' }}">{{ $vendor->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="text-end pe-3 text-nowrap">
                                <a href="{{ route('vendors.show', $vendor) }}" class="btn btn-sm btn-outline-primary" title="Open vendor profile">
                                    <i class="bi bi-eye me-1"></i>Profile
                                </a>
                                @if($canManage)
                                {{-- No Edit pencil here by design: editing is done from the vendor's
                                     own Profile page, which is the only screen that shows what the
                                     change affects (contracts, billing, assets). --}}
                                <form action="{{ route('vendors.toggle-active', $vendor) }}" method="POST" class="d-inline js-confirm ms-1"
                                      data-confirm="{{ $vendor->is_active ? 'Deactivate' : 'Activate' }} vendor &quot;{{ $vendor->name }}&quot;?"
                                      data-confirm-title="{{ $vendor->is_active ? 'Deactivate vendor' : 'Activate vendor' }}"
                                      data-confirm-ok="{{ $vendor->is_active ? 'Deactivate' : 'Activate' }}"
                                      data-confirm-variant="{{ $vendor->is_active ? 'danger' : 'success' }}">
                                    @csrf
                                    <button class="btn btn-sm {{ $vendor->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}" title="{{ $vendor->is_active ? 'Deactivate' : 'Activate' }}">
                                        <i class="bi bi-{{ $vendor->is_active ? 'toggle-on' : 'toggle-off' }}"></i>
                                    </button>
                                </form>

                                {{-- Delete is for a row that is not history — a duplicate or a typo.
                                     Shown DISABLED rather than hidden once anything references the
                                     vendor, so the rule explains itself instead of the button looking
                                     missing; the controller refuses either way. --}}
                                @php $vndBlockers = $vendor->deletionBlockers(); @endphp
                                @if($vndBlockers)
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-1 disabled" tabindex="-1" aria-disabled="true"
                                            title="Cannot delete — {{ implode(', ', $vndBlockers) }} on record. Deactivate instead.">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @else
                                    <form action="{{ route('vendors.destroy', $vendor) }}" method="POST" class="d-inline js-confirm ms-1"
                                          data-confirm="Permanently delete vendor &quot;{{ $vendor->name }}&quot;? Nothing is filed against them, so nothing else is affected — but this cannot be undone."
                                          data-confirm-title="Delete vendor"
                                          data-confirm-ok="Delete"
                                          data-confirm-variant="danger">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="Delete vendor">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

    @if($vendors->hasPages())
    <div class="mt-3">{{ $vendors->links() }}</div>
    @endif
</div>

{{-- In-app confirmation dialog (replaces native confirm()) for activate/deactivate. --}}
@include('partials.confirm-modal')
@endsection
