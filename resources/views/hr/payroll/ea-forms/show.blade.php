@extends('layouts.app')
@section('title', 'EA Form — ' . $eaForm->employee_name)
@section('page-title', 'Borang EA (CP.8D) — ' . $eaForm->year)

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-circle me-1"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- Action Bar (hidden on print) --}}
<div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
    <div>
        <a href="{{ route('hr.payroll.ea-forms.index', ['year' => $eaForm->year]) }}" class="btn btn-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to EA Forms
        </a>
    </div>
    <div class="d-flex gap-2">
        @if($eaForm->status === 'draft')
        <form method="POST" action="{{ route('hr.payroll.ea-forms.finalize', $eaForm) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Finalize this EA form?')">
                <i class="bi bi-check-lg me-1"></i>Finalize
            </button>
        </form>
        @endif
        <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Print / PDF
        </button>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     EA FORM — LHDN CP.8D FORMAT
     ═══════════════════════════════════════════════════════════════ --}}
<div class="card" id="ea-form-print">
    <div class="card-body p-4">

        {{-- Header --}}
        <div class="text-center mb-4">
            <div class="fw-bold" style="font-size:1.25rem">PENYATA SARAAN DARIPADA PENGGAJIAN</div>
            <div class="fw-bold" style="font-size:1.1rem">STATEMENT OF REMUNERATION FROM EMPLOYMENT</div>
            <div class="mt-1">
                <span class="fw-bold">BORANG EA</span> &nbsp;|&nbsp;
                <span>Tahun Saraan / Year of Remuneration: <strong>{{ $eaForm->year }}</strong></span>
            </div>
            <div class="mt-1">
                <small class="text-muted">Lembaga Hasil Dalam Negeri Malaysia (LHDN) — Form CP.8D</small>
            </div>
            <div class="mt-1">{!! $eaForm->statusBadge() !!}</div>
        </div>

        <hr>

        {{-- Employer Details --}}
        <div class="mb-4">
            <h6 class="fw-bold text-uppercase border-bottom pb-1"><i class="bi bi-building me-1"></i>Employer / Majikan</h6>
            <div class="row g-2 mt-1">
                <div class="col-md-8">
                    <small class="text-muted">Name of Employer / Nama Majikan</small>
                    <div class="fw-semibold">{{ $eaForm->employer_name ?: '—' }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Employer's Tax Ref. No. / No. Cukai Majikan (E No.)</small>
                    <div class="fw-semibold">{{ $eaForm->employer_tax_no ?: '—' }}</div>
                </div>
                <div class="col-12">
                    <small class="text-muted">Address / Alamat</small>
                    <div>{{ $eaForm->employer_address ?: '—' }}</div>
                </div>
            </div>
        </div>

        {{-- Section A: Employee Particulars --}}
        <div class="mb-4">
            <h6 class="fw-bold text-uppercase border-bottom pb-1"><i class="bi bi-person me-1"></i>A — Employee Particulars / Butiran Pekerja</h6>
            <div class="row g-2 mt-1">
                <div class="col-md-6">
                    <small class="text-muted">A1. Name / Nama</small>
                    <div class="fw-semibold">{{ $eaForm->employee_name }}</div>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">A2. Income Tax No. / No. Cukai Pendapatan</small>
                    <div class="fw-semibold">{{ $eaForm->employee_tax_no ?: '—' }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">A3. IC / Passport No.</small>
                    <div>{{ $eaForm->employee_ic_no ?: '—' }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">A4. EPF No. / No. KWSP</small>
                    <div>{{ $eaForm->employee_epf_no ?: '—' }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">A5. SOCSO No. / No. PERKESO</small>
                    <div>{{ $eaForm->employee_socso_no ?: '—' }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">A6. Designation / Jawatan</small>
                    <div>{{ $eaForm->designation ?: '—' }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">A7. Start Date / Tarikh Mula</small>
                    <div>{{ $eaForm->employment_start_date?->format('d/m/Y') ?? '—' }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">A8. End Date / Tarikh Berhenti</small>
                    <div>{{ $eaForm->employment_end_date?->format('d/m/Y') ?? 'Still employed' }}</div>
                </div>
            </div>
        </div>

        {{-- Section B: Remuneration --}}
        <div class="mb-4">
            <h6 class="fw-bold text-uppercase border-bottom pb-1"><i class="bi bi-cash-stack me-1"></i>B — Remuneration / Saraan</h6>
            <table class="table table-sm table-bordered mb-0 mt-2">
                <tbody>
                    <tr>
                        <td class="text-muted" style="width:60%">B1(a). Salary, Wages, Leave Pay, Fee, Commission, Overtime, Tips, etc.<br><small class="text-muted">Gaji, upah, gaji cuti, yuran, komisen, kerja lebih masa, tip, dsb.</small></td>
                        <td></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="ps-4"><small>Basic Salary / Gaji Pokok</small></td>
                        <td class="text-end" style="width:20%">{{ number_format($eaForm->gross_salary, 2) }}</td>
                        <td style="width:20%"></td>
                    </tr>
                    <tr>
                        <td class="ps-4"><small>Overtime / Kerja Lebih Masa</small></td>
                        <td class="text-end">{{ number_format($eaForm->overtime_pay, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="ps-4"><small>Commission / Bonus / Komisen</small></td>
                        <td class="text-end">{{ number_format($eaForm->commission, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="ps-4"><small>Allowances / Other Earnings / Elaun Lain</small></td>
                        <td class="text-end">{{ number_format($eaForm->allowances, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr class="table-light fw-semibold">
                        <td>B1. Total Gross Remuneration / Jumlah Saraan Kasar</td>
                        <td></td>
                        <td class="text-end">{{ number_format($eaForm->gross_remuneration, 2) }}</td>
                    </tr>
                    <tr>
                        <td>B2. Value of Benefits-in-Kind (BIK) / Manfaat Berupa Barangan</td>
                        <td></td>
                        <td class="text-end">{{ number_format($eaForm->benefits_in_kind, 2) }}</td>
                    </tr>
                    <tr>
                        <td>B3. Value of Living Accommodation / Tempat Kediaman</td>
                        <td></td>
                        <td class="text-end">{{ number_format($eaForm->value_of_living_accommodation, 2) }}</td>
                    </tr>
                    <tr>
                        <td>B4. Pension / Annuity / Pencen / Anuiti</td>
                        <td></td>
                        <td class="text-end">{{ number_format($eaForm->pension_or_annuity, 2) }}</td>
                    </tr>
                    <tr>
                        <td>B5. Gratuity / Compensation for Loss of Employment / Gratuit</td>
                        <td></td>
                        <td class="text-end">{{ number_format($eaForm->gratuity, 2) }}</td>
                    </tr>
                    <tr class="table-warning fw-bold">
                        <td>TOTAL REMUNERATION (B1 + B2 + B3 + B4 + B5) / JUMLAH SARAAN</td>
                        <td></td>
                        <td class="text-end">RM {{ number_format($eaForm->total_remuneration, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Section C: Deductions --}}
        <div class="mb-4">
            <h6 class="fw-bold text-uppercase border-bottom pb-1"><i class="bi bi-dash-circle me-1"></i>C — Deductions / Potongan</h6>
            <table class="table table-sm table-bordered mb-0 mt-2">
                <tbody>
                    <tr>
                        <td style="width:60%">C1. EPF / KWSP (Employee's Contribution)</td>
                        <td class="text-end" style="width:20%">{{ number_format($eaForm->epf_employee, 2) }}</td>
                        <td style="width:20%"></td>
                    </tr>
                    <tr>
                        <td>C2. SOCSO / PERKESO (Employee's Contribution)</td>
                        <td class="text-end">{{ number_format($eaForm->socso_employee, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>C3. EIS / SIP (Employee's Contribution)</td>
                        <td class="text-end">{{ number_format($eaForm->eis_employee, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>C4. Income Tax Deducted (PCB/MTD/CP38) / Potongan Cukai Bulanan</td>
                        <td class="text-end">{{ number_format($eaForm->pcb_paid, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>C5. Zakat</td>
                        <td class="text-end">{{ number_format($eaForm->zakat, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr class="table-warning fw-bold">
                        <td>TOTAL DEDUCTIONS / JUMLAH POTONGAN</td>
                        <td></td>
                        <td class="text-end">RM {{ number_format($eaForm->total_deductions, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Employer Contributions (info) --}}
        <div class="mb-4">
            <h6 class="fw-bold text-uppercase border-bottom pb-1"><i class="bi bi-building-check me-1"></i>Employer Contributions / Caruman Majikan</h6>
            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <small class="text-muted">EPF Employer</small>
                    <div class="fw-semibold">RM {{ number_format($eaForm->epf_employer, 2) }}</div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">SOCSO Employer</small>
                    <div class="fw-semibold">RM {{ number_format($eaForm->socso_employer, 2) }}</div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">EIS Employer</small>
                    <div class="fw-semibold">RM {{ number_format($eaForm->eis_employer, 2) }}</div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">HRDF Employer</small>
                    <div class="fw-semibold">RM {{ number_format($eaForm->hrdf_employer, 2) }}</div>
                </div>
            </div>
        </div>

        {{-- Net Summary --}}
        <div class="card bg-light border-0 p-3 mb-4">
            <div class="row text-center">
                <div class="col-md-4">
                    <small class="text-muted">Total Remuneration</small>
                    <div class="h5 mb-0">RM {{ number_format($eaForm->total_remuneration, 2) }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Total Deductions</small>
                    <div class="h5 mb-0 text-danger">- RM {{ number_format($eaForm->total_deductions, 2) }}</div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Net Remuneration</small>
                    <div class="h5 mb-0 text-success">RM {{ number_format($eaForm->net_pay, 2) }}</div>
                </div>
            </div>
        </div>

        {{-- Edit Form (draft only, hidden on print) --}}
        @if($eaForm->status === 'draft')
        <div class="d-print-none">
            <h6 class="fw-bold text-uppercase border-bottom pb-1 mt-4"><i class="bi bi-pencil me-1"></i>Edit Additional Fields</h6>
            <form method="POST" action="{{ route('hr.payroll.ea-forms.update', $eaForm) }}">
                @csrf @method('PUT')
                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label">Benefits-in-Kind (BIK)</label>
                        <input type="number" step="0.01" min="0" name="benefits_in_kind" class="form-control" value="{{ old('benefits_in_kind', $eaForm->benefits_in_kind) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Value of Living Accommodation</label>
                        <input type="number" step="0.01" min="0" name="value_of_living_accommodation" class="form-control" value="{{ old('value_of_living_accommodation', $eaForm->value_of_living_accommodation) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Pension / Annuity</label>
                        <input type="number" step="0.01" min="0" name="pension_or_annuity" class="form-control" value="{{ old('pension_or_annuity', $eaForm->pension_or_annuity) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Commission / Bonus</label>
                        <input type="number" step="0.01" min="0" name="commission" class="form-control" value="{{ old('commission', $eaForm->commission) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Gratuity / Compensation</label>
                        <input type="number" step="0.01" min="0" name="gratuity" class="form-control" value="{{ old('gratuity', $eaForm->gratuity) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Zakat</label>
                        <input type="number" step="0.01" min="0" name="zakat" class="form-control" value="{{ old('zakat', $eaForm->zakat) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes', $eaForm->notes) }}</textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1"></i>Update EA Form</button>
            </form>
        </div>
        @endif

        {{-- Footer (meta info) --}}
        <div class="border-top pt-3 mt-4 text-muted" style="font-size:12px;">
            <div class="row">
                <div class="col-md-6">
                    Generated by: {{ $eaForm->generator?->name ?? 'System' }} on {{ $eaForm->created_at?->format('d M Y, H:i') }}
                </div>
                <div class="col-md-6 text-md-end">
                    @if($eaForm->finalized_at)
                        Finalized: {{ $eaForm->finalized_at->format('d M Y, H:i') }}
                    @endif
                </div>
            </div>
            @if($eaForm->notes)
            <div class="mt-1"><strong>Notes:</strong> {{ $eaForm->notes }}</div>
            @endif
        </div>

    </div>
</div>

{{-- Print Styles --}}
<style>
@media print {
    .d-print-none { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-body { padding: 0 !important; }
    body { font-size: 11pt; }
    table { font-size: 10pt; }
}
</style>
@endsection
