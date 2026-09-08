@extends('layouts.app')
@section('title', 'Announcements')
@section('page-title', 'News & Announcements')

@section('content')

@php
    // Every control on this page is gated by the same helpers the controller
    // refuses on, so a hidden button is never the only thing standing between
    // a withheld capability and the action.
    $u             = Auth::user();
    $canPublish    = $u->canPublishAnnouncement();
    $publishGrant  = $u->canDoAnnouncementAction('publish');
    $canEdit       = $u->canDoAnnouncementAction('edit');
    $canDelete  = $u->canDoAnnouncementAction('delete');
    $canOthers  = $u->canManageOthersAnnouncements();
@endphp

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    @if($canPublish)
    <a href="{{ route('announcements.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New Announcement
    </a>
    @elseif($publishGrant)
        {{-- Holds the Publish capability but cannot edit the Title, which is
             required — so the form could never be submitted. Saying so beats a
             button that dead-ends on a validation error they cannot clear. --}}
        <span class="text-muted small">
            <i class="bi bi-info-circle me-1"></i>
            Publishing is unavailable because you do not have edit access to the announcement Title.
        </span>
    @endif

    @if($canOthers)
        <span class="badge bg-light text-secondary border ms-auto">
            <i class="bi bi-people me-1"></i>Showing announcements from all authors
        </span>
    @endif
</div>

<div class="card">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <i class="bi bi-megaphone text-primary me-1"></i>
        <h6 class="mb-0 fw-bold">All Announcements</h6>
        <span class="ms-auto badge bg-primary rounded-pill">{{ $announcements->total() }}</span>
    </div>
    <div class="card-body p-0">
        @forelse($announcements as $a)
        <div class="p-3 {{ !$loop->last ? 'border-bottom' : '' }}">
            <div class="d-flex align-items-start gap-3">
                <div style="width:44px;height:44px;background:#dbeafe;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-megaphone-fill" style="font-size:18px;color:#2563eb;"></i>
                </div>
                <div class="flex-fill">
                    <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                        <div>
                            <div class="fw-semibold" style="font-size:15px;">{{ $a->title }}</div>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @if(!empty($a->companies))
                                    @foreach($a->companies as $co)
                                        <span class="badge bg-primary" style="font-size:11px;">{{ $co }}</span>
                                    @endforeach
                                @else
                                    <span class="badge bg-secondary" style="font-size:11px;">All Companies</span>
                                @endif
                                @if(!empty($a->attachment_paths))
                                    <span class="badge bg-light text-secondary border" style="font-size:11px;">
                                        <i class="bi bi-paperclip me-1"></i>{{ count($a->attachment_paths) }} attachment(s)
                                    </span>
                                @endif
                            </div>
                        </div>
                        @php
                            // An author may always act on their own; reaching a
                            // colleague's needs the explicit grant. Mirrors
                            // AnnouncementController::authorizeOwner().
                            $isMine  = $a->created_by === Auth::id();
                            $canActOn = $isMine || $canOthers;
                        @endphp
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <span class="text-muted" style="font-size:12px;">
                                {{ $a->created_at->format('d/m/Y, h:i A') }}
                            </span>
                            @if($canEdit && $canActOn)
                            <a href="{{ route('announcements.edit', $a) }}"
                               class="btn btn-outline-warning btn-sm" style="padding:2px 8px;" title="Edit">
                                <i class="bi bi-pencil" style="font-size:12px;"></i>
                            </a>
                            @endif
                            @if($canDelete && $canActOn)
                            {{-- js-confirm, not onsubmit="confirm(...)": CSP blocks inline
                                 handlers, so the native confirm never ran and this
                                 destructive action submitted unchallenged. --}}
                            <form action="{{ route('announcements.destroy', $a) }}" method="POST"
                                  class="js-confirm"
                                  data-confirm-title="Delete announcement"
                                  data-confirm="Delete &quot;{{ $a->title }}&quot;? This cannot be undone."
                                  data-confirm-ok="Delete"
                                  data-confirm-variant="danger">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm" style="padding:2px 8px;" title="Delete">
                                    <i class="bi bi-trash" style="font-size:12px;"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </div>

                    <p class="text-muted mt-2 mb-0" style="font-size:13px;line-height:1.6;white-space:pre-line;">{{ \Illuminate\Support\Str::limit($a->body, 200) }}</p>

                    @if(!empty($a->attachment_paths))
                    <div class="mt-2 d-flex flex-wrap gap-1">
                        @foreach($a->attachment_paths as $i => $path)
                        @php $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); @endphp
                        <a href="{{ asset('storage/'.$path) }}" target="_blank"
                           class="btn btn-outline-primary btn-sm" style="font-size:12px;padding:2px 8px;">
                            <i class="bi bi-{{ in_array($ext,['pdf']) ? 'file-earmark-pdf' : 'image' }} me-1"></i>Attachment {{ $i+1 }}
                        </a>
                        @endforeach
                    </div>
                    @endif

                    <div class="text-muted mt-1" style="font-size:11px;">
                        Posted by {{ $a->creator?->employee?->full_name ?? $a->creator?->name ?? '—' }}@if($canOthers && $isMine) <span class="text-primary">(you)</span>@endif
                    </div>
                </div>
            </div>
        </div>
        @empty
        <div class="text-center py-5">
            <div style="width:56px;height:56px;background:#f1f5f9;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <i class="bi bi-megaphone" style="font-size:26px;color:#94a3b8;"></i>
            </div>
            <div class="text-muted">
                No announcements yet.@if($canPublish) <a href="{{ route('announcements.create') }}">Create one</a>.@endif
            </div>
        </div>
        @endforelse
    </div>
    @if($announcements->hasPages())
    <div class="card-footer bg-white border-top py-2">
        {{ $announcements->links() }}
    </div>
    @endif
</div>

@include('partials.confirm-modal')

@endsection
