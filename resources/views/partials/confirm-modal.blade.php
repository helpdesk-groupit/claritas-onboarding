{{--
    Reusable confirmation modal. Any <form class="js-confirm"> opens this instead of the
    native confirm(). Optional data-* attributes on the form customise it:
      data-confirm          → the message (required)
      data-confirm-title    → modal title (default "Please confirm")
      data-confirm-ok       → confirm button label (default "Confirm")
      data-confirm-variant  → confirm button colour: primary|success|danger|warning (default primary)
    CSP-compliant: no inline handlers; logic runs in a nonce-protected script.
--}}
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-semibold" id="confirmModalTitle"><i class="bi bi-question-circle me-2 text-primary"></i>Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-secondary" id="confirmModalBody">Are you sure you want to proceed?</div>
            <div class="modal-footer border-0 pt-1">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmModalOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        var el = document.getElementById('confirmModal');
        if (!el || typeof bootstrap === 'undefined') return;
        var modal = new bootstrap.Modal(el);
        var bodyEl = document.getElementById('confirmModalBody');
        var titleEl = document.getElementById('confirmModalTitle');
        var okBtn = document.getElementById('confirmModalOk');
        var pending = null;

        document.querySelectorAll('form.js-confirm').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                pending = f;
                bodyEl.textContent = f.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
                titleEl.innerHTML = '<i class="bi bi-question-circle me-2 text-primary"></i>' +
                    (f.getAttribute('data-confirm-title') || 'Please confirm');
                okBtn.className = 'btn btn-' + (f.getAttribute('data-confirm-variant') || 'primary');
                okBtn.textContent = f.getAttribute('data-confirm-ok') || 'Confirm';
                modal.show();
            });
        });

        okBtn.addEventListener('click', function () {
            if (!pending) return;
            var f = pending;
            pending = null;
            modal.hide();
            f.submit(); // native submit() bypasses the submit listener, so it goes through
        });
    })();
</script>
@endpush
