<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Claritas Asia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background:#E8F0FE; min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .auth-card { background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,0.3); width:100%; max-width:420px; overflow:hidden; }
        .auth-header { background:linear-gradient(135deg,#2684FE,#60A5FA); padding:30px; text-align:center; color:#fff; }
        .auth-header h4 { font-weight:700; margin:0; }
        .auth-body { padding:30px; }
        .form-control:focus { border-color:#2684FE; box-shadow:0 0 0 3px rgba(38,132,254,0.15); }
        .btn-send { background:linear-gradient(135deg,#2684FE,#60A5FA); border:none; color:#fff; padding:12px; font-weight:600; border-radius:8px; }
        .btn-send:hover { opacity:0.9; color:#fff; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <i class="bi bi-key-fill" style="font-size:40px;"></i>
            <h4 class="mt-2">Reset Password</h4>
            <p style="color:rgba(255,255,255,0.75);margin:0;font-size:14px;">Enter your work email to receive a reset link</p>
        </div>
        <div class="auth-body">

            @if(session('status'))
                <div class="alert alert-success py-2">
                    <i class="bi bi-check-circle me-1"></i>{{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger py-2 small">
                    @foreach($errors->all() as $e)
                        <div><i class="bi bi-exclamation-circle me-1"></i>{{ $e }}</div>
                    @endforeach
                </div>
            @endif

            <p class="text-muted small mb-3">
                Enter your work email and we'll send you a link to reset your password.
            </p>

            <form action="{{ route('password.email') }}" method="POST">
                @csrf
                <div class="mb-3">
                    <label class="form-label fw-semibold">Work Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', session('prefill_email')) }}"
                               placeholder="yourname@claritas.com" required autofocus>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-send w-100">
                    <i class="bi bi-send me-2"></i>Send Reset Link
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
</body>
</html>