<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Claritas Asia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background:#f1f5f9; min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .auth-card { background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,0.3); width:100%; max-width:440px; overflow:hidden; }
        .auth-header { background:linear-gradient(135deg,#2684FE,#60A5FA); padding:30px; text-align:center; color:#fff; }
        .auth-header h4 { font-weight:700; margin:0; }
        .auth-body { padding:30px; }
        .form-control:focus { border-color:#2684FE; box-shadow:0 0 0 3px rgba(38,132,254,0.15); }
        .btn-reset { background:linear-gradient(135deg,#2684FE,#60A5FA); border:none; color:#fff; padding:12px; font-weight:600; border-radius:8px; }
        .btn-reset:hover { opacity:0.9; color:#fff; }
        .req-list li { font-size:12px; color:#6b7280; margin-bottom:2px; }
        .req-list li.met { color:#16a34a; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <i class="bi bi-shield-lock-fill" style="font-size:40px;"></i>
            <h4 class="mt-2">Reset Password</h4>
            <p style="color:rgba(255,255,255,0.75);margin:0;font-size:14px;">Create a new secure password</p>
        </div>
        <div class="auth-body">

            @if($errors->any())
                <div class="alert alert-danger py-2 small">
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('password.update') }}" method="POST" id="resetForm">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Work Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', $email ?? '') }}" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="password"
                               class="form-control @error('password') is-invalid @enderror"
                               oninput="checkRequirements(this.value)" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePw('password')">
                            <i class="bi bi-eye" id="eyeIcon1"></i>
                        </button>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    {{-- Password requirement hints --}}
                    <ul class="req-list list-unstyled mt-2 mb-0" id="reqList">
                        <li id="req-len"><i class="bi bi-circle me-1"></i>At least 8 characters</li>
                        <li id="req-num"><i class="bi bi-circle me-1"></i>At least 1 number</li>
                        <li id="req-sym"><i class="bi bi-circle me-1"></i>At least 1 symbol (e.g. @, #, !)</li>
                    </ul>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Confirm New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="password_confirmation" id="password_confirmation"
                               class="form-control" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePw('password_confirmation')">
                            <i class="bi bi-eye" id="eyeIcon2"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-reset w-100">
                    <i class="bi bi-check-circle me-2"></i>Reset Password
                </button>
            </form>

            <hr class="my-3">
            <p class="text-center small text-muted mb-0">
                <a href="{{ route('login') }}" class="text-primary fw-semibold">
                    <i class="bi bi-arrow-left me-1"></i>Back to login
                </a>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePw(id) {
            const input = document.getElementById(id);
            const iconId = id === 'password' ? 'eyeIcon1' : 'eyeIcon2';
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        }

        function checkRequirements(val) {
            const checks = {
                'req-len': val.length >= 8,
                'req-num': /[0-9]/.test(val),
                'req-sym': /[\W_]/.test(val),
            };
            Object.entries(checks).forEach(([id, met]) => {
                const el = document.getElementById(id);
                el.classList.toggle('met', met);
                el.querySelector('i').className = met ? 'bi bi-check-circle-fill me-1' : 'bi bi-circle me-1';
            });
        }
    </script>
</body>
</html>