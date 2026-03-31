@extends('layouts.app')

@section('title', 'Re-acknowledge Declaration & Consent')
@section('page-title', 'Declaration & Consent')

@section('content')
@php
$p = $onboarding->personalDetail;
$w = $onboarding->workDetail;
@endphp

<div class="row justify-content-center">
<div class="col-lg-8">

{{-- Already acknowledged --}}
@if($editLog->isAcknowledged())
<div class="card mb-3">
    <div class="card-body text-center py-5">
        <i class="bi bi-check-circle-fill text-success" style="font-size:48px;"></i>
        <h5 class="mt-3 fw-bold">Already Acknowledged</h5>
        <p class="text-muted">
            You acknowledged this consent on
            <strong>{{ $editLog->acknowledged_at->format('d M Y, h:i A') }}</strong>.
        </p>
        <a href="{{ route('user.dashboard') }}" class="btn btn-outline-primary btn-sm">Go to Dashboard</a>
    </div>
</div>

{{-- Token expired --}}
@elseif($editLog->isTokenExpired())
<div class="card mb-3">
    <div class="card-body text-center py-5">
        <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:48px;"></i>
        <h5 class="mt-3 fw-bold">Link Expired</h5>
        <p class="text-muted">This consent link has expired. Please contact HR to resend the acknowledgement request.</p>
        <a href="{{ route('user.dashboard') }}" class="btn btn-outline-secondary btn-sm">Go to Dashboard</a>
    </div>
</div>

{{-- Consent form --}}
@else

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- What was changed --}}
@if(!empty($editLog->sections_changed))
<div class="card mb-3" style="border-left:4px solid #f59e0b;">
    <div class="card-body">
        <h6 class="fw-bold mb-2"><i class="bi bi-info-circle me-2 text-warning"></i>Your information was updated by HR</h6>
        <p class="text-muted small mb-2">The following sections were modified on <strong>{{ $editLog->created_at->format('d M Y, h:i A') }}</strong> by <strong>{{ $editLog->edited_by_name }}</strong>:</p>
        <ul class="mb-0" style="font-size:13.5px;">
            @foreach($editLog->sections_changed as $section)
            <li>{{ $section }}</li>
            @endforeach
        </ul>
        @if($editLog->change_notes)
        <p class="mt-2 mb-0 text-muted small"><strong>Note from HR:</strong> {{ $editLog->change_notes }}</p>
        @endif
    </div>
</div>
@endif

{{-- PDPA Consent text --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Declaration &amp; Consent</h6>
    </div>
    <div class="card-body">
        <div class="p-3 rounded mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;font-size:13.5px;line-height:1.8;">
            <p class="fw-semibold mb-2">Personal Data Protection Act (PDPA) 2010 — Consent</p>
            <p class="mb-2">I hereby declare that all information provided above is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disciplinary action or termination of employment.</p>
            <p class="mb-2">I consent to the collection, use, and disclosure of my personal data by the Company for purposes related to employment administration, payroll processing, statutory contributions and deductions (EPF, SOCSO, EIS, PCB), and employee benefits management, in compliance with the Personal Data Protection Act (PDPA) 2010 of Malaysia.</p>
            <p class="mb-0">I also agree to promptly notify the HRA Department of any changes to the information provided above, including updates to my contact details, banking information, or personal particulars.</p>
        </div>

        <form method="POST" action="{{ route('onboarding.re-consent.store', $onboarding) }}">
            @csrf
            <input type="hidden" name="token" value="{{ $editLog->consent_token }}">
            <input type="hidden" name="edit_log_id" value="{{ $editLog->id }}">

            <div class="mb-3">
                <label class="form-label fw-semibold small">Notes (optional)</label>
                <textarea name="acknowledgement_notes" class="form-control" rows="2"
                          placeholder="Any remarks or comments..."></textarea>
            </div>

            <div class="d-flex align-items-center justify-content-between">
                <small class="text-muted">
                    <i class="bi bi-clock me-1"></i>
                    @if($editLog->consent_token_expires_at)
                    Link expires {{ $editLog->consent_token_expires_at->format('d M Y, h:i A') }}
                    @endif
                </small>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-circle me-2"></i>I Acknowledge
                </button>
            </div>
        </form>
    </div>
</div>

@endif

</div>
</div>
@endsection
