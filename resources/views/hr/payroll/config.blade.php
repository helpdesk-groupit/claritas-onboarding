@extends('layouts.app')
@section('title', 'Payroll Configuration')
@section('page-title', 'Payroll Configuration')

@section('content')
<form method="POST" action="{{ route('hr.payroll.config.update') }}">
    @csrf
    @method('PUT')

    <!-- Statutory Rates -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-sliders me-2"></i>Statutory Rates</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">EPF Employee Rate (%)</label>
                    <input type="number" step="0.01" name="epf_employee_rate" class="form-control" value="{{ old('epf_employee_rate', $config->epf_employee_rate ?? 11.00) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">EPF Employer Rate (%)</label>
                    <input type="number" step="0.01" name="epf_employer_rate" class="form-control" value="{{ old('epf_employer_rate', $config->epf_employer_rate ?? 13.00) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">SOCSO Employee Rate (%)</label>
                    <input type="number" step="0.0001" name="socso_employee_rate" class="form-control" value="{{ old('socso_employee_rate', $config->socso_employee_rate ?? 0.50) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">SOCSO Employer Rate (%)</label>
                    <input type="number" step="0.0001" name="socso_employer_rate" class="form-control" value="{{ old('socso_employer_rate', $config->socso_employer_rate ?? 1.75) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">SOCSO Wage Ceiling (RM)</label>
                    <input type="number" step="0.01" name="socso_wage_ceiling" class="form-control" value="{{ old('socso_wage_ceiling', $config->socso_wage_ceiling ?? 5000.00) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">EIS Rate (%)</label>
                    <input type="number" step="0.0001" name="eis_rate" class="form-control" value="{{ old('eis_rate', $config->eis_rate ?? 0.20) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">EIS Wage Ceiling (RM)</label>
                    <input type="number" step="0.01" name="eis_wage_ceiling" class="form-control" value="{{ old('eis_wage_ceiling', $config->eis_wage_ceiling ?? 5000.00) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">HRDF Rate (%)</label>
                    <input type="number" step="0.01" name="hrdf_rate" class="form-control" value="{{ old('hrdf_rate', $config->hrdf_rate ?? 1.00) }}">
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input type="hidden" name="hrdf_enabled" value="0">
                        <input type="checkbox" name="hrdf_enabled" value="1" class="form-check-input" id="hrdfEnabled" {{ old('hrdf_enabled', $config->hrdf_enabled ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="hrdfEnabled">HRDF Enabled</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Default Working Days/Month</label>
                    <input type="number" name="default_working_days" class="form-control" value="{{ old('default_working_days', $config->default_working_days ?? 26) }}">
                </div>
            </div>
        </div>
    </div>

    <!-- Employer Registration Numbers -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-building me-2"></i>Employer Registration Numbers</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">LHDN Employer No. (E Number)</label>
                    <input type="text" name="lhdn_employer_no" class="form-control" value="{{ old('lhdn_employer_no', $config->lhdn_employer_no) }}" placeholder="E1234567890">
                </div>
                <div class="col-md-6">
                    <label class="form-label">EPF Employer No.</label>
                    <input type="text" name="epf_employer_no" class="form-control" value="{{ old('epf_employer_no', $config->epf_employer_no) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">SOCSO Employer No.</label>
                    <input type="text" name="socso_employer_no" class="form-control" value="{{ old('socso_employer_no', $config->socso_employer_no) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">EIS Employer No.</label>
                    <input type="text" name="eis_employer_no" class="form-control" value="{{ old('eis_employer_no', $config->eis_employer_no) }}">
                </div>
            </div>
        </div>
    </div>

    <!-- Company Bank Details -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-bank me-2"></i>Company Bank Details</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Bank Name</label>
                    <input type="text" name="bank_name" class="form-control" value="{{ old('bank_name', $config->bank_name) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Bank Account Number</label>
                    <input type="text" name="bank_account_number" class="form-control" value="{{ old('bank_account_number', $config->bank_account_number) }}">
                </div>
            </div>
        </div>
    </div>

    <!-- External Links -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-link-45deg me-2"></i>External Portals</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <a href="https://ez.hasil.gov.my" target="_blank" rel="noopener" class="btn btn-outline-primary w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i>LHDN e-Filing
                    </a>
                    <small class="text-muted d-block mt-1">PCB/MTD submission, EA forms</small>
                </div>
                <div class="col-md-6 col-lg-3">
                    <a href="https://i-akaun.kwsp.gov.my" target="_blank" rel="noopener" class="btn btn-outline-success w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i>EPF i-Akaun
                    </a>
                    <small class="text-muted d-block mt-1">EPF contribution remittance</small>
                </div>
                <div class="col-md-6 col-lg-3">
                    <a href="https://assist.perkeso.gov.my" target="_blank" rel="noopener" class="btn btn-outline-info w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i>SOCSO Portal
                    </a>
                    <small class="text-muted d-block mt-1">SOCSO/PERKESO contributions</small>
                </div>
                <div class="col-md-6 col-lg-3">
                    <a href="https://eis.perkeso.gov.my" target="_blank" rel="noopener" class="btn btn-outline-warning w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i>EIS Portal
                    </a>
                    <small class="text-muted d-block mt-1">Employment Insurance System</small>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Configuration</button>
    <a href="{{ route('hr.payroll.pay-runs.index') }}" class="btn btn-secondary ms-2">Back to Payroll</a>
</form>
@endsection
