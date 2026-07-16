@extends('layouts.app')

@section('title', 'Email Workflow Automation')
@section('page-title', 'Email Workflow')

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .ewf-hero { background:linear-gradient(135deg,#1A6FE8,#4B9EFF); color:#fff; border-radius:14px; padding:22px 24px; margin-bottom:20px; position:relative; overflow:hidden; }
    .ewf-hero::after { content:''; position:absolute; right:-40px; top:-60px; width:180px; height:180px; border-radius:50%; background:rgba(255,255,255,.10); }
    .ewf-hero h3 { font-weight:700; margin:0 0 4px; font-size:20px; position:relative; z-index:1; }
    .ewf-hero p  { margin:0; opacity:.9; font-size:13px; position:relative; z-index:1; max-width:640px; }

    .ewf-pipeline { display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:11px; color:#64748b; }
    .ewf-pipeline .stage { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:999px; background:#f1f5f9; white-space:nowrap; }
    .ewf-pipeline .stage.ok { background:#dcfce7; color:#166534; }
    .ewf-pipeline .stage.missing { background:#fef2f2; color:#991b1b; }
    .ewf-pipeline .arrow { color:#cbd5e1; }

    .ewf-table th { background:#fafafa; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }
    .ewf-table td { vertical-align:middle; font-size:13px; }

    /* Active toggle (form switch) */
    .ewf-switch { display:inline-flex; align-items:center; gap:8px; }
    .ewf-switch .form-check-input { width:40px; height:21px; cursor:pointer; }
    .ewf-switch .form-check-input:disabled { cursor:not-allowed; }

    .ewf-run-badge { font-size:9px; text-transform:uppercase; letter-spacing:.4px; padding:2px 6px; }

    .empty-state { text-align:center; padding:48px 20px; color:#64748b; }
    .empty-state .bi { font-size:46px; color:#cbd5e1; }

    [data-theme="dark"] .ewf-table th { background:#0f172a; color:#94a3b8; }
    [data-theme="dark"] .ewf-pipeline .stage { background:#1e293b; color:#cbd5e1; }
</style>
@endpush

@section('content')
<div class="ewf-hero">
    <h3><i class="bi bi-robot me-2"></i>Email Workflow Automation</h3>
    <p>Build no-code automations that watch an inbox, detect documents like invoices &amp; receipts, file the attachments into cloud storage by month, and log every one to a spreadsheet — all on a schedule.</p>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-muted small">
        {{ $workflows->total() }} workflow{{ $workflows->total() === 1 ? '' : 's' }}
    </div>
    <a href="{{ route('it.automation.email-workflow.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> New Workflow
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        @if($workflows->count() === 0)
            <div class="empty-state">
                <i class="bi bi-inboxes d-block mb-3"></i>
                <h6 class="fw-semibold mb-1">No workflows yet</h6>
                <p class="small mb-3">Create your first automation to start capturing documents from email.</p>
                <a href="{{ route('it.automation.email-workflow.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> New Workflow
                </a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table ewf-table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="min-width:200px;">Workflow</th>
                            <th>Pipeline</th>
                            <th>Status</th>
                            <th>Last run</th>
                            <th>Captured</th>
                            <th class="text-end" style="min-width:170px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($workflows as $wf)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $wf->name ?: 'Untitled workflow' }}</div>
                                <div class="text-muted" style="font-size:11px;">
                                    by {{ $wf->owner->name ?? '—' }} · updated {{ $wf->updated_at->diffForHumans() }}
                                </div>
                            </td>
                            <td>
                                <div class="ewf-pipeline">
                                    <span class="stage {{ $wf->email_connection_id ? 'ok' : 'missing' }}">
                                        <i class="bi bi-envelope"></i> Email
                                    </span>
                                    <i class="bi bi-arrow-right arrow"></i>
                                    <span class="stage">Rules</span>
                                    <i class="bi bi-arrow-right arrow"></i>
                                    <span class="stage {{ $wf->storage_connection_id ? 'ok' : 'missing' }}">
                                        <i class="bi bi-folder"></i> Storage
                                    </span>
                                    <i class="bi bi-arrow-right arrow"></i>
                                    <span class="stage {{ $wf->log_connection_id ? 'ok' : 'missing' }}">
                                        <i class="bi bi-table"></i> Log
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="badge {{ $wf->statusBadgeClass() }} text-capitalize">{{ $wf->status }}</span>
                            </td>
                            <td class="text-muted">
                                @if($wf->last_run_at)
                                    <div>{{ $wf->last_run_at->diffForHumans() }}</div>
                                    @if($wf->latestRun)
                                        <span class="badge {{ $wf->latestRun->statusBadgeClass() }} ewf-run-badge">
                                            {{ $wf->latestRun->status }}
                                        </span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $wf->captured_count }}</td>
                            <td class="text-end">
                                <div class="d-inline-flex align-items-center gap-2">
                                    {{-- Active status toggle (form POST — CSP-safe) --}}
                                    <form method="POST" action="{{ route('it.automation.email-workflow.toggle', $wf->id) }}" class="ewf-switch m-0">
                                        @csrf
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input ewf-toggle" type="checkbox"
                                                   role="switch"
                                                   {{ $wf->isActive() ? 'checked' : '' }}
                                                   {{ (!$wf->isActive() && !$wf->isReadyToActivate()) ? 'disabled' : '' }}
                                                   aria-label="Toggle active"
                                                   title="{{ (!$wf->isActive() && !$wf->isReadyToActivate()) ? 'Finish setup to activate' : 'Toggle active' }}">
                                        </div>
                                    </form>
                                    {{-- Run now (synchronous sweep — button disables while it works) --}}
                                    <form method="POST" action="{{ route('it.automation.email-workflow.run', $wf->id) }}"
                                          class="m-0 ewf-run-form">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary ewf-run-btn"
                                                {{ $wf->isReadyToActivate() ? '' : 'disabled' }}
                                                title="{{ $wf->isReadyToActivate() ? 'Run this workflow now' : 'Finish setup to run' }}">
                                            <i class="bi bi-play-fill"></i>
                                        </button>
                                    </form>
                                    {{-- Run history --}}
                                    <button type="button" class="btn btn-sm btn-outline-secondary ewf-history-btn"
                                            data-id="{{ $wf->id }}" data-name="{{ $wf->name ?: 'Untitled workflow' }}"
                                            data-url="{{ route('it.automation.email-workflow.runs', $wf->id) }}"
                                            title="Run history">
                                        <i class="bi bi-clock-history"></i>
                                    </button>
                                    {{-- Edit --}}
                                    <a href="{{ route('it.automation.email-workflow.edit', $wf->id) }}"
                                       class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    {{-- Delete --}}
                                    <button type="button" class="btn btn-sm btn-outline-danger ewf-delete-btn"
                                            data-id="{{ $wf->id }}" data-name="{{ $wf->name ?: 'Untitled workflow' }}"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if($workflows->hasPages())
    <div class="mt-3">{{ $workflows->links() }}</div>
@endif

{{-- Run history: recent runs + the documents they captured --}}
<div class="modal fade" id="ewfHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-clock-history me-2"></i>Run history — <span id="ewfHistoryName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="ewfHistoryBody">
                <div class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm me-2"></div>Loading…
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Delete confirmation modal (HITL gate for the destructive action) --}}
<div class="modal fade" id="ewfDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Delete workflow</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Delete <strong id="ewfDeleteName">this workflow</strong>? This removes its configuration and run history.
                Connected accounts and any files already saved to your storage are <em>not</em> affected.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="ewfDeleteForm" action="">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    // Active toggle: submit the parent form on change (no inline handlers — CSP).
    document.querySelectorAll('.ewf-toggle').forEach(function (el) {
        el.addEventListener('change', function () {
            this.closest('form').submit();
        });
    });

    // Delete: populate + show the confirm modal, point the form at the right id.
    var modalEl = document.getElementById('ewfDeleteModal');
    var modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    var baseAction = "{{ route('it.automation.email-workflow.index') }}";
    document.querySelectorAll('.ewf-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            var name = this.getAttribute('data-name');
            document.getElementById('ewfDeleteName').textContent = name;
            document.getElementById('ewfDeleteForm').setAttribute('action', baseAction + '/' + id);
            if (modal) modal.show();
        });
    });

    // Escape everything user/provider-supplied before it reaches innerHTML.
    function escHtml(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Run now: the sweep is synchronous and can take a while — make that visible
    // and block double-submits (each run spends Google API quota).
    document.querySelectorAll('.ewf-run-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('.ewf-run-btn');
            if (!btn) return;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        });
    });

    // Run history: fetch + render recent runs and captured documents.
    var histEl = document.getElementById('ewfHistoryModal');
    var histModal = histEl ? new bootstrap.Modal(histEl) : null;
    var histBody = document.getElementById('ewfHistoryBody');

    function runsTable(runs) {
        if (!runs.length) {
            return '<p class="text-muted mb-0">This workflow has not run yet. Press the play button to run it now.</p>';
        }
        var rows = runs.map(function (r) {
            return '<tr>'
                + '<td><span class="badge ' + escHtml(r.badge) + '">' + escHtml(r.status) + '</span></td>'
                + '<td>' + escHtml(r.started_at) + '</td>'
                + '<td>' + escHtml(r.by) + '<div class="text-muted" style="font-size:11px;">' + escHtml(r.trigger) + '</div></td>'
                + '<td>' + (r.duration === null ? '—' : escHtml(r.duration) + 's') + '</td>'
                + '<td>' + escHtml(r.scanned) + '</td>'
                + '<td>' + escHtml(r.matched) + '</td>'
                + '<td class="fw-semibold">' + escHtml(r.captured) + '</td>'
                + '<td>' + escHtml(r.skipped) + '</td>'
                + '<td>' + escHtml(r.failed) + '</td>'
                + '<td class="text-danger" style="font-size:11px;">' + escHtml(r.error || '') + '</td>'
                + '</tr>';
        }).join('');

        return '<h6 class="mb-2">Recent runs</h6>'
            + '<div class="table-responsive mb-4"><table class="table table-sm align-middle mb-0">'
            + '<thead><tr><th>Status</th><th>Started</th><th>By</th><th>Took</th>'
            + '<th>Scanned</th><th>Matched</th><th>Captured</th><th>Skipped</th><th>Failed</th><th>Error</th></tr></thead>'
            + '<tbody>' + rows + '</tbody></table></div>';
    }

    function capturesTable(captures) {
        if (!captures.length) return '';
        var rows = captures.map(function (c) {
            var name = c.url
                ? '<a href="' + escHtml(c.url) + '" target="_blank" rel="noopener noreferrer">' + escHtml(c.name) + '</a>'
                : escHtml(c.name);
            var amount = c.amount ? escHtml((c.currency || '') + ' ' + c.amount) : '<span class="text-muted">—</span>';
            var review = c.needs_review ? ' <span class="badge bg-warning text-dark">review</span>' : '';
            return '<tr>'
                + '<td>' + name + review + '</td>'
                + '<td>' + escHtml(c.status) + '</td>'
                + '<td>' + amount + '</td>'
                + '<td>' + escHtml(c.logged_at || '—') + '</td>'
                + '<td class="text-danger" style="font-size:11px;">' + escHtml(c.error || '') + '</td>'
                + '</tr>';
        }).join('');

        return '<h6 class="mb-2">Captured documents</h6>'
            + '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'
            + '<thead><tr><th>File</th><th>Status</th><th>Amount</th><th>Logged</th><th>Error</th></tr></thead>'
            + '<tbody>' + rows + '</tbody></table></div>';
    }

    document.querySelectorAll('.ewf-history-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('ewfHistoryName').textContent = this.getAttribute('data-name');
            histBody.innerHTML = '<div class="text-center text-muted py-4">'
                + '<div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>';
            if (histModal) histModal.show();

            fetch(this.getAttribute('data-url'), { headers: { 'Accept': 'application/json' } })
                .then(function (res) { if (!res.ok) throw new Error(res.status); return res.json(); })
                .then(function (data) {
                    histBody.innerHTML = runsTable(data.runs || []) + capturesTable(data.captures || []);
                })
                .catch(function () {
                    histBody.innerHTML = '<p class="text-danger mb-0">Could not load run history.</p>';
                });
        });
    });
})();
</script>
@endpush
