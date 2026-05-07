<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'User Manual')</title>

    <script nonce="{{ $cspNonce ?? '' }}">
        (function() {
            var t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', t);
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style nonce="{{ $cspNonce ?? '' }}">
        body { background: #f1f5f9; font-family: 'Segoe UI', sans-serif; color: #1e293b; }
        .help-topbar {
            background: #fff; border-bottom: 1px solid #e2e8f0;
            padding: 14px 24px; display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 10;
        }
        .help-topbar .help-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; color: #1e40af; font-size: 16px; }
        .help-topbar .help-brand i { font-size: 22px; }
        .help-topbar .help-share { display: flex; gap: 8px; }
        .help-content { max-width: 1140px; margin: 0 auto; padding: 24px; }
        .help-page-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 26px 30px; }
        .help-page-card h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin: 0 0 6px; }
        .help-page-card .help-subtitle { font-size: 13px; color: #64748b; margin-bottom: 22px; }
        .help-footer {
            text-align: center; color: #64748b; font-size: 12px;
            padding: 22px 24px; border-top: 1px solid #e2e8f0; margin-top: 30px;
        }
        .help-footer a { color: #1e40af; text-decoration: none; font-weight: 500; }
        .help-footer a:hover { text-decoration: underline; }
        .help-toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
            background: #1e293b; color: #fff; padding: 10px 18px; border-radius: 999px;
            font-size: 13px; opacity: 0; pointer-events: none; transition: opacity .2s;
            z-index: 9999;
        }
        .help-toast.show { opacity: 1; }

        [data-theme="dark"] body { background: #0f172a; color: #cbd5e1; }
        [data-theme="dark"] .help-topbar { background: #1e293b; border-color: #334155; }
        [data-theme="dark"] .help-topbar .help-brand { color: #93c5fd; }
        [data-theme="dark"] .help-page-card { background: #1e293b; border-color: #334155; }
        [data-theme="dark"] .help-page-card h1 { color: #f1f5f9; }
        [data-theme="dark"] .help-footer { color: #94a3b8; border-color: #334155; }
        [data-theme="dark"] .help-footer a { color: #93c5fd; }
    </style>

    @stack('styles')
</head>
<body>
    <div class="help-topbar">
        <div class="help-brand">
            <i class="bi bi-book-half"></i> Help &amp; User Manuals
        </div>
        <div class="help-share">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="helpCopyLinkBtn">
                <i class="bi bi-link-45deg me-1"></i> Copy link
            </button>
            <a href="{{ route('login') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-box-arrow-in-right me-1"></i> Login
            </a>
        </div>
    </div>

    <div class="help-content">
        <div class="help-page-card">
            <h1>@yield('manual-title')</h1>
            <p class="help-subtitle">@yield('manual-subtitle')</p>
            @yield('content')
        </div>
    </div>

    <div class="help-footer">
        Need to use the app? <a href="{{ route('login') }}">Sign in here.</a>
    </div>

    <div class="help-toast" id="helpToast" role="status">Link copied to clipboard.</div>

    <script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        var btn = document.getElementById('helpCopyLinkBtn');
        var toast = document.getElementById('helpToast');
        if (!btn || !toast) return;
        btn.addEventListener('click', function () {
            var url = window.location.href;
            var done = function () {
                toast.classList.add('show');
                setTimeout(function () { toast.classList.remove('show'); }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, function () {
                    // Clipboard API failed (insecure context, etc.) — fall back to legacy
                    var ta = document.createElement('textarea');
                    ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(ta);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(ta);
            }
        });
    })();
    </script>

    @stack('scripts')
</body>
</html>
