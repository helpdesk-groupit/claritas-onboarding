@extends('layouts.app')

@section('title', 'Social Media AI Strategist')
@section('page-title', 'Social Media AI Strategist')

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .sms-hero { background:linear-gradient(135deg,#7C3AED,#C026D3); color:#fff; border-radius:14px; padding:22px 24px; margin-bottom:20px; position:relative; overflow:hidden; }
    .sms-hero::after { content:''; position:absolute; right:-40px; top:-60px; width:180px; height:180px; border-radius:50%; background:rgba(255,255,255,.10); }
    .sms-hero h3 { font-weight:700; margin:0 0 4px; font-size:20px; position:relative; z-index:1; }
    .sms-hero p  { margin:0; opacity:.92; font-size:13px; position:relative; z-index:1; max-width:680px; }
    .sms-table th { background:#fafafa; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }
    .sms-table td { vertical-align:middle; font-size:13px; }
    .sms-progress { height:6px; border-radius:999px; background:#eef2f7; overflow:hidden; width:120px; }
    .sms-progress > span { display:block; height:100%; background:#7C3AED; }
    .empty-state { text-align:center; padding:48px 20px; color:#64748b; }
    .empty-state .bi { font-size:46px; color:#cbd5e1; }
    [data-theme="dark"] .sms-table th { background:#0f172a; color:#94a3b8; }
</style>
@endpush

@section('content')
<div class="sms-hero">
    <h3><i class="bi bi-megaphone me-2"></i>Social Media AI Strategist</h3>
    <p>A no-guessing strategy agent. Feed it the brief and any files, it interrogates the gaps, then writes a canon-governed social strategy — market intelligence, competitor leverage, a law &times; platform compliance matrix, channels, pillars, a 90-day roadmap and measurement — with live-sourced citations. Export as a deck, PDF or sheet.</p>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-muted small">
        {{ $strategies->total() }} {{ \Illuminate\Support\Str::plural('strategy', $strategies->total()) }}
    </div>
    <button type="button" class="btn btn-primary btn-sm" id="smsNewBtn">
        <i class="bi bi-plus-lg me-1"></i> New Strategy
    </button>
</div>

<div class="card">
    <div class="card-body p-0">
        @if($strategies->count() === 0)
            <div class="empty-state">
                <i class="bi bi-stars d-block mb-3"></i>
                <h6 class="fw-semibold mb-1">No strategies yet</h6>
                <p class="small mb-3">Start one to brief the agent and generate a full social strategy.</p>
                <button type="button" class="btn btn-primary btn-sm" id="smsNewBtnEmpty">
                    <i class="bi bi-plus-lg me-1"></i> New Strategy
                </button>
            </div>
        @else
            <div class="table-responsive">
                <table class="table sms-table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="min-width:220px;">Strategy</th>
                            <th>Status</th>
                            <th>Sections</th>
                            <th>Generated</th>
                            <th class="text-end" style="min-width:150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($strategies as $s)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $s->clientName() }}</div>
                                <div class="text-muted" style="font-size:11px;">
                                    {{ $s->intake('industry') ?: 'No industry set' }} · by {{ $s->owner->name ?? '—' }} · updated {{ $s->updated_at->diffForHumans() }}
                                </div>
                            </td>
                            <td>
                                <span class="badge {{ $s->statusBadgeClass() }} text-capitalize">{{ $s->status }}</span>
                            </td>
                            <td>
                                @php $ready = (int) ($s->ready_sections ?? 0); $total = count(\App\Models\SocialStrategy::SECTIONS); @endphp
                                <div class="d-flex align-items-center gap-2">
                                    <div class="sms-progress"><span style="width: {{ $total ? round($ready / $total * 100) : 0 }}%;"></span></div>
                                    <span class="text-muted" style="font-size:11px;">{{ $ready }}/{{ $total }}</span>
                                </div>
                            </td>
                            <td class="text-muted">
                                {{ $s->generated_at ? $s->generated_at->diffForHumans() : '—' }}
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex align-items-center gap-2">
                                    <a href="{{ route('it.automation.social-media-strategist.edit', $s->id) }}"
                                       class="btn btn-sm btn-outline-primary" title="Open">
                                        <i class="bi bi-box-arrow-in-right"></i> Open
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger sms-delete-btn"
                                            data-id="{{ $s->id }}" data-name="{{ $s->clientName() }}" title="Delete">
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

@if($strategies->hasPages())
    <div class="mt-3">{{ $strategies->links() }}</div>
@endif

{{-- New strategy: name it, then step into the intake wizard --}}
<div class="modal fade" id="smsNewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ route('it.automation.social-media-strategist.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-stars me-2"></i>New strategy</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small fw-semibold">Engagement / client name</label>
                    <input type="text" name="name" class="form-control" maxlength="120" required
                           placeholder="e.g. Aria Aesthetics KL — Q4 launch">
                    <div class="form-text">You can refine the exact brand name inside the intake wizard.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-right me-1"></i>Start intake</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Delete confirmation --}}
<div class="modal fade" id="smsDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Delete strategy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Delete <strong id="smsDeleteName">this strategy</strong>? This removes its intake, knowledge base files, and every generated section. This cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="smsDeleteForm" action="">
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
    var newModalEl = document.getElementById('smsNewModal');
    var newModal = newModalEl ? new bootstrap.Modal(newModalEl) : null;
    ['smsNewBtn', 'smsNewBtnEmpty'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b && newModal) b.addEventListener('click', function () { newModal.show(); });
    });

    var delModalEl = document.getElementById('smsDeleteModal');
    var delModal = delModalEl ? new bootstrap.Modal(delModalEl) : null;
    var baseAction = "{{ route('it.automation.social-media-strategist.index') }}";
    document.querySelectorAll('.sms-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('smsDeleteName').textContent = this.getAttribute('data-name');
            document.getElementById('smsDeleteForm').setAttribute('action', baseAction + '/' + this.getAttribute('data-id'));
            if (delModal) delModal.show();
        });
    });
})();
</script>
@endpush
