<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Employee Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background:#E8F0FE; min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .auth-card { background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,0.3); width:100%; max-width:420px; overflow:hidden; }
        .auth-header { background:linear-gradient(135deg,#2684FE,#60A5FA); padding:30px; text-align:center; color:#fff; }
        .auth-header h4 { font-weight:700; margin:0; }
        .auth-body { padding:30px; }
        .form-control:focus { border-color:#2684FE; box-shadow:0 0 0 3px rgba(37,99,235,0.15); }
        .btn-primary-grad { background:linear-gradient(135deg,#2684FE,#60A5FA); border:none; color:#fff; padding:12px; font-weight:600; border-radius:8px; }
        .btn-primary-grad:hover { opacity:0.9; color:#fff; }
    </style>
</head>
<body>
<div class="auth-card">
    <div class="auth-header">
        <i class="bi bi-building" style="font-size:40px;"></i>
        <h4 class="mt-2">Create Account</h4>
        <p class="mb-0 opacity-75" style="font-size:14px;">Employee Portal — Sign Up</p>
    </div>
    <div class="auth-body">

        <div class="alert py-2 mb-3 small" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;color:#1e40af;">
            <i class="bi bi-info-circle me-2"></i>
            Use the work email assigned to you by IT to create your account.
            If you cannot remember your email, please see the IT team.
        </div>

        @if ($errors->any())
            <div class="alert alert-danger py-2 small">
                <i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}
            </div>
        @endif

        <form action="{{ route('register.checkEmail') }}" method="POST">
            @csrf
            <div class="mb-3">
                <label class="form-label fw-semibold">Work Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-envelope text-muted"></i></span>
                    <input type="email" name="work_email"
                           class="form-control @error('work_email') is-invalid @enderror"
                           value="{{ old('work_email') }}"
                           placeholder="yourname@claritas.asia"
                           required autofocus>
                    @error('work_email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <button type="submit" class="btn btn-primary-grad w-100">
                <i class="bi bi-arrow-right-circle me-2"></i>Continue
            </button>
        </form>

        <hr class="my-3">
        <p class="text-center small text-muted mb-0">
            Already have an account?
            <a href="{{ route('login') }}" class="text-primary fw-semibold">Sign in</a>
        </p>
    </div>
</d