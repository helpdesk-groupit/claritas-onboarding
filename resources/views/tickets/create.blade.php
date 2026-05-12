@extends('layouts.app')

@section('title', 'New Ticket')
@section('page-title', 'New Ticket')

@section('content')
<div class="card" style="max-width:760px;">
    <div class="card-body">
        <h5 class="fw-semibold mb-3"><i class="bi bi-ticket-perforated me-1"></i> Raise a New Ticket</h5>

        @if($errors->any())
            <div class="alert alert-danger small">
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data" id="ticketCreateForm">
            @csrf

            {{-- Row 1: Company + Priority --}}
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Company <span class="text-danger">*</span></label>
                    <select name="company_id" id="ticketCompany" class="form-select" required autofocus>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}"
                                    @selected(old('company_id', $defaultCompanyId) == $company->id)>
                                {{ $company->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Priority <span class="text-danger">*</span></label>
                    <select name="priority" class="form-select" required>
                        @foreach($priorities as $p)
                            <option value="{{ $p }}" @selected(old('priority', 'Medium') === $p)>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Row 2: Department (filtered by company) --}}
            <div class="mb-3 mt-3">
                <label class="form-label small fw-semibold">Department <span class="text-danger">*</span></label>
                <select name="department" id="ticketDepartment" class="form-select" required>
                    <option value="">Select department…</option>
                </select>
                <small class="text-muted d-block mt-1" style="font-size:11px;">
                    <i class="bi bi-info-circle me-1"></i>Departments are filtered by the selected company.
                </small>
            </div>

            {{-- Row 3: Subject (filtered by department) --}}
            <div class="mb-3 mt-3">
                <label class="form-label small fw-semibold">Subject <span class="text-danger">*</span></label>
                <select name="subject" id="ticketSubject" class="form-select" required disabled>
                    <option value="">Select department first…</option>
                </select>
                <small class="text-muted d-block mt-1" id="subjectHint" style="display:none;font-size:11px;">
                    <i class="bi bi-info-circle me-1"></i>Pick the closest matching subject. Add detail in the description.
                </small>
            </div>

            {{-- Row 4: Subject "Other" custom text (shown only when subject = Other) --}}
            <div class="mb-3" id="subjectOtherWrap" style="display:none;">
                <label class="form-label small fw-semibold">Custom Subject <span class="text-danger">*</span></label>
                <input type="text" name="subject_other" id="subjectOther" class="form-control"
                       maxlength="255" value="{{ old('subject_other') }}"
                       placeholder="Briefly describe the issue (this will appear as the ticket subject).">
            </div>

            {{-- Row 5: Description --}}
            <div class="mb-3 mt-3">
                <label class="form-label small fw-semibold">Description <span class="text-danger">*</span></label>
                <textarea name="description" rows="6" class="form-control" maxlength="10000"
                          required placeholder="Describe the issue, what you've tried, and any deadlines.">{{ old('description') }}</textarea>
            </div>

            {{-- Row 6: Attachments (multi-file) --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Attachments
                    <span class="text-muted fw-normal">(optional, max 10 files, 10 MB each)</span>
                </label>
                <input type="file" name="attachments[]" id="ticketAttachments" class="form-control" multiple
                       accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
                <small class="text-muted d-block mt-1" style="font-size:11px;">
                    <i class="bi bi-shield-check me-1"></i>
                    Allowed: JPG, PNG, GIF, WEBP, PDF. Images are auto-compressed; all files are scanned (magic-byte check, EXIF strip) before being saved.
                </small>
                <div id="attachmentsPreview" class="mt-2"></div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="{{ route('tickets.index') }}" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary" id="ticketSubmitBtn">
                    <i class="bi bi-send me-1"></i> Submit Ticket
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

    var departmentsByCompany = @json($departmentsByCompany);
    var departmentSubjects   = @json($departmentSubjects);
    var oldDepartment        = @json(old('department'));
    var oldSubject           = @json(old('subject'));
    var oldSubjectOther      = @json(old('subject_other'));

    var companySel    = document.getElementById('ticketCompany');
    var deptSel       = document.getElementById('ticketDepartment');
    var subjectSel    = document.getElementById('ticketSubject');
    var subjectHint   = document.getElementById('subjectHint');
    var otherWrap     = document.getElementById('subjectOtherWrap');
    var otherInput    = document.getElementById('subjectOther');
    var fileInput     = document.getElementById('ticketAttachments');
    var preview       = document.getElementById('attachmentsPreview');

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    // ── Cascade: Company → Department ────────────────────────────────────
    function populateDepartments(companyId) {
        var depts = departmentsByCompany[companyId] || [];
        if (depts.length === 0) {
            deptSel.innerHTML = '<option value="">No departments serve this company</option>';
            populateSubjects('');
            return;
        }
        var html = '<option value="">Select department…</option>';
        depts.forEach(function (d) {
            var selected = (d === oldDepartment) ? ' selected' : '';
            html += '<option value="' + escapeHtml(d) + '"' + selected + '>' + escapeHtml(d) + '</option>';
        });
        deptSel.innerHTML = html;
        // Trigger subject re-population for the (possibly newly-selected) dept
        populateSubjects(deptSel.value);
    }

    // ── Cascade: Department → Subject ────────────────────────────────────
    function populateSubjects(dept) {
        var subjects = departmentSubjects[dept] || [];
        if (subjects.length === 0) {
            subjectSel.innerHTML = '<option value="">Select department first…</option>';
            subjectSel.disabled = true;
            subjectHint.style.display = 'none';
            toggleOther(false);
            return;
        }
        var html = '<option value="">Select subject…</option>';
        subjects.forEach(function (s) {
            var selected = (s === oldSubject) ? ' selected' : '';
            html += '<option value="' + escapeHtml(s) + '"' + selected + '>' + escapeHtml(s) + '</option>';
        });
        subjectSel.innerHTML = html;
        subjectSel.disabled = false;
        subjectHint.style.display = 'block';
        toggleOther(subjectSel.value === 'Other');
    }

    // ── Toggle the custom-subject text input when "Other" is selected ────
    function toggleOther(show) {
        otherWrap.style.display = show ? 'block' : 'none';
        otherInput.required = show;
        if (!show) otherInput.value = '';
    }

    // ── Live attachment preview ──────────────────────────────────────────
    fileInput.addEventListener('change', function () {
        preview.innerHTML = '';
        var files = Array.from(fileInput.files);
        if (files.length === 0) return;
        var html = '<div class="border rounded p-2" style="background:#f8fafc;">';
        files.forEach(function (f) {
            var sizeKB = Math.ceil(f.size / 1024);
            var icon = f.type.indexOf('image/') === 0 ? 'bi-image' : 'bi-file-earmark-pdf';
            html += '<div class="d-flex align-items-center gap-2 py-1" style="font-size:12px;">' +
                      '<i class="bi ' + icon + ' text-primary"></i>' +
                      '<span class="flex-grow-1 text-truncate">' + escapeHtml(f.name) + '</span>' +
                      '<span class="text-muted">' + sizeKB + ' KB</span>' +
                    '</div>';
        });
        html += '</div>';
        preview.innerHTML = html;
    });

    // ── Wire up cascade triggers ─────────────────────────────────────────
    companySel.addEventListener('change', function (e) { populateDepartments(e.target.value); });
    deptSel.addEventListener('change',    function (e) { populateSubjects(e.target.value); });
    subjectSel.addEventListener('change', function (e) { toggleOther(e.target.value === 'Other'); });

    // ── Initial population on page load ──────────────────────────────────
    if (companySel.value) {
        populateDepartments(companySel.value);
    }
    if (oldSubjectOther) {
        otherInput.value = oldSubjectOther;
    }

    // ── Double-submit guard ──────────────────────────────────────────────
    // A fast double-click on Submit was creating two near-identical tickets
    // with consecutive numbers. Disable the button on the first submit, swap
    // its label to a spinner, and short-circuit any subsequent submit events.
    // The disabled state automatically resets if the server returns the form
    // with validation errors (page reload re-renders fresh markup).
    var ticketForm = document.getElementById('ticketCreateForm');
    var submitBtn  = document.getElementById('ticketSubmitBtn');
    var submitting = false;
    if (ticketForm && submitBtn) {
        ticketForm.addEventListener('submit', function (e) {
            if (submitting) {
                e.preventDefault();
                return;
            }
            submitting = true;
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Submitting…';
        });
    }
})();
</script>
@endpush
