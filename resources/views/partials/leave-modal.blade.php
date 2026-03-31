<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#16a34a,#22c55e);">
                <h5 class="modal-title text-white"><i class="bi bi-calendar-check me-2"></i>Leave Calculator</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3"><i class="bi bi-info-circle me-1"></i>Full leave management with AI chatbot will be available in the next release.</div>
                <h6 class="fw-bold mb-3">Leave Overview</h6>
                <div class="row g-2 mb-4">
                    @foreach(['Annual'=>[14,'success'],'Medical'=>[14,'primary'],'Emergency'=>[3,'warning'],'Unpaid'=>[0,'secondary']] as $t=>[$d,$c])
                    <div class="col-md-3 text-center p-2 rounded border">
                        <div class="fw-bold fs-4 text-{{ $c }}">{{ $d }}</div>
                        <div class="text-muted small fw-semibold">{{ $t }} Leave</div>
                        <div class="text-muted" style="font-size:10px;">0 taken · {{ $d }} remaining</div>
                    </div>
                    @endforeach
                </div>
                <h6 class="fw-bold mb-3">Simulate Leave</h6>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label fw-semibold">Start Date</label><input type="date" class="form-control" id="leaveStart"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">End Date</label><input type="date" class="form-control" id="leaveEnd"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Leave Type</label>
                        <select class="form-select"><option>Annual Leave</option><option>Medical Leave</option><option>Emergency Leave</option></select></div>
                    <div class="col-12">
                        <button type="button" class="btn btn-success" onclick="calcLeave()"><i class="bi bi-calculator me-1"></i>Calculate</button>
                        <span id="leaveResult" class="ms-3 text-muted small"></span>
                    </div>
                </div>
                <div class="mt-4 p-3 rounded" style="background:#f8fafc;border:1px dashed #cbd5e1;">
                    <h6 class="fw-bold mb-1"><i class="bi bi-robot me-2 text-primary"></i>AI Leave Suggestion</h6>
                    <p class="text-muted small mb-0">AI chatbot suggestions based on Malaysian public holidays — coming soon.</p>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
@push('scripts')
<script>
function calcLeave(){
    const s=new Date(document.getElementById('leaveStart').value),e=new Date(document.getElementById('leaveEnd').value);
    if(!s||!e||isNaN(s)||isNaN(e)){document.getElementById('leaveResult').textContent='Please select both dates.';return;}
    let d=0,c=new Date(s);
    while(c<=e){if(c.getDay()!==0&&c.getDay()!==6)d++;c.setDate(c.getDate()+1);}
    document.getElementById('leaveResult').textContent=d+' working day(s) will be used.';
}
</script>
@endpush
