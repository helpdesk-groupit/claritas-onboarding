{{--
    Handles the per-item "Verify" buttons (partials/claim-item-checks): POSTs to the
    verify endpoint and renders OCR receipt-amount + ORS mileage-distance result chips.
    CSP-compliant (nonce script).
--}}
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        const CSRF = '{{ csrf_token() }}';
        function chip(ok, text) {
            return '<span class="badge rounded-pill ' + (ok ? 'bg-success' : 'bg-danger') +
                   ' me-1 mb-1"><i class="bi bi-' + (ok ? 'check-lg' : 'exclamation-triangle') +
                   ' me-1"></i>' + text + '</span>';
        }
        function money(n) { return 'RM ' + Number(n).toFixed(2); }

        document.querySelectorAll('.verify-item-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const out = btn.parentElement.querySelector('.verify-result');
                btn.disabled = true;
                out.innerHTML = '<span class="text-info">Verifying…</span>';
                fetch(btn.dataset.verifyUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                })
                .then(r => r.json())
                .then(function (d) {
                    btn.disabled = false;
                    let html = '';
                    if (d.receipt) {
                        if (d.receipt.disabled) html += chip(false, 'Receipt OCR is off');
                        else if (!d.receipt.ok) html += chip(false, 'Receipt unreadable — check manually');
                        else if (d.receipt.match) html += chip(true, 'Receipt ' + money(d.receipt.receipt_amount) + ' matches');
                        else html += chip(false, 'Receipt ' + money(d.receipt.receipt_amount) + ' ≠ claimed ' + money(d.receipt.claimed));
                    }
                    if (d.mileage) {
                        if (!d.mileage.ok) html += chip(false, 'Distance unavailable');
                        else if (d.mileage.match) html += chip(true, 'Distance ~' + d.mileage.calc_km + ' km — OK');
                        else html += chip(false, 'Claimed ' + d.mileage.claimed_km + ' km > calc ' + d.mileage.calc_km + ' km');
                    }
                    out.innerHTML = html || '<span class="text-muted">Nothing to verify.</span>';
                })
                .catch(function () {
                    btn.disabled = false;
                    out.innerHTML = '<span class="text-danger">Verification failed — try again.</span>';
                });
            });
        });
    })();
</script>
@endpush
