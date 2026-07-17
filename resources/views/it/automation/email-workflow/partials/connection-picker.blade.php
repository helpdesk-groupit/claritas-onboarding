{{--
    Connection picker partial.
    Props: $category ('email'|'storage'|'log'), $field (form field name),
           $selected (current connection id), $items (collection of connections),
           $formId (id of the wizard step's carrier form to associate the radio with).

    IMPORTANT: this partial renders its OWN <form> elements (add-credentials,
    delete). It must therefore be included OUTSIDE the wizard step's <form> —
    HTML forbids nested forms, and a nested </form> silently closes the outer
    form, dropping every field after it. The step form is a self-closed carrier
    form; the connection radio associates back to it via form="{{ $formId }}".
--}}
@php
    $enabledProviders = collect(\App\Support\Automation\ProviderRegistry::forCategory($category))
        ->where('enabled', true)->values();
    $stepFormId = $formId ?? null;
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
        <div class="text-muted small mb-2">No {{ $category }} accounts connected yet. Add credentials below.</div>
    @else
        @foreach($items as $conn)
            @php $prov = \App\Support\Automation\ProviderRegistry::find($conn->provider_id); @endphp
            <div class="conn-row">
                <div class="meta">
                    <i class="bi {{ $prov['icon'] ?? 'bi-plug' }} me-1"></i>
                    <strong>{{ \App\Support\Automation\ProviderRegistry::name($conn->provider_id) }}</strong>
                    @if($conn->account_label) · {{ $conn->account_label }} @endif
                    @php
                        $isConnected = $conn->isConnected();
                        $badge = $isConnected ? 'bg-success' : ($conn->status === 'error' || $conn->status === 'needs_reconnect' ? 'bg-danger' : 'bg-light text-dark border');
                    @endphp
                    <span class="badge {{ $badge }} ms-1">{{ str_replace('_',' ', $conn->status) }}</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="form-check m-0">
                        <input class="form-check-input" type="radio" name="{{ $field }}" value="{{ $conn->id }}"
                               @if($stepFormId) form="{{ $stepFormId }}" @endif
                               {{ (string) $selected === (string) $conn->id ? 'checked' : '' }}>
                        <span class="small">Use</span>
                    </label>
                    {{-- OAuth providers → run consent.
                         `workflow`/`step` tell the callback where to send the user back
                         to; without them it dumps everyone on the list page and the
                         connection they just authorized never gets selected here.
                         Shown for already-connected accounts too: a scope change in the
                         ProviderRegistry only takes effect on re-consent, and the old
                         token keeps working (badly) until then. --}}
                    @if($conn->isOAuth())
                        @php
                            $connectParams = ['connection' => $conn->id];
                            if (isset($workflow) && $workflow->exists) {
                                $connectParams['workflow'] = $workflow->id;
                                $connectParams['step'] = $step ?? 1;
                            }
                            $needsAuth = ! $conn->isConnected();
                        @endphp
                        <a href="{{ route('it.automation.email-workflow.connections.connect', $connectParams) }}"
                           class="btn btn-sm {{ $needsAuth ? 'btn-success' : 'btn-outline-secondary' }}"
                           title="{{ $needsAuth ? 'Authorize this account' : 'Re-authorize (needed after a permission change)' }}">
                            <i class="bi bi-box-arrow-up-right me-1"></i>{{ $needsAuth ? ($conn->status === 'needs_reconnect' ? 'Reconnect' : 'Connect') : 'Re-connect' }}
                        </a>
                    @endif
                    {{-- Email accounts can be re-verified in place. A green badge only
                         proves the account worked when it was added — a mailbox can have
                         IMAP switched off, or an app password revoked, at any time after.
                         Storage/log are OAuth-consent providers with no verify() on their
                         contracts, so the button is email-only. --}}
                    @if($category === 'email')
                        <button type="submit" form="testConn_{{ $conn->id }}" class="btn btn-sm btn-outline-secondary"
                                title="Sign in to this account now and report what happens">
                            <i class="bi bi-plug me-1"></i>Test
                        </button>
                    @endif
                    <button type="submit" form="delConn_{{ $conn->id }}" class="btn btn-sm btn-outline-danger" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            {{-- Action forms kept outside the wizard form to avoid nesting. --}}
            <form id="delConn_{{ $conn->id }}" method="POST"
                  action="{{ route('it.automation.email-workflow.connections.delete', $conn->id) }}" class="d-none">
                @csrf @method('DELETE')
            </form>
            @if($category === 'email')
                <form id="testConn_{{ $conn->id }}" method="POST"
                      action="{{ route('it.automation.email-workflow.connections.test', $conn->id) }}" class="d-none">
                    @csrf
                </form>
            @endif
        @endforeach
    @endif
</div>

{{-- Add-credentials form (collapsed). Posts to connections.save. --}}
<div class="collapse" id="addConn_{{ $category }}">
    <div class="border rounded p-3 mb-2" style="background:#f8fafc;">
        <form method="POST" action="{{ route('it.automation.email-workflow.connections.save') }}">
            @csrf
            <input type="hidden" name="category" value="{{ $category }}">

            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-4">
                    <label class="form-label small">Provider</label>
                    <select name="provider_id" class="form-select form-select-sm conn-provider-select"
                            data-scope="{{ $category }}" required>
                        @foreach($enabledProviders as $p)
                            <option value="{{ $p['id'] }}" data-auth="{{ $p['auth_type'] }}"
                                    data-host="{{ data_get($p, 'imap.host', '') }}"
                                    data-port="{{ data_get($p, 'imap.port', 993) }}"
                                    data-enc="{{ data_get($p, 'imap.encryption', 'ssl') }}">
                                {{ $p['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- ── OAuth fields (Gmail / Outlook) ── --}}
            <div class="conn-oauth-fields" data-scope="{{ $category }}">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small">OAuth Client ID</label>
                        <input type="text" class="form-control form-control-sm conn-oauth-input"
                               data-name="client_id" placeholder="xxxxxxxx.apps.googleusercontent.com">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">OAuth Client Secret</label>
                        <input type="password" class="form-control form-control-sm conn-oauth-input"
                               data-name="client_secret" placeholder="Stored encrypted — never shown again">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                    </div>
                </div>
                <div class="scope-list mt-2">
                    <strong>Scopes requested</strong> (least-privilege):
                    @foreach($enabledProviders as $p)
                        @if($p['auth_type'] === 'oauth')
                            <div class="conn-scope-info" data-provider="{{ $p['id'] }}" style="display:none;">
                                @forelse($p['scopes'] as $s)
                                    <code class="d-block">{{ $s }}</code>
                                @empty
                                    <span>No external scopes.</span>
                                @endforelse
                            </div>
                        @endif
                    @endforeach
                </div>
                <div class="form-text mt-1">
                    <i class="bi bi-shield-lock me-1"></i>
                    Create an OAuth client in the provider console. Add this redirect URI:
                    <code>{{ route('it.automation.email-workflow.connections.callback') }}</code>.
                    Credentials are encrypted at rest and never logged.
                </div>
            </div>

            {{-- ── IMAP fields (Generic IMAP / Yahoo) ── --}}
            <div class="conn-imap-fields" data-scope="{{ $category }}" style="display:none;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small">IMAP host</label>
                        <input type="text" class="form-control form-control-sm conn-imap-input"
                               data-name="imap_host" placeholder="imap.example.com">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Port</label>
                        <input type="number" class="form-control form-control-sm conn-imap-input"
                               data-name="imap_port" value="993">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Encryption</label>
                        <select class="form-select form-select-sm conn-imap-input" data-name="imap_encryption">
                            <option value="ssl">SSL</option>
                            <option value="tls">TLS</option>
                            <option value="starttls">STARTTLS</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 align-items-end mt-1">
                    <div class="col-md-5">
                        <label class="form-label small">Username / email</label>
                        <input type="text" class="form-control form-control-sm conn-imap-input"
                               data-name="imap_username" placeholder="you@example.com">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">App password</label>
                        <input type="password" class="form-control form-control-sm conn-imap-input"
                               data-name="imap_password" placeholder="App-specific password">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Connect</button>
                    </div>
                </div>
                <div class="form-text mt-1">
                    <i class="bi bi-shield-lock me-1"></i>
                    Generate an <strong>app password</strong> in your mail account's security settings (regular passwords are rejected by most providers). Stored encrypted, never logged.
                </div>
                <div class="form-text">
                    <i class="bi bi-check2-circle me-1"></i>
                    We sign in to the mailbox before saving it. If the login is refused — IMAP switched off for
                    that mailbox, wrong password, wrong port — nothing is stored and you'll be told why.
                    <strong>IMAP is a per-mailbox setting</strong>, so one mailbox can work while another on the
                    same host does not.
                </div>
            </div>
        </form>
    </div>
</div>

@once
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    // Toggle OAuth vs IMAP credential fields by the selected provider's auth type,
    // and only enable the active block's inputs so the right fields are submitted.
    document.querySelectorAll('.conn-provider-select').forEach(function (sel) {
        var form = sel.closest('form');
        var oauthBlock = form.querySelector('.conn-oauth-fields');
        var imapBlock  = form.querySelector('.conn-imap-fields');

        function setNames(block, on) {
            block.querySelectorAll('[data-name]').forEach(function (el) {
                if (on) { el.setAttribute('name', el.getAttribute('data-name')); el.disabled = false; }
                else    { el.removeAttribute('name'); el.disabled = true; }
            });
        }
        function sync() {
            var opt = sel.options[sel.selectedIndex];
            var auth = opt.getAttribute('data-auth');
            var isImap = auth === 'imap';
            imapBlock.style.display  = isImap ? '' : 'none';
            oauthBlock.style.display = isImap ? 'none' : '';
            setNames(imapBlock, isImap);
            setNames(oauthBlock, !isImap);

            // Prefill IMAP host/port/encryption from the provider preset (Yahoo, etc.).
            if (isImap) {
                var host = imapBlock.querySelector('[data-name="imap_host"]');
                var port = imapBlock.querySelector('[data-name="imap_port"]');
                var enc  = imapBlock.querySelector('[data-name="imap_encryption"]');
                if (host && !host.value) host.value = opt.getAttribute('data-host') || '';
                if (port) port.value = opt.getAttribute('data-port') || '993';
                if (enc)  enc.value  = opt.getAttribute('data-enc') || 'ssl';
            }

            // Scope list follows the OAuth provider.
            form.querySelectorAll('.conn-scope-info').forEach(function (el) {
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
