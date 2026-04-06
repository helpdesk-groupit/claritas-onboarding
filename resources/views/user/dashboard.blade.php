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

@include('partials.birthday-babies-widget')

@include('partials.announcements-widget')

@include('partials.on-leave-widget')

@endsection