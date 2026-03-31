@extends('layouts.app')
@section('title', 'Company Registration')
@section('page-title', 'Company Registration')

@section('content')

<div class="row g-4">

    {{-- ── Add Company Form ─────────────────────────────────────────────── --}}
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
                <h6 class="mb-0 fw-bold"><i class="bi bi-building-add me-2 text-primary"></i>Register New Company</h6>
            </div>
            <div class="card-body">
                <form action="{{ route('superadmin.companies.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="e.g. Claritas Asia Sdn. Bhd." required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Address</label>
                        <textarea name="address" class="form-control @error('address') is-invalid @enderror"
                                  rows="3" placeholder="Full registered address...">{{ old('address') }}</textarea>
                        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Registration Number</label>
                        <input type="text" name="registration_number"
                               class="form-control @error('registration_number') is-invalid @enderror"
                               value="{{ old('registration_number') }}" placeholder="e.g. 202301012345">
                        @error('registration_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Logo</label>
                        <input type="file" name="logo" class="form-control @error('logo') is-invalid @enderror"
                               accept="image/*">
                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">JPG, PNG, SVG or WebP — max 2 MB</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus-circle me-2"></i>Register Company
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- ── Registered Companies List ────────────────────────────────────── --}}
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold"><i class="bi bi-building me-2 text-primary"></i>Registered Companies</h6>
                <span class="badge bg-primary rounded-pill">{{ $companies->total() }}</span>
            </div>

            @if($companies->isEmpty())
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-building" style="font-size:40px;opacity:.3;"></i>
                    <p class="mt-2 mb-0">No companies registered yet.</p>
                </div>
            @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                        <thead style="background:#f8fafc;">
                            <tr>
                                <th class="ps-3">#</th>
                                <th>Logo</th>
                                <th>Company Name</th>
                                <th>Registration No.</th>
                                <th>Address</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($companies as $company)
                            <tr>
                                <td class="ps-3 text-muted">{{ $loop->iteration }}</td>
                                <td>
                                    @if($company->logo_path)
                                    <img src="{{ asset('storage/'.$company->logo_path) }}"
                                         alt="{{ $company->name }}"
                                         style="height:32px;max-width:80px;object-fit:contain;">
                                    @else
                                    <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td class="fw-semibold">{{ $company->name }}</td>
                                <td>{{ $company->registration_number ?? '—' }}</td>
                                <td class="text-muted" style="max-width:220px;">
                                    <span title="{{ $company->address }}" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        {{ $company->address ?? '—' }}
                                    </span>
                                </td>
                                <td class="text-end pe-3">
                                    <button class="btn btn-outline-primary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editModal{{ $company->id }}"
                                            title="Edit">
                                        <i class="bi bi-pencil" style="font-size:12px;"></i>
                                    </button>
                                    <form action="{{ route('superadmin.companies.destroy', $company) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('Remove {{ addslashes($company->name) }}?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm" title="Delete">
                                            <i class="bi bi-trash" style="font-size:12px;"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2">{{ $companies->links() }}</div>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- ── Edit Modals ──────────────────────────────────────────────────────── --}}
@foreach($companies as $company)
<div class="modal fade" id="editModal{{ $company->id }}" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h6 class="modal-title text-white fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>Edit Company
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('superadmin.companies.update', $company) }}" method="POST" enctype="multipart/form-data">
                @csrf @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="{{ old('name', $company->name) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Address</label>
                        <textarea name="address" class="form-control" rows="3">{{ old('address', $company->address) }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Registration Number</label>
                        <input type="text" name="registration_number" class="form-control"
                               value="{{ old('registration_number', $company->registration_number) }}">
                    </div>
                </div>
                <div class="modal-body pt-0">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Logo</label>
                        @if($company->logo_path)
                        <div class="mb-2 d-flex align-items-center gap-2">
                            <img src="{{ asset('storage/'.$company->logo_path) }}"
                                 alt="{{ $company->name }}"
                                 style="height:40px;max-width:120px;object-fit:contain;border:1px solid #e2e8f0;border-radius:6px;padding:4px;">
                            <span class="text-muted small">Current logo</span>
                        </div>
                        @endif
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div class="form-text">Upload a new file to replace the current logo. JPG, PNG, SVG or WebP — max 2 MB.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

@endsection