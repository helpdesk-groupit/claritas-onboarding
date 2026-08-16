@extends('layouts.app')

@section('title', 'E-Waste Approvers')

@section('content')
@include('partials.decommission-ui-style')

<div class="container-fluid px-4 py-4">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-shield-check me-2 text-primary"></i>E-Waste Approvers</h4>
            <p class="text-muted small mb-0">
                Who, in management, may authorise an e-waste disposal — per company.
            </p>
        </div>
        <a href="{{ route('assets.index', ['tab' => 'damaged']) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Decommissioning queue
        </a>
    </div>

    {{-- Why this screen exists at all, in one paragraph. Without it the natural assumption is
         that a role could do the job, and the next person "simplifies" it into one. --}}
    <div class="alert alert-light border small">
        <i class="bi bi-info-circle me-1"></i>
        Approvers are named people, not a role: the same person is often CEO of one company and an
        officer of another, and an employee record carries only one company. <strong>The management
        decision is the one that authorises a disposal</strong> — Finance may leave optional remarks on
        the same comparison, but does not approve or reject it and cannot release assets on its own.
        Where several people are named for one company, the <strong>first decision counts</strong>.
    </div>

    {{-- The list has a SECOND reader (see EwasteCompanyApprover). Somebody editing this screen
         is changing two things at once, and the one they did not come here for is the one that
         fails silently — a name removed here also stops that person's AARF copies, with nothing
         on the AARF side to say so. --}}
    <div class="alert alert-warning border small">
        <i class="bi bi-envelope-check me-1"></i>
        These names are <strong>also who receives the signed Asset Acceptance &amp; Return Form
        (AARF)</strong> for that company, alongside the vendor's PIC, IT and Finance. Naming
        somebody here grants disposal authority <em>and</em> puts them on the AARF copy list;
        removing them takes away both.
    </div>

    <form method="POST" action="{{ route('superadmin.ewaste-approvers.update') }}">@csrf
        <div class="row g-3">
            @forelse($companies as $company)
                @php $picked = $assigned[$company->name] ?? []; @endphp
                <div class="col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <span class="fw-semibold"><i class="bi bi-building me-2 text-primary"></i>{{ $company->name }}</span>
                            @if(empty($picked))
                                <span class="badge bg-warning text-dark">No approver named</span>
                            @else
                                <span class="badge bg-success">{{ count($picked) }} named</span>
                            @endif
                        </div>
                        <div class="card-body">
                            <label class="form-label small fw-semibold text-muted text-uppercase">Approvers</label>
                            <select name="approvers[{{ $company->name }}][]" class="form-select" multiple size="8">
                                @foreach($candidates as $user)
                                    <option value="{{ $user->id }}" {{ in_array($user->id, $picked) ? 'selected' : '' }}>
                                        {{ $user->name }}@if($user->work_email) — {{ $user->work_email }}@endif
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Ctrl/Cmd-click to select several. Leaving this empty falls back to superadmin
                                approval, so a disposal is never left with nobody able to sign it.
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="alert alert-warning mb-0">
                        No companies are registered, so there is nothing to configure yet.
                    </div>
                </div>
            @endforelse
        </div>

        @if($companies->isNotEmpty())
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save approvers</button>
            </div>
        @endif
    </form>
</div>
@endsection
