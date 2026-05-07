@once
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    // Wire the "Copy share link" buttons inside any User Manual modal.
    // Falls back to a hidden textarea + execCommand for non-secure contexts.
    document.querySelectorAll('.um-share-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var raw = btn.getAttribute('data-share-url') || '';
            var url = (raw.indexOf('http') === 0)
                ? raw
                : (window.location.origin + raw);
            var done = function () {
                var original = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Copied!';
                setTimeout(function () { btn.innerHTML = original; }, 1600);
            };
            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(ta);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, fallback);
            } else {
                fallback();
            }
        });
    });
})();
</script>
@endpush
@endonce
