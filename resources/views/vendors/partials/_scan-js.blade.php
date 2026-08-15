{{-- Drives the Add/Edit document modals on the vendor profile: upload → read → show the
     summary for correction → Save.

     CSP-safe throughout — no inline handlers anywhere, everything bound with
     addEventListener inside this nonce-protected block, and every value the server sends
     written with textContent rather than innerHTML. The summary of an uploaded document is
     third-party text; putting it through innerHTML is exactly the injection this module's
     doctrine spends a paragraph guarding the model against.

     THE TOKEN IS THE POINT. The read runs inside the upload request, so a long PDF can
     outlive the edge timeout on live: the browser sees a network error while PHP finishes
     the read and writes it to the staging row. The token is generated here and sent WITH
     the upload, so that work can be collected by polling instead of being paid for twice
     and then thrown away. --}}
@once
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    'use strict';

    var forms = document.querySelectorAll('[data-vnd-doc-form]');
    if (!forms.length) { return; }

    // Bounded recovery: ~2 minutes of polling at 4s. Past that the read has either failed
    // in a way the row records, or something is wrong that another poll will not fix.
    var POLL_EVERY_MS = 4000;
    var POLL_ATTEMPTS = 30;
    var MAX_BYTES = 10 * 1024 * 1024;

    function newToken() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        // Not security-sensitive on its own — the endpoint scopes every lookup to the
        // vendor AND the uploader — but it still has to be unique per upload.
        var out = '';
        for (var i = 0; i < 4; i++) { out += Math.random().toString(36).slice(2, 10); }
        return out.slice(0, 32);
    }

    forms.forEach(function (form) {
        var file = form.querySelector('[data-vnd-file]');
        var tokenInput = form.querySelector('[data-vnd-token]');
        var save = form.querySelector('[data-vnd-save]');
        var state = form.querySelector('[data-vnd-state]');
        var stateText = form.querySelector('[data-vnd-state-text]');
        var spinner = form.querySelector('[data-vnd-spinner]');
        var reading = form.querySelector('[data-vnd-reading]');
        var summary = form.querySelector('[data-vnd-summary]');
        var companies = form.querySelector('[data-vnd-companies]');
        var pointsWrap = form.querySelector('[data-vnd-points-wrap]');
        var points = form.querySelector('[data-vnd-points]');

        if (!file || !tokenInput || !save) { return; }

        var isNew = form.getAttribute('data-vnd-new') === '1';
        var scanUrl = form.getAttribute('data-vnd-scan-url');
        var statusUrl = form.getAttribute('data-vnd-status-url');
        var kind = form.getAttribute('data-vnd-kind');
        var csrf = form.querySelector('input[name="_token"]');
        var busy = false;

        function setState(text, working, tone) {
            if (!state) { return; }
            state.classList.remove('d-none', 'vnd-scan-warn', 'vnd-scan-ok');
            if (tone) { state.classList.add(tone); }
            if (spinner) { spinner.classList.toggle('d-none', !working); }
            if (stateText) { stateText.textContent = text; }
        }

        // Save is blocked only while a read is in flight, and — on a NEW document — until
        // one has produced a token. A read that FAILED still leaves a stored file and a
        // claimable token, so the document can still be filed with a summary typed by hand:
        // an unreadable document must never be an unfileable one.
        function refreshSave() {
            save.disabled = busy || (isNew && !tokenInput.value);
        }

        function renderPoints(list) {
            if (!points || !pointsWrap) { return; }
            points.textContent = '';
            if (!list || !list.length) {
                pointsWrap.classList.add('d-none');
                return;
            }
            list.forEach(function (point) {
                var li = document.createElement('li');
                li.textContent = point;
                points.appendChild(li);
            });
            pointsWrap.classList.remove('d-none');
        }

        function apply(payload) {
            tokenInput.value = payload.token || tokenInput.value;

            if (reading) { reading.classList.remove('d-none'); }
            if (summary) { summary.value = payload.summary || ''; }
            if (companies) { companies.value = (payload.companies || []).join(', '); }
            renderPoints(payload.key_points);

            var readable = payload.status === 'ok' || payload.status === 'partial';
            setState(
                payload.note || (readable ? 'Read.' : 'The document could not be read.'),
                false,
                readable ? 'vnd-scan-ok' : 'vnd-scan-warn'
            );

            if (!readable && summary) {
                // The file is stored and the record can still be filed — say so, rather than
                // leaving an empty box that reads as a document with nothing in it.
                summary.setAttribute('placeholder', 'The document was not read. You can still save it and write the summary yourself.');
            }

            busy = false;
            refreshSave();
        }

        // Collect a read whose response never arrived. The work is already done and paid
        // for; this is the only thing standing between that and doing it again.
        function poll(token, attempt) {
            if (attempt > POLL_ATTEMPTS) {
                setState('The document is taking longer than expected to read. You can still save it and write the summary yourself.', false, 'vnd-scan-warn');
                tokenInput.value = token;
                busy = false;
                refreshSave();
                return;
            }

            window.setTimeout(function () {
                fetch(statusUrl + '?token=' + encodeURIComponent(token), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function (res) {
                    if (res.status === 404) {
                        // The upload itself never landed, so there is nothing to save.
                        setState('The upload did not complete. Please attach the document again.', false, 'vnd-scan-warn');
                        tokenInput.value = '';
                        busy = false;
                        refreshSave();
                        return null;
                    }
                    if (!res.ok) { throw new Error('status ' + res.status); }
                    return res.json();
                }).then(function (payload) {
                    if (!payload) { return; }
                    if (payload.settled) { apply(payload); return; }
                    poll(token, attempt + 1);
                }).catch(function () {
                    poll(token, attempt + 1);
                });
            }, POLL_EVERY_MS);
        }

        file.addEventListener('change', function () {
            var chosen = file.files && file.files[0];
            if (!chosen) { return; }

            if (chosen.size > MAX_BYTES) {
                setState('That file is larger than 10 MB. Please attach a smaller copy.', false, 'vnd-scan-warn');
                file.value = '';
                return;
            }

            var token = newToken();
            busy = true;
            tokenInput.value = '';
            refreshSave();
            setState('Reading the document — this takes a moment for a long PDF.', true);
            if (reading && isNew) { reading.classList.add('d-none'); }
            renderPoints([]);

            var body = new FormData();
            body.append('kind', kind);
            body.append('token', token);
            body.append('document', chosen);
            if (csrf) { body.append('_token', csrf.value); }

            fetch(scanUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: body
            }).then(function (res) {
                if (res.status === 422 || res.status === 413) {
                    return res.json().catch(function () { return null; }).then(function (data) {
                        var first = data && data.errors ? Object.keys(data.errors)[0] : null;
                        throw new Error(first && data.errors[first][0] ? data.errors[first][0] : 'That file was rejected.');
                    });
                }
                if (res.status === 429) { throw new Error('Too many uploads in a short time. Please wait a moment and try again.'); }
                if (!res.ok) { throw new Error(''); }
                return res.json();
            }).then(function (payload) {
                apply(payload);
            }).catch(function (err) {
                var stated = err && err.message ? err.message : '';
                if (stated) {
                    // A refusal the server explained. Nothing was stored under this token.
                    setState(stated, false, 'vnd-scan-warn');
                    file.value = '';
                    busy = false;
                    refreshSave();
                    return;
                }
                // A lost response, not a refusal — the read may well be finishing right now.
                setState('Still reading the document — waiting for it to finish.', true);
                poll(token, 1);
            });
        });

        // A modal closed and re-opened must not offer to save an upload the operator walked
        // away from: the staging row is swept, and Save would fail on a token that is gone.
        var modal = form.closest('.modal');
        if (modal && isNew) {
            modal.addEventListener('hidden.bs.modal', function () {
                file.value = '';
                tokenInput.value = '';
                busy = false;
                if (summary) { summary.value = ''; }
                if (companies) { companies.value = ''; }
                renderPoints([]);
                if (reading) { reading.classList.add('d-none'); }
                if (state) { state.classList.add('d-none'); }
                refreshSave();
            });
        }

        refreshSave();
    });
})();
</script>
@endpush
@endonce
