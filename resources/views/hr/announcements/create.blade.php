@extends('layouts.app')
@section('title', 'New Announcement')
@section('page-title', 'New Announcement')

@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('announcements.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form action="{{ route('announcements.store') }}" method="POST" enctype="multipart/form-data">
    @csrf

    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <i class="bi bi-megaphone-fill text-primary"></i>
            <h6 class="mb-0 fw-bold">Announcement Details</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">

                {{-- Title --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title"
                           class="form-control @error('title') is-invalid @enderror"
                           value="{{ old('title') }}" placeholder="e.g. Public Holiday Notice — Hari Raya" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Body --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Message <span class="text-muted fw-normal small">(max 500 characters)</span></label>
                    <textarea name="body" id="createBodyField" rows="5" maxlength="500"
                              class="form-control @error('body') is-invalid @enderror"
                              placeholder="Write your announcement here..."
                              oninput="updateCounter('createBodyField','createBodyCounter')">{{ old('body') }}</textarea>
                    @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="d-flex justify-content-between mt-1">
                        <span class="form-text">Supports line breaks. Employees will receive this as an email notification.</span>
                        <span id="createBodyCounter" class="form-text text-end" style="flex-shrink:0;">{{ strlen(old('body','')) }}/500</span>
                    </div>
                </div>

                {{-- Target Companies --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Target Companies</label>
                    <div class="form-text mb-2">Leave all unchecked to send to <strong>all companies</strong>.</div>
                    @if($companies->isEmpty())
                        <div class="text-muted small">No companies registered yet.</div>
                    @else
                    <div class="border rounded p-3 d-flex flex-wrap gap-3" style="max-height:180px;overflow-y:auto;">
                        @foreach($companies as $c)
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="companies[]"
                                   value="{{ $c }}" id="co_{{ $loop->index }}"
                                   {{ in_array($c, old('companies', [])) ? 'checked' : '' }}>
                            <label class="form-check-label" for="co_{{ $loop->index }}">{{ $c }}</label>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>

                {{-- Attachments --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Attachments <span class="text-muted fw-normal small">(optional — PDF or image, max 10 MB each, up to 10 files)</span></label>

                    {{-- Drop zone display --}}
                    <div id="attachDropzone"
                         class="rounded-3 border-2 border-dashed p-4 text-center"
                         style="border:2px dashed #cbd5e1;cursor:pointer;transition:border-color 0.2s;"
                         onclick="document.getElementById('attachInput').click()"
                         ondragover="event.preventDefault();this.style.borderColor='#2563eb';"
                         ondragleave="this.style.borderColor='#cbd5e1';"
                         ondrop="handleAttachDrop(event)">
                        <i class="bi bi-cloud-upload" style="font-size:28px;color:#94a3b8;"></i>
                        <div class="text-muted mt-1" style="font-size:13px;">Click or drag files here</div>
                        <div class="text-muted" style="font-size:11px;">PDF, JPG, PNG, GIF, WebP &middot; max 10 MB each</div>
                    </div>

                    {{-- Hidden real input --}}
                    <input type="file" id="attachInput" name="attachments[]"
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.webp"
                           multiple style="display:none"
                           onchange="renderAttachPreviews(this.files)">

                    @error('attachments')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    @error('attachments.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror

                    {{-- Preview list --}}
                    <div id="attachPreviewList" class="mt-2 d-flex flex-wrap gap-2"></div>

                    {{-- Hidden inputs to carry selected files --}}
                    <div id="attachHiddenInputs"></div>
                </div>

            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex align-items-center justify-content-between gap-3">
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                Employees from the selected companies (or all, if none checked) will receive an email notification upon publishing.
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('announcements.index') }}" class="btn btn-outline-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-send me-1"></i>Publish Announcement
                </button>
            </div>
        </div>
    </div>

</form>

<script>
function updateCounter(fieldId, counterId) {
    var field = document.getElementById(fieldId);
    var counter = document.getElementById(counterId);
    if (!field || !counter) return;
    var len = field.value.length;
    counter.textContent = len + '/500';
    counter.style.color = len >= 480 ? '#ef4444' : '#94a3b8';
}

// Track selected files across multiple "add" actions
let selectedFiles = [];

function renderAttachPreviews(newFiles) {
    // Merge new files with existing
    const arr = Array.from(newFiles);
    arr.forEach(f => {
        if (!selectedFiles.find(x => x.name === f.name && x.size === f.size)) {
            selectedFiles.push(f);
        }
    });
    rebuildPreviewsAndInput();
}

function removeAttach(idx) {
    selectedFiles.splice(idx, 1);
    rebuildPreviewsAndInput();
}

function rebuildPreviewsAndInput() {
    const list = document.getElementById('attachPreviewList');
    list.innerHTML = '';

    selectedFiles.forEach((f, i) => {
        const ext = f.name.split('.').pop().toLowerCase();
        const isPdf = ext === 'pdf';
        const icon = isPdf ? 'bi-file-earmark-pdf text-danger' : 'bi-image text-primary';
        const item = document.createElement('div');
        item.className = 'd-flex align-items-center gap-1 px-2 py-1 rounded border bg-light';
        item.style = 'font-size:12px;max-width:220px;';
        item.innerHTML = `
            <i class="bi ${icon}" style="font-size:15px;flex-shrink:0;"></i>
            <span class="text-truncate flex-fill" title="${f.name}">${f.name}</span>
            <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1"
                    onclick="removeAttach(${i})" style="font-size:14px;line-height:1;">&times;</button>
        `;
        list.appendChild(item);
    });

    // Rebuild the actual file input using DataTransfer
    const dt = new DataTransfer();
    selectedFiles.forEach(f => dt.items.add(f));
    document.getElementById('attachInput').files = dt.files;
}

function handleAttachDrop(e) {
    e.preventDefault();
    document.getElementById('attachDropzone').style.borderColor = '#cbd5e1';
    renderAttachPreviews(e.dataTransfer.files);
}
</script>

@endsection
