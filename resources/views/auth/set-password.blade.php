<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Password — Employee Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background:#E8F0FE; min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .auth-card { background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,0.3); width:100%; max-width:420px; overflow:hidden; }
        .auth-header { background:linear-gradient(135deg,#2684FE,#60A5FA); padding:30px; text-align:center; color:#fff; }
        .auth-header h4 { font-weight:700; margin:0; }
        .auth-body { padding:30px; }
        .form-control:focus { border-color:#2684FE; box-shadow:0 0 0 3px rgba(38,132,254,0.15); }
        .btn-primary-grad { background:linear-gradient(135deg,#2684FE,#60A5FA); border:none; color:#fff; padding:12px; font-weight:600; border-radius:8px; }
        .btn-primary-grad:hover { opacity:0.9; color:#fff; }
        .strength-bar { height:4px; border-radius:4px; transition:background .2s, width .2s; }
        .req-item { font-size:11px; }
        .req-item.met { color:#059669; }
        .req-item.unmet { color:#94a3b8; }
    </style>
</head>
<body>
<div class="auth-card">
    <div class="auth-header">
        <i class="bi bi-lock-fill" style="font-size:40px;"></i>
        <h4 class="mt-2">Set Your Password</h4>
        <p class="mb-0 opacity-75" style="font-size:13px;">{{ $verified_email }}</p>
    </div>
    <div class="auth-body">

        {{-- Back link --}}
        <a href="{{ route('register') }}" class="btn btn-link p-0 mb-3 small text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>

        @if ($errors->any())
            <div class="alert alert-danger py-2 small">
                <i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}
            </div>
        @endif

        <form action="{{ route('register') }}" method="POST" id="registerForm">
            @csrf
            {{-- Pass verified email through --}}
            <input type="hidden" name="work_email" value="{{ $verified_email }}">

            {{-- Password --}}
            <div class="mb-2">
                <label class="form-label fw-semibold">Create Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-lock text-muted"></i></span>
                    <input type="password" name="password" id="password"
                           class="form-control @error('password') is-invalid @enderror"
                           placeholder="Min. 8 characters, number &amp; symbol"
                           oninput="checkStrength()"
                           required>
                    <button type="button" class="input-group-text bg-white border-start-0" onclick="togglePw('password','eyePw')">
                        <i class="bi bi-eye text-muted" id="eyePw"></i>
                    </button>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            {{-- Strength bar --}}
            <div class="mb-3" id="strengthSection" style="display:none;">
                <div class="d-flex gap-1 mb-1">
                    <div class="strength-bar flex-fill" id="bar1" style="background:#e2e8f0;"></div>
                    <div class="strength-bar flex-fill" id="bar2" style="background:#e2e8f0;"></div>
                    <div class="strength-bar flex-fill" id="bar3" style="background:#e2e8f0;"></div>
                </div>
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="req-item unmet" id="req-length"><i class="bi bi-x-circle-fill me-1" style="font-size:10px;"></i>At least 8 characters</div>
                        <div class="req-item unmet" id="req-number"><i class="bi bi-x-circle-fill me-1" style="font-size:10px;"></i>At least one number</div>
                        <div class="req-item unmet" id="req-symbol"><i class="bi bi-x-circle-fill me-1" style="font-size:10px;"></i>At least one symbol (e.g. @, #, !)</div>
                    </div>
                    <span class="fw-semibold" id="strengthLabel" style="font-size:11px;"></span>
                </div>
            </div>

            {{-- Confirm Password --}}
            <div class="mb-4">
                <label class="form-label fw-semibold">Confirm Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-lock-fill text-muted"></i></span>
                    <input type="password" name="password_confirmation" id="password_confirmation"
                           class="form-control"
                           placeholder="Re-enter your password"
                           oninput="checkMatch()"
                           required>
                    <button type="button" class="input-group-text bg-white border-start-0" onclick="togglePw('password_confirmation','eyeCf')">
                        <i class="bi bi-eye text-muted" id="eyeCf"></i>
                    </button>
                </div>
                <div id="matchMsg" class="mt-1" style="font-size:12px;display:none;"></div>
            </div>

            <button type="submit" class="btn btn-primary-grad w-100" id="submitBtn" disabled>
                <i class="bi bi-person-check me-2"></i>Create Account
            </button>
        </form>

        <hr class="my-3">
        <p class="text-center small text-muted mb-0">
            Already have an account?
            <a href="{{ route('login') }}" class="text-primary fw-semibold">Sign in</a>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const colors = ['#dc2626','#d97706','#2684FE','#059669'];
    const labels = ['Weak','Fair','Good','Strong'];

    function checkStrength() {
        const pw = document.getElementById('password').value;
        const section = document.getElementById('strengthSection');

        if (pw.length === 0) { section.style.display = 'none'; updateBtn(); return; }
        section.style.display = 'block';

        const hasLen    = pw.length >= 8;
        const hasNum    = /[0-9]/.test(pw);
        const hasSym    = /[^A-Za-z0-9]/.test(pw);
        const score     = [hasLen, hasNum, hasSym].filter(Boolean).length;
        const color     = colors[score];

        // Update requirement rows
        setReq('req-length', hasLen);
        setReq('req-number', hasNum);
        setReq('req-symbol', hasSym);

        // Update bars
        for (let i = 1; i <= 3; i++) {
            document.getElementById('bar' + i).style.background = i <= score ? color : '#e2e8f0';
        }

        document.getElementById('strengthLabel').textContent = labels[score];
        document.getElementById('strengthLabel').style.color  = color;

        updateBtn();
    }

    function setReq(id, met) {
        const el = document.getElementById(id);
        el.className = 'req-item ' + (met ? 'met' : 'unmet');
        el.querySelector('i').className = met
            ? 'bi bi-check-circle-fill me-1'
            : 'bi bi-x-circle-fill me-1';
        el.querySelector('i').style.fontSize = '10px';
        el.querySelector('i').style.color = met ? '#059669' : '#fca5a5';
    }

    function checkMatch() {
        const pw = document.getElementById('password').value;
        const cf = document.getElementById('password_confirmation').value;
        const msg = document.getElementById('matchMsg');
        const cf_input = document.getElementById('password_confirmation');

        if (cf.length === 0) { msg.style.display = 'none'; cf_input.className = 'form-control'; updateBtn(); return; }

        msg.style.display = 'block';
        if (pw === cf) {
            msg.textContent = '✓ Passwords match';
            msg.style.color = '#059669';
            cf_input.className = 'form-control is-valid';
        } else {
            msg.textContent = '✗ Passwords do not match';
            msg.style.color = '#dc2626';
            cf_input.className = 'form-control is-invalid';
        }
        updateBtn();
    }

    function updateBtn() {
        const pw = document.getElementById('password').value;
        const cf = document.getElementById('password_confirmation').value;
        const hasLen = pw.length >= 8;
        const hasNum = /[0-9]/.test(pw);
        const hasSym = /[^A-Za-z0-9]/.test(pw);
        const allMet = hasLen && hasNum && hasSym && pw === cf && cf.length > 0;
        document.getElementById('submitBtn').disabled = !allMet;
    }

    function togglePw(fieldId, iconId) {
        const field = document.getElementById(fieldId);
        const icon  = document.getElementById(iconId);
        if (field.type === 'password') {
            field.type = 'text';
            icon.className = 'bi bi-eye-slash text-muted';
        } else {
            field.type = 'password';
            icon.className = 'bi bi-eye text-muted';
        }
    }
</script>
</body>
</html>