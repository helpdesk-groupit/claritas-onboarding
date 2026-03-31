<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Acceptance & Return Form — {{ $aarf->aarf_reference }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background:#f1f5f9; }
        .aarf-card { max-width:820px; margin:30px auto; background:#fff; border-radius:16px;
                     box-shadow:0 4px 20px rgba(0,0,0,0.1); overflow:hidden; }
        .aarf-header { background:linear-gradient(135deg,#1e3a5f,#2563eb); color:#fff; padding:30px; }
        .aarf-body   { padding:30px; }
        .info-label  { font-weight:600; color:#475569; min-width:180px; }
        .info-value  { color:#1e293b; }
        .btn-ack     { background:#f59e0b; border:2px solid #d97706; color:#000;
                       padding:14px 30px; font-size:16px; font-weight:700; border-radius:8px; }
        @media print { .no-print { display:none !important; } }
    </style>
</head>
<body>
<div class="aarf-card">
    <div class="aarf-header">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h4 class="mb-1"><i class="bi bi-file-earmark-check me-2"></i>Asset Acceptance & Return Form</h4>
            </div>
            <div class="text-end">
                <div style="font-size:12px;opacity:.7;">Reference No.</div>
                <strong>{{ $aarf->aarf_reference }}</strong>
            </div>
        </div>
    </div>

    <div class="aarf-body">

        {{-- Session messages --}}
        @if(session('success'))
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}</div>
        @endif
        @if(session('info'))
            <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>{{ session('info') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}</div>
        @endif

        {{-- Acknowledgement status summary --}}
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="p-3 rounded {{ $aarf->acknowledged ? 'bg-success bg-opacity-10 border border-success' : 'bg-light border' }}">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-{{ $aarf->acknowledged ? 'check-circle-fill text-success' : 'clock text-secondary' }}" style="font-size:20px;"></i>
                        <div>
                            <div class="fw-semibold small">Employee Acknowledgement</div>
                            @if($aarf->acknowledged)
                                <small class="text-success">Acknowledged {{ $aarf->acknowledged_at?->format('d M Y') }}</small>
                            @else
                                <small class="text-muted">Pending acknowledgement</small>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @php
            $p           = $aarf->onboarding?->personalDetail;
            $w           = $aarf->onboarding?->workDetail;

            // Base: assignments from onboarding relation — only currently assigned
            $assignments = ($aarf->onboarding?->assetAssignments ?? collect())
                ->where('status', 'assigned')->values();

            // Fallback / merge: if no onboarding, pull from employee directly
            if (!$aarf->onboarding && $aarf->employee) {
                $emp = $aarf->employee;
                $p = (object)[
                    'full_name'            => $emp->full_name,
                    'official_document_id' => $emp->official_document_id,
                ];
                $w = (object)[
                    'designation'  => $emp->designation,
                    'department'   => $emp->department,
                    'company'      => $emp->company,
                    'start_date'   => $emp->start_date,
                ];
            }

            // Always also pull assignments stored directly via employee_id,
            // then merge with onboarding assignments (deduped by id).
            // This covers assets added manually after onboarding via the assign modal.
            if ($aarf->employee_id ?? ($aarf->onboarding?->employee?->id ?? null)) {
                $empId = $aarf->employee_id
                      ?? \App\Models\Employee::where('onboarding_id', $aarf->onboarding_id)->value('id');
                if ($empId) {
                    $directAssignments = \App\Models\AssetAssignment::with('asset')
                        ->where('employee_id', $empId)
                        ->where('status', 'assigned')
                        ->get();
                    // Merge, keeping unique by id
                    $existingIds = $assignments->pluck('id')->filter()->toArray();
                    foreach ($directAssignments as $da) {
                        if (!in_array($da->id, $existingIds)) {
                            $assignments->push($da);
                        }
                    }
                }
            }
        @endphp

        {{-- Employee info --}}
        <h6 class="fw-bold mb-3" style="color:#1e3a5f;border-bottom:2px solid #dbeafe;padding-bottom:8px;">
            <i class="bi bi-person me-2"></i>Employee Information
        </h6>
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="d-flex gap-2 mb-2"><span class="info-label">Full Name:</span><span class="info-value">{{ $p?->full_name ?? '—' }}</span></div>
                <div class="d-flex gap-2 mb-2"><span class="info-label">Document ID:</span><span class="info-value">{{ $p?->official_document_id ?? '—' }}</span></div>
                <div class="d-flex gap-2 mb-2"><span class="info-label">Designation:</span><span class="info-value">{{ $w?->designation ?? '—' }}</span></div>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2 mb-2"><span class="info-label">Department:</span><span class="info-value">{{ $w?->department ?? '—' }}</span></div>
                <div class="d-flex gap-2 mb-2"><span class="info-label">Company:</span><span class="info-value">{{ $w?->company ?? '—' }}</span></div>
                <div class="d-flex gap-2 mb-2"><span class="info-label">Start Date:</span><span class="info-value">{{ $w?->start_date?->format('d M Y') ?? '—' }}</span></div>
            </div>
        </div>

        {{-- Assigned assets --}}
        <h6 class="fw-bold mb-3" style="color:#1e3a5f;border-bottom:2px solid #dbeafe;padding-bottom:8px;">
            <i class="bi bi-box-seam me-2"></i>Assigned Assets
        </h6>
        @if($assignments->isEmpty())
            <p class="text-muted">No assets have been assigned yet.</p>
        @else
        <div class="table-responsive mb-4">
            {{-- Main asset table --}}
            <table class="table table-bordered">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th>Asset Tag</th>
                        <th>Brand / Model</th>
                        <th>Type</th>
                        <th>Serial No.</th>
                        <th>Assigned Date</th>
                        <th>Photos</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($assignments as $assign)
                    @php $a = $assign->asset; @endphp
                    <tr>
                        <td><code>{{ $a?->asset_tag ?? '—' }}</code></td>
                        <td>{{ trim(($a?->brand ?? '').' '.($a?->model ?? '')) ?: '—' }}</td>
                        <td>{{ ucfirst(str_replace('_',' ', $a?->asset_type ?? '—')) }}</td>
                        <td>{{ $a?->serial_number ?? '—' }}</td>
                        <td>{{ $assign->assigned_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            @if($a && $a->asset_photos && count($a->asset_photos))
                                <a href="#assetSpec{{ $a->id }}" style="font-size:12px;">
                                    <i class="bi bi-images me-1"></i>{{ count($a->asset_photos) }} photo(s)
                                </a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Section A & B + Photos per asset --}}
            @foreach($assignments as $assign)
            @php $a = $assign->asset; @endphp
            @if($a)
            <div id="assetSpec{{ $a->id }}" class="border rounded p-3 mb-3" style="background:#f8fafc;">
                <div class="fw-semibold mb-2" style="font-size:13px;">
                    <i class="bi bi-tag me-1 text-primary"></i>
                    <code>{{ $a->asset_tag }}</code> — {{ trim(($a->brand ?? '').' '.($a->model ?? '')) ?: '—' }}
                </div>
                <div class="row g-3" style="font-size:12px;">
                    <div class="col-6">
                        <div class="fw-semibold text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Section A — Identification</div>
                        <table class="table table-sm table-borderless mb-0" style="font-size:12px;">
                            <tr><td class="text-muted py-0" style="width:45%">Asset Tag</td><td class="py-0"><code>{{ $a->asset_tag }}</code></td></tr>
                            <tr><td class="text-muted py-0">Type</td><td class="py-0">{{ ucfirst(str_replace('_',' ',$a->asset_type ?? '—')) }}</td></tr>
                            <tr><td class="text-muted py-0">Brand</td><td class="py-0">{{ $a->brand ?? '—' }}</td></tr>
                            <tr><td class="text-muted py-0">Model</td><td class="py-0">{{ $a->model ?? '—' }}</td></tr>
                            <tr><td class="text-muted py-0">Serial No.</td><td class="py-0">{{ $a->serial_number ?? '—' }}</td></tr>
                        </table>
                    </div>
                    <div class="col-6">
                        <div class="fw-semibold text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Section B — Specification</div>
                        <table class="table table-sm table-borderless mb-0" style="font-size:12px;">
                            <tr><td class="text-muted py-0" style="width:45%">Processor</td><td class="py-0">{{ $a->processor ?? '—' }}</td></tr>
                            <tr><td class="text-muted py-0">RAM</td><td class="py-0">{{ $a->ram_size ?? '—' }}</td></tr>
                            <tr><td class="text-muted py-0">Storage</td><td class="py-0">{{ $a->storage ?? '—' }}</td></tr>
                            <tr><td class="text-muted py-0">OS</td><td class="py-0">{{ $a->operating_system ?? '—' }}</td></tr>
                            <tr><td class="text-muted py-0">Screen</td><td class="py-0">{{ $a->screen_size ?? '—' }}</td></tr>
                        </table>
                    </div>
                </div>
                @if($a->asset_photos && count($a->asset_photos))
                <div class="mt-2">
                    <div class="fw-semibold text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Asset Photos</div>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($a->asset_photos as $photo)
                        <a href="{{ asset('storage/'.$photo) }}" target="_blank">
                            <img src="{{ asset('storage/'.$photo) }}"
                                 style="height:80px;width:100px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6;">
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
            @endif
            @endforeach
        </div>
        @endif

        @if($aarf->it_notes)
        <div class="alert alert-light border mb-4">
            <strong><i class="bi bi-chat-square-text me-1"></i>IT Notes:</strong>
            <p class="mb-0 mt-1">{{ $aarf->it_notes }}</p>
        </div>
        @endif

        {{-- ── ACKNOWLEDGEMENT SECTION ─────────────────────────────────────
             Req 4: Only visible to New Hire (employee) and IT Manager.
             HR Manager sees the status summary above but NOT this button.
             
             Logic: This public page is accessed via a token link (not auth),
             so we cannot rely on Auth::user(). Instead we pass $viewerRole
             from the controller — default is 'employee' (new hire).
             IT Manager accesses this via their authenticated AARF management page.
        ──────────────────────────────────────────────────────────────────── --}}
        @php $viewerRole = $viewerRole ?? 'employee'; @endphp

        @if($viewerRole === 'employee')
            {{-- ── EMPLOYEE ACKNOWLEDGEMENT ── --}}
            @if(!$aarf->acknowledged)
            <div class="border rounded p-4 bg-light mb-4 no-print">
                <h6 class="fw-bold mb-2">
                    <i class="bi bi-clipboard-check me-2 text-primary"></i>Acknowledgement
                </h6>
                @if($assignments->isEmpty())
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        No assets are currently assigned. No acknowledgement needed at this time.
                    </p>
                @else
                    <p class="text-muted small mb-3">
                        By clicking <strong>"I Acknowledge"</strong> below, you confirm you have received the assets listed
                        above in good working condition. These assets remain the property of Claritas Asia Sdn. Bhd. and
                        must be returned upon resignation or end of employment.
                    </p>
                    <form action="{{ route('aarf.acknowledge', $aarf->acknowledgement_token) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-ack"
                                onclick="return confirm('Confirm acknowledgement of receipt of the listed assets?')">
                            <i class="bi bi-check2-circle me-2"></i>I Acknowledge Receipt of Assets
                        </button>
                    </form>
                @endif
            </div>
            @else
            <div class="border rounded p-4 text-center bg-success bg-opacity-10 mb-4">
                <i class="bi bi-patch-check-fill text-success" style="font-size:48px;"></i>
                <h5 class="mt-2 text-success fw-bold">Acknowledged</h5>
                <p class="text-muted mb-0">Acknowledged on {{ $aarf->acknowledged_at?->format('d M Y at h:i A') }}</p>
            </div>
            @endif

        @else
            {{-- HR and others: read-only --}}
            <div class="alert alert-secondary no-print">
                <i class="bi bi-eye me-2"></i>You are viewing this AARF in <strong>read-only mode</strong>.
            </div>
        @endif

        @if($aarf->asset_changes)
        <div class="mt-4">
            <h6 class="fw-bold mb-2" style="color:#1e3a5f;border-bottom:2px solid #dbeafe;padding-bottom:8px;">
                <i class="bi bi-clock-history me-2"></i>Asset History &amp; Changes
            </h6>
            <pre style="font-size:12px;font-family:monospace;white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;max-height:300px;overflow-y:auto;">{{ $aarf->asset_changes }}</pre>
        </div>
        @endif

        <div class="text-center mt-4 no-print">
            <small class="text-muted">
                Official document — {{ $w?->company ?? 'Claritas Asia Sdn. Bhd.' }} | Ref: {{ $aarf->aarf_reference }}
            </small>
            <br>
            <button class="btn btn-outline-secondary btn-sm mt-2" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print / Save as PDF
            </button>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>