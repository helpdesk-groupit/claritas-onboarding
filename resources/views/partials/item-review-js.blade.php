{{--
    Drives the per-item review controls inside any <form class="item-review-form">:
    ticking a .reject-toggle reveals its .reject-reason, strikes the row's .item-total,
    and live-updates the .payable-amount (grand total − rejected items).
    CSP-compliant (nonce script); runs once at the bottom of the page.
--}}
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    document.querySelectorAll('.item-review-form').forEach(function (form) {
        const payableRow = form.querySelector('.payable-row');
        const payableAmt = form.querySelector('.payable-amount');
        const grand = payableAmt ? parseFloat(payableAmt.dataset.grand) : 0;

        function recompute() {
            let rejected = 0;
            form.querySelectorAll('.reject-toggle').forEach(function (cb) {
                const row = cb.closest('.review-row');
                const reason = row.querySelector('.reject-reason');
                const totalCell = row.querySelector('.item-total');
                if (cb.checked) {
                    row.classList.add('table-danger');
                    if (reason) reason.classList.remove('d-none');
                    if (totalCell) totalCell.classList.add('text-decoration-line-through', 'text-muted');
                    rejected += parseFloat(totalCell ? totalCell.dataset.total : 0) || 0;
                } else {
                    row.classList.remove('table-danger');
                    if (reason) reason.classList.add('d-none');
                    if (totalCell) totalCell.classList.remove('text-decoration-line-through', 'text-muted');
                }
            });
            if (!payableRow) return;
            if (rejected > 0) {
                payableRow.classList.remove('d-none');
                payableAmt.textContent = 'RM ' + (grand - rejected).toFixed(2);
            } else {
                payableRow.classList.add('d-none');
            }
        }

        form.querySelectorAll('.reject-toggle').forEach(cb => cb.addEventListener('change', recompute));
        recompute(); // reflect any pre-checked items (e.g. manager rejections shown to HR)
    });
</script>
@endpush
