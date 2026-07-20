@extends('layouts.app')
@section('title', 'Claude API')
@section('page-title', 'Claude API')

@section('content')
<div class="container-fluid" style="max-width:760px;">

    <div class="d-flex align-items-center gap-3 mb-3">
        <div class="ca-hero"><i class="bi bi-robot"></i></div>
        <div>
            <h5 class="mb-0 fw-bold">Claude API</h5>
            <div class="text-muted small">The Anthropic API key that powers <strong>receipt scanning (OCR)</strong> on the claim forms. Superadmin only.</div>
        </div>
    </div>

    {{-- Current status --}}
    @php $active = $setting->isActive(); $hasKey = (bool) $setting->getRawKey(); @endphp
    <div class="alert d-flex align-items-center gap-2 py-2 {{ $active ? 'alert-success' : ($hasKey ? 'alert-warning' : 'alert-secondary') }}">
        <i class="bi {{ $active ? 'bi-check-circle-fill' : ($hasKey ? 'bi-pause-circle-fill' : 'bi-slash-circle') }}"></i>
        <div class="small">
            @if($active)
                <strong>OCR is active</strong> — receipts are scanned with <strong>{{ $setting->modelLabel() }}</strong>.
            @elseif($hasKey)
                <strong>A key is saved, but OCR is switched off.</strong> Turn it on below to start scanning.
            @else
                <strong>OCR is inactive.</strong> Enter an API key below and switch it on to activate receipt scanning.
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('superadmin.claude-api.update') }}" id="caForm">
                @csrf

                {{-- Enable toggle --}}
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="caEnabled" name="enabled" value="1" @checked($setting->enabled)>
                    <label class="form-check-label fw-semibold" for="caEnabled">Enable receipt OCR (scan receipts with Claude)</label>
                    <div class="form-text">When off, the Scan button is hidden and users type receipt details manually. Nothing else is affected.</div>
                </div>

                {{-- API key --}}
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-1">Anthropic API Key</label>
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="password" name="api_key" id="caKey" class="form-control" autocomplete="off"
                               style="min-width:280px;flex:1;"
                               placeholder="{{ $hasKey ? 'Key saved ('.$setting->maskedKey().') — leave blank to keep it' : 'sk-ant-…' }}">
                        <button type="button" class="btn btn-outline-primary" id="caTest"><i class="bi bi-lightning-charge me-1"></i>Test key</button>
                    </div>
                    <div class="form-text">
                        Create a key at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>
                        (the Anthropic API bills separately from any Claude Pro / ChatGPT subscription — add a little credit).
                        The key is stored <strong>encrypted</strong> and never shown again in full.
                    </div>
                    <div id="caTestResult" class="small mt-2 d-none py-2 px-3 rounded"></div>
                </div>

                {{-- Model --}}
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-1">Model</label>
                    <select name="model" id="caModel" class="form-select" style="max-width:420px;">
                        @foreach($models as $id => $label)
                            <option value="{{ $id }}" @selected($setting->model === $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Haiku is the cheapest and handles receipts well; pick a stronger model only if you need more accuracy on messy receipts.</div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>

    <div class="text-muted small mt-3">
        <i class="bi bi-info-circle me-1"></i>This overrides any provider set in the server's <code>.env</code>. Cost is roughly a fraction of a cent per receipt on Haiku.
    </div>
</div>
@endsection

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .ca-hero { width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,#7c3aed,#4f46e5); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; box-shadow:0 4px 10px rgba(79,70,229,.3); }
</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    const testBtn = document.getElementById('caTest');
    const keyEl   = document.getElementById('caKey');
    const modelEl = document.getElementById('caModel');
    const resultEl = document.getElementById('caTestResult');
    const token   = document.querySelector('#caForm input[name="_token"]').value;

    function showResult(ok, message) {
        resultEl.className = 'small mt-2 py-2 px-3 rounded ' + (ok
            ? 'bg-success-subtle text-success-emphasis'
            : 'bg-danger-subtle text-danger-emphasis');
        resultEl.innerHTML = '<i class="bi ' + (ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + ' me-1"></i>' + message;
    }

    testBtn.addEventListener('click', function () {
        const original = testBtn.innerHTML;
        testBtn.disabled = true;
        testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';
        resultEl.classList.add('d-none');

        const fd = new FormData();
        fd.append('_token', token);
        fd.append('api_key', keyEl.value);      // blank -> server tests the saved key
        fd.append('model', modelEl.value);

        fetch('{{ route('superadmin.claude-api.test') }}', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(r => r.json())
        .then(d => {
            resultEl.classList.remove('d-none');
            showResult(!!d.ok, (d.message || 'Unknown response') + '');
        })
        .catch(() => {
            resultEl.classList.remove('d-none');
            showResult(false, 'Test request failed — please try again.');
        })
        .finally(() => {
            testBtn.disabled = false;
            testBtn.innerHTML = original;
        });
    });
})();
</script>
@endpush
