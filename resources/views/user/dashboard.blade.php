@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')

{{-- Welcome Banner --}}
@php $p = $employee?->onboarding?->personalDetail; $w = $employee?->onboarding?->workDetail; @endphp
<div class="card mb-4" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);border:none;">
    <div class="card-body d-flex align-items-center gap-3 py-3">
        <div style="width:52px;height:52px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-person-fill" style="font-size:26px;color:#fff;"></i>
        </div>
        <div>
            <h5 class="text-white mb-0 fw-bold">Welcome, {{ $p?->full_name ?? $user->name }}</h5>
            <small style="color:rgba(255,255,255,0.75);">{{ $w?->designation ?? 'Employee' }}{{ $w?->company ? ' · '.$w->company : '' }}</small>
        </div>
    </div>
</div>

{{-- Claim & Leave calculators — hidden for designation-only roles --}}
@php
    $hideCalculators = in_array($user->employee?->work_role ?? '', [
        'manager','senior_executive','executive_associate','director_hod','others'
    ]);
@endphp

@if(!$hideCalculators)
{{-- Claim Calculator Widget --}}
<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:48px;height:48px;background:#dbeafe;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-receipt" style="font-size:24px;color:#2563eb;"></i>
                    </div>
                    <div><h6 class="mb-0 fw-bold">Claim Calculator</h6><small class="text-muted">Submit and track expense claims</small></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4 text-center p-2 rounded" style="background:#f8fafc;"><div class="fw-bold text-primary">RM 0</div><div class="text-muted" style="font-size:11px;">This Month</div></div>
                    <div class="col-4 text-center p-2 rounded" style="background:#f8fafc;"><div class="fw-bold text-success">RM 0</div><div class="text-muted" style="font-size:11px;">Approved</div></div>
                    <div class="col-4 text-center p-2 rounded" style="background:#f8fafc;"><div class="fw-bold text-warning">RM 0</div><div class="text-muted" style="font-size:11px;">Pending</div></div>
                </div>
                <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#claimModal">
                    <i class="bi bi-plus-circle me-2"></i>Claim Calculator
                </button>
            </div>
        </div>
    </div>

    {{-- Leave Calculator Widget --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:48px;height:48px;background:#dcfce7;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-calendar-check" style="font-size:24px;color:#16a34a;"></i>
                    </div>
                    <div><h6 class="mb-0 fw-bold">Leave Calculator</h6><small class="text-muted">Check balance & plan leave</small></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4 text-center p-2 rounded" style="background:#f8fafc;"><div class="fw-bold text-success">14</div><div class="text-muted" style="font-size:11px;">Entitlement</div></div>
                    <div class="col-4 text-center p-2 rounded" style="background:#f8fafc;"><div class="fw-bold text-warning">0</div><div class="text-muted" style="font-size:11px;">Taken</div></div>
                    <div class="col-4 text-center p-2 rounded" style="background:#f8fafc;"><div class="fw-bold text-primary">14</div><div class="text-muted" style="font-size:11px;">Remaining</div></div>
                </div>
                <button class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#leaveModal">
                    <i class="bi bi-calendar3 me-2"></i>Leave Calculator
                </button>
            </div>
        </div>
    </div>
</div>

@include('partials.claim-modal')
@include('partials.leave-modal')
@endif
@endsection