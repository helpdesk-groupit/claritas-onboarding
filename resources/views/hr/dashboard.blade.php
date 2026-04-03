@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')

{{-- Welcome Banner --}}
@php
    $dashUser = Auth::user();
    $dashName = $dashUser->employee?->full_name ?? $dashUser->name;
    $dashDesig = $dashUser->employee?->designation ?? ucwords(str_replace('_',' ',$dashUser->role));
    $dashCompany = $dashUser->employee?->company;
@endphp
<div class="card mb-4" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);border:none;">
    <div class="card-body d-flex align-items-center gap-3 py-3">
        <div style="width:52px;height:52px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-person-fill" style="font-size:26px;color:#fff;"></i>
        </div>
        <div>
            <h5 class="text-white mb-0 fw-bold">Welcome, {{ $dashName }}</h5>
            <small style="color:rgba(255,255,255,0.75);">{{ $dashDesig }}{{ $dashCompany ? ' · '.$dashCompany : '' }}</small>
        </div>
    </div>
</div>

@include('partials.birthday-babies-widget')

@include('partials.announcements-widget')

{{-- ── ONBOARDING OVERVIEW CARDS ──────────────────────────────────────── --}}
<div class="mb-2">
    <small class="text-muted fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;">
        <i class="bi bi-person-plus me-1"></i>Onboarding Overview
    </small>
</div>
<div class="row g-3 mb-4">

    {{-- 1. Total Onboard Year to Date --}}
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #2563eb;min-height:210px;">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:46px;height:46px;background:#dbeafe;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-person-plus" style="font-size:20px;color:#2563eb;"></i>
                    </div>
                    <div>
                        <div style="font-size:28px;font-weight:700;color:#1e293b;line-height:1;">{{ $stats['total_onboardings_ytd'] }}</div>
                        <div class="text-muted small">Total Onboard Year to Date</div>
                    </div>
                </div>
                <div class="flex-fill">
                    <div class="text-muted mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;">By Company</div>
                    @forelse($onboardingsByCompany as $row)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:12px;">{{ $row->company }}</span>
                        <span class="badge bg-primary" style="font-size:11px;">{{ $row->total }}</span>
                    </div>
                    @empty
                    <div class="text-muted small">No data yet</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- 2. New Joiners This Month --}}
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #f59e0b;min-height:210px;">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:46px;height:46px;background:#fef3c7;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-calendar-plus" style="font-size:20px;color:#f59e0b;"></i>
                    </div>
                    <div>
                        <div style="font-size:28px;font-weight:700;color:#1e293b;line-height:1;">{{ $stats['new_joiners_this_month'] }}</div>
                        <div class="text-muted small">New Joiners This Month</div>
                    </div>
                </div>
                <div class="flex-fill">
                    <div class="text-muted mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;">By Company</div>
                    @forelse($newJoinersByCompany as $row)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:12px;">{{ $row->company }}</span>
                        <span class="badge" style="background:#f59e0b;font-size:11px;">{{ $row->total }}</span>
                    </div>
                    @empty
                    <div class="text-muted small">No new joiners this month</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- 3. Exiting This Month --}}
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #ef4444;min-height:210px;">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:46px;height:46px;background:#fee2e2;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-calendar-x" style="font-size:20px;color:#ef4444;"></i>
                    </div>
                    <div>
                        <div style="font-size:28px;font-weight:700;color:#1e293b;line-height:1;">{{ $stats['exiting_this_month'] }}</div>
                        <div class="text-muted small">Exiting This Month</div>
                    </div>
                </div>
                <div class="flex-fill">
                    <div class="text-muted mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;">By Company</div>
                    @forelse($exitingByCompany as $row)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:12px;">{{ $row->company ?? 'Unknown' }}</span>
                        <span class="badge bg-danger" style="font-size:11px;">{{ $row->total }}</span>
                    </div>
                    @empty
                    <div class="text-muted small">No exits this month</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- 4. Active Employees — company breakdown only, no filter --}}
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #16a34a;min-height:210px;">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:46px;height:46px;background:#dcfce7;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-people" style="font-size:20px;color:#16a34a;"></i>
                    </div>
                    <div>
                        <div style="font-size:28px;font-weight:700;color:#1e293b;line-height:1;">{{ $stats['active_employees'] }}</div>
                        <div class="text-muted small">Active Employees</div>
                    </div>
                </div>
                <div class="flex-fill">
                    <div class="text-muted mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;">By Company</div>
                    @forelse($activeByCompany as $row)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:12px;">{{ $row->company }}</span>
                        <span class="badge bg-success" style="font-size:11px;">{{ $row->total }}</span>
                    </div>
                    @empty
                    <div class="text-muted small">No active employees</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ── ASSET OVERVIEW CARDS (superadmin only) ───────────────────────────── --}}
