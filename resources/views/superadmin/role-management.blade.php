@extends('layouts.app')
@section('title', 'Role Management')
@section('page-title', 'Role Management')

@section('content')

<div class="card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Role Management</h6>
        <span class="text-muted small">Assign system roles to active employees</span>
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
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">{{ $employees->withQueryString()->links() }}</div>
        @endif
    </div>
</div>

@endsection