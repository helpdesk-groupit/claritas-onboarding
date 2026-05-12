@extends('layouts.app')

@section('title', 'Edit Ticket ' . $ticket->ticket_number)
@section('page-title', 'Edit Ticket ' . $ticket->ticket_number)

@section('content')
<div class="card" style="max-width:760px;">
    <div class="card-body">
        <h5 class="fw-semibold mb-1">
            <i class="bi bi-pencil-square me-1"></i> Edit Ticket
            <span class="badge bg-light text-dark border ms-1">{{ $ticket->ticket_number }}</span>
        </h5>
        <p class="text-muted small mb-3">
            Use this when a ticket was filed under the wrong department. Changing the department clears
            the current PIC, resets status to <strong>Open</strong>, and sends a "new ticket" email + bell
            to managers of the new department. All other fields are read-only — only Department and Reason
            can be changed.
        </p>

        @if($errors->any())
            <div class="alert alert-danger small">
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('tickets.update-admin', $ticket) }}" id="ticketEditAdminForm">
            @csrf
            @method('PUT')

            {{-- Row 1: Company + Priority (read-only) --}}
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Company</label>
                    <select class="form-select" disabled>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}" @selected($ticket->company_id == $company->id)>
                                {{ $company->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Priority</label>
                    <select class="form-select" disabled>
                        @foreach($priorities as $p)
                            <option value="{{ $p }}" @selected($ticket->priority === $p)>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Row 2: Department (the only editable field besides Reason) --}}
            <div class="mb-3 mt-3">
                <label class="form-label small fw-semibold">
                    Department <span class="text-danger">*</span>
                    <span class="badge bg-warning-subtle text-warning-emphasis ms-1" style="font-size:10px;">Editable</span>
                </label>
                <select name="department" class="form-select" required>
                    @foreach($departments as $d)
                        <option value="{{ $d }}" @selected(old('department', $ticket->department) === $d)>{{ $d }}</option>
                    @endforeach
                </select>
                <small class="text-muted d-block mt-1" style="font-size:11px;">
                    <i class="bi bi-info-circle me-1"></i>Pick the department this ticket should have been raised under.
                    Changing this re-routes the ticket and notifies the new dept's managers.
                </small>
            </div>

            {{-- Row 3: Subject (read-only) --}}
            <div class="mb-3 mt-3">
                <label class="form-label small fw-semibold">Subject</label>
                <input type="text" class="form-control" value="{{ $ticket->subject }}" disabled>
            </div>

            {{-- Row 4: Description (read-only) --}}
            <div class="mb-3 mt-3">
                <label class="form-label small fw-semibold">Description</label>
                <textarea rows="6" class="form-control" disabled>{{ $ticket->description }}</textarea>
            </div>

            {{-- Row 5: Existing attachments (read-only, listed if any) --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Attachments
                    <span class="text-muted fw-normal" style="font-size:11px;">(read-only)</span>
                </label>
                @if($ticket->attachments->isNotEmpty())
                    <div class="d-flex flex-column gap-1 border rounded p-2" style="background:#f8fafc;">
                        @foreach($ticket->attachments as $att)
                            <a href="{{ $att->url() }}" target="_blank" rel="noopener"
                               class="d-flex align-items-center gap-2 text-decoration-none small"
                               style="color:#1e293b; padding:4px 6px; border-radius:6px;">
                                <i class="bi {{ $att->is_image ? 'bi-image' : 'bi-file-earmark-pdf' }} text-primary"></i>
                                <span class="text-truncate flex-grow-1" title="{{ $att->original_name }}">{{ $att->original_name }}</span>
                                <span class="text-muted" style="font-size:11px;">{{ $att->humanSize() }}</span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <input type="text" class="form-control" value="No attachments" disabled>
                @endif
            </div>

            {{-- Row 6: Reason for edit (saved to log) --}}
            <div class="mb-3 mt-3">
                <label class="form-label small fw-semibold">
                    Reason
                    <span class="text-muted fw-normal" style="font-size:11px;">(optional — saved in the edit log)</span>
                    <span class="badge bg-warning-subtle text-warning-emphasis ms-1" style="font-size:10px;">Editable</span>
                </label>
                <input type="text" name="note" class="form-control" maxlength="1000"
                       value="{{ old('note') }}"
                       placeholder="e.g. Raiser picked KOL — should have been Group IT">
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="{{ route('tickets.show', ['ticket' => $ticket, 'from' => 'manage']) }}" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary" id="ticketEditAdminSubmit">
                    <i class="bi bi-save me-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    'use strict';
    // Same double-submit guard as the create form.
    var form = document.getElementById('ticketEditAdminForm');
    var btn  = document.getElementById('ticketEditAdminSubmit');
    var sending = false;
    if (form && btn) {
        form.addEventListener('submit', function (e) {
            if (sending) { e.preventDefault(); return; }
            sending = true;
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving…';
        });
    }
})();
</script>
@endpush
