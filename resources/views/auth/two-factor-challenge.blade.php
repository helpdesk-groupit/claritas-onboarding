<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #E8F0FE; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .auth-card { background: #fff; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 420px; overflow: hidden; }
        .auth-header { background: linear-gradient(135deg, #2684FE, #60A5FA); padding: 30px; text-align: center; color: #fff; }
        .auth-header h4 { font-weight: 700; margin: 0; }
        .auth-body { padding: 30px; }
        .form-control:focus { border-color: #2684FE; box-shadow: 0 0 0 3px rgba(38,132,254,0.15); }
        .btn-login { background: linear-gradient(135deg, #2684FE, #60A5FA); border: none; color: #fff; padding: 12px; font-weight: 600; border-radius: 8px; }
        .btn-login:hover { opacity: 0.9; color: #fff; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <i class="bi bi-shield-lock" style="font-size:40px;"></i>
            <h4 class="mt-2">Two-Factor Authentication</h4>
            <p class="mb-0" style="opacity:0.8; font-size:14px;">Enter the code from your authenticator app</p>
        </div>
        <div class="auth-body">
            @if($errors->any())
                <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
            @endif

            <form action="{{ route('two-factor.verify') }}" method="POST" id="codeForm">
                @csrf
                <div class="mb-3" id="codeSection">
                    <label class="form-label fw-semibold">Authentication Code</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key"></i></span>
                        <input type="text" name="code" class="form-control text-center"
                               placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                               autocomplete="one-time-code" autofocus>
                    </div>
                </div>

                <div class="mb-3 d-none" id="recoverySection">
                    <label class="form-label fw-semibold">Recovery Code</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key"></i></span>
                        <input type="text" name="recovery_code" class="form-control" placeholder="Enter recovery code">
                    </div>
                </div>

                <label for="rememberDevice" class="d-flex align-items-start gap-2 mb-3 p-3 rounded-3"
                       style="background:#eff6ff;border:1px solid #bfdbfe;cursor:pointer;">
                    <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" name="remember_device" value="1"
                           id="rememberDevice" style="width:1.3rem;height:1.3rem;">
                    <span>
                        <span class="fw-semibold text-primary d-block">
                            <i class="bi bi-shield-check me-1"></i>Trust this device for 30 days
                        </span>
                        <span class="small text-muted">
                            You won't be asked for a code again on this device unless you sign in from a
                            new device or country.
                        </span>
                    </span>
                </label>

                <button type="submit" class="btn btn-login w-100 mb-3">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Verify
                </button>
            </form>

            <div class="text-center">
                <a href="#" class="text-primary small" id="toggleRecovery">
                    Use a recovery code instead
                </a>
            </div>

            <hr class="my-3">
            <p class="text-center small text-muted mb-0">
                <a href="{{ route('login') }}" class="text-muted">Back to login</a>
            </p>
        </div>
    </div>
    <script nonce="{{ $cspNonce ?? '' }}">
        document.getElementById('toggleRecovery').addEventListener('click', toggleRecoveryMode);
        function toggleRecoveryMode(e) {
            e.preventDefault();
            var codeSection = document.getElementById('codeSection');
            var recoverySection = document.getElementById('recoverySection');
            var toggleLink = document.getElementById('toggleRecovery');
            if (recoverySection.classList.contains('d-none')) {
                recoverySection.classList.remove('d-none');
                codeSection.classList.add('d-none');
                codeSection.querySelector('input').value = '';
                toggleLink.textContent = 'Use authenticator code instead';
                recoverySection.querySelector('input').focus();
            } else {
                codeSection.classList.remove('d-none');
                recoverySection.classList.add('d-none');
                recoverySection.querySelector('input').value = '';
                toggleLink.textContent = 'Use a recovery code instead';
                codeSection.querySelector('input').focus();
            }
        }
    </script>
</body>
</html>
