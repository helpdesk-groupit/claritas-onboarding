{{-- ── BIRTHDAY BABIES OF THE MONTH ───────────────────────────────────── --}}
@php $currentMonth = \Carbon\Carbon::now()->format('F'); @endphp
<div class="mb-2 mt-2">
    <small class="text-muted fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;">
        <i class="bi bi-balloon-heart me-1"></i>Birthday Babies — {{ $currentMonth }}
    </small>
</div>
<div class="card mb-4" style="border-left:4px solid #ec4899;">
    <div class="card-body">
        @if($birthdayBabies->isEmpty())
            <div class="text-center py-3">
                <div style="width:48px;height:48px;background:#fce7f3;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;">
                    <i class="bi bi-balloon" style="font-size:22px;color:#ec4899;"></i>
                </div>
                <div class="text-muted small">No birthdays this month</div>
            </div>
        @else
            <div class="row g-2">
                @foreach($birthdayBabies as $baby)
                @php
                    $day = \Carbon\Carbon::parse($baby->date_of_birth)->day;
                    $displayName = $baby->preferred_name ?: $baby->full_name;
                    $isToday = \Carbon\Carbon::parse($baby->date_of_birth)->day === \Carbon\Carbon::now()->day;
                @endphp
                <div class="col-md-6 col-lg-4">
                    <div class="d-flex align-items-center gap-2 p-2 rounded"
                         style="background:{{ $isToday ? '#fdf2f8' : '#fafafa' }};border:1px solid {{ $isToday ? '#f9a8d4' : '#f1f5f9' }};">
                        <div style="width:36px;height:36px;background:{{ $isToday ? '#ec4899' : '#fce7f3' }};border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            @if($isToday)
                                <i class="bi bi-balloon-heart-fill" style="font-size:16px;color:#fff;"></i>
                            @else
                                <span style="font-size:13px;font-weight:700;color:#ec4899;">{{ $day }}</span>
                            @endif
                        </div>
                        <div style="min-width:0;">
                            <div class="fw-semibold text-truncate" style="font-size:13px;color:#1e293b;">
                                {{ $displayName }}
                                @if($isToday)
                                    <span class="badge ms-1" style="background:#ec4899;font-size:9px;vertical-align:middle;">Today 🎂</span>
                                @endif
                            </div>
                            <div class="text-truncate" style="font-size:11px;color:#64748b;">
                                {{ $baby->designation }}{{ $baby->company ? ' · '.$baby->company : '' }}
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>