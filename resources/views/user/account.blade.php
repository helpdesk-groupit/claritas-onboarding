@extends('layouts.app')
@section('title', 'Account Settings')
@section('page-title', 'Account Settings')

@section('content')
<div class="row g-4">

    {{-- ── LEFT COL ─────────────────────────────────────────────────────── --}}
    <div class="col-md-6">

        {{-- Profile Picture --}}
        <div class="card mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-person-circle me-2 text-primary"></i>Profile Picture</h6>
            </div>
            <div class="card-body text-center">
                @if(session('avatar_success'))
                    <div class="alert alert-success py-2"><i class="bi bi-check-circle me-2"></i>{{ session('avatar_success') }}</div>
                @endif
                <div class="mb-3">
                    <img src="{{ Auth::user()->profile_picture_url }}" alt="Profile" class="rounded-circle border shadow-sm" style="width:100px;height:100px;object-fit:cover;">
                </div>
                <form action="{{ route('account.avatar') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3 text-start">
                        <label class="form-label fw-semibold small">Upload New Photo</label>
                        <input type="file" name="profile_picture" class="form-control @error('profile_picture') is-invalid @enderror" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" onchange="previewAvatar(this)">
                        @error('profile_picture')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">JPG, PNG, GIF or WebP. Max 2MB.</div>
                    </div>
                    <div id="avatarPreviewWrap" class="mb-3 d-none">
                        <img id="avatarPreview" class="rounded-circle border" style="width:70px;height:70px;object-fit:cover;">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload me-2"></i>Upload Profile Picture</button>
                </form>
            </div>
        </div>

        {{-- Change Password — single button, no card wrapper --}}
        <form action="{{ route('account.change-password') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-warning w-100 fw-bold py-2">
                <i class="bi bi-key me-2"></i>Change Password
            </button>
        </form>

    </div>

    {{-- ── RIGHT COL ────────────────────────────────────────────────────── --}}
    <div class="col-md-6">

        {{-- Theme --}}
        <div class="card mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-palette me-2 text-primary"></i>Change Theme</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Choose your preferred interface theme.</p>
                <div class="d-flex gap-3">
                    <div class="theme-option p-3 rounded border text-center flex-fill" data-theme="light" style="cursor:pointer;" onclick="setTheme('light')">
                        <i class="bi bi-sun-fill" style="font-size:28px;color:#f59e0b;"></i>
                        <div class="fw-semibold mt-2 small">Light</div>
                    </div>
                    <div class="theme-option p-3 rounded border text-center flex-fill" data-theme="dark" style="cursor:pointer;" onclick="setTheme('dark')">
                        <i class="bi bi-moon-fill" style="font-size:28px;color:#2684FE;"></i>
                        <div class="fw-semibold mt-2 small">Dark</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Two-Factor Authentication --}}
        <div class="card mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Two-Factor Authentication</h6>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success py-2"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger py-2">{{ session('error') }}</div>
                @endif

                @if(Auth::user()->hasTwoFactorEnabled())
                    <div class="alert alert-success py-2 mb-3">
                        <i class="bi bi-shield-fill-check me-1"></i>
                        Two-factor authentication is <strong>enabled</strong>.
                    </div>
                    @if(Auth::user()->requiresTwoFactor())
                        <div class="alert alert-info py-2 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Two-factor authentication is <strong>required</strong> for your role and cannot be disabled.
                        </div>
                    @else
                        <form action="{{ route('two-factor.disable') }}" method="POST">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Confirm Password to Disable</label>
                                <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" placeholder="Enter your current password" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="bi bi-shield-x me-2"></i>Disable Two-Factor Authentication
                            </button>
                        </form>
                    @endif
                @else
                    <p class="text-muted small mb-3">Add an extra layer of security to your account using a TOTP authenticator app (e.g. Google Authenticator, Authy).</p>
                    @if(Auth::user()->requiresTwoFactor())
                        <div class="alert alert-warning py-2 mb-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Two-factor authentication is <strong>required</strong> for your role. You must enable it to continue using the system.
                        </div>
                    @endif
                    <a href="{{ route('two-factor.setup') }}" class="btn btn-primary w-100">
                        <i class="bi bi-shield-plus me-2"></i>Enable Two-Factor Authentication
                    </a>
                @endif
            </div>
        </div>

        {{-- Trusted Devices (only relevant when 2FA is enabled) --}}
        @if(Auth::user()->hasTwoFactorEnabled())
        <div class="card mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-laptop me-2 text-primary"></i>Trusted Devices</h6>
                @if(isset($trustedDevices) && $trustedDevices->isNotEmpty())
                <form action="{{ route('account.trusted-devices.revoke-all') }}" method="POST" class="m-0"
                      onsubmit="return confirm('Remove all trusted devices? You will need to enter a 2FA code on your next login from every device.');">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash me-1"></i>Revoke all
                    </button>
                </form>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Devices you've chosen to trust skip the 2FA code at login. You'll still be asked for a code
                    when signing in from a new device or a different country. Don't see one you recognise? Revoke it.
                </p>
                @if(isset($trustedDevices) && $trustedDevices->isNotEmpty())
                    <ul class="list-group list-group-flush">
                        @foreach($trustedDevices as $device)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                            <div class="me-2">
                                <div class="fw-semibold">
                                    {{ $device->device_label ?: 'Unknown device' }}
                                    @if(isset($currentSelector) && $device->selector === $currentSelector)
                                        <span class="badge bg-success ms-1">This device</span>
                                    @endif
                                </div>
                                <div class="small text-muted">
                                    @if($device->last_ip)IP {{ $device->last_ip }}@endif
                                    @if($device->last_country) &middot; {{ $device->last_country }}@endif
                                    @if($device->last_used_at) &middot; last used {{ $device->last_used_at->diffForHumans() }}@endif
                                </div>
                                <div class="small text-muted">Expires {{ $device->expires_at->format('d M Y') }}</div>
                            </div>
                            <form action="{{ route('account.trusted-devices.revoke', $device) }}" method="POST" class="m-0">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1"></i>Revoke
                                </button>
                            </form>
                        </li>
                        @endforeach
                    </ul>
                @else
                    <div class="alert alert-light border small mb-0">
                        <i class="bi bi-info-circle me-1"></i>No trusted devices yet. Tick
                        &ldquo;Trust this device&rdquo; on the 2FA screen at your next login to add one.
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Language --}}
        <div class="card">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-translate me-2 text-primary"></i>Language Preference</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Select your preferred interface language.</p>
                <div class="d-grid gap-2">
                    @foreach(['en' => ['label'=>'English','flag'=>'🇬🇧'], 'ms' => ['label'=>'Bahasa Melayu','flag'=>'🇲🇾']] as $code => $lang)
                    <button type="button"
                        class="btn btn-outline-secondary text-start d-flex align-items-center gap-2 lang-btn {{ session('locale','en')===$code?'active btn-primary text-white border-primary':'' }}"
                        onclick="setLanguage('{{ $code }}')">
                        <span style="font-size:20px;">{{ $lang['flag'] }}</span>
                        <span class="fw-semibold">{{ $lang['label'] }}</span>
                        @if(session('locale','en')===$code)<i class="bi bi-check-circle-fill ms-auto text-white"></i>@endif
                    </button>
                    @endforeach
                </div>
                <div class="mt-3 alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i>Full multilingual support coming soon.</div>
            </div>
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatarPreview').src = e.target.result;
            document.getElementById('avatarPreviewWrap').classList.remove('d-none');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function setTheme(theme) {
    localStorage.setItem('theme', theme);
    document.documentElement.setAttribute('data-bs-theme', theme);
    document.documentElement.setAttribute('data-theme', theme);
    // Update active state on the cards
    document.querySelectorAll('.theme-option').forEach(el => {
        el.classList.remove('border-primary', 'bg-primary', 'text-white');
        el.style.borderWidth = '';
    });
    const active = document.querySelector('.theme-option[data-theme="' + theme + '"]');
    if (active) {
        active.style.borderWidth = '2px';
        active.classList.add('border-primary');
    }
}

// Highlight the currently active theme on page load
document.addEventListener('DOMContentLoaded', function () {
    const current = localStorage.getItem('theme') || 'light';
    const active = document.querySelector('.theme-option[data-theme="' + current + '"]');
    if (active) {
        active.style.borderWidth = '2px';
        active.classList.add('border-primary');
    }
});
</script>
@endpush