@extends('layouts.app')

@section('title', 'Ticket ' . $ticket->ticket_number)
@section('page-title', 'Ticket ' . $ticket->ticket_number)

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    /* ── Layout ─────────────────────────────────────────────────────────── */
    .ticket-grid { display: grid; grid-template-columns: minmax(0, 33%) minmax(0, 67%); gap: 18px; }
    @media (max-width: 991.98px) { .ticket-grid { grid-template-columns: 1fr; } }

    /* ── Chat panel (high-contrast styling — stands out from page background) ── */
    .chat-panel {
        display: flex; flex-direction: column;
        height: calc(100vh - 170px); min-height: 480px;
        background: #fff; border-radius: 14px; overflow: hidden;
        box-shadow: 0 8px 28px rgba(37, 99, 235, 0.12), 0 2px 6px rgba(0, 0, 0, 0.06);
        border-top: 4px solid #2563eb;
    }
    .chat-header {
        padding: 16px 20px; border-bottom: 1px solid #bfdbfe;
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        display: flex; align-items: center; justify-content: space-between;
    }
    .chat-header .fw-semibold { color: #1e3a8a; }
    .chat-thread {
        flex: 1; overflow-y: auto; padding: 18px;
        background-image: linear-gradient(to bottom, #f0f9ff 0%, #e0f2fe 100%);
    }
    .chat-form { border-top: 2px solid #bfdbfe; padding: 14px 16px; background: #fff; position: sticky; bottom: 0; }
    .chat-closed-banner { background: #fef3c7; border: 1px solid #fbbf24; border-radius: 10px; padding: 14px 18px; color: #78350f; text-align: center; font-size: 13px; line-height: 1.5; }
    .chat-closed-banner i { font-size: 20px; vertical-align: middle; margin-right: 6px; }
    .chat-closed-banner .hint { display: block; margin-top: 4px; color: #92400e; font-style: italic; font-size: 12px; }
    [data-theme="dark"] .chat-closed-banner { background: #1f1408; border-color: #92400e; color: #fde68a; }
    [data-theme="dark"] .chat-closed-banner .hint { color: #fcd34d; }
    [data-theme="dark"] .chat-panel { background: #1e293b; border-top-color: #3b82f6; box-shadow: 0 8px 28px rgba(0,0,0,0.4); }
    [data-theme="dark"] .chat-header { background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); border-color: #334155; }
    [data-theme="dark"] .chat-header .fw-semibold { color: #bfdbfe; }
    [data-theme="dark"] .chat-thread { background: #0f172a; background-image: none; }
    [data-theme="dark"] .chat-form { background: #1e293b; border-color: #334155; }

    /* ── Message bubbles (Tailwind-equivalent inline so it works without rebuild) ── */
    .msg-row { display: flex; gap: 10px; margin-bottom: 14px; max-width: 78%; }
    .msg-row.mine { margin-left: auto; flex-direction: row-reverse; }
    .msg-avatar { width: 36px; height: 36px; border-radius: 9999px; object-fit: cover; flex-shrink: 0; background: #cbd5e1; }
    .msg-bubble { padding: 9px 13px; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.06); font-size: 14px; line-height: 1.45; color: #1e293b; word-wrap: break-word; max-width: 100%; }
    .msg-row.mine .msg-bubble { background: #2563eb; color: #fff; border-bottom-right-radius: 4px; }
    .msg-row:not(.mine) .msg-bubble { border-bottom-left-radius: 4px; }
    .msg-meta { font-size: 11px; color: #64748b; margin: 2px 6px; }
    .msg-row.mine .msg-meta { text-align: right; }
    .msg-attachment { margin-top: 6px; }
    .msg-attachment img { max-width: 220px; max-height: 220px; border-radius: 8px; display: block; cursor: pointer; }
    .msg-attachment .file-link { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; background: rgba(255,255,255,0.15); border-radius: 8px; color: inherit; text-decoration: none; font-size: 13px; }
    .msg-row:not(.mine) .msg-attachment .file-link { background: #f1f5f9; color: #1e293b; }

    /* ── Composer ───────────────────────────────────────────────────────── */
    .composer { display: flex; align-items: flex-end; gap: 8px; }
    .composer textarea { flex: 1; resize: none; border: 1px solid #cbd5e1; border-radius: 12px; padding: 9px 12px; font-size: 14px; min-height: 42px; max-height: 140px; outline: none; }
    .composer textarea:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.15); }
    .composer .icon-btn { width: 42px; height: 42px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 18px; color: #475569; }
    .composer .send-btn { background: #2563eb; color: #fff; border: none; }
    .composer .send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .file-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px; background: #e0f2fe; color: #075985; border-radius: 999px; font-size: 12px; margin-bottom: 8px; }
    .file-chip .remove { cursor: pointer; font-weight: 700; }

    .empty-thread { color: #94a3b8; text-align: center; padding-top: 60px; font-size: 14px; }
</style>
@endpush

@section('content')
<div class="ticket-grid">

    {{-- ═══════════════════════════════════════════════════════════════════
         LEFT (33%) — Ticket metadata cards
         ═══════════════════════════════════════════════════════════════════ --}}
    <div>
        @php $isAdminEditor = Auth::user()->isSuperadmin() || Auth::user()->isSystemAdmin(); @endphp
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="fw-semibold mb-0">{{ $ticket->ticket_number }}</h6>
                    <div class="d-flex align-items-center gap-2">
                        {{-- Edit (department re-route) — only when this page was reached from
                             /tickets/manage AND the viewer can manage this dept. Same gating as
                             the Assign-PIC / Update-Status controls; absent on /tickets. --}}
                        @if($canManage)
                            <a href="{{ route('tickets.edit-admin', $ticket) }}"
                               class="btn btn-outline-secondary btn-sm py-0 px-2"
                               title="Re-route this ticket to a different department">
                                <i class="bi bi-arrow-left-right"></i> Edit
                            </a>
                        @endif
                        <span class="badge bg-{{ $ticket->statusColor() }}" id="ticketStatusBadge">{{ $ticket->status }}</span>
                    </div>
                </div>
                <p class="fw-semibold mb-2">{{ $ticket->subject }}</p>
                <p class="small text-muted mb-3" style="white-space:pre-wrap;">{{ $ticket->description }}</p>

                @php $attachments = $ticket->attachments; @endphp
                @if($attachments->isNotEmpty())
                    <div class="border-top pt-2 mt-2">
                        <div class="small fw-semibold text-muted mb-2">
                            <i class="bi bi-paperclip me-1"></i>Attachments ({{ $attachments->count() }})
                        </div>
                        <div class="d-flex flex-column gap-1">
                            @foreach($attachments as $att)
                                <a href="{{ $att->url() }}" target="_blank" rel="noopener"
                                   class="d-flex align-items-center gap-2 text-decoration-none small"
                                   style="color:#1e293b; padding:4px 6px; border-radius:6px; background:#f8fafc;">
                                    <i class="bi {{ $att->is_image ? 'bi-image' : 'bi-file-earmark-pdf' }} text-primary"></i>
                                    <span class="text-truncate flex-grow-1" title="{{ $att->original_name }}">{{ $att->original_name }}</span>
                                    <span class="text-muted" style="font-size:11px;">{{ $att->humanSize() }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="fw-semibold mb-3"><i class="bi bi-info-circle me-1"></i> Details</h6>
                <div class="small">
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Company</span>
                        <span class="badge bg-light text-dark border">
                            {{ $ticket->company?->name ?? '—' }}
                        </span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Department</span>
                        <span class="badge bg-light text-dark border">{{ $ticket->department }}</span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Priority</span>
                        <span class="badge bg-{{ $ticket->priorityColor() }}">{{ $ticket->priority }}</span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Created by</span>
                        <span>{{ $ticket->creator?->name ?? '—' }}</span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Created at</span>
                        <span>{{ $ticket->created_at?->format('d M Y, H:i') }}</span>
                    </div>
                    @if($ticket->resolved_at)
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Resolved at</span>
                        <span>{{ $ticket->resolved_at?->format('d M Y, H:i') }}</span>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="fw-semibold mb-3"><i class="bi bi-person-badge me-1"></i> PIC (Person In Charge)</h6>
                @if($ticket->assignee)
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <img src="{{ $ticket->assignee->profile_picture_url }}" class="rounded-circle"
                             style="width:38px;height:38px;object-fit:cover;" alt="">
                        <div>
                            <div class="fw-semibold small">{{ $ticket->assignee->name }}</div>
                            <div class="text-muted" style="font-size:12px;">{{ str_replace('_', ' ', ucwords($ticket->assignee->role)) }}</div>
                        </div>
                    </div>
                @else
                    <p class="small text-muted mb-2">No PIC assigned yet.</p>
                @endif

                @if($canManage)
                    @if($ticket->assigned_to)
                        {{-- A PIC is already assigned — must be removed before reassignment --}}
                        <form method="POST" action="{{ route('tickets.assign-pic', $ticket) }}">
                            @csrf
                            <input type="hidden" name="assigned_pic_user_id" value="">
                            <small class="text-muted d-block mb-2" style="font-size:12px;">
                                <i class="bi bi-info-circle me-1"></i>
                                To reassign this ticket, remove the current PIC first.
                            </small>
                            <button type="submit" class="btn btn-sm btn-outline-warning w-100">
                                <i class="bi bi-person-x me-1"></i> Remove PIC
                            </button>
                        </form>
                    @else
                        {{-- No PIC assigned — pick one --}}
                        @if($assigneePool->isEmpty())
                            <div class="alert alert-warning small mb-0 py-2 px-3">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                No eligible PIC found for this ticket. The creator's company may not have any matching managers — only superadmin/system admin can be assigned.
                            </div>
                        @else
                            <form method="POST" action="{{ route('tickets.assign-pic', $ticket) }}">
                                @csrf
                                <label class="form-label small fw-semibold">Assign PIC</label>
                                <select name="assigned_pic_user_id" class="form-select form-select-sm mb-2" required>
                                    <option value="" disabled selected>— Select PIC —</option>
                                    @foreach($assigneePool as $candidate)
                                        <option value="{{ $candidate->id }}">
                                            {{ $candidate->name }} ({{ str_replace('_',' ', ucwords($candidate->role)) }})
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <i class="bi bi-check2-circle me-1"></i> Assign PIC
                                </button>
                            </form>
                        @endif
                    @endif
                @endif
            </div>
        </div>

        @if($canManage || $ticket->assigned_to === Auth::id())
        <div class="card">
            <div class="card-body">
                <h6 class="fw-semibold mb-3"><i class="bi bi-flag me-1"></i> Status</h6>
                <form method="POST" action="{{ route('tickets.status', $ticket) }}">
                    @csrf
                    <select name="status" class="form-select form-select-sm mb-2">
                        @foreach($statuses as $s)
                            <option value="{{ $s }}" @selected($ticket->status === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-arrow-repeat me-1"></i> Update Status
                    </button>
                </form>
            </div>
        </div>
        @endif

        @php $editLogs = $ticket->editLogs()->with('editor')->get(); @endphp
        @if($editLogs->isNotEmpty())
            <div class="card mt-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">
                        <i class="bi bi-clock-history me-1"></i> Edit log
                        <span class="badge bg-secondary ms-1">{{ $editLogs->count() }}</span>
                    </h6>
                        <div class="d-flex flex-column gap-2">
                            @foreach($editLogs as $log)
                                <div class="border rounded p-2" style="background:#f8fafc;font-size:12px;">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <span class="fw-semibold">{{ $log->editor?->name ?? 'Unknown' }}</span>
                                        <span class="text-muted" style="font-size:11px;">{{ $log->created_at?->format('d M Y, H:i') }}</span>
                                    </div>
                                    @foreach($log->changes as $field => $diff)
                                        @php
                                            $fromVal = is_array($diff) ? ($diff['from'] ?? '') : '';
                                            $toVal   = is_array($diff) ? ($diff['to']   ?? '') : '';
                                            // Display friendly company name when the field is company_id.
                                            if ($field === 'company_id') {
                                                $fromVal = \App\Models\Company::where('id', $fromVal)->value('name') ?? $fromVal;
                                                $toVal   = \App\Models\Company::where('id', $toVal)->value('name')   ?? $toVal;
                                            }
                                            // Truncate long subject/description bodies in the log preview.
                                            if (in_array($field, ['subject','description'], true)) {
                                                $fromVal = \Illuminate\Support\Str::limit((string) $fromVal, 60);
                                                $toVal   = \Illuminate\Support\Str::limit((string) $toVal, 60);
                                            }
                                        @endphp
                                        <div class="mb-1">
                                            <span class="text-muted text-uppercase" style="font-size:10px;letter-spacing:.4px;">
                                                {{ str_replace('_', ' ', $field) }}
                                            </span>
                                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                                <span class="badge bg-light text-dark border" style="font-weight:normal;">{{ $fromVal !== '' ? $fromVal : '—' }}</span>
                                                <i class="bi bi-arrow-right text-muted"></i>
                                                <span class="badge bg-light text-dark border" style="font-weight:normal;">{{ $toVal !== '' ? $toVal : '—' }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                    @if($log->note)
                                        <div class="mt-1 pt-1 border-top" style="font-style:italic;color:#475569;">
                                            <i class="bi bi-chat-quote me-1"></i>{{ $log->note }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════
         RIGHT (67%) — Chat panel
         ═══════════════════════════════════════════════════════════════════ --}}
    <div>
        <div class="chat-panel">
            <div class="chat-header">
                <div>
                    <div class="fw-semibold"><i class="bi bi-chat-dots me-1"></i> Conversation</div>
                    <div class="text-muted" style="font-size:12px;">
                        Live updates every 3 seconds.
                        <span id="pollStatus" class="ms-1"></span>
                    </div>
                </div>
                @php $backUrl = request('from') === 'manage' ? route('tickets.manage') : route('tickets.index'); @endphp
                <a href="{{ $backUrl }}" class="btn btn-sm btn-light">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <div class="chat-thread" id="chatThread"
                 data-ticket-id="{{ $ticket->id }}"
                 data-current-user-id="{{ Auth::id() }}"
                 data-poll-url="{{ route('tickets.messages.index', $ticket) }}"
                 data-send-url="{{ route('tickets.messages.store', $ticket) }}">
                @if($ticket->messages->isEmpty())
                    <div class="empty-thread" id="emptyThread">
                        <i class="bi bi-chat-square-text" style="font-size:30px;"></i>
                        <p class="mt-2">No messages yet. Start the conversation below.</p>
                    </div>
                @endif
                {{-- Messages will be rendered by JS on initial poll for consistency --}}
            </div>

            <div class="chat-form">
                <div id="filePreview" class="d-none"></div>
                <form id="messageForm" class="composer" enctype="multipart/form-data">
                    @csrf
                    <button type="button" class="icon-btn" id="attachBtn" title="Attach file">
                        <i class="bi bi-paperclip"></i>
                    </button>
                    <input type="file" id="attachmentInput" name="attachments[]" hidden multiple
                           accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
                    <textarea id="messageInput" name="message" placeholder="Type a message…" rows="1"></textarea>
                    <button type="submit" class="icon-btn send-btn" id="sendBtn" title="Send">
                        <i class="bi bi-send"></i>
                    </button>
                </form>
                {{-- Shown when ticket is in a terminal status (Resolved/Closed) — replaces the composer --}}
                <div id="chatClosedBanner" class="chat-closed-banner" style="display:none;">
                    <i class="bi bi-lock-fill"></i>
                    <strong>This ticket is <span id="chatClosedStatus">Resolved</span>.</strong> Chat is no longer available.
                    @if($canManage || $ticket->assigned_to === Auth::id())
                        <span class="hint">Re-open the ticket via the status dropdown to continue the conversation.</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    'use strict';

    var thread       = document.getElementById('chatThread');
    var form         = document.getElementById('messageForm');
    var input        = document.getElementById('messageInput');
    var attachBtn    = document.getElementById('attachBtn');
    var attachInput  = document.getElementById('attachmentInput');
    var filePreview  = document.getElementById('filePreview');
    var sendBtn      = document.getElementById('sendBtn');
    var pollStatus   = document.getElementById('pollStatus');
    var statusBadge  = document.getElementById('ticketStatusBadge');
    var emptyThread  = document.getElementById('emptyThread');
    var closedBanner = document.getElementById('chatClosedBanner');
    var closedStatus = document.getElementById('chatClosedStatus');

    // Statuses that disable the composer (kept in sync with Ticket::ARCHIVED_STATUSES)
    var ARCHIVED_STATUSES = ['Resolved', 'Closed'];

    var ticketId       = thread.getAttribute('data-ticket-id');
    var currentUserId  = parseInt(thread.getAttribute('data-current-user-id'), 10);
    var pollUrl        = thread.getAttribute('data-poll-url');
    var sendUrl        = thread.getAttribute('data-send-url');
    var csrfToken      = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var lastMessageId  = 0;
    var pollTimer      = null;
    var POLL_INTERVAL  = 3000;
    var pendingFiles   = []; // array of File objects (multi-file support)
    var MAX_ATTACHMENTS = 10;

    // ── Toggle composer vs "chat closed" banner based on ticket status ──
    function updateChatLockState(status) {
        if (!status) return;
        var locked = ARCHIVED_STATUSES.indexOf(status) !== -1;
        if (locked) {
            form.style.display = 'none';
            if (filePreview) filePreview.classList.add('d-none');
            if (closedStatus) closedStatus.textContent = status;
            if (closedBanner) closedBanner.style.display = 'block';
        } else {
            form.style.display = '';   // restore CSS-default (flex via .composer)
            if (closedBanner) closedBanner.style.display = 'none';
        }
    }

    // ── Safe HTML escape (no escHtml helper exists in this project) ──────
    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Render a single message into the thread ──────────────────────────
    function renderMessage(m) {
        // Dedupe: a message can arrive via both the send response and the
        // in-flight poll cycle. Skip if we've already drawn it.
        if (thread.querySelector('[data-msg-id="' + m.id + '"]')) {
            return;
        }

        if (emptyThread) {
            emptyThread.remove();
        }

        var row = document.createElement('div');
        row.className = 'msg-row' + (m.is_mine ? ' mine' : '');
        row.setAttribute('data-msg-id', m.id);

        // Avatar
        var avatar = document.createElement('img');
        avatar.className = 'msg-avatar';
        avatar.src = m.sender_avatar || '';
        avatar.alt = '';

        // Body wrapper
        var body = document.createElement('div');
        body.style.minWidth = '0';
        body.style.flex = '1';

        // Bubble
        var bubble = document.createElement('div');
        bubble.className = 'msg-bubble';

        // Sender name (only for "others")
        if (!m.is_mine) {
            var who = document.createElement('div');
            who.style.fontSize = '11px';
            who.style.fontWeight = '600';
            who.style.opacity = '0.7';
            who.style.marginBottom = '3px';
            who.textContent = m.sender_name;
            bubble.appendChild(who);
        }

        // Message text
        if (m.message) {
            var msgText = document.createElement('div');
            // Preserve line breaks; escape HTML
            msgText.innerHTML = escapeHtml(m.message).replace(/\n/g, '<br>');
            bubble.appendChild(msgText);
        }

        // Attachments (multi — server normalises legacy single-attachment too)
        var attList = m.attachments || [];
        if (attList.length > 0) {
            attList.forEach(function (a) {
                var att = document.createElement('div');
                att.className = 'msg-attachment';
                if (a.is_image) {
                    var img = document.createElement('img');
                    img.src = a.url;
                    img.alt = a.name || 'Attachment';
                    img.addEventListener('click', function () { window.open(a.url, '_blank'); });
                    att.appendChild(img);
                } else {
                    var link = document.createElement('a');
                    link.className = 'file-link';
                    link.href = a.url;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.innerHTML = '<i class="bi bi-paperclip"></i> ' + escapeHtml(a.name || 'Download');
                    att.appendChild(link);
                }
                bubble.appendChild(att);
            });
        }

        // Meta line (time)
        var meta = document.createElement('div');
        meta.className = 'msg-meta';
        meta.textContent = m.created_time || '';

        body.appendChild(bubble);
        body.appendChild(meta);

        row.appendChild(avatar);
        row.appendChild(body);
        thread.appendChild(row);
    }

    function scrollToBottom() {
        thread.scrollTop = thread.scrollHeight;
    }

    // ── Poll for new messages ────────────────────────────────────────────
    function poll() {
        if (document.visibilityState !== 'visible') return;

        var url = pollUrl + '?after_id=' + lastMessageId;
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                if (Array.isArray(data.messages) && data.messages.length > 0) {
                    var atBottom = (thread.scrollHeight - thread.scrollTop - thread.clientHeight) < 80;
                    data.messages.forEach(function (m) {
                        renderMessage(m);
                        if (m.id > lastMessageId) lastMessageId = m.id;
                    });
                    if (atBottom) scrollToBottom();
                }
                // Reflect status changes from server (badge + composer lock state)
                if (data.ticket && data.ticket.status) {
                    if (statusBadge && statusBadge.textContent !== data.ticket.status) {
                        statusBadge.textContent = data.ticket.status;
                    }
                    updateChatLockState(data.ticket.status);
                }
                pollStatus.textContent = '';
            })
            .catch(function () {
                pollStatus.textContent = ' (offline)';
            });
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(poll, POLL_INTERVAL);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { poll(); startPolling(); }
        else { stopPolling(); }
    });

    // ── Composer: multi-file picker + preview chips ─────────────────────
    attachBtn.addEventListener('click', function () { attachInput.click(); });

    attachInput.addEventListener('change', function () {
        if (!attachInput.files || attachInput.files.length === 0) return;
        // ADD to existing pendingFiles (cumulative across multiple picks)
        Array.from(attachInput.files).forEach(function (f) {
            if (pendingFiles.length >= MAX_ATTACHMENTS) return;
            pendingFiles.push(f);
        });
        // Reset the file input so the same file can be re-picked if removed
        attachInput.value = '';
        renderFilePreview();
    });

    function renderFilePreview() {
        if (pendingFiles.length === 0) {
            filePreview.classList.add('d-none');
            filePreview.innerHTML = '';
            return;
        }
        filePreview.classList.remove('d-none');
        filePreview.innerHTML = '';

        pendingFiles.forEach(function (file, idx) {
            var chip = document.createElement('span');
            chip.className = 'file-chip';
            chip.innerHTML = '<i class="bi bi-paperclip"></i> ' + escapeHtml(file.name) +
                             ' (' + Math.ceil(file.size / 1024) + ' KB)';

            var remove = document.createElement('span');
            remove.className = 'remove';
            remove.textContent = '×';
            remove.title = 'Remove this attachment';
            remove.addEventListener('click', function () {
                pendingFiles.splice(idx, 1);
                renderFilePreview();
            });

            chip.appendChild(remove);
            filePreview.appendChild(chip);
        });

        if (pendingFiles.length >= MAX_ATTACHMENTS) {
            var note = document.createElement('span');
            note.className = 'small text-muted ms-2';
            note.textContent = 'Max ' + MAX_ATTACHMENTS + ' files reached.';
            filePreview.appendChild(note);
        }
    }

    // ── Auto-grow textarea ───────────────────────────────────────────────
    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(140, input.scrollHeight) + 'px';
    });

    // ── Submit on Enter (Shift+Enter = newline) ──────────────────────────
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    // ── Send message ─────────────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var text = input.value.trim();
        if (!text && pendingFiles.length === 0) return;

        sendBtn.disabled = true;

        var fd = new FormData();
        fd.append('_token', csrfToken);
        fd.append('message', text);
        pendingFiles.forEach(function (file) {
            fd.append('attachments[]', file);
        });

        fetch(sendUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        })
        .then(function (res) {
            sendBtn.disabled = false;
            if (!res.ok) {
                alert((res.data && res.data.error) || 'Failed to send message.');
                return;
            }
            input.value = '';
            input.style.height = 'auto';
            pendingFiles = [];
            attachInput.value = '';
            renderFilePreview();

            if (res.data.message) {
                renderMessage(res.data.message);
                if (res.data.message.id > lastMessageId) lastMessageId = res.data.message.id;
                scrollToBottom();
            }
            if (res.data.ticket && res.data.ticket.status) {
                if (statusBadge) statusBadge.textContent = res.data.ticket.status;
                updateChatLockState(res.data.ticket.status);
            }
        })
        .catch(function () {
            sendBtn.disabled = false;
            alert('Network error — message not sent.');
        });
    });

    // ── Initial state: lock composer if ticket already in a terminal status
    updateChatLockState(@json($ticket->status));

    // ── Initial load: pull all messages from id=0 ────────────────────────
    poll();
    startPolling();
})();
</script>
@endpush
