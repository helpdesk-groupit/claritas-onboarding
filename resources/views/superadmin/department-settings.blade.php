@extends('layouts.app')

@section('title', 'Department Settings')
@section('page-title', 'Department Settings')

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    /* Accordion shells — mirror the look used on the Ticket Management page */
    .company-section { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:12px; overflow:hidden; background:#fff; }
    .company-header  { display:flex; align-items:center; gap:10px; width:100%; padding:14px 16px; background:#e0f2fe; border:none; text-align:left; cursor:pointer; transition:background .15s; }
    .company-header:hover { background:#bae6fd; }
    .company-header .chev { font-size:14px; transition:transform .2s; flex-shrink:0; color:#075985; }
    .company-header.expanded .chev { transform:rotate(90deg); }
    .company-header .name { font-weight:700; color:#075985; flex:1; }
    .company-header .hint { font-size:11px; color:#0c4a6e; opacity:.7; font-style:italic; }
    .company-header .count { background:#0369a1; color:#fff; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:600; }

    .company-body { display:none; padding:10px 14px 14px; }
    .company-body.show { display:block; }
    .company-body .ds-empty {
        padding:12px; text-align:center; font-size:12px; color:#94a3b8; font-style:italic;
        border:1px dashed #cbd5e1; border-radius:8px; background:#f8fafc;
    }

    /* Department row */
    .ds-dept-row {
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
        padding:12px 14px; margin:8px 0;
    }
    .ds-dept-row .ds-dept-row-header {
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        margin-bottom:10px;
    }
    .ds-dept-row .ds-dept-name { font-weight:600; color:#1e293b; font-size:14px; min-width:140px; }
    .ds-dept-row .ds-dept-name i { color:#64748b; }
    .ds-pill { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; line-height:1.4; }
    .ds-pill.auto { background:#dcfce7; color:#15803d; }
    .ds-pill .ds-pill-note { font-weight:500; opacity:.85; margin-left:4px; }

    /* "Also serves these other companies" sub-section per dept row */
    .ds-extras-block {
        background:#fff; border:1px solid #e2e8f0; border-radius:6px;
        padding:8px 12px;
    }
    .ds-extras-label {
        font-size:10.5px; font-weight:700; color:#64748b;
        text-transform:uppercase; letter-spacing:.5px;
        display:flex; align-items:center; gap:5px; margin-bottom:8px;
    }
    .ds-extras-label i { color:#94a3b8; }
    .ds-extras-list { display:flex; flex-wrap:wrap; gap:6px; }
    .ds-extra-toggle {
        display:inline-flex; align-items:center; gap:6px;
        padding:4px 12px 4px 8px; border-radius:999px;
        background:#f1f5f9; border:1px solid #e2e8f0;
        cursor:pointer; user-select:none;
        font-size:12px; color:#475569;
        transition:background .12s, border-color .12s, color .12s;
        margin:0;
    }
    .ds-extra-toggle:hover { background:#e2e8f0; border-color:#cbd5e1; }
    .ds-extra-toggle.is-on {
        background:#dbeafe; border-color:#93c5fd; color:#1e40af;
    }
    .ds-extra-toggle.is-on:hover { background:#bfdbfe; }
    .ds-extra-toggle .ds-extra-input {
        margin:0; cursor:pointer; width:14px; height:14px;
    }
    .ds-extra-toggle .ds-extra-input:checked { background-color:#2563eb; border-color:#2563eb; }
    .ds-extras-empty { font-size:11px; color:#94a3b8; font-style:italic; }

    .bulk-bar { display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }

    /* Dark theme */
    [data-theme="dark"] .company-section { background:#1e293b; border-color:#334155; }
    [data-theme="dark"] .company-header { background:#0f172a; }
    [data-theme="dark"] .company-header:hover { background:#1e293b; }
    [data-theme="dark"] .company-header .name,
    [data-theme="dark"] .company-header .chev,
    [data-theme="dark"] .company-header .hint { color:#bae6fd; }
    [data-theme="dark"] .company-body .ds-empty { background:#0f172a; border-color:#475569; color:#94a3b8; }
    [data-theme="dark"] .ds-dept-row { background:#0f172a; border-color:#334155; }
    [data-theme="dark"] .ds-dept-row .ds-dept-name { color:#f1f5f9; }
    [data-theme="dark"] .ds-pill.auto { background:#14532d; color:#86efac; }
    [data-theme="dark"] .ds-extras-block { background:#1e293b; border-color:#334155; }
    [data-theme="dark"] .ds-extras-label { color:#94a3b8; }
    [data-theme="dark"] .ds-extra-toggle { background:#0f172a; border-color:#475569; color:#cbd5e1; }
    [data-theme="dark"] .ds-extra-toggle:hover { background:#1e293b; }
    [data-theme="dark"] .ds-extra-toggle.is-on { background:#1e3a8a; border-color:#3b82f6; color:#bfdbfe; }
</style>
@endpush

@section('content')
<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-start justify-content-between mb-3 gap-2">
            <div>
                <h5 class="mb-1 fw-semibold">
                    <i class="bi bi-diagram-2 me-1"></i> Department Settings
                </h5>
                <small class="text-muted">
                    Each company expands to show the departments that <strong>actually exist there</strong>
                    &mdash; meaning at least one of that department's members works at the company.
                    For each existing department you can additionally assign other companies whose tickets it
                    should also handle (Extras).
                </small>
            </div>
            <div>
                <button type="button" class="btn btn-outline-info btn-sm"
                        data-bs-toggle="modal" data-bs-target="#userManualDeptModal">
                    <i class="bi bi-book me-1"></i> User Manual
                </button>
            </div>
        </div>

        @if($companies->isEmpty())
            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                No companies are registered yet. Add companies on
                <a href="{{ route('superadmin.companies.index') }}">Company Registration</a> first.
            </div>
        @else
            <div class="alert alert-info small mb-3">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Existence is member-based.</strong> A department appears under a company only if
                someone with that department/role works there. To "add" a missing department to a company,
                hire or assign an employee in that role &mdash; this page can't fabricate one. The only
                cross-company adjustment available here is configuring an existing department to also
                serve another company's tickets via the <em>Also serves</em> toggles below each row.
            </div>

            <form method="POST" action="{{ route('superadmin.department-settings.update') }}" id="deptSettingsForm">
                @csrf

                <div class="bulk-bar">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="expandAllBtn">
                        <i class="bi bi-arrows-expand me-1"></i> Expand all
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="collapseAllBtn">
                        <i class="bi bi-arrows-collapse me-1"></i> Collapse all
                    </button>
                </div>

                @foreach($companies as $company)
                    @php
                        // Depts that exist at this company = auto-derived members here.
                        // Extras (cross-company assignments) do NOT count as existence.
                        $existingDepts = [];
                        foreach ($departments as $d) {
                            if (in_array($company->id, $autoServed[$d] ?? [], true)) {
                                $existingDepts[] = $d;
                            }
                        }
                        $existingCount = count($existingDepts);
                    @endphp

                    <div class="company-section" data-company-id="{{ $company->id }}">
                        <button type="button" class="company-header" data-toggle="company">
                            <i class="bi bi-chevron-right chev"></i>
                            <i class="bi bi-building"></i>
                            <span class="name">{{ $company->name }}</span>
                            <span class="hint">Click to view departments</span>
                            <span class="count" data-existing-count>{{ $existingCount }}</span>
                        </button>
                        <div class="company-body">
                            @if(empty($existingDepts))
                                <div class="ds-empty">
                                    No department members work at this company yet. Hire or reassign an employee to a
                                    department here, and they'll appear automatically.
                                </div>
                            @else
                                @foreach($existingDepts as $dept)
                                    @php
                                        $extraCompanyIds = $assignments[$dept] ?? [];
                                        $otherCompanies  = $companies->where('id', '!=', $company->id);
                                    @endphp
                                    <div class="ds-dept-row" data-dept="{{ $dept }}">
                                        <div class="ds-dept-row-header">
                                            <span class="ds-dept-name">
                                                <i class="bi bi-tag me-1"></i>{{ $dept }}
                                            </span>
                                            <span class="ds-pill auto">
                                                <i class="bi bi-magic"></i> Auto-served
                                                <span class="ds-pill-note">(member of this dept works here)</span>
                                            </span>
                                        </div>

                                        <div class="ds-extras-block">
                                            <div class="ds-extras-label">
                                                <i class="bi bi-share"></i>
                                                Also serves these other companies (Extras)
                                            </div>
                                            @if($otherCompanies->isEmpty())
                                                <div class="ds-extras-empty">No other companies registered.</div>
                                            @else
                                                <div class="ds-extras-list">
                                                    @foreach($otherCompanies as $other)
                                                        @php
                                                            $isExtra = in_array($other->id, $extraCompanyIds, true);
                                                        @endphp
                                                        <label class="ds-extra-toggle {{ $isExtra ? 'is-on' : '' }}"
                                                               for="ds_extra_{{ $company->id }}_{{ $dept }}_{{ $other->id }}">
                                                            <input type="checkbox"
                                                                   class="form-check-input ds-extra-input"
                                                                   id="ds_extra_{{ $company->id }}_{{ $dept }}_{{ $other->id }}"
                                                                   name="assignments[{{ $dept }}][]"
                                                                   value="{{ $other->id }}"
                                                                   data-dept="{{ $dept }}"
                                                                   data-company-id="{{ $other->id }}"
                                                                   @checked($isExtra)>
                                                            <span class="ds-extra-name">{{ $other->name }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                @endforeach

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a href="{{ url()->current() }}" class="btn btn-light btn-sm">Reset</a>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i> Save All Changes
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>

@include('partials._user-manual-deptsettings')
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    'use strict';

    var form = document.getElementById('deptSettingsForm');
    if (!form) return;

    // ── Accordion: toggle a company's body ────────────────────────────────
    document.querySelectorAll('.company-header').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body = btn.nextElementSibling; // .company-body
            if (!body) return;
            var willOpen = !body.classList.contains('show');
            body.classList.toggle('show', willOpen);
            btn.classList.toggle('expanded', willOpen);
            var hint = btn.querySelector('.hint');
            if (hint) hint.textContent = willOpen ? 'Click to collapse' : 'Click to view departments';
        });
    });

    var expandAllBtn = document.getElementById('expandAllBtn');
    var collapseAllBtn = document.getElementById('collapseAllBtn');
    function setAll(open) {
        document.querySelectorAll('.company-body').forEach(function (el) {
            el.classList.toggle('show', open);
        });
        document.querySelectorAll('.company-header').forEach(function (btn) {
            btn.classList.toggle('expanded', open);
            var hint = btn.querySelector('.hint');
            if (hint) hint.textContent = open ? 'Click to collapse' : 'Click to view departments';
        });
    }
    if (expandAllBtn) expandAllBtn.addEventListener('click', function () { setAll(true); });
    if (collapseAllBtn) collapseAllBtn.addEventListener('click', function () { setAll(false); });

    // ── Extras toggles: visual on/off + sync siblings ─────────────────────
    // If the same dept auto-derives at multiple companies, the same (dept,
    // other_company) checkbox can appear in multiple rows. We keep them in
    // sync so the visual state matches the underlying single pivot row.
    function applyVisualState(input) {
        var label = input.closest('.ds-extra-toggle');
        if (label) label.classList.toggle('is-on', input.checked);
    }

    form.querySelectorAll('input.ds-extra-input').forEach(function (input) {
        input.addEventListener('change', function () {
            applyVisualState(input);

            // Sync any sibling checkboxes that target the same (dept, company) pair.
            var dept = input.getAttribute('data-dept');
            var companyId = input.getAttribute('data-company-id');
            if (!dept || !companyId) return;
            form.querySelectorAll(
                'input.ds-extra-input[data-dept="' + dept + '"][data-company-id="' + companyId + '"]'
            ).forEach(function (sibling) {
                if (sibling === input) return;
                if (sibling.checked !== input.checked) {
                    sibling.checked = input.checked;
                    applyVisualState(sibling);
                }
            });
        });
    });
})();
</script>
@endpush
