@extends('layouts.app')
@section('title', 'Role Management')
@section('page-title', 'Role Management')

@section('content')

@php
$fieldMap = \App\Models\UserPermission::fieldMap();

// Flatten for By Page tab (module-level only)
$modules = [];
foreach ($fieldMap as $mKey => $mod) {
    $modules[$mKey] = ['label' => $mod['label'], 'icon' => $mod['icon']];
}

// Flatten for By Section tab (section-level)
$sections = [];
foreach ($fieldMap as $mKey => $mod) {
    $sections[$mKey] = ['label' => $mod['label'], 'icon' => $mod['icon'], 'sections' => []];
    foreach ($mod['sections'] as $sKey => $sec) {
        $sections[$mKey]['sections']["{$mKey}.{$sKey}"] = $sec['label'];
    }
}
@endphp

<div class="card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Role Management</h6>
        <span class="text-muted small">Assign system roles and manage access permissions for active employees</span>
    </div>

    <div class="card-body border-bottom pb-3">
        <form method="GET" action="{{ route('superadmin.roles.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm"
                    placeholder="Search name or email..." value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="company" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    @foreach($companies as $c)
                        <option value="{{ $c }}" {{ request('company') == $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                @if(request()->hasAny(['search','company']))
                    <a href="{{ route('superadmin.roles.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                @endif
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <small class="text-muted px-3 pt-2 d-block">{{ $employees->total() }} active employee(s)</small>

        @if($employees->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-people" style="font-size:40px;"></i>
                <p class="mt-2">No employees found.</p>
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Full Name</th>
                        <th>Preferred Name</th>
                        <th>Designation</th>
                        <th>Company</th>
                        <th>Department</th>
                        <th>Current Role</th>
                        <th>Assign Role</th>
                        <th>Manage Access</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($employees as $i => $emp)
                    <tr>
                        <td class="ps-3 text-muted">{{ $employees->firstItem() + $loop->index }}</td>
                        <td class="fw-semibold">{{ $emp->full_name ?? '—' }}</td>
                        <td>{{ $emp->preferred_name ?? '—' }}</td>
                        <td>{{ $emp->designation ?? '—' }}</td>
                        <td>{{ $emp->company ?? '—' }}</td>
                        <td>{{ $emp->department ?? '—' }}</td>
                        <td>
                            @php
                                $roleColors = [
                                    'superadmin'          => 'danger',
                                    'system_admin'        => 'dark',
                                    'hr_manager'          => 'primary',
                                    'hr_executive'        => 'primary',
                                    'hr_intern'           => 'info',
                                    'it_manager'          => 'success',
                                    'it_executive'        => 'success',
                                    'it_intern'           => 'success',
                                    'finance_manager'     => 'warning',
                                    'finance_executive'   => 'warning',
                                    'manager'             => 'secondary',
                                    'director_hod'        => 'secondary',
                                    'senior_executive'    => 'secondary',
                                    'executive_associate' => 'secondary',
                                    'others'              => 'light',
                                ];
                                $roleColor = $roleColors[$emp->work_role ?? 'others'] ?? 'light';
                            @endphp
                            <span class="badge bg-{{ $roleColor }} {{ $roleColor === 'light' ? 'text-dark' : '' }}">
                                {{ ucfirst(str_replace('_',' ', $emp->work_role ?? 'others')) }}
                            </span>
                        </td>
                        <td style="min-width:220px;">
                            <form action="{{ route('superadmin.roles.update', $emp) }}" method="POST"
                                  class="d-flex gap-1 align-items-center">
                                @csrf @method('PUT')
                                <select name="work_role" class="form-select form-select-sm">
                                    @foreach([
                                        'manager'             => 'Manager',
                                        'senior_executive'    => 'Senior Executive',
                                        'executive_associate' => 'Executive / Associate',
                                        'director_hod'        => 'Director / HOD',
                                        'hr_manager'          => 'HR Manager',
                                        'hr_executive'        => 'HR Executive',
                                        'hr_intern'           => 'HR Intern',
                                        'it_manager'          => 'IT Manager',
                                        'it_executive'        => 'IT Executive',
                                        'it_intern'           => 'IT Intern',
                                        'finance_manager'     => 'Finance Manager',
                                        'finance_executive'   => 'Finance Executive',
                                        'superadmin'          => 'Superadmin',
                                        'system_admin'        => 'System Admin',
                                        'others'              => 'Others',
                                    ] as $val => $label)
                                    <option value="{{ $val }}" {{ ($emp->work_role ?? 'others') === $val ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary" title="Save">
                                    <i class="bi bi-check2"></i>
                                </button>
                            </form>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-manage-access"
                                    data-employee-id="{{ $emp->id }}"
                                    data-employee-name="{{ $emp->full_name ?? 'Employee' }}"
                                    data-permissions-url="{{ route('superadmin.permissions.get', $emp) }}"
                                    data-update-url="{{ route('superadmin.permissions.update', $emp) }}"
                                    title="Manage page & field access">
                                <i class="bi bi-key me-1"></i>Access
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">{{ $employees->withQueryString()->links() }}</div>
        @endif
    </div>
</div>

{{-- ── Access Permissions Modal ──────────────────────────────────────────── --}}
<div class="modal fade" id="accessModal" tabindex="-1" aria-labelledby="accessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold" id="accessModalLabel">
                    <i class="bi bi-key me-2 text-primary"></i>Manage Access — <span id="modal-emp-name"></span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form id="permissionsForm" method="POST">
                @csrf
                <div class="modal-body p-0">

                    {{-- Loading state --}}
                    <div id="modal-loading" class="text-center py-5 text-muted">
                        <div class="spinner-border spinner-border-sm me-2"></div>Loading permissions...
                    </div>

                    {{-- No account warning --}}
                    <div id="modal-no-account" class="alert alert-warning mx-3 mt-3 d-none">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This employee does not have a user account linked. Role and permissions cannot be assigned until they log in.
                    </div>

                    {{-- Tabs --}}
                    <div id="modal-content" class="d-none">
                        <div class="px-3 pt-3 pb-1">
                            <p class="text-muted small mb-2">
                                Set custom access levels per page or per form section. Leave <strong>Default</strong> to use the employee's role-based permissions.
                            </p>
                            <ul class="nav nav-tabs" id="accessTabs">
                                <li class="nav-item">
                                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-by-page" type="button">
                                        <i class="bi bi-layout-sidebar me-1"></i>By Page
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-by-section" type="button">
                                        <i class="bi bi-list-ul me-1"></i>By Section
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-by-field" type="button">
                                        <i class="bi bi-input-cursor-text me-1"></i>By Field
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div class="tab-content px-3 pb-3 pt-2">
                            {{-- Tab 1: By Page --}}
                            <div class="tab-pane fade show active" id="tab-by-page">
                                <p class="text-muted small mb-3">Control access to entire pages/modules.</p>
                                <table class="table table-bordered table-sm align-middle" style="font-size:13px;">
                                    <thead style="background:#f8fafc;">
                                        <tr>
                                            <th style="width:200px;">Module</th>
                                            <th class="text-center">Default<br><small class="fw-normal text-muted">Role-based</small></th>
                                            <th class="text-center">Full Access<br><small class="fw-normal text-muted">View + Edit</small></th>
                                            <th class="text-center">View Only</th>
                                            <th class="text-center">Edit Only</th>
                                            <th class="text-center">No Access</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($modules as $mKey => $mod)
                                        <tr>
                                            <td class="fw-semibold">
                                                <i class="bi {{ $mod['icon'] }} me-2 text-primary"></i>{{ $mod['label'] }}
                                            </td>
                                            @foreach(['' , 'full', 'view', 'edit', 'none'] as $val)
                                            <td class="text-center">
                                                <input type="radio" class="form-check-input perm-radio"
                                                       name="permissions[{{ $mKey }}]"
                                                       value="{{ $val }}"
                                                       data-resource="{{ $mKey }}">
                                            </td>
                                            @endforeach
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            {{-- Tab 2: By Section --}}
                            <div class="tab-pane fade" id="tab-by-section">
                                <p class="text-muted small mb-3">Control access to specific sections within each module's forms.</p>
                                <div class="accordion" id="sectionAccordion">
                                    @foreach($sections as $mKey => $mod)
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button collapsed py-2" type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#sec-acc-{{ $mKey }}">
                                                <i class="bi {{ $mod['icon'] }} me-2 text-primary"></i>
                                                <strong>{{ $mod['label'] }}</strong>
                                            </button>
                                        </h2>
                                        <div id="sec-acc-{{ $mKey }}" class="accordion-collapse collapse">
                                            <div class="accordion-body p-0">
                                                <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:12px;">
                                                    <thead style="background:#f8fafc;">
                                                        <tr>
                                                            <th style="width:200px;">Section</th>
                                                            <th class="text-center">Default</th>
                                                            <th class="text-center">Full Access</th>
                                                            <th class="text-center">View Only</th>
                                                            <th class="text-center">Edit Only</th>
                                                            <th class="text-center">No Access</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($mod['sections'] as $sResource => $sLabel)
                                                        <tr>
                                                            <td>{{ $sLabel }}</td>
                                                            @foreach(['' , 'full', 'view', 'edit', 'none'] as $val)
                                                            <td class="text-center">
                                                                <input type="radio" class="form-check-input perm-radio"
                                                                       name="permissions[{{ $sResource }}]"
                                                                       value="{{ $val }}"
                                                                       data-resource="{{ $sResource }}">
                                                            </td>
                                                            @endforeach
                                                        </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Tab 3: By Field --}}
                            <div class="tab-pane fade" id="tab-by-field">
                                <p class="text-muted small mb-3">Control access to individual fields within each section.</p>
                                <div class="accordion" id="fieldAccordion">
                                    @foreach($fieldMap as $mKey => $mod)
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button collapsed py-2" type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#fld-mod-{{ $mKey }}">
                                                <i class="bi {{ $mod['icon'] }} me-2 text-primary"></i>
                                                <strong>{{ $mod['label'] }}</strong>
                                            </button>
                                        </h2>
                                        <div id="fld-mod-{{ $mKey }}" class="accordion-collapse collapse">
                                            <div class="accordion-body p-0">
                                                {{-- Nested accordion: one per section --}}
                                                <div class="accordion accordion-flush" id="fld-sec-accordion-{{ $mKey }}">
                                                    @foreach($mod['sections'] as $sKey => $sec)
                                                    @php $sResource = "{$mKey}.{$sKey}"; @endphp
                                                    <div class="accordion-item border-0 border-bottom">
                                                        <h2 class="accordion-header">
                                                            <button class="accordion-button collapsed py-2 ps-4 bg-light"
                                                                    style="font-size:12px;" type="button"
                                                                    data-bs-toggle="collapse"
                                                                    data-bs-target="#fld-sec-{{ $mKey }}-{{ $sKey }}">
                                                                {{ $sec['label'] }}
                                                            </button>
                                                        </h2>
                                                        <div id="fld-sec-{{ $mKey }}-{{ $sKey }}" class="accordion-collapse collapse">
                                                            <div class="accordion-body p-0">
                                                                <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:12px;">
                                                                    <thead style="background:#f8fafc;">
                                                                        <tr>
                                                                            <th style="width:220px;" class="ps-4">Field</th>
                                                                            <th class="text-center">Default</th>
                                                                            <th class="text-center">Full Access</th>
                                                                            <th class="text-center">View Only</th>
                                                                            <th class="text-center">Edit Only</th>
                                                                            <th class="text-center">No Access</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        @foreach($sec['fields'] as $fKey => $fLabel)
                                                                        @php $fResource = "{$mKey}.{$sKey}.{$fKey}"; @endphp
                                                                        <tr>
                                                                            <td class="ps-4">{{ $fLabel }}</td>
                                                                            @foreach(['' , 'full', 'view', 'edit', 'none'] as $val)
                                                                            <td class="text-center">
                                                                                <input type="radio" class="form-check-input perm-radio"
                                                                                       name="permissions[{{ $fResource }}]"
                                                                                       value="{{ $val }}"
                                                                                       data-resource="{{ $fResource }}">
                                                                            </td>
                                                                            @endforeach
                                                                        </tr>
                                                                        @endforeach
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>{{-- /tab-content --}}
                    </div>{{-- /modal-content --}}
                </div>{{-- /modal-body --}}

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="modal-save-btn" disabled>
                        <i class="bi bi-save me-1"></i>Save Permissions
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modal     = document.getElementById('accessModal');
    const form      = document.getElementById('permissionsForm');
    const loading   = document.getElementById('modal-loading');
    const noAccount = document.getElementById('modal-no-account');
    const content   = document.getElementById('modal-content');
    const saveBtn   = document.getElementById('modal-save-btn');
    const empName   = document.getElementById('modal-emp-name');

    document.querySelectorAll('.btn-manage-access').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const name       = btn.dataset.employeeName;
            const getUrl     = btn.dataset.permissionsUrl;
            const updateUrl  = btn.dataset.updateUrl;

            // Reset modal state
            empName.textContent = name;
            form.action = updateUrl;
            loading.classList.remove('d-none');
            noAccount.classList.add('d-none');
            content.classList.add('d-none');
            saveBtn.disabled = true;

            // Default all radios to "" (Default)
            document.querySelectorAll('.perm-radio').forEach(function (r) {
                r.checked = r.value === '';
            });

            // Show modal
            var bsModal = new bootstrap.Modal(modal);
            bsModal.show();

            // Fetch current permissions
            fetch(getUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                loading.classList.add('d-none');

                if (!data.has_account) {
                    noAccount.classList.remove('d-none');
                    return;
                }

                content.classList.remove('d-none');
                saveBtn.disabled = false;

                // Apply saved permissions to radios
                var perms = data.permissions || {};
                Object.keys(perms).forEach(function (resource) {
                    var level = perms[resource];
                    var radio = document.querySelector(
                        'input[type="radio"][name="permissions[' + resource + ']"][value="' + level + '"]'
                    );
                    if (radio) radio.checked = true;
                });
            })
            .catch(function () {
                loading.classList.add('d-none');
                noAccount.textContent = 'Failed to load permissions. Please try again.';
                noAccount.classList.remove('d-none');
            });
        });
    });
})();
</script>

@endsection
