@extends('layouts.app')

@section('title', 'AARF - ' . $aarf->aarf_reference)
@section('page-title', 'Asset Acceptance & Return Form')

@section('content')
<div class="d-flex gap-2 mb-3">
    <a href="{{ route('onboarding.show', $aarf->onboarding) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Record
    </a>
    @if($aarf->acknowledgement_token)
    <a href="{{ route('aarf.view', $aarf->acknowledgement_token) }}" target="_blank" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-box-arrow-up-right me-1"></i>Open Public AARF Link
    </a>
    @endif
</div>

<div class="card">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-check me-2 text-primary"></i>{{ $aarf->aarf_reference }}</h6>
        <span class="badge {{ $aarf->acknowledged ? 'bg-success' : 'bg-warning text-dark' }} fs-6">
            {{ $aarf->acknowledged ? 'Acknowledged' : 'Pending Acknowledgement' }}
        </span>
    </div>
    <div class="card-body">
        @php
        $p = $aarf->onboarding->personalDetail;
        $w = $aarf->onboarding->workDetail;
        @endphp

        <div class="row mb-4">
            <div class="col-md-6">
                <h6 class="text-muted fw-semibold mb-2">Employee</h6>
                <p class="mb-1"><strong>{{ $p?->full_name }}</strong></p>
                <p class="mb-1 text-muted">{{ $w?->designation }} — {{ $w?->company }}</p>
                <p class="mb-0 text-muted">Start: {{ $w?->start_date?->format('d M Y') }}</p>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted fw-semibold mb-2">Acknowledgement Status</h6>
                @if($aarf->acknowledged)
                    <p class="text-success mb-0"><i class="bi bi-check-circle-fill me-1"></i>Acknowledged on {{ $aarf->acknowledged_at?->format('d M Y, h:i A') }}</p>
                @else
                    <p class="text-warning mb-0"><i class="bi bi-clock me-1"></i>Awaiting acknowledgement from new hire</p>
                    <small class="text-muted">Share the public link with the new hire via email</small>
                @endif
            </div>
        </div>

        <h6 class="fw-bold mb-3">Assigned Assets</h6>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead style="background:#f8fafc;">
                    <tr><th>Asset Tag</th><th>Asset Name</th><th>Type</th><th>Brand/Model</th><th>Serial</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($aarf->onboarding->assetAssignments as $assign)
                    <tr>
                        <td><code>{{ $assign->asset?->asset_tag }}</code></td>
                        <td>{{ $assign->asset?->asset_tag }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $assign->asset?->asset_type)) }}</td>
                        <td>{{ $assign->asset?->brand }} {{ $assign->asset?->model }}</td>
                        <td>{{ $assign->asset?->serial_number }}</td>
                        <td><span class="badge {{ $assign->status === 'assigned' ? 'bg-success' : 'bg-secondary' }}">{{ ucfirst($assign->status) }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection