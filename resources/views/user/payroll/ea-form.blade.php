@extends('layouts.app')
@section('title', 'My EA Form')
@section('page-title', 'My EA Form (Borang EA)')

@section('content')

{{-- Year Selector --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 fw-semibold">Tax Year:</label>
        <form method="GET" action="{{ route('user.payroll.ea-form') }}" class="d-flex gap-2">
            <select name="year" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                @forelse($availableYears as $y)
                    <option value="{{ $y }}" {{ $y == $year ? 'selected' : '' }}>{{ $y }}</option>
                @empty
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforelse
            </select>
        </form>
    </div>
    @if($currentForm)
    <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Print / PDF
    </button>
    @endif
</div>

@if(!$currentForm)
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-file-earmark-text" style="font-size:3rem;color:#ccc"></i>
            <p class="mt-3 mb-0 text-muted">No EA form available for {{ $year }}.<br>
            EA forms are published by HR after the end of each tax year.</p>
        </div>
    </div>
@else
    {{-- EA Form Content --}}
    <div class="card" id="ea-form-print">
        <div class="card-body p-4">

            {{-- Header --}}
            <div class="text-center mb-4">
                <div class="fw-bold" style="font-size:1.2rem">PENYATA SARAAN DARIPADA PENGGAJIAN</div>
                <div class="fw-bold">STATEMENT OF REMUNERATION FROM EMPLOYMENT</div>
                <div class="mt-1">
                    <span class="fw-bold">BORANG EA</span> &nbsp;|&nbsp;
                    Year of Remuneration: <strong>{{ $currentForm->year }}</strong>
                </div>
            </div>

            <hr>

            {{-- Employer --}}
            <div class="mb-4">
                <h6 class="fw-bold border-bottom pb-1">Employer / Majikan</h6>
                <div class="row g-2 mt-1">
                    <div class="col-md-8">
                        <small class="text-muted">Name</small>
                        <div class="fw-semibold">{{ $currentForm->employer_name }}</div>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted">Employer Tax No. (E No.)</small>
                        <div class="fw-semibold">{{ $currentForm->employer_tax_no ?: '—' }}</div>
                    </div>
                </div>
            </div>

            {{-- Section A --}}
            <div class="mb-4">
                <h6 class="fw-bold border-bottom pb-1">A — Employee Particulars</h6>
                <div class="row g-2 mt-1">
                    <div class="col-md-6"><small class="text-muted">Name</small><div class="fw-semibold">{{ $currentForm->employee_name }}</div></div>
                    <div class="col-md-6"><small class="text-muted">Income Tax No.</small><div>{{ $currentForm->employee_tax_no ?: '—' }}</div></div>
                    <div class="col-md-4"><small class="text-muted">IC / Passport</small><div>{{ $currentForm->employee_ic_no ?: '—' }}</div></div>
                    <div class="col-md-4"><small class="text-muted">EPF No.</small><div>{{ $currentForm->employee_epf_no ?: '—' }}</div></div>
                    <div class="col-md-4"><small class="text-muted">SOCSO No.</small><div>{{ $currentForm->employee_socso_no ?: '—' }}</div></div>
                </div>
            </div>

            {{-- Section B --}}
            <div class="mb-4">
                <h6 class="fw-bold border-bottom pb-1">B — Remuneration / Saraan</h6>
                <table class="table table-sm table-bordered mb-0 mt-2">
                    <tbody>
                        <tr>
                            <td style="width:60%">B1(a). Basic Salary / Gaji Pokok</td>
                            <td class="text-end" style="width:20%">{{ number_format($currentForm->gross_salary, 2) }}</td>
                            <td style="width:20%"></td>
                        </tr>
                        <tr>
                            <td>B1(b). Overtime / Kerja Lebih Masa</td>
                            <td class="text-end">{{ number_format($currentForm->overtime_pay, 2) }}</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td>B1(c). Commission / Bonus</td>
                            <td class="text-end">{{ number_format($currentForm->commission, 2) }}</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td>B1(d). Allowances / Elaun</td>
                            <td class="text-end">{{ number_format($currentForm->allowances, 2) }}</td>
                            <td></td>
                        </tr>
                        <tr class="table-light fw-semibold">
                            <td>B1. Total Gross Remuneration</td>
                            <td></td>
                            <td class="text-end">{{ number_format($currentForm->gross_remuneration, 2) }}</td>
                        </tr>
                        <tr><td>B2. Benefits-in-Kind (BIK)</td><td></td><td class="text-end">{{ number_format($currentForm->benefits_in_kind, 2) }}</td></tr>
                        <tr><td>B3. Value of Living Accommodation</td><td></td><td class="text-end">{{ number_format($currentForm->value_of_living_accommodation, 2) }}</td></tr>
                        <tr><td>B4. Pension / Annuity</td><td></td><td class="text-end">{{ number_format($currentForm->pension_or_annuity, 2) }}</td></tr>
                        <tr><td>B5. Gratuity / Compensation</td><td></td><td class="text-end">{{ number_format($currentForm->gratuity, 2) }}</td></tr>
                        <tr class="table-warning fw-bold">
                            <td>TOTAL REMUNERATION</td>
                            <td></td>
                            <td class="text-end">RM {{ number_format($currentForm->total_remuneration, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Section C --}}
            <div class="mb-4">
                <h6 class="fw-bold border-bottom pb-1">C — Deductions / Potongan</h6>
                <table class="table table-sm table-bordered mb-0 mt-2">
                    <tbody>
                        <tr><td style="width:60%">C1. EPF / KWSP</td><td class="text-end">{{ number_format($currentForm->epf_employee, 2) }}</td><td></td></tr>
                        <tr><td>C2. SOCSO / PERKESO</td><td class="text-end">{{ number_format($currentForm->socso_employee, 2) }}</td><td></td></tr>
                        <tr><td>C3. EIS / SIP</td><td class="text-end">{{ number_format($currentForm->eis_employee, 2) }}</td><td></td></tr>
                        <tr><td>C4. PCB / MTD / CP38</td><td class="text-end">{{ number_format($currentForm->pcb_paid, 2) }}</td><td></td></tr>
                        <tr><td>C5. Zakat</td><td class="text-end">{{ number_format($currentForm->zakat, 2) }}</td><td></td></tr>
                        <tr class="table-warning fw-bold">
                            <td>TOTAL DEDUCTIONS</td>
                            <td></td>
                            <td class="text-end">RM {{ number_format($currentForm->total_deductions, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Net Summary --}}
            <div class="card bg-light border-0 p-3">
                <div class="row text-center">
                    <div class="col-md-4">
                        <small class="text-muted">Total Remuneration</small>
                        <div class="h5 mb-0">RM {{ number_format($currentForm->total_remuneration, 2) }}</div>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted">Total Deductions</small>
                        <div class="h5 mb-0 text-danger">- RM {{ number_format($currentForm->total_deductions, 2) }}</div>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted">Net Remuneration</small>
                        <div class="h5 mb-0 text-success">RM {{ number_format($currentForm->net_pay, 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="text-muted mt-3" style="font-size:12px;">
                Published: {{ $currentForm->finalized_at?->format('d M Y') ?? '—' }}
            </div>
        </div>
    </div>
@endif

<style>
@media print {
    .d-print-none, .sidebar, .navbar { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-body { padding: 0 !important; }
}
</style>
@endsection
