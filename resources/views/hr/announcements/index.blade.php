@extends('layouts.app')
@section('title', 'Announcements')
@section('page-title', 'News & Announcements')

@section('content')

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="{{ route('announcements.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New Announcement
    </a>
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
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <span class="text-muted" style="font-size:12px;">
                                {{ $a->created_at->format('d M Y, h:i A') }}
                            </span>
                            <a href="{{ route('announcements.edit', $a) }}"
                               class="btn btn-outline-warning btn-sm" style="padding:2px 8px;" title="Edit">
                                <i class="bi bi-pencil" style="font-size:12px;"></i>
                            </a>
                            <form action="{{ route('announcements.destroy', $a) }}" method="POST"
                                  onsubmit="return confirm('Delete this announcement?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm" style="padding:2px 8px;" title="Delete">
                                    <i class="bi bi-trash" style="font-size:12px;"></i>
                                </button>
                            </form>
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
                        Posted by {{ $a->creator?->employee?->full_name ?? $a->creator?->name ?? '—' }}
                    </div>
                </div>
            </div>
        </div>
        @empty
        <div class="text-center py-5">
            <div style="width:56px;height:56px;background:#f1f5f9;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <i class="bi bi-megaphone" style="font-size:26px;color:#94a3b8;"></i>
            </div>
            <div class="text-muted">No announcements yet. <a href="{{ route('announcements.create') }}">Create one</a>.</div>
        </div>
        @endforelse
    </div>
    @if($announcements->hasPages())
    <div class="card-footer bg-white border-top py-2">
        {{ $announcements->links() }}
    </div>
    @endif
</div>

@endsection
