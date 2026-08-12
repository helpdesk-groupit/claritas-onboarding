@once
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
/**
 * The vendor document assistant, and the poll that fills in a summary being read in the
 * background.
 *
 * CSP-safe throughout: one nonce'd block, addEventListener only, and every
 * dynamic value goes in via textContent. The ONE exception is the assistant's answer,
 * which the server has already rendered from markdown with html_input=strip — the model's
 * own output never reaches innerHTML unsanitised, and it is never re-parsed here.
 */
(function () {
    'use strict';

    var metaTag = document.querySelector('meta[name="csrf-token"]');
    var CSRF = metaTag ? metaTag.getAttribute('content') : '';

    // ── Pending-summary poll ──────────────────────────────────────────────────
    // A row whose document is being read shows "reading…". Rather than patch the DOM —
    // which risks the page disagreeing with the database about a document's state — this
    // reloads once the server says the reading is done, so what is on screen is always
    // exactly what was rendered from the record.
    (function () {
        var pendingNodes = document.querySelectorAll('[data-vnd-ai-pending]');
        var host = document.querySelector('[data-vnd-insights-url]');
        var url = host ? host.getAttribute('data-vnd-insights-url') : null;
        if (!pendingNodes.length || !url) { return; }

        var watching = {};
        pendingNodes.forEach(function (n) { watching[n.getAttribute('data-vnd-ai-pending')] = true; });

        // Bounded: a job that died leaves a row at "pending" forever, and polling it for
        // the rest of the session would be traffic that can never resolve.
        var attempts = 0;
        var MAX = 25;
        var timer = setInterval(function () {
            if (++attempts > MAX) { clearInterval(timer); return; }
            // Matches the notification bell's rule — no background traffic on a hidden tab.
            if (document.visibilityState !== 'visible') { return; }

            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                    if (!data || !data.documents) { return; }
                    var stillPending = data.documents.some(function (d) {
                        return watching[d.key] && d.status === 'pending';
                    });
                    if (!stillPending) {
                        clearInterval(timer);
                        window.location.reload();
                    }
                })
                .catch(function () { /* a blip; the next tick tries again */ });
        }, 8000);
    })();

    // ── The scope selector ────────────────────────────────────────────────────
    // Hoisted above the composer's own guard on purpose: the chips render whenever
    // something is readable, INCLUDING when the AI is switched off and there is no form to
    // bind — so the count on the collapsed bar has to be kept honest from here, not from
    // inside the send logic.
    var scope = document.querySelector('[data-vnd-ask-scope]');

    function boxes() {
        return scope ? Array.prototype.slice.call(scope.querySelectorAll('input[type="checkbox"]')) : [];
    }

    function selected() {
        return boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
    }

    /** The bar is collapsed most of the time, so its count IS the scope for most reads. */
    function refreshCount() {
        var label = document.querySelector('[data-vnd-ask-count]');
        if (label) { label.textContent = String(selected().length); }
    }

    if (scope) {
        scope.addEventListener('change', refreshCount);

        // Select all/none set .checked programmatically, which fires no change event — so
        // each one refreshes the count itself rather than relying on the listener above.
        var all = document.querySelector('[data-vnd-ask-all]');
        var none = document.querySelector('[data-vnd-ask-none]');
        if (all) {
            all.addEventListener('click', function () {
                boxes().forEach(function (b) { b.checked = true; });
                refreshCount();
            });
        }
        if (none) {
            none.addEventListener('click', function () {
                boxes().forEach(function (b) { b.checked = false; });
                refreshCount();
            });
        }
    }

    // ── Opening the panel ─────────────────────────────────────────────────────
    // The assistant is a floating panel, not a tab, so everything that used to SELECT the
    // Ask AI tab has to open it instead: the "Ask about this document" link on a document
    // row, every redirect an assistant action makes, and the panel's own Read now. They
    // all carry ONE param, `?ask=1`, read here and nowhere else — `focus` says WHICH
    // document, never whether to open, so the two can't drift into meaning each other.
    // Without this, starting a new topic would answer by closing the assistant.
    //
    // Deliberately BEFORE the `if (!form) return` below: the panel must still open when
    // there is nothing to ask — that is where the reason is printed.
    (function () {
        var panel = document.getElementById('vndAskPanel');
        if (!panel || !window.bootstrap || !window.bootstrap.Offcanvas) { return; }

        // Land at the newest turn with the cursor in the box, rather than at the top of a
        // long thread — an assistant you have to scroll before you can use reads as stuck.
        panel.addEventListener('shown.bs.offcanvas', function () {
            var t = panel.querySelector('[data-vnd-ask-thread]');
            if (t) { t.scrollTop = t.scrollHeight; }
            var i = panel.querySelector('[data-vnd-ask-input]');
            if (i) { i.focus(); }
        });

        if (new URLSearchParams(window.location.search).get('ask')) {
            window.bootstrap.Offcanvas.getOrCreateInstance(panel).show();
        }
    })();

    // ── The assistant ─────────────────────────────────────────────────────────
    var form = document.querySelector('[data-vnd-ask-form]');
    if (!form) { return; }

    var thread = document.querySelector('[data-vnd-ask-thread]');
    var input = form.querySelector('[data-vnd-ask-input]');
    var send = form.querySelector('[data-vnd-ask-send]');

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    /** Drop the "nothing asked yet" placeholder the first time something is asked. */
    function clearPlaceholder() {
        var empty = thread ? thread.querySelector('.vnd-ask-empty') : null;
        if (empty) { empty.remove(); }
    }

    function appendMessage(role, opts) {
        if (!thread) { return null; }
        clearPlaceholder();

        var wrap = el('div', 'vnd-ask-msg vnd-ask-' + role + (opts.failed ? ' vnd-ask-failed' : ''));

        var who = el('div', 'vnd-ask-who');
        var icon = el('i', role === 'assistant' ? 'bi bi-robot me-1' : 'bi bi-person me-1');
        who.appendChild(icon);
        who.appendChild(document.createTextNode(role === 'assistant' ? 'Assistant' : (opts.author || 'You')));
        if (opts.at) { who.appendChild(el('span', 'vnd-ask-when', opts.at)); }
        wrap.appendChild(who);

        var body = el('div', 'vnd-ask-body');
        if (opts.html) {
            // Server-rendered from markdown with HTML stripped. The model's raw output is
            // never placed here.
            body.innerHTML = opts.html;
        } else {
            body.appendChild(el('div', null, opts.text || ''));
        }
        wrap.appendChild(body);

        if (opts.used && opts.used.length) {
            var cites = el('div', 'vnd-ask-cites');
            cites.appendChild(el('i', 'bi bi-file-earmark-text me-1'));
            cites.appendChild(document.createTextNode('Read from: ' + opts.used.join(' · ')));
            wrap.appendChild(cites);
        }
        if (opts.excluded && opts.excluded.length) {
            var warn = el('div', 'vnd-ask-cites vnd-ask-cites-warn');
            warn.appendChild(el('i', 'bi bi-exclamation-triangle me-1'));
            warn.appendChild(document.createTextNode('Not read for this answer: ' + opts.excluded.map(function (x) {
                return x.label + ' (' + x.reason + ')';
            }).join(' · ')));
            wrap.appendChild(warn);
        }

        thread.appendChild(wrap);
        wrap.scrollIntoView({ block: 'nearest' });
        return wrap;
    }

    function busy(on) {
        send.disabled = on;
        input.disabled = on;
        send.textContent = '';
        var icon = document.createElement(on ? 'span' : 'i');
        icon.className = on ? 'spinner-border spinner-border-sm me-1' : 'bi bi-send me-1';
        send.appendChild(icon);
        send.appendChild(document.createTextNode(on ? 'Reading…' : 'Ask'));
    }

    function describe(status) {
        if (status === 419) { return 'Your session has expired — reload the page and try again.'; }
        if (status === 429) { return 'Too many questions in a short time — wait a moment and try again.'; }
        if (status === 403) { return 'You do not have permission to ask about this vendor.'; }
        return 'The question could not be sent (error ' + status + ').';
    }

    function ask(question) {
        var docs = selected();
        if (!docs.length) {
            appendMessage('assistant', {
                text: 'Tick at least one document above — an answer with nothing to read from would be a guess.',
                failed: true
            });
            return;
        }

        appendMessage('user', { text: question });
        input.value = '';
        busy(true);

        var body = new FormData();
        body.append('question', question);
        docs.forEach(function (d) { body.append('documents[]', d); });

        fetch(form.getAttribute('data-vnd-ask-url'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: body,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (payload) {
                if (!payload) {
                    appendMessage('assistant', { text: describe(res.status), failed: true });
                    return;
                }
                if (payload.error) {
                    appendMessage('assistant', { text: payload.error, failed: true });
                    return;
                }
                if (payload.answer) {
                    appendMessage('assistant', {
                        html: payload.answer.failed ? null : payload.answer.html,
                        text: payload.answer.failed ? payload.answer.html : null,
                        used: payload.answer.used,
                        excluded: payload.answer.excluded,
                        failed: payload.answer.failed,
                        at: payload.answer.at
                    });
                }
            });
        }).catch(function () {
            appendMessage('assistant', {
                text: 'The assistant could not be reached. Your question was not sent — try again.',
                failed: true
            });
        }).then(function () {
            busy(false);
            input.focus();
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var question = (input.value || '').trim();
        if (question) { ask(question); }
    });

    // Suggested openers. Delegated, because the placeholder they live in is removed the
    // moment the first question is asked.
    if (thread) {
        thread.addEventListener('click', function (e) {
            var seed = e.target.closest ? e.target.closest('.vnd-ask-seed') : null;
            if (seed) { ask(seed.textContent.trim()); }
        });
    }
})();
</script>
@endpush
@endonce
