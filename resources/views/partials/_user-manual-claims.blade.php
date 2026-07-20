<div class="modal fade um-modal" id="userManualClaimsModal" tabindex="-1" aria-labelledby="userManualClaimsTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userManualClaimsTitle">
                    <i class="bi bi-book-half"></i> User Manual &mdash; My Claims
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @include('partials._user-manual-claims-body')
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm um-share-btn"
                        data-share-url="{{ route('help.claims') }}">
                    <i class="bi bi-link-45deg me-1"></i> Copy share link
                </button>
                <a href="{{ route('help.claims') }}" target="_blank" rel="noopener"
                   class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Open in new tab
                </a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Got it</button>
            </div>
        </div>
    </div>
</div>

@include('partials._user-manual-share-js')
