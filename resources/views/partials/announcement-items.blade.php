{{-- ── ANNOUNCEMENT CARD LIST ────────────────────────────────────────────────
     Shared by the dashboard widget (initial render) and the AJAX feed endpoint
     (AnnouncementController@feed) so both paint identical markup.

     Params:
       $announcements  Collection|paginator slice of Announcement models
       $isFirstPage    bool — only the newest announcement on page 1 gets the
                       "Latest" badge + always-open treatment; every other item
                       (including the first item on pages 2+) is a collapsed toggle
--}}
@php $isFirstPage = $isFirstPage ?? true; @endphp
@forelse($announcements as $ann)
    @php
        $attachments = $ann->attachment_paths ?? [];
        $imageExts   = ['jpg','jpeg','png','gif','webp'];
        $isLatest    = $loop->first && $isFirstPage;
        $bodyId      = 'ann-body-' . $ann->id;
    @endphp
    <div class="{{ !$loop->last ? 'border-bottom pb-3 mb-3' : '' }}">
        <div class="d-flex align-items-start gap-3">
            <div style="width:40px;height:40px;background:#fef3c7;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-megaphone-fill" style="font-size:18px;color:#d97706;"></i>
            </div>
            <div class="flex-fill" style="min-width:0;">

                {{-- Title row — clickable toggle for older items; always-open for the latest --}}
                <div class="d-flex align-items-center gap-2 flex-wrap @if(!$isLatest) ann-toggle collapsed @endif"
                     @if(!$isLatest)
                         role="button"
                         data-bs-toggle="collapse"
                         data-bs-target="#{{ $bodyId }}"
                         aria-expanded="false"
                         aria-controls="{{ $bodyId }}"
                     @endif>
                    <span class="fw-semibold" style="font-size:14px;color:#1e293b;">{{ $ann->title }}</span>
                    @if(!empty($ann->companies))
                        @foreach($ann->companies as $co)
                            <span class="badge" style="background:#f59e0b;font-size:10px;vertical-align:middle;">{{ $co }}</span>
                        @endforeach
                    @endif
                    @if($isLatest)
                        <span class="badge ms-1" style="background:#10b981;font-size:10px;">Latest</span>
                    @else
                        <i class="bi bi-chevron-down ms-auto ann-chevron" style="color:#94a3b8;font-size:14px;"></i>
                    @endif
                </div>

                {{-- Body + attachments — open for latest, collapsed for older ones --}}
                <div @if(!$isLatest) class="collapse" id="{{ $bodyId }}" @endif>
                    @if($ann->body)
                    <div class="mt-1" style="font-size:13px;line-height:1.6;color:#475569;white-space:pre-line;">{{ $ann->body }}</div>
                    @endif

                    {{-- Attachments — images inline, PDFs as styled link --}}
                    @if(!empty($attachments))
                    <div class="mt-2">
                        @foreach($attachments as $i => $path)
                        @php $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); @endphp
                        @if(in_array($ext, $imageExts))
                            <a href="{{ asset('storage/'.$path) }}" target="_blank" style="display:inline-block;margin:0 6px 6px 0;">
                                <img src="{{ asset('storage/'.$path) }}" alt="Attachment {{ $i+1 }}"
                                     style="max-width:100%;max-height:280px;border-radius:8px;border:1px solid #e2e8f0;object-fit:contain;display:block;">
                            </a>
                        @else
                            <a href="{{ asset('storage/'.$path) }}" target="_blank"
                               class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded border mb-2"
                               style="font-size:13px;color:#dc2626;text-decoration:none;background:#fff5f5;border-color:#fca5a5 !important;">
                                <i class="bi bi-file-earmark-pdf-fill" style="font-size:18px;"></i>
                                <span>PDF Attachment {{ $i+1 }}</span>
                                <i class="bi bi-box-arrow-up-right" style="font-size:11px;opacity:0.6;"></i>
                            </a>
                        @endif
                        @endforeach
                    </div>
                    @endif
                </div>

                {{-- Date/byline always visible so collapsed items still show context --}}
                <div class="text-muted mt-1" style="font-size:11px;">
                    {{ $ann->created_at->format('d/m/Y') }}
                    &bull; {{ $ann->creator?->employee?->full_name ?? $ann->creator?->name ?? 'HR' }}
                </div>

            </div>
        </div>
    </div>
@empty
    <div class="text-center py-3">
        <div style="width:44px;height:44px;background:#fef3c7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;">
            <i class="bi bi-megaphone" style="font-size:20px;color:#d97706;"></i>
        </div>
        <div class="text-muted small">No announcements at the moment.</div>
    </div>
@endforelse
