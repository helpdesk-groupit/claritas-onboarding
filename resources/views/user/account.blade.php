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
                    @if(Auth::user()->profile_picture)
                        <img src="{{ asset('storage/' . Auth::user()->profile_picture) }}" alt="Profile" class="rounded-circle border shadow-sm" style="width:100px;height:100px;object-fit:cover;">
                    @else
                        <img src="https://ui-avatars.com/api/?name={{ urlencode(Auth::user()->name) }}&background=2684FE&color=fff&size=200" alt="Avatar" class="rounded-circle border shadow-sm" style="width:100px;height:100px;object-fit:cover;">
                    @endif
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
<script>
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