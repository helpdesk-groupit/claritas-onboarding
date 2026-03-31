@extends('layouts.app')
@section('title', 'Employee Listing')
@section('page-title', 'Employee Listing')

@push('styles')
<style>
.dept-scroll::-webkit-scrollbar { width: 4px; }
.dept-scroll::-webkit-scrollbar-track { background: transparent; }
.dept-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.dept-scroll { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
</style>
@endpush

@section('content')

@if(session('import_errors') && count(session('import_errors')) > 0)
<div class="alert alert-warning alert-dismissible fade show">
    <strong><i class="bi bi-exclamation-triangle me-1"></i>Some rows were skipped:</strong>
    <ul class="mb-0 mt-1">
        @foreach(session('import_errors') as $err)
            <li class="small">{{ $err }}</li>
        @endforeach
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- ── Summary Cards ──────────────────────────────────────────────────── --}}
<p class="text-uppercase fw-semibold mb-2" style="font-size:11px; letter-spacing:1px; color:#94a3b8;">
    <i class="bi bi-people me-1"></i> Employee Overview
</p>
<div class="row g-3 mb-4">

    {{-- Card 1: By Company --}}
    @php $companyTotal = $statsByCompany->sum('total'); @endphp
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card h-100" style="border-left:4px solid #2563eb; border-top:none; border-right:none; border-bottom:none; box-shadow:0 1px 6px rgba(0,0,0,.06);">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:44px;height:44px;background:#eff6ff;">
                        <i class="bi bi-building" style="font-size:20px;color:#2563eb;"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size:28px;line-height:1;">{{ $companyTotal }}</div>
                        <div class="text-muted" style="font-size:13px;">Overall Active Employee</div>
                    </div>
                </div>
                <div class="text-uppercase fw-semibold mb-2" style="font-size:10px;letter-spacing:.8px;color:#94a3b8;">By Company</div>
                @forelse($statsByCompany as $row)
                <div class="d-flex justify-content-between align-items-center py-1" style="font-size:13px;">
                    <span>{{ $row->company }}</span>
                    <span class="badge rounded-pill" style="background:#2563eb;min-width:26px;">{{ $row->total }}</span>
                </div>
                @empty
                <div class="text-muted small">No data</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Card 2: By Department --}}
    @php $deptGroups = $statsByDept->groupBy('department'); $deptTotal = $statsByDept->sum('total'); @endphp
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card h-100" style="border-left:4px solid #10b981; border-top:none; border-right:none; border-bottom:none; box-shadow:0 1px 6px rgba(0,0,0,.06);">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:44px;height:44px;background:#f0fdf4;">
                        <i class="bi bi-diagram-3" style="font-size:20px;color:#10b981;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold" style="font-size:28px;line-height:1;">{{ $deptGroups->count() }}</div>
                        <div class="text-muted" style="font-size:13px;">Overall Active Employee</div>
                    </div>
                    <select class="form-select form-select-sm ms-auto" style="font-size:11px;width:auto;max-width:130px;"
                        onchange="filterCard('dept', this.value)">
                        <option value="">All</option>
                        @foreach($registeredCompanies as $co)
                        <option value="{{ $co->name }}">{{ $co->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="text-uppercase fw-semibold mb-2" style="font-size:10px;letter-spacing:.8px;color:#94a3b8;">By Department</div>
                <div class="dept-scroll" style="max-height:160px;overflow-y:auto;">
                @forelse($deptGroups as $dept => $rows)
                @php
                    $dt = $rows->sum('total');
                    $deptCountStr = $rows->map(fn($r) => $r->company . ':' . $r->total)->implode('||');
                @endphp
                <div class="dept-row d-flex justify-content-between align-items-center py-1"
                     data-companies="{{ $rows->pluck('company')->implode('|') }}"
                     data-counts="{{ $deptCountStr }}"
                     data-total="{{ $dt }}"
                     style="font-size:13px;padding-right:20px;">
                    <span>{{ $dept }}</span>
                    <span class="badge rounded-pill dept-badge" style="background:#10b981;min-width:26px;">{{ $dt }}</span>
                </div>
                @empty
                <div class="text-muted small">No data</div>
                @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Card 3: By Employment Type --}}
    @php
        $typeGroups  = $statsByType->groupBy('employment_type');
        $typeConfig  = [
            'permanent' => ['color'=>'#f59e0b','bg'=>'#fffbeb','icon'=>'bi-person-check'],
            'contract'  => ['color'=>'#6366f1','bg'=>'#eef2ff','icon'=>'bi-file-earmark-person'],
            'intern'    => ['color'=>'#ec4899','bg'=>'#fdf2f8','icon'=>'bi-mortarboard'],
        ];
        $borderColor = '#f59e0b';
    @endphp
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card h-100" style="border-left:4px solid {{ $borderColor }}; border-top:none; border-right:none; border-bottom:none; box-shadow:0 1px 6px rgba(0,0,0,.06);">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:44px;height:44px;background:#fffbeb;">
                        <i class="bi bi-person-badge" style="font-size:20px;color:#f59e0b;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold" style="font-size:28px;line-height:1;">{{ $statsByType->sum('total') }}</div>
                        <div class="text-muted" style="font-size:13px;">Overall Active Employee</div>
                    </div>
                    <select class="form-select form-select-sm ms-auto" style="font-size:11px;width:auto;max-width:130px;"
                        onchange="filterCard('type', this.value)">
                        <option value="">All</option>
                        @foreach($registeredCompanies as $co)
                        <option value="{{ $co->name }}">{{ $co->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="text-uppercase fw-semibold mb-2" style="font-size:10px;letter-spacing:.8px;color:#94a3b8;">By Type</div>
                @forelse($typeGroups as $type => $rows)
                @php
                    $tt  = $rows->sum('total');
                    $cfg = $typeConfig[$type] ?? ['color'=>'#6b7280','bg'=>'#f3f4f6','icon'=>'bi-person'];
                    $typeCountStr = $rows->map(fn($r) => $r->company . ':' . $r->total)->implode('||');
                @endphp
                <div class="type-row d-flex justify-content-between align-items-center py-1"
                     data-companies="{{ $rows->pluck('company')->implode('|') }}"
                     data-counts="{{ $typeCountStr }}"
                     data-total="{{ $tt }}"
                     style="font-size:13px;">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-2 d-flex align-items-center justify-content-center"
                             style="width:26px;height:26px;background:{{ $cfg['bg'] }};">
                            <i class="{{ $cfg['icon'] }}" style="font-size:13px;color:{{ $cfg['color'] }};"></i>
                        </div>
                        <span>{{ ucfirst($type) }}</span>
                    </div>
                    <span class="badge rounded-pill" style="background:{{ $cfg['color'] }};min-width:26px;">{{ $tt }}</span>
                </div>
                @empty
                <div class="text-muted small">No data</div>
                @endforelse
            </div>
        </div>
    </div>

</div>
{{-- ── End Summary Cards ───────────────────────────────────────────────── --}}

<div class="card">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Active Employees</h6>
        <div class="d-flex gap-2">
            @if(in_array(Auth::user()->role, ['hr_manager', 'superadmin']))
            <a href="{{ route('employees.import.template') }}" class="btn btn-sm btn-outline-secondary" title="Download CSV Template">
                <i class="bi bi-file-earmark-arrow-down me-1"></i>CSV Template
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="bi bi-upload me-1"></i>Import CSV
            </button>
            @endif
            @if(in_array(Auth::user()->role, ['hr_manager','superadmin','system_admin']))
            <a href="{{ route('employees.export', request()->query()) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
            @endif
        </div>
    </div>

    {{-- Filters --}}
    <div class="card-body border-bottom pb-3">
        <form action="{{ route('employees.index') }}" method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm"
                    placeholder="Name or preferred name..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <select name="company" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    @foreach($companies as $c)
                    <option value="{{ $c }}" {{ request('company')==$c?'selected':'' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="department" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    @foreach($departments as $d)
                    <option value="{{ $d }}" {{ request('department')==$d?'selected':'' }}>{{ $d }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="designation" class="form-select form-select-sm">
                    <option value="">All Designations</option>
                    @foreach($designations as $dg)
                    <option value="{{ $dg }}" {{ request('designation')==$dg?'selected':'' }}>{{ $dg }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                @if(request()->hasAny(['search','company','department','designation','work_role']))
                    <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                @endif
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <small class="text-muted px-3 pt-2 d-block">{{ $employees->total() }} record(s)</small>
        @if($employees->isEmpty())
            <div class="text-center py-5 text-muted"><i class="bi bi-inbox" style="font-size:40px;"></i><p class="mt-2">No employees found</p></div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3" style="width:40px;">#</th>
                        <th>Full Name</th>
                        <th>Preferred Name</th>
                        <th>Designation</th>
                        <th>Company</th>
                        <th>Department</th>
                        <th>Start Date</th>
                        <th>Employment Type</th>
                        <th>Company Email</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($employees as $emp)
                    <tr>
                        <td class="ps-3 text-muted" style="font-size:12px;">{{ $employees->firstItem() + $loop->index }}</td>
                        <td><strong>{{ $emp->full_name ?? '—' }}</strong></td>
                        <td>{{ $emp->preferred_name ?? '—' }}</td>
                        <td>{{ $emp->designation ?? '—' }}</td>
                        <td>{{ $emp->company ?? '—' }}</td>
                        <td>{{ $emp->department ?? '—' }}</td>
                        <td>{{ $emp->start_date?->format('d M Y') ?? '—' }}</td>
                        <td>{{ $emp->employment_type ? ucfirst($emp->employment_type) : '—' }}</td>
                        <td>{{ $emp->company_email ?? '—' }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="{{ route('employees.show', $emp) }}" class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if(Auth::user()->canEditOnboarding())
                                <a href="{{ route('employees.edit', $emp) }}" class="btn btn-sm btn-warning" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    @if($employees->hasPages())
    <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing {{ $employees->firstItem() }}–{{ $employees->lastItem() }} of {{ $employees->total() }} employees
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                {{-- Previous --}}
                @if($employees->onFirstPage())
                    <li class="page-item disabled"><span class="page-link">&laquo;</span></li>
                @else
                    <li class="page-item"><a class="page-link" href="{{ $employees->previousPageUrl() }}">&laquo;</a></li>
                @endif

                {{-- Page numbers --}}
                @php
                    $currentPage = $employees->currentPage();
                    $lastPage    = $employees->lastPage();
                    $start = max(1, $currentPage - 2);
                    $end   = min($lastPage, $currentPage + 2);
                @endphp
                @if($start > 1)
                    <li class="page-item"><a class="page-link" href="{{ $employees->url(1) }}">1</a></li>
                    @if($start > 2)<li class="page-item disabled"><span class="page-link">…</span></li>@endif
                @endif
                @for($i = $start; $i <= $end; $i++)
                    <li class="page-item {{ $i == $currentPage ? 'active' : '' }}">
                        <a class="page-link" href="{{ $employees->url($i) }}">{{ $i }}</a>
                    </li>
                @endfor
                @if($end < $lastPage)
                    @if($end < $lastPage - 1)<li class="page-item disabled"><span class="page-link">…</span></li>@endif
                    <li class="page-item"><a class="page-link" href="{{ $employees->url($lastPage) }}">{{ $lastPage }}</a></li>
                @endif

                {{-- Next --}}
                @if($employees->hasMorePages())
                    <li class="page-item"><a class="page-link" href="{{ $employees->nextPageUrl() }}">&raquo;</a></li>
                @else
                    <li class="page-item disabled"><span class="page-link">&raquo;</span></li>
                @endif
            </ul>
        </nav>
    </div>
    @endif
</div>

{{-- Import CSV Modal --}}
@if(in_array(Auth::user()->role, ['hr_manager', 'superadmin']))
<div class="modal fade" id="importModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-upload me-2"></i>Import Employees from CSV</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                {{-- Instructions --}}
                <div class="alert alert-info mb-4">
                    <h6 class="fw-bold mb-2"><i class="bi bi-info-circle me-1"></i>Before you upload:</h6>
                    <ol class="mb-2 small">
                        <li>Download the <a href="{{ route('employees.import.template') }}" class="fw-semibold">CSV Template</a> and fill in your data</li>
                        <li>Do <strong>not</strong> rename or reorder the column headers</li>
                        <li>Dates must be in <code>DD-MM-YYYY</code> format (e.g. <code>15-03-2026</code>)</li>
                        <li>Leave optional fields blank — do not delete the column</li>
                        <li>Max file size: 5 MB</li>
                    </ol>
                </div>

                {{-- Column reference table --}}
                <h6 class="fw-bold mb-2">Column Reference</h6>
                <div class="table-responsive mb-4" style="max-height:280px; overflow-y:auto;">
                    <table class="table table-sm table-bordered" style="font-size:12px;">
                        <thead class="table-dark">
                            <tr><th>Column</th><th>Required</th><th>Format / Accepted Values</th></tr>
                        </thead>
                        <tbody>
                            <tr class="table-warning"><td><code>full_name</code></td><td><span class="badge bg-danger">Yes</span></td><td>Text</td></tr>
                            <tr class="table-warning"><td><code>preferred_name</code></td><td><span class="badge bg-danger">Yes</span></td><td>Text (nickname)</td></tr>
                            <tr class="table-warning"><td><code>personal_contact_number</code></td><td><span class="badge bg-danger">Yes</span></td><td>Text (phone number)</td></tr>
                            <tr class="table-warning"><td><code>employment_type</code></td><td><span class="badge bg-danger">Yes</span></td><td><code>permanent</code> / <code>intern</code> / <code>contract</code> (default if blank: contract)</td></tr>
                            <tr class="table-warning"><td><code>designation</code></td><td><span class="badge bg-danger">Yes</span></td><td>Text (position / job title)</td></tr>
                            <tr class="table-warning"><td><code>department</code></td><td><span class="badge bg-danger">Yes</span></td><td>Text</td></tr>
                            <tr class="table-warning"><td><code>reporting_manager</code></td><td><span class="badge bg-danger">Yes</span></td><td>Text (manager full name)</td></tr>
                            <tr class="table-warning"><td><code>start_date</code></td><td><span class="badge bg-danger">Yes</span></td><td><code>DD-MM-YYYY</code> (e.g. 15-03-2026)</td></tr>
                            <tr><td><code>official_document_id</code></td><td><span class="badge bg-secondary">No</span></td><td>NRIC or Passport No</td></tr>
                            <tr><td><code>date_of_birth</code></td><td><span class="badge bg-secondary">No</span></td><td><code>DD-MM-YYYY</code></td></tr>
                            <tr><td><code>sex</code></td><td><span class="badge bg-secondary">No</span></td><td><code>male</code> or <code>female</code></td></tr>
                            <tr><td><code>marital_status</code></td><td><span class="badge bg-secondary">No</span></td><td><code>single</code> / <code>married</code> / <code>divorced</code> / <code>widowed</code></td></tr>
                            <tr><td><code>religion</code></td><td><span class="badge bg-secondary">No</span></td><td>Text</td></tr>
                            <tr><td><code>race</code></td><td><span class="badge bg-secondary">No</span></td><td>Text</td></tr>
                            <tr><td><code>residential_address</code></td><td><span class="badge bg-secondary">No</span></td><td>Text</td></tr>
                            <tr><td><code>personal_email</code></td><td><span class="badge bg-secondary">No</span></td><td>Valid email</td></tr>
                            <tr><td><code>bank_account_number</code></td><td><span class="badge bg-secondary">No</span></td><td>Text</td></tr>
                            <tr><td><code>company</code></td><td><span class="badge bg-secondary">No</span></td><td>Text (e.g. Claritas Asia Sdn. Bhd.)</td></tr>
                            <tr><td><code>office_location</code></td><td><span class="badge bg-secondary">No</span></td><td>Text</td></tr>
                            <tr><td><code>company_email</code></td><td><span class="badge bg-secondary">No</span></td><td>Valid email</td></tr>
                            <tr><td><code>google_id</code></td><td><span class="badge bg-secondary">No</span></td><td>Usually same as company_email</td></tr>
                            <tr><td><code>work_role</code></td><td><span class="badge bg-secondary">No</span></td><td><code>manager</code> / <code>senior_executive</code> / <code>executive_associate</code> / <code>director_hod</code> / <code>hr_manager</code> / <code>hr_executive</code> / <code>hr_intern</code> / <code>it_manager</code> / <code>it_executive</code> / <code>it_intern</code> / <code>others</code></td></tr>
                            <tr><td><code>exit_date</code></td><td><span class="badge bg-secondary">No</span></td><td><code>DD-MM-YYYY</code> or leave blank</td></tr>
                        </tbody>
                    </table>
                </div>

                {{-- Upload form --}}
                <form action="{{ route('employees.import') }}" method="POST" enctype="multipart/form-data" id="importForm">
                    @csrf
                    <label class="form-label fw-semibold">Select CSV File <span class="text-danger">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required
                           onchange="updateImportLabel(this)">
                    <div id="importFileLabel" class="form-text text-muted mt-1">No file chosen</div>
                </form>

            </div>
            <div class="modal-footer">
                <a href="{{ route('employees.import.template') }}" class="btn btn-outline-secondary me-auto">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i>Download Template
                </a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="importForm" class="btn btn-primary px-4">
                    <i class="bi bi-upload me-1"></i>Import
                </button>
            </div>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
function filterCard(type, company) {
    const selector = type === 'dept' ? '.dept-row' : '.type-row';
    const selected = company ? company.trim().toLowerCase() : '';

    document.querySelectorAll(selector).forEach(row => {
        const badge = row.querySelector('.badge');
        if (!selected) {
            row.style.display = '';
            if (badge) badge.textContent = row.dataset.total;
            return;
        }

        // Parse data-counts: "Company A:3||Company B:2"
        let count = 0;
        let found = false;
        const pairs = (row.dataset.counts || '').split('||');
        for (const pair of pairs) {
            const colonIdx = pair.lastIndexOf(':');
            if (colonIdx === -1) continue;
            const name = pair.substring(0, colonIdx).trim().toLowerCase();
            const val  = parseInt(pair.substring(colonIdx + 1).trim(), 10);
            if (name === selected) { count = isNaN(val) ? 0 : val; found = true; break; }
        }

        // Always show the row — display 0 if company not in this row's data
        row.style.display = '';
        if (badge) badge.textContent = found ? count : 0;
    });
}

function updateImportLabel(input) {
    const label = document.getElementById('importFileLabel');
    if (input.files && input.files[0]) {
        const size = (input.files[0].size / 1024).toFixed(1);
        label.textContent = input.files[0].name + ' (' + size + ' KB)';
        label.classList.remove('text-muted');
        label.classList.add('text-success', 'fw-semibold');
    }
}
</script>
@endpush

@endsection