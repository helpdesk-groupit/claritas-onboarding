{{--
    Connection picker partial.
    Props: $category ('email'|'storage'|'log'), $field (form field name),
           $selected (current connection id), $items (collection of connections).

    Lets the user pick an existing connection, add new OAuth credentials
    (their own Google client id/secret), see the requested scopes, and remove
    a connection. The owning <form> is the wizard step form; the "add" form
    posts separately to connections.save.
--}}
@php
    $enabledProviders = collect(\App\Support\Automation\ProviderRegistry::forCategory($category))
        ->where('enabled', true);
@endphp

<div class="mb-2">
    <label class="form-label d-flex align-items-center justify-content-between">
        <span>Connected account</span>
        <button type="button" class="btn btn-sm btn-outline-primary"
                data-bs-toggle="collapse" data-bs-target="#addConn_{{ $category }}">
            <i class="bi bi-plus-lg me-1"></i>Add credentials
        </button>
    </label>

    @if($items->isEmpty())
        <div class="text-muted small mb-2">No {{ $category }} accounts connected yet. Add OAuth credentials below.</div>
    @else
        @foreach($items as $conn)
            <div class="conn-row">
                <div class="meta">
                    <i class="bi {{ \App\Support\Automation\ProviderRegistry::find($conn->provider_id)['icon'] ?? 'bi-plug' }} me-1"></i>
                    <strong>{{ \App\Support\Automation\ProviderRegistry::name($conn->provider_id) }}</strong>
                    @if($conn->account_label) · {{ $conn->account_label }} @endif
                    <span class="badge bg-light text-dark border ms-1">{{ str_replace('_',' ', $conn->status) }}</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="form-check m-0">
                        <input class="form-check-input" type="radio" name="{{ $field }}" value="{{ $conn->id }}"
                               {{ (string) $selected === (string) $conn->id ? 'checked' : '' }}>
                        <span class="small">Use</span>
                    </label>
                    @if(!$conn->isConnected())
                        {{-- Phase 2 wires this to the live OAuth consent redirect. --}}
                        <button type="button" class="btn btn-sm btn-success" disabled
                                title="OAuth consent flow is enabled in the next release">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Connect
                        </button>
                    @endif
                    <button type="submit" form="delConn_{{ $conn->id }}" class="btn btn-sm btn-outline-danger" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            {{-- Delete form kept outside the wizard form to avoid nesting. --}}
            <form id="delConn_{{ $conn->id }}" method="POST"
                  action="{{ route('it.automation.email-workflow.connections.delete', $conn->id) }}" class="d-none">
                @csrf @method('DELETE')
            </form>
        @endforeach
    @endif
</div>

{{-- Add-credentials form (collapsed). Posts to connections.save, separate from the step form. --}}
<div class="collapse" id="addConn_{{ $category }}">
    <div class="border rounded p-3 mb-2" style="background:#f8fafc;">
        <form method="POST" action="{{ route('it.automation.email-workflow.connections.save') }}">
            @csrf
            <input type="hidden" name="category" value="{{ $category }}">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Provider</label>
                    <select name="provider_id" class="form-select form-select-sm conn-provider-select"
                            data-category="{{ $category }}" required>
                        @foreach($enabledProviders as $p)
                            <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">OAuth Client ID</label>
                    <input type="text" name="client_id" class="form-control form-control-sm" required
                           placeholder="xxxxxxxx.apps.googleusercontent.com">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">OAuth Client Secret</label>
                    <input type="password" name="client_secret" class="form-control form-control-sm" required
                           placeholder="Stored encrypted — never shown again">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                </div>
            </div>
            <div class="scope-list mt-2">
                <strong>Scopes requested</strong> (least-privilege):
                @foreach($enabledProviders as $p)
                    <div class="conn-scope-info" data-provider="{{ $p['id'] }}" style="{{ $loop->first ? '' : 'display:none;' }}">
                        @forelse($p['scopes'] as $s)
                            <code class="d-block">{{ $s }}</code>
                        @empty
                            <span>No external scopes.</span>
                        @endforelse
                    </div>
                @endforeach
            </div>
            <div class="form-text mt-1">
                <i class="bi bi-shield-lock me-1"></i>
                Create an OAuth client in your Google Cloud Console (consent screen + the listed scopes). Credentials are encrypted at rest and never logged.
            </div>
        </form>
    </div>
</div>

@once
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    // Show the scope list for the selected provider (per add-credentials block).
    document.querySelectorAll('.conn-provider-select').forEach(function (sel) {
        var block = sel.closest('form');
        function sync() {
            block.querySelectorAll('.conn-scope-info').forEach(function (el) {
                el.style.display = (el.getAttribute('data-provider') === sel.value) ? '' : 'none';
            });
        }
        sel.addEventListener('change', sync);
        sync();
    });
})();
</script>
@endpush
@endonce
