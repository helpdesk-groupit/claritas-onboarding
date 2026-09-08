@extends('layouts.app')
@section('title', 'Portal Changelog')
@section('page-title', 'Portal Changelog')

@php
    // The multi-modal old() gotcha: the Add and Edit forms share field names, so only the form
    // whose _form marker matches the failed submit may read old() — otherwise a rejected Edit
    // would silently pre-fill the (unrelated, unopened) Add modal the next time it's opened.
    $isAddOld = old('_form') === 'add';
    $isEditOld = old('_form') === 'edit';
    $nowLocal = now()->format('Y-m-d\TH:i');
@endphp

@section('content')

<p class="text-muted mb-3" style="font-size:13px;">
    A manual record of changes, fixes, and new features made to the portal itself — who made
    each change, its status, and when it happened. Superadmin only.
</p>

{{-- ── Filters ───────────────────────────────────────────────────────────── --}}
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('superadmin.changelog.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Title or description…" value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($types as $key => $label)
                    <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Module / Area</label>
                <select name="module_area" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($moduleAreas as $area)
                    <option value="{{ $area }}" @selected(request('module_area') === $area)>{{ $area }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Occurred from</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Occurred to</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                @if(request()->anyFilled(['search', 'type', 'status', 'module_area', 'date_from', 'date_to']))
                <a href="{{ route('superadmin.changelog.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- ── Entries ───────────────────────────────────────────────────────────── --}}
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between py-3" style="background:#fff;">
        <div>
            <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i>Changelog</h6>
            <small class="text-muted">{{ $entries->total() }} {{ $entries->total() === 1 ? 'entry' : 'entries' }}</small>
        </div>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addChangelogModal">
            <i class="bi bi-plus-circle me-1"></i>Add Entry
        </button>
    </div>

    <div class="card-body p-0">
        @if($entries->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-clock-history" style="font-size:40px;opacity:.3;"></i>
            <p class="mt-2 mb-0 text-muted">No changelog entries yet.</p>
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">Occurred</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Module / Area</th>
                        <th>Recorded By</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($entries as $entry)
                <tr>
                    <td class="ps-3" style="white-space:nowrap;">{{ fmt_datetime($entry->occurred_at) }}</td>
                    <td style="max-width:320px;">
                        <div class="fw-semibold">{{ $entry->title }}</div>
                        @if($entry->description)
                        <div class="text-muted text-truncate" style="font-size:12px;max-width:320px;" title="{{ $entry->description }}">
                            {{ $entry->description }}
                        </div>
                        @endif
                    </td>
                    <td><span class="badge {{ $entry->typeBadgeClass() }}" style="font-size:11px;">{{ $entry->typeLabel() }}</span></td>
                    <td><span class="badge {{ $entry->statusBadgeClass() }}" style="font-size:11px;">{{ $entry->statusLabel() }}</span></td>
                    <td>{{ $entry->module_area ?? '—' }}</td>
                    <td>
                        <div>{{ $entry->changed_by_name ?? '—' }}</div>
                        @if($entry->updated_by_name)
                        <div class="text-muted" style="font-size:11px;">edited by {{ $entry->updated_by_name }}</div>
                        @endif
                    </td>
                    <td class="text-end pe-3">
                        <button type="button" class="btn btn-outline-primary btn-sm js-edit-changelog"
                                data-bs-toggle="modal" data-bs-target="#editChangelogModal"
                                data-id="{{ $entry->id }}"
                                data-url="{{ route('superadmin.changelog.update', $entry) }}"
                                data-title="{{ $entry->title }}"
                                data-description="{{ $entry->description }}"
                                data-type="{{ $entry->type }}"
                                data-status="{{ $entry->status }}"
                                data-module="{{ $entry->module_area }}"
                                data-occurred="{{ $entry->occurred_at->format('Y-m-d\TH:i') }}"
                                data-by="{{ $entry->changed_by_name }}"
                                title="Edit">
                            <i class="bi bi-pencil" style="font-size:12px;"></i>
                        </button>
                        <form action="{{ route('superadmin.changelog.destroy', $entry) }}" method="POST" class="d-inline js-confirm"
                              data-confirm="Delete the changelog entry '{{ $entry->title }}'? This cannot be undone."
                              data-confirm-title="Delete entry" data-confirm-ok="Delete" data-confirm-variant="danger">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                <i class="bi bi-trash" style="font-size:12px;"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if($entries->hasPages())
        <div class="d-flex justify-content-end p-3">{{ $entries->links() }}</div>
        @endif
        @endif
    </div>
</div>

{{-- ── Add Entry Modal ──────────────────────────────────────────────────── --}}
<div class="modal fade" id="addChangelogModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h6 class="modal-title text-white fw-bold"><i class="bi bi-plus-circle me-2"></i>Add Changelog Entry</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('superadmin.changelog.store') }}" method="POST">
                @csrf
                <input type="hidden" name="_form" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
                               value="{{ $isAddOld ? old('title') : '' }}"
                               placeholder="e.g. Added bulk vendor import" required maxlength="255">
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control @error('description') is-invalid @enderror"
                                  rows="3" placeholder="Optional details…">{{ $isAddOld ? old('description') : '' }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select name="type" class="form-select @error('type') is-invalid @enderror" required>
                                @foreach($types as $key => $label)
                                <option value="{{ $key }}" @selected($isAddOld ? old('type') === $key : $key === 'feature')>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                @foreach($statuses as $key => $label)
                                <option value="{{ $key }}" @selected($isAddOld ? old('status') === $key : $key === 'completed')>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Module / Area</label>
                            <select name="module_area" class="form-select @error('module_area') is-invalid @enderror">
                                <option value="">— Select —</option>
                                @foreach($moduleAreas as $area)
                                <option value="{{ $area }}" @selected($isAddOld && old('module_area') === $area)>{{ $area }}</option>
                                @endforeach
                            </select>
                            @error('module_area')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Occurred At <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="occurred_at"
                                   class="form-control @error('occurred_at') is-invalid @enderror"
                                   value="{{ $isAddOld ? old('occurred_at', $nowLocal) : $nowLocal }}" required>
                            @error('occurred_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Recorded By</label>
                            <input type="text" name="changed_by_name"
                                   class="form-control @error('changed_by_name') is-invalid @enderror"
                                   value="{{ $isAddOld ? old('changed_by_name', Auth::user()->name) : Auth::user()->name }}" maxlength="150">
                            <div class="form-text">Defaults to you — edit to credit someone else.</div>
                            @error('changed_by_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-2"></i>Add Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── Shared Edit Modal (fields populated via JS from the clicked row) ────── --}}
<div class="modal fade" id="editChangelogModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h6 class="modal-title text-white fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Changelog Entry</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editChangelogForm" method="POST"
                  action="{{ $isEditOld ? route('superadmin.changelog.update', old('entry_id')) : '#' }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="_form" value="edit">
                <input type="hidden" name="entry_id" id="editChangelogEntryId" value="{{ $isEditOld ? old('entry_id') : '' }}">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="editChangelogTitle"
                               class="form-control @error('title') is-invalid @enderror"
                               value="{{ $isEditOld ? old('title') : '' }}" required maxlength="255">
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="editChangelogDescription"
                                  class="form-control @error('description') is-invalid @enderror"
                                  rows="3">{{ $isEditOld ? old('description') : '' }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select name="type" id="editChangelogType" class="form-select @error('type') is-invalid @enderror" required>
                                @foreach($types as $key => $label)
                                <option value="{{ $key }}" @selected($isEditOld && old('type') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" id="editChangelogStatus" class="form-select @error('status') is-invalid @enderror" required>
                                @foreach($statuses as $key => $label)
                                <option value="{{ $key }}" @selected($isEditOld && old('status') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Module / Area</label>
                            <select name="module_area" id="editChangelogModule" class="form-select @error('module_area') is-invalid @enderror">
                                <option value="">— Select —</option>
                                @foreach($moduleAreas as $area)
                                <option value="{{ $area }}" @selected($isEditOld && old('module_area') === $area)>{{ $area }}</option>
                                @endforeach
                            </select>
                            @error('module_area')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Occurred At <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="occurred_at" id="editChangelogOccurred"
                                   class="form-control @error('occurred_at') is-invalid @enderror"
                                   value="{{ $isEditOld ? old('occurred_at') : '' }}" required>
                            @error('occurred_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Recorded By</label>
                            <input type="text" name="changed_by_name" id="editChangelogBy"
                                   class="form-control @error('changed_by_name') is-invalid @enderror"
                                   value="{{ $isEditOld ? old('changed_by_name') : '' }}" maxlength="150">
                            @error('changed_by_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-2"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

@include('partials.confirm-modal')

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var editForm = document.getElementById('editChangelogForm');
    if (!editForm) return;

    var fields = {
        title: document.getElementById('editChangelogTitle'),
        description: document.getElementById('editChangelogDescription'),
        type: document.getElementById('editChangelogType'),
        status: document.getElementById('editChangelogStatus'),
        module: document.getElementById('editChangelogModule'),
        occurred: document.getElementById('editChangelogOccurred'),
        by: document.getElementById('editChangelogBy')
    };
    var entryIdField = document.getElementById('editChangelogEntryId');

    document.querySelectorAll('.js-edit-changelog').forEach(function (btn) {
        btn.addEventListener('click', function () {
            editForm.action = btn.getAttribute('data-url');
            entryIdField.value = btn.getAttribute('data-id') || '';
            fields.title.value = btn.getAttribute('data-title') || '';
            fields.description.value = btn.getAttribute('data-description') || '';
            fields.type.value = btn.getAttribute('data-type') || '';
            fields.status.value = btn.getAttribute('data-status') || '';

            // module_area isn't validated against the fixed list server-side (see the model),
            // so an entry saved before this dropdown existed — or tagged with a module added
            // here later — can carry a value with no matching <option>. Setting .value on a
            // <select> silently clears the selection in that case; detect it and add the
            // stored value as its own option rather than losing the tag on the next save.
            var moduleVal = btn.getAttribute('data-module') || '';
            fields.module.value = moduleVal;
            if (moduleVal && fields.module.value !== moduleVal) {
                var extraOpt = document.createElement('option');
                extraOpt.value = moduleVal;
                extraOpt.textContent = moduleVal;
                fields.module.appendChild(extraOpt);
                fields.module.value = moduleVal;
            }

            fields.occurred.value = btn.getAttribute('data-occurred') || '';
            fields.by.value = btn.getAttribute('data-by') || '';
        });
    });

    @if($isAddOld)
    if (typeof bootstrap !== 'undefined') {
        var addEl = document.getElementById('addChangelogModal');
        if (addEl) new bootstrap.Modal(addEl).show();
    }
    @endif

    @if($isEditOld)
    if (typeof bootstrap !== 'undefined') {
        new bootstrap.Modal(editForm.closest('.modal')).show();
    }
    @endif
})();
</script>
@endpush

@endsection
