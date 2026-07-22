{{-- ── NEWS & ANNOUNCEMENTS WIDGET ──────────────────────────────────────── --}}
@include('partials.dashboard-widgets-style')

@php
    // $latestAnnouncements is a LengthAwarePaginator (DashboardController::getAnnouncements),
    // seeded to page 1. Fall back gracefully if a plain collection is ever passed.
    $annTotal    = method_exists($latestAnnouncements, 'total') ? $latestAnnouncements->total() : $latestAnnouncements->count();
    $annLastPage = method_exists($latestAnnouncements, 'lastPage') ? $latestAnnouncements->lastPage() : 1;
    $annCurrent  = method_exists($latestAnnouncements, 'currentPage') ? $latestAnnouncements->currentPage() : 1;
@endphp

<style>
    .ann-toggle { cursor: pointer; user-select: none; }
    .ann-toggle .ann-chevron { transition: transform 0.2s ease; }
    /* Bootstrap adds .collapsed to the trigger when the target is collapsed,
       and removes it when expanded. Rotate the chevron in the expanded state. */
    .ann-toggle:not(.collapsed) .ann-chevron { transform: rotate(180deg); }
    .ann-pager-btn { border:1px solid #fcd34d; background:#fffbeb; color:#b45309; border-radius:8px;
                     padding:4px 12px; font-size:12px; font-weight:600; line-height:1.4; transition:background .15s ease; }
    .ann-pager-btn:hover:not(:disabled) { background:#fef3c7; }
    .ann-pager-btn:disabled { opacity:.45; cursor:not-allowed; }
</style>

<div class="section-header">
    <div class="section-icon" style="background:#fef3c7;">
        <i class="bi bi-megaphone-fill" style="font-size:16px;color:#d97706;"></i>
    </div>
    <h6>News &amp; Announcements</h6>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card dash-widget" style="min-height:auto;">
            <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#b45309);padding:16px 22px 12px;">
                <div class="d-flex align-items-center gap-3">
                    <div class="widget-icon"><i class="bi bi-megaphone-fill"></i></div>
                    <div>
                        <div class="widget-number" style="font-size:28px;">{{ $annTotal }}</div>
                        <div class="widget-label">{{ $annTotal === 1 ? 'Announcement' : 'Announcements' }}</div>
                    </div>
                </div>
            </div>
            <div class="widget-body" style="padding:16px 22px;">
                <div id="ann-list">
                    @include('partials.announcement-items', ['announcements' => $latestAnnouncements, 'isFirstPage' => true])
                </div>

                @if($annLastPage > 1)
                <div id="ann-pager" class="d-flex align-items-center justify-content-between gap-2 pt-3 mt-1 border-top"
                     data-feed-url="{{ route('announcements.feed') }}"
                     data-current="{{ $annCurrent }}"
                     data-last="{{ $annLastPage }}">
                    <button type="button" class="ann-pager-btn" data-ann-prev disabled>
                        <i class="bi bi-chevron-left"></i> Prev
                    </button>
                    <span class="text-muted" style="font-size:12px;">
                        Page <span data-ann-current>{{ $annCurrent }}</span> of <span data-ann-last>{{ $annLastPage }}</span>
                    </span>
                    <button type="button" class="ann-pager-btn" data-ann-next>
                        Next <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($annLastPage > 1)
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    const pager = document.getElementById('ann-pager');
    const list  = document.getElementById('ann-list');
    if (!pager || !list) return;

    const prevBtn  = pager.querySelector('[data-ann-prev]');
    const nextBtn  = pager.querySelector('[data-ann-next]');
    const curLabel = pager.querySelector('[data-ann-current]');
    const feedUrl  = pager.dataset.feedUrl;
    const lastPage = parseInt(pager.dataset.last, 10) || 1;
    let page       = parseInt(pager.dataset.current, 10) || 1;
    let loading    = false;

    function syncButtons() {
        prevBtn.disabled = loading || page <= 1;
        nextBtn.disabled = loading || page >= lastPage;
    }

    async function go(target) {
        if (loading || target < 1 || target > lastPage || target === page) return;
        loading = true;
        syncButtons();
        try {
            const url = feedUrl + (feedUrl.includes('?') ? '&' : '?') + 'page=' + target;
            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            list.innerHTML = data.html;                 // server-rendered + Blade-escaped
            page = data.current_page || target;
            curLabel.textContent = page;
        } catch (e) {
            // Leave the current page in place on failure — nothing destructive happened.
            console.error('Announcement feed load failed:', e);
        } finally {
            loading = false;
            syncButtons();
        }
    }

    prevBtn.addEventListener('click', () => go(page - 1));
    nextBtn.addEventListener('click', () => go(page + 1));
    syncButtons();
})();
</script>
@endif
