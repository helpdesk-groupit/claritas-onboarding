@extends('layouts.app')

@section('title', 'New Ticket')
@section('page-title', 'New Ticket')

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style nonce="{{ $cspNonce ?? '' }}">
    /* Tom Select sizing tweaks to match Bootstrap form-control */
    .ts-wrapper.form-select { padding: 0; }
    .ts-wrapper .ts-control { border-radius: var(--bs-border-radius); }

    /* Routing preview block */
    .ticket-routing {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 12px 14px;
        margin-top: 14px;
    }
    .ticket-routing .tr-head {
        font-size: 11px; color: #475569; text-transform: uppercase;
        letter-spacing: .5px; margin-bottom: 8px; font-weight: 600;
    }
    .ticket-routing .tr-row {
        display: flex; align-items: center; gap: 8px;
        margin: 4px 0; font-size: 13px;
    }
    .ticket-routing .tr-label {
        color: #64748b; font-weight: 600; min-width: 92px;
    }
    .ticket-routing .tr-arrow { color: #94a3b8; }
    .ticket-routing .tr-value { font-weight: 600; color: #1e293b; }
    .ticket-routing .tr-value.pending {
        color: #b45309; font-style: italic; font-weight: 500;
    }
    .ticket-routing .tr-auto-hint {
        font-size: 11px; color: #6b7280; font-style: italic;
        margin-left: 6px;
    }
    .ticket-routing .tr-change-btn {
        margin-left: auto; background: none; border: none;
        color: #2563eb; font-size: 12px; padding: 0;
        text-decoration: underline; cursor: pointer;
    }
    .ticket-routing .tr-change-btn:hover { color: #1d4ed8; }

    .ticket-routing .tr-blocked {
        margin-top: 10px;
        padding: 10px 12px;
        background: #fef3c7;
        border-left: 4px solid #f59e0b;
        border-radius: 6px;
        font-size: 12.5px;
        color: #78350f;
        line-height: 1.45;
    }
    .ticket-routing .tr-blocked i { margin-right: 6px; }

    [data-theme="dark"] .ticket-routing { background: #0f172a; border-color: #475569; }
    [data-theme="dark"] .ticket-routing .tr-value { color: #e2e8f0; }
    [data-theme="dark"] .ticket-routing .tr-label { color: #94a3b8; }
    [data-theme="dark"] .ticket-routing .tr-blocked { background: #422006; color: #fde68a; border-color: #b45309; }
</style>
@endpush

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

            {{-- Row 1: Subject (primary control, searchable).
                 Token format: "<Dept>::<Subject>" so duplicate subjects across
                 departments (e.g. "Brand Asset Request" → Design + Marketing)
                 each get a unique option. The actual subject text and dept are
                 carried on data-* attributes and copied to the hidden inputs
                 below on change. --}}
            @php
                // Reconstruct the picker token from old() inputs after a
                // validation redirect. "Other" stays as "Other"; everything
                // else is "Dept::Subject".
                $oldTok = old('subject') === 'Other'
                    ? 'Other'
                    : (old('department') && old('subject') ? old('department').'::'.old('subject') : '');
            @endphp
            <div class="mb-3">
                <label class="form-label small fw-semibold">Subject <span class="text-danger">*</span></label>
                <select id="ticketSubject" class="form-select" required>
                    <option value="">Type to search subjects…</option>
                    @foreach($departmentSubjects as $dept => $subjects)
                        <optgroup label="{{ $dept }}">
                            @foreach($subjects as $s)
                                @if($s !== 'Other')
                                    @php $tok = $dept.'::'.$s; @endphp
                                    <option value="{{ $tok }}"
                                            data-dept="{{ $dept }}"
                                            data-subject="{{ $s }}"
                                            @selected($oldTok === $tok)>{{ $s }}</option>
                                @endif
                            @endforeach
                        </optgroup>
                    @endforeach
                    <option value="Other"
                            data-dept=""
                            data-subject="Other"
                            @selected($oldTok === 'Other')>
                        Other / not sure
                    </option>
                </select>
                <input type="hidden" name="subject" id="subjectHidden" value="{{ old('subject') }}">
                <small class="text-muted d-block mt-1" style="font-size:11px;">
                    <i class="bi bi-info-circle me-1"></i>Start typing to filter. The system will route the ticket to the right department automatically.
                </small>
            </div>

            {{-- Row 2: Custom subject (shown only when "Other") --}}
            <div class="mb-3" id="subjectOtherWrap" style="display:none;">
                <label class="form-label small fw-semibold">Describe the subject <span class="text-danger">*</span></label>
                <input type="text" name="subject_other" id="subjectOther" class="form-control"
                       maxlength="255" value="{{ old('subject_other') }}"
                       placeholder="e.g. 'My laptop won't turn on'">
                <small class="text-muted d-block mt-1" style="font-size:11px;">
                    <i class="bi bi-magic me-1"></i>A few keywords are enough — we'll auto-detect the department from what you type.
                </small>
            </div>

            {{-- Row 3: Description --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Description <span class="text-danger">*</span></label>
                <textarea name="description" rows="6" class="form-control" maxlength="10000"
                          required placeholder="Describe the issue, what you've tried, and any deadlines.">{{ old('description') }}</textarea>
            </div>

            {{-- Row 4: Priority --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Priority <span class="text-danger">*</span></label>
                <select name="priority" class="form-select" required>
                    @foreach($priorities as $p)
                        <option value="{{ $p }}" @selected(old('priority', 'Medium') === $p)>{{ $p }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Row 5: Attachments --}}
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

            {{-- Row 6: Company (auto-set if employee record exists; editable fallback otherwise) --}}
            @if($autoCompanyId)
                <input type="hidden" name="company_id" id="ticketCompany" value="{{ $autoCompanyId }}">
            @else
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Company <span class="text-danger">*</span></label>
                    <select name="company_id" id="ticketCompany" class="form-select" required>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}"
                                    @selected(old('company_id', $defaultCompanyId) == $company->id)>
                                {{ $company->name }}
                            </option>
                        @endforeach
                    </select>
                    <small class="text-muted d-block mt-1" style="font-size:11px;">
                        <i class="bi bi-info-circle me-1"></i>No employee record found — pick which company this is for.
                    </small>
                </div>
            @endif

            {{-- Hidden routing fields, set by JS:
                  department         — the dept that should handle the ticket
                  service_company_id — the company whose team is the service
                                       provider (NOT the raiser's company). --}}
            <input type="hidden" name="department" id="ticketDepartment" value="{{ old('department') }}">
            <input type="hidden" name="service_company_id" id="ticketServiceCompany" value="{{ old('service_company_id') }}">

            {{-- Routing preview --}}
            <div class="ticket-routing">
                <div class="tr-head"><i class="bi bi-signpost-2 me-1"></i>Routing</div>
                <div class="tr-row">
                    <span class="tr-label">Department</span>
                    <i class="bi bi-arrow-right tr-arrow"></i>
                    <span class="tr-value pending" id="routingDept">Pick a subject…</span>
                </div>
                <div class="tr-row">
                    <span class="tr-label">Company</span>
                    <i class="bi bi-arrow-right tr-arrow"></i>
                    <span class="tr-value pending" id="routingService">—</span>
                    <span class="tr-auto-hint" id="routingAutoHint" style="display:none;">
                        (service provider)
                    </span>
                    <button type="button" class="tr-change-btn" id="routingChangeBtn" style="display:none;">
                        Change
                    </button>
                </div>

                {{-- Dead-end warning: dept is known but no team is configured
                     to handle it for this raiser. Shown in place of the
                     override picker (which would have zero options). --}}
                <div class="tr-blocked" id="routingBlockedMsg" style="display:none;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>No team is configured to handle <span id="blockedDeptName">this</span> tickets from your company.</strong>
                    Pick a different subject, or raise a ticket to <strong>Group IT</strong> to set up routing under
                    <em>Department Settings &rarr; Also serves these other companies</em>.
                </div>

                {{-- Manual service-provider picker (shown when ambiguous or
                     when user clicks Change). Each option is a
                     (service_company_id, department) pair so the user picks
                     the entire routing decision in one step. --}}
                <div class="mt-2" id="overrideWrap" style="display:none;">
                    <label class="form-label small fw-semibold mb-1" style="font-size:12px;">
                        Pick a service provider
                    </label>
                    <select id="overridePicker" class="form-select form-select-sm">
                        <option value="">— select a Company &gt; Department —</option>
                        @foreach($serviceOptions as $opt)
                            <option value="{{ $opt['company_id'] }}::{{ $opt['department'] }}"
                                    data-company-id="{{ $opt['company_id'] }}"
                                    data-company-name="{{ $opt['company_name'] }}"
                                    data-dept="{{ $opt['department'] }}">
                                {{ $opt['company_name'] }} &gt; {{ $opt['department'] }}
                            </option>
                        @endforeach
                    </select>
                    <small class="text-muted d-block mt-1" style="font-size:11px;">
                        <i class="bi bi-info-circle me-1"></i>Each option is a real team that can handle your ticket.
                    </small>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
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
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    'use strict';

    var subjectToDepartments  = @json($subjectToDepartments);
    var keywordHints          = @json($keywordHints);
    var resolvedServiceByDept = @json($resolvedServiceByDept);
    var serviceOptions        = @json($serviceOptions);
    var oldDepartment         = @json(old('department'));
    var oldServiceCompanyId   = @json(old('service_company_id'));
    var oldSubjectOther       = @json(old('subject_other'));

    var subjectSelEl       = document.getElementById('ticketSubject');
    var subjectHidden      = document.getElementById('subjectHidden');
    var subjectOtherWrap   = document.getElementById('subjectOtherWrap');
    var subjectOther       = document.getElementById('subjectOther');
    var deptHidden         = document.getElementById('ticketDepartment');
    var serviceHidden      = document.getElementById('ticketServiceCompany');
    var overrideWrap       = document.getElementById('overrideWrap');
    var overridePicker     = document.getElementById('overridePicker');
    var routingDept        = document.getElementById('routingDept');
    var routingService     = document.getElementById('routingService');
    var routingAutoHint    = document.getElementById('routingAutoHint');
    var routingChangeBtn   = document.getElementById('routingChangeBtn');
    var routingBlockedMsg  = document.getElementById('routingBlockedMsg');
    var blockedDeptName    = document.getElementById('blockedDeptName');
    var submitBtn          = document.getElementById('ticketSubmitBtn');
    var fileInput          = document.getElementById('ticketAttachments');
    var preview            = document.getElementById('attachmentsPreview');

    // Toggle the Submit button's enabled state based on whether routing has
    // resolved to a real (dept, service company) pair. Tooltip explains the
    // disabled state for assistive tech / hover.
    function syncSubmitEnabled() {
        var ok = !!(deptHidden.value && serviceHidden.value);
        submitBtn.disabled = !ok;
        submitBtn.title = ok ? '' : 'Routing not resolved — pick a subject the system can route.';
    }

    // Show or hide the dead-end warning banner for a specific dept.
    function setBlockedBanner(dept) {
        if (dept) {
            blockedDeptName.textContent = dept;
            routingBlockedMsg.style.display = 'block';
        } else {
            routingBlockedMsg.style.display = 'none';
        }
    }

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    // ── Tom Select on subject ───────────────────────────────────────────
    // Searchable picker with optgroups preserved. Uses Bootstrap 5 theme so
    // it visually matches the rest of the form.
    var subjectTS = new TomSelect(subjectSelEl, {
        placeholder: 'Type to search subjects…',
        allowEmptyOption: false,
        maxOptions: 200,
        searchField: ['text'],
        sortField: { field: '$order' },
    });

    // Helper: look up a service company's name by id from the serviceOptions
    // payload (each option carries company_name).
    function companyNameFor(id) {
        for (var i = 0; i < serviceOptions.length; i++) {
            if (String(serviceOptions[i].company_id) === String(id)) {
                return serviceOptions[i].company_name;
            }
        }
        return null;
    }

    // ── Set routing (dept + service company) and update preview ────────────
    // `mode` controls secondary UI:
    //   'exact'        — picked from optgroup AND single auto service co
    //   'auto'         — service co auto-resolved (show Change link)
    //   'manual'       — user picked via override picker
    //   'needs-pick'   — dept resolved but service co ambiguous; show picker
    //   'needs-dept'   — no dept resolved yet (e.g. Other w/ no keyword match)
    //   'empty'        — nothing chosen
    function setRouting(dept, serviceCompanyId, mode) {
        deptHidden.value    = dept || '';
        serviceHidden.value = serviceCompanyId || '';

        routingDept.classList.toggle('pending', !dept);
        routingService.classList.toggle('pending', !serviceCompanyId);

        if (dept) {
            routingDept.textContent = dept;
        } else if (mode === 'needs-dept') {
            routingDept.textContent = "Couldn't infer — pick below";
        } else {
            routingDept.textContent = 'Pick a subject…';
        }

        if (serviceCompanyId) {
            routingService.textContent = companyNameFor(serviceCompanyId) || '(unknown)';
        } else if (mode === 'needs-pick') {
            routingService.textContent = 'Multiple options — pick below';
        } else if (dept) {
            routingService.textContent = 'No provider configured';
        } else {
            routingService.textContent = '—';
        }

        var showAutoHint = (mode === 'auto' || mode === 'exact') && serviceCompanyId;
        routingAutoHint.style.display  = showAutoHint ? 'inline' : 'none';
        routingChangeBtn.style.display = showAutoHint ? 'inline-block' : 'none';

        syncSubmitEnabled();
    }

    // Filter the override picker to options for a specific dept, or show all.
    function filterOverridePicker(dept) {
        var options = overridePicker.options;
        for (var i = 0; i < options.length; i++) {
            var opt = options[i];
            if (!opt.value) { opt.hidden = false; continue; }
            var optDept = opt.getAttribute('data-dept');
            opt.hidden = dept ? (optDept !== dept) : false;
        }
        // If current value is now hidden, reset to placeholder.
        if (overridePicker.selectedIndex >= 0 && options[overridePicker.selectedIndex].hidden) {
            overridePicker.value = '';
        }
    }

    // ── Client-side keyword inference (mirrors Ticket::inferDepartmentFromText)
    function inferDeptFromText(text) {
        var lower = (text || '').toLowerCase().trim();
        if (!lower) return null;
        for (var dept in keywordHints) {
            if (!Object.prototype.hasOwnProperty.call(keywordHints, dept)) continue;
            var kws = keywordHints[dept];
            for (var i = 0; i < kws.length; i++) {
                if (lower.indexOf(kws[i]) !== -1) return dept;
            }
        }
        return null;
    }

    // Resolves dept → service company id using the pre-computed map. Returns
    // null when ambiguous (multiple candidates) or no provider configured.
    function autoServiceFor(dept) {
        if (!dept) return null;
        var resolved = resolvedServiceByDept[dept];
        return (resolved === null || resolved === undefined) ? null : resolved;
    }

    // Returns true if at least one service option exists for this dept.
    function hasAnyServiceOption(dept) {
        for (var i = 0; i < serviceOptions.length; i++) {
            if (serviceOptions[i].department === dept) return true;
        }
        return false;
    }

    // ── Handle subject change ────────────────────────────────────────────
    function handleSubjectChange() {
        var token = subjectSelEl.value;  // "Dept::Subject" or "Other" or ""
        var selectedOption = subjectSelEl.options[subjectSelEl.selectedIndex];
        var dataDept    = selectedOption ? (selectedOption.getAttribute('data-dept') || '') : '';
        var dataSubject = selectedOption ? (selectedOption.getAttribute('data-subject') || '') : '';

        subjectHidden.value = dataSubject;

        var isOther = (token === 'Other');
        subjectOtherWrap.style.display = isOther ? 'block' : 'none';
        subjectOther.required = isOther;

        // Clear any prior dead-end warning; applyDept() will re-show it if
        // the new subject also routes to an unserviceable dept.
        setBlockedBanner(null);

        if (token === '') {
            setRouting(null, null, 'empty');
            overrideWrap.style.display = 'none';
            return;
        }

        if (isOther) {
            var inferred = inferDeptFromText(subjectOther.value);
            if (inferred) {
                applyDept(inferred, 'auto');
            } else if (subjectOther.value.trim() !== '') {
                setRouting(null, null, 'needs-dept');
                filterOverridePicker(null);
                overrideWrap.style.display = 'block';
            } else {
                setRouting(null, null, 'empty');
                overrideWrap.style.display = 'none';
            }
            return;
        }

        // Standardised subject — dept comes from the option's optgroup
        if (dataDept) {
            applyDept(dataDept, 'exact');
        } else {
            setRouting(null, null, 'empty');
            overrideWrap.style.display = 'none';
        }
    }

    // Given a known dept, resolve the service company (or surface picker).
    function applyDept(dept, baseMode) {
        var svc = autoServiceFor(dept);
        if (svc) {
            // Auto-resolved to a single service provider — done.
            setRouting(dept, svc, baseMode === 'exact' ? 'exact' : 'auto');
            overrideWrap.style.display = 'none';
            overridePicker.value = '';
            setBlockedBanner(null);
            return;
        }

        // No auto-resolution. If options exist for this dept, ask the user;
        // otherwise this is a dead end — no team handles this dept for the
        // raiser's company. Disable Submit and show the inline warning.
        if (hasAnyServiceOption(dept)) {
            setRouting(dept, null, 'needs-pick');
            filterOverridePicker(dept);
            overrideWrap.style.display = 'block';
            setBlockedBanner(null);
        } else {
            setRouting(dept, null, baseMode);
            overrideWrap.style.display = 'none';
            setBlockedBanner(dept);
        }
    }

    // ── Debounced re-inference as user types in the Other field ──────────
    var debounceTimer;
    subjectOther.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(handleSubjectChange, 300);
    });

    // ── Change button → reveal the override picker manually ──────────────
    routingChangeBtn.addEventListener('click', function () {
        var visible = overrideWrap.style.display === 'block';
        if (visible) {
            overrideWrap.style.display = 'none';
            return;
        }
        // Filter to the current dept if one is set; if not, show all options.
        filterOverridePicker(deptHidden.value || null);
        overrideWrap.style.display = 'block';
        // Pre-select the current routing if it matches an option.
        if (deptHidden.value && serviceHidden.value) {
            overridePicker.value = serviceHidden.value + '::' + deptHidden.value;
        }
    });

    // ── Override picker → manual (service company, dept) selection ────────
    overridePicker.addEventListener('change', function () {
        var v = overridePicker.value;
        if (!v) return;
        var opt = overridePicker.options[overridePicker.selectedIndex];
        var companyId = opt.getAttribute('data-company-id');
        var dept      = opt.getAttribute('data-dept');
        setRouting(dept, companyId, 'manual');
    });

    // ── Live attachment preview (image thumbnails + open-in-new-tab) ──────
    // Object URLs let the raiser view each file before submitting. We revoke
    // the previous selection's URLs on every change to avoid memory leaks.
    var attachmentUrls = [];
    fileInput.addEventListener('change', function () {
        attachmentUrls.forEach(function (u) { URL.revokeObjectURL(u); });
        attachmentUrls = [];

        preview.innerHTML = '';
        var files = Array.from(fileInput.files);
        if (files.length === 0) return;
        var html = '<div class="border rounded p-2" style="background:#f8fafc;">';
        files.forEach(function (f) {
            var sizeKB  = Math.ceil(f.size / 1024);
            var isImage = f.type.indexOf('image/') === 0;
            var url     = URL.createObjectURL(f);
            attachmentUrls.push(url);

            var thumb = isImage
                ? '<a href="' + url + '" target="_blank" rel="noopener">' +
                    '<img src="' + url + '" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #e2e8f0;">' +
                  '</a>'
                : '<i class="bi bi-file-earmark-pdf text-danger" style="font-size:26px;"></i>';

            html += '<div class="d-flex align-items-center gap-2 py-1" style="font-size:12px;">' +
                      thumb +
                      '<a href="' + url + '" target="_blank" rel="noopener" class="flex-grow-1 text-truncate text-decoration-none" title="Open in new tab">' +
                        escapeHtml(f.name) +
                      '</a>' +
                      '<span class="text-muted">' + sizeKB + ' KB</span>' +
                      '<a href="' + url + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0 px-1" title="View"><i class="bi bi-eye"></i></a>' +
                    '</div>';
        });
        html += '</div>';
        preview.innerHTML = html;
    });

    // ── Initial population on page load ──────────────────────────────────
    if (oldSubjectOther) subjectOther.value = oldSubjectOther;
    subjectSelEl.addEventListener('change', handleSubjectChange);
    handleSubjectChange();

    // If a validation redirect restored a manual routing that doesn't match
    // what the subject auto-resolves to, honour it via the override picker.
    if (oldDepartment && oldServiceCompanyId &&
        (deptHidden.value !== oldDepartment || String(serviceHidden.value) !== String(oldServiceCompanyId))) {
        setRouting(oldDepartment, oldServiceCompanyId, 'manual');
        filterOverridePicker(oldDepartment);
        overridePicker.value = oldServiceCompanyId + '::' + oldDepartment;
        overrideWrap.style.display = 'block';
    }

    // ── Double-submit guard ──────────────────────────────────────────────
    var ticketForm = document.getElementById('ticketCreateForm');
    var submitBtn  = document.getElementById('ticketSubmitBtn');
    var submitting = false;
    if (ticketForm && submitBtn) {
        ticketForm.addEventListener('submit', function (e) {
            // Both routing fields must be set before submit is allowed.
            if (!deptHidden.value || !serviceHidden.value) {
                e.preventDefault();
                if (!deptHidden.value) {
                    routingDept.classList.add('pending');
                    routingDept.textContent = 'Please pick a department first';
                }
                if (!serviceHidden.value) {
                    routingService.classList.add('pending');
                    routingService.textContent = 'Please pick a service provider';
                }
                filterOverridePicker(deptHidden.value || null);
                overrideWrap.style.display = 'block';
                return;
            }
            if (submitting) { e.preventDefault(); return; }
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