@if(Auth::user()->isSuperadmin())
<div class="mb-2 mt-2"><small class="text-muted fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;"><i class="bi bi-laptop me-1"></i>Asset Overview</small></div>
<div class="row g-3 mb-4">

    {{-- Card 1: Overall Assets --}}
    <div class="col-md-4">
        <div class="card h-100" style="border-left:4px solid #2563eb;min-height:210px;">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:46px;height:46px;background:#dbeafe;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-laptop" style="font-size:20px;color:#2563eb;"></i>
                    </div>
                    <div>
                        <div style="font-size:28px;font-weight:700;line-height:1;">{{ $assetStats['total_assets'] }}</div>
                        <div class="text-muted small">Overall Assets</div>
                    </div>
                    <div class="ms-auto text-end">
                        <span class="badge bg-success">{{ $assetStats['available'] }} Available</span><br>
                        <span class="badge bg-primary mt-1">{{ $assetStats['assigned'] }} Assigned</span>
                    </div>
                </div>
                <div class="flex-fill">
                    <div class="text-muted mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;">By Type</div>
                    @forelse($assetsByType as $row)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:12px;">{{ ucfirst(str_replace('_',' ', $row->asset_type)) }}</span>
                        <span class="badge bg-primary" style="font-size:11px;">{{ $row->total }}</span>
                    </div>
                    @empty
                    <div class="text-muted small">No assets</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Card 2: Company Owned --}}
    <div class="col-md-4">
        <div class="card h-100" style="border-left:4px solid #16a34a;min-height:210px;">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:46px;height:46px;background:#dcfce7;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-building" style="font-size:20px;color:#16a34a;"></i>
                    </div>
                    <div>
                        <div style="font-size:28px;font-weight:700;line-height:1;">{{ $companyOwnedTotal }}</div>
                        <div class="text-muted small">Company Owned</div>
                    </div>
                </div>
                <div class="flex-fill">
                    <div class="text-muted mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;">By Company</div>
                    @forelse($companyOwnedByCompany as $row)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:12px;">{{ $row->company }}</span>
                        <span class="badge bg-success" style="font-size:11px;">{{ $row->total }}</span>
                    </div>
                    @empty
                    <div class="text-muted small">No company-owned assets</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Card 3: Rental --}}
    <div class="col-md-4">
        <div class="card h-100" style="border-left:4px solid #f59e0b;min-height:210px;">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:46px;height:46px;background:#fef3c7;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-truck" style="font-size:20px;color:#f59e0b;"></i>
                    </div>
                    <div>
                        <div style="font-size:28px;font-weight:700;line-height:1;">{{ $rentalTotal }}</div>
                        <div class="text-muted small">Rental / Leased</div>
                    </div>
                </div>
                <div class="flex-fill">
                    <div class="text-muted mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;">By Vendor</div>
                    @forelse($rentalByVendor as $row)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:12px;">{{ $row->vendor }}</span>
                        <span class="badge" style="background:#f59e0b;font-size:11px;">{{ $row->total }}</span>
                    </div>
                    @empty
                    <div class="text-muted small">No rental assets</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

</div>
@endif

{{-- ── CLAIM & LEAVE WIDGETS (hidden — do not remove, re-enable later) ──── --}}
{{--
<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div style="width:48px;height:48px;background:#dbeafe;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-receipt" style="font-size:24px;color:#2563eb;"></i>
                </div>
                <div><h6 class="mb-0 fw-bold">Claim Calculator</h6><small class="text-muted">Submit and track expense claims</small></div>
            </div>
            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#claimModal">
                <i class="bi bi-plus-circle me-2"></i>Claim Calculator
            </button>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div style="width:48px;height:48px;background:#dcfce7;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-calendar-check" style="font-size:24px;color:#16a34a;"></i>
                </div>
                <div><h6 class="mb-0 fw-bold">Leave Calculator</h6><small class="text-muted">Check balance & plan leave</small></div>
            </div>
            <button class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#leaveModal">
                <i class="bi bi-calendar3 me-2"></i>Leave Calculator
            </button>
        </div></div>
    </div>
</div>
--}}

@include('partials.claim-modal')
@include('partials.leave-modal')
@endsection