<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Employee Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #E8F0FE;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
        }
        .auth-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%; max-width: 420px;
            overflow: hidden;
        }
        .auth-header {
            background: linear-gradient(135deg, #2684FE, #60A5FA);
            padding: 30px;
            text-align: center;
            color: #fff;
        }
        .auth-header h4 { font-weight: 700; margin: 0; }
        .auth-header p { margin: 5px 0 0; opacity: 0.8; font-size: 14px; }
        .auth-body { padding: 30px; }
        .form-control:focus { border-color: #2684FE; box-shadow: 0 0 0 3px rgba(38,132,254,0.15); }
        .btn-login {
            background: linear-gradient(135deg, #2684FE, #60A5FA);
            border: none; color: #fff;
            padding: 12px; font-weight: 600;
            border-radius: 8px;
        }
        .btn-login:hover { opacity: 0.9; color: #fff; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <i class="bi bi-people-fill" style="font-size:40px;"></i>
            <h4 class="mt-2">Employee Portal</h4>
        </div>
        <div class="auth-body">
            @if(session('success'))
                <div class="alert alert-success py-2">{{ session('success') }}</div>
            @endif
            @if(session('status'))
                <div class="alert alert-info py-2">{{ session('status') }}</div>
            @endif
            {{-- Shown when Laravel's throttle middleware blocks the request (HTTP 429) --}}
            @if($errors->has('email'))
                <div class="alert alert-danger py-2">
                    <i class="bi bi-shield-exclamation me-1"></i>{{ $errors->first('email') }}
                </div>
            @endif

            <form action="{{ route('login') }}{{ isset($redirectIntent) ? '?redirect='.$redirectIntent : '' }}" method="POST">
                @csrf
                <div class="mb-3">
                    <label class="form-label fw-semibold">Work Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="work_email" class="form-control @error('work_email') is-invalid @enderror"
                            value="{{ old('work_email') }}" placeholder="yourname@claritas.com"
                            autocomplete="username" required autofocus>
                        @error('work_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                            placeholder="Enter password" autocomplete="current-password" required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                        <label class="form-check-label small" for="remember">Remember me</label>
                    </div>
                    <a href="{{ route('password.request') }}" class="text-primary small">Forgot password?</a>
                </div>
                <button type="submit" class="btn btn-login w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </form>

            <hr class="my-3">
            <p class="text-center small text-muted mb-0">
                New employee?
                <a href="{{ route('register') }}" class="text-primary fw-semibold">Create account</a>
            </p>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>