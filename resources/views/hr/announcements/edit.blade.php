@extends('layouts.app')
@section('title', 'Edit Announcement')
@section('page-title', 'Edit Announcement')

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

<form action="{{ route('announcements.update', $announcement) }}" method="POST" enctype="multipart/form-data">
    @csrf @method('PUT')

    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #f59e0b;">
            <i class="bi bi-pencil-fill text-warning"></i>
            <h6 class="mb-0 fw-bold">Edit Announcement</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">

                {{-- Title --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title"
                           class="form-control @error('title') is-invalid @enderror"
                           value="{{ old('title', $announcement->title) }}" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Body --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Message <span class="text-muted fw-normal small">(max 500 characters)</span></label>
                    <textarea name="body" id="editBodyField" rows="5" maxlength="500"
                              class="form-control @error('body') is-invalid @enderror"
                              oninput="updateCounter('editBodyField','editBodyCounter')">{{ old('body', $announcement->body) }}</textarea>
                    @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="d-flex justify-content-end mt-1">
                        <span id="editBodyCounter" class="form-text">{{ strlen(old('body', $announcement->body ?? '')) }}/500</span>
                    </div>
                </div>

                {{-- Target Companies --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Target Companies</label>
                    <div class="form-text mb-2">Leave all unchecked to send to <strong>all companies</strong>.</div>
                    @if($companies->isEmpty())
                        <div class="text-muted small">No companies registered yet.</div>
                    @else
                    @php $selectedCompanies = old('companies', $announcement->companies ?? []); @endphp
                    <div class="border rounded p-3 d-flex flex-wrap gap-3" style="max-height:180px;overflow-y:auto;">
                        @foreach($companies as $c)
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="companies[]"
                                   value="{{ $c }}" id="eco_{{ $loop->index }}"
                                   {{ in_array($c, $selectedCompanies) ? 'checked' : '' }}>
                            <label class="form-check-label" for="eco_{{ $loop->index }}">{{ $c }}</label>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>

                {{-- Existing Attachments --}}
                @if(!empty($announcement->attachment_paths))
                <div class="col-12">
                    <label class="form-label fw-semibold">Current Attachments</label>
                    <div class="d-flex flex-wrap gap-2" id="existingAttachList">
                        @foreach($announcement->attachment_paths as $i => $path)
                        @php $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); @endphp
                        <div class="d-flex align-items-center gap-1 px-2 py-1 rounded border bg-light"
                             id="existingItem_{{ $i }}" style="font-size:12px;">
                            <i class="bi bi-{{ $ext === 'pdf' ? 'file-earmark-pdf text-danger' : 'image text-primary' }}" style="font-size:15px;flex-shrink:0;"></i>
                            <a href="{{ asset('storage/'.$path) }}" target="_blank"
                               class="text-truncate text-decoration-none text-dark flex-fill" style="max-width:180px;" title="{{ basename($path) }}">
                                Attachment {{ $i + 1 }}
                            </a>
                            {{-- Hidden keep input — disabled = removed --}}
                            <input type="hidden" name="keep_attachments[]" value="{{ $path }}"
                                   id="keepInput_{{ $i }}">
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1"
                                    style="font-size:14px;line-height:1;"
                                    onclick="removeExisting({{ $i }})" title="Remove">&times;</button>
                        </div>
                        @endforeach
                    </div>
                    <div class="form-text">Click &times; to remove an existing attachment.</div>
                </div>
                @endif

                {{-- New Attachments --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Add New Attachments <span class="text-muted fw-normal small">(PDF or image, max 10 MB each, up to 10 total)</span></label>

                    <div id="attachDropzone"
                         class="rounded-3 p-4 text-center"
                         style="border:2px dashed #cbd5e1;cursor:pointer;transition:border-color 0.2s;"
                         onclick="document.getElementById('attachInput').click()"
                         ondragover="event.preventDefault();this.style.borderColor='#2563eb';"
                         ondragleave="this.style.borderColor='#cbd5e1';"
                         ondrop="handleAttachDrop(event)">
                        <i class="bi bi-cloud-upload" style="font-size:28px;color:#94a3b8;"></i>
                        <div class="text-muted mt-1" style="font-size:13px;">Click or drag files here</div>
                        <div class="text-muted" style="font-size:11px;">PDF, JPG, PNG, GIF, WebP &middot; max 10 MB each</div>
                    </div>

                    <input type="file" id="attachInput" name="attachments[]"
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.webp"
                           multiple style="display:none"
                           onchange="renderAttachPreviews(this.files)">

                    @error('attachments')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    @error('attachments.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror

                    <div id="attachPreviewList" class="mt-2 d-flex flex-wrap gap-2"></div>
                </div>

            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex align-items-center justify-content-between gap-3">
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                Editing will <strong>not</strong> re-send email notifications to employees.
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('announcements.index') }}" class="btn btn-outline-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="bi bi-save me-1"></i>Save Changes
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
document.addEventListener('DOMContentLoaded', function() {
    updateCounter('editBodyField', 'editBodyCounter');
});

// Remove existing attachment — disables the hidden keep input so it won't be submitted
function removeExisting(i) {
    document.getElementById('keepInput_' + i).disabled = true;
    const item = document.getElementById('existingItem_' + i);
    item.style.opacity = '0.4';
    item.style.textDecoration = 'line-through';
    item.querySelector('button').disabled = true;
}

// New file selection
let selectedFiles = [];

function renderAttachPreviews(newFiles) {
    Array.from(newFiles).forEach(f => {
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
        const icon = ext === 'pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-image text-primary';
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
