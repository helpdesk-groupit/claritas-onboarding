<div class="modal fade" id="claimModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h5 class="modal-title text-white"><i class="bi bi-receipt me-2"></i>Claim Submission</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3"><i class="bi bi-info-circle me-1"></i>Claim submission and tracking will be available in the next release.</div>
                <div class="row g-3">
                    @php $pName = Auth::user()->employee?->onboarding?->personalDetail?->full_name ?? Auth::user()->name;
                         $dept  = Auth::user()->employee?->onboarding?->workDetail?->department ?? '—'; @endphp
                    <div class="col-md-6"><label class="form-label fw-semibold">Name</label><input type="text" class="form-control" value="{{ $pName }}" readonly></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Department</label><input type="text" class="form-control" value="{{ $dept }}" readonly></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Date</label><input type="date" class="form-control" value="{{ now()->format('Y-m-d') }}"></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Expense Type</label>
                        <select class="form-select"><option>Travel</option><option>Food</option><option>Medical</option><option>Optical</option><option>Accommodation</option><option>Office Purchase</option></select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Project / Client Name</label><input type="text" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Amount (RM)</label><input type="number" class="form-control" step="0.01"></div>
                    <div class="col-12"><label class="form-label fw-semibold">Expense Description</label><textarea class="form-control" rows="2"></textarea></div>
                    <div class="col-12"><label class="form-label fw-semibold">Receipt Upload (multiple)</label><input type="file" class="form-control" accept=".jpg,.jpeg,.png,.pdf" multiple></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" disabled><i class="bi bi-send me-1"></i>Submit Claim (Coming Soon)</button>
            </div>
        </div>
    </div>
</div>
