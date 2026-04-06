@extends('layouts.app')
@section('title', 'Claim Policy Settings')
@section('page-title', 'Claim Policy')

@section('content')
<div class="container-fluid">
    <a href="{{ route('hr.claims.index') }}" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to Claims
    </a>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Expense Claim Policy Settings</h5>
        </div>
        <div class="card-body">
            <form action="{{ route('hr.claims.policy.update') }}" method="POST">
                @csrf @method('PUT')

                <div class="row g-4">
                    {{-- Submission Deadline --}}
                    <div class="col-md-6">
                        <div class="section-header">
                            <h6><i class="bi bi-calendar-event me-2"></i>Submission Deadline</h6>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deadline Day of Month <span class="text-danger">*</span></label>
                            <input type="number" name="submission_deadline_day" class="form-control" min="1" max="28"
                                   value="{{ old('submission_deadline_day', $policy->submission_deadline_day) }}" required>
                            <small class="text-muted">Claims must be submitted by this day each month (default: 20th)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reminder Days Before Deadline</label>
                            <input type="number" name="reminder_days_before" class="form-control" min="0" max="15"
                                   value="{{ old('reminder_days_before', $policy->reminder_days_before) }}">
                            <small class="text-muted">Send email reminder N days before deadline</small>
                        </div>
                    </div>

                    {{-- GST Settings --}}
                    <div class="col-md-6">
                        <div class="section-header">
                            <h6><i class="bi bi-percent me-2"></i>GST / Tax Settings</h6>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="gst_enabled" value="1" class="form-check-input"
                                       {{ old('gst_enabled', $policy->gst_enabled) ? 'checked' : '' }}>
                                <label class="form-check-label">Enable GST on Claims</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">GST Rate (%)</label>
                            <input type="number" name="gst_rate" class="form-control" step="0.01" min="0" max="100"
                                   value="{{ old('gst_rate', $policy->gst_rate) }}">
                        </div>
                    </div>

                    {{-- Approval Settings --}}
                    <div class="col-md-6">
                        <div class="section-header">
                            <h6><i class="bi bi-check2-square me-2"></i>Approval Settings</h6>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="require_manager_approval" value="1" class="form-check-input"
                                       {{ old('require_manager_approval', $policy->require_manager_approval) ? 'checked' : '' }}>
                                <label class="form-check-label">Require Manager Approval</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="require_hr_approval" value="1" class="form-check-input"
                                       {{ old('require_hr_approval', $policy->require_hr_approval) ? 'checked' : '' }}>
                                <label class="form-check-label">Require HR Approval</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Auto-Approve Below (RM)</label>
                            <input type="number" name="auto_approve_below" class="form-control" step="0.01" min="0"
                                   value="{{ old('auto_approve_below', $policy->auto_approve_below) }}">
                            <small class="text-muted">Claims below this amount skip manager approval (0 = disabled)</small>
                        </div>
                    </div>

                    {{-- General Rules --}}
                    <div class="col-md-6">
                        <div class="section-header">
                            <h6><i class="bi bi-journal-text me-2"></i>General Rules</h6>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Company Rules (displayed to employees)</label>
                            <textarea name="general_rules" class="form-control" rows="6" placeholder="Enter company claim rules...">{{ old('general_rules', $policy->general_rules) }}</textarea>
                            <small class="text-muted">One rule per line. Shown in the employee claims page.</small>
                        </div>
                    </div>
                </div>

                <hr>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save Policy Settings
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
