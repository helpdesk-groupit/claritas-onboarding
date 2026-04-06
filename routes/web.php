<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItTaskController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\AarfController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\OffboardingController;
use App\Http\Controllers\AccountManagementController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\OnboardingInviteController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ExpenseClaimController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SecureFileController;
use App\Http\Controllers\Accounting\AccountingDashboardController;
use App\Http\Controllers\Accounting\ChartOfAccountController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\AccountsReceivableController;
use App\Http\Controllers\Accounting\AccountsPayableController;
use App\Http\Controllers\Accounting\BankingController;
use App\Http\Controllers\Accounting\TaxController;
use App\Http\Controllers\Accounting\FixedAssetController;
use App\Http\Controllers\Accounting\BudgetController;
use App\Http\Controllers\Accounting\FinancialReportController;
use App\Http\Controllers\Accounting\AiAccountingController;
use App\Http\Controllers\Accounting\AccountingSettingController;
use Illuminate\Support\Facades\Route;

// Root redirect
Route::get('/', fn() => redirect()->route('login'));

// ── Guest ──────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login',                  [AuthController::class, 'showLogin'])->name('login');
    // Throttle: 30 per minute per IP as last-resort protection — app-level lockout handles per-user lockout at 5 attempts
    Route::post('/login',                 [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::get('/register',               [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register/check-email',  [AuthController::class, 'checkEmail'])->name('register.checkEmail');
    Route::get('/register/set-password',  [AuthController::class, 'showSetPassword'])->name('register.setPassword');
    Route::post('/register',              [AuthController::class, 'register']);
    Route::get('/forgot-password',        [AuthController::class, 'showForgotPassword'])->name('password.request');
    // Throttle: max 5 reset requests per minute per IP — prevents email flooding
    Route::post('/forgot-password',       [AuthController::class, 'sendResetLink'])->name('password.email')->middleware('throttle:5,1');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password',        [AuthController::class, 'resetPassword'])->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// ── Public AARF token link (for employee acknowledgement via email) ─────────
Route::get('/aarf/{token}',              [AarfController::class, 'viewAarf'])->name('aarf.view');
// Throttle: 10 per minute — CSRF-exempt but still rate-limited
Route::post('/aarf/{token}/acknowledge', [AarfController::class, 'acknowledge'])->name('aarf.acknowledge')->middleware('throttle:10,1');

// Public onboarding invite routes (no auth required)
Route::get('/onboarding-invite/success',          [OnboardingInviteController::class, 'success'])->name('onboarding.invite.success');
Route::get('/onboarding-invite/{token}',          [OnboardingInviteController::class, 'showForm'])->name('onboarding.invite.form');
Route::post('/onboarding-invite/{token}/verify',  [OnboardingInviteController::class, 'verifyEmail'])->name('onboarding.invite.verify')->middleware('throttle:10,1');
Route::post('/onboarding-invite/{token}/consent', [OnboardingInviteController::class, 'acceptConsent'])->name('onboarding.invite.consent')->middleware('throttle:10,1');
Route::post('/onboarding-invite/{token}/submit',  [OnboardingInviteController::class, 'submit'])->name('onboarding.invite.submit')->middleware('throttle:5,1');

// ── Authenticated ──────────────────────────────────────────────────────────
Route::middleware(['auth', \App\Http\Middleware\EnforceSingleSession::class, \App\Http\Middleware\SecurityAuditMiddleware::class])->group(function () {

    // Secure file serving — all sensitive documents (NRIC, contracts, certs) require auth
    Route::get('/secure-file/{path}', [SecureFileController::class, 'serve'])
        ->where('path', '.*')
        ->name('secure.file');

    // Dashboards
    Route::get('/dashboard',    [DashboardController::class, 'userDashboard'])->name('user.dashboard');
    Route::get('/hr/dashboard', [DashboardController::class, 'hrDashboard'])->name('hr.dashboard');
    Route::get('/it/dashboard',   [DashboardController::class, 'itDashboard'])->name('it.dashboard');
    Route::get('/it/onboarding',  [DashboardController::class, 'itOnboarding'])->name('it.onboarding');
    Route::get('/it/tasks',              [ItTaskController::class, 'index'])->name('it.tasks');
    Route::post('/it/tasks/{task}/status', [ItTaskController::class, 'updateStatus'])->name('it.tasks.status');
    Route::post('/it/onboarding/{onboarding}/assign-pic', [ItTaskController::class, 'assignPic'])->name('it.assign.pic');
    Route::post('/it/tasks/{task}/reassign', [ItTaskController::class, 'reassign'])->name('it.tasks.reassign');

    // Profile (all users)
    Route::get('/profile',                 [ProfileController::class, 'show'])->name('profile');
    Route::put('/profile',                 [ProfileController::class, 'update'])->name('profile.update')->middleware('throttle:uploads');
    Route::put('/profile/biodata',              [ProfileController::class, 'updateBiodata'])->name('profile.biodata.update');
    Route::put('/profile/work',                 [ProfileController::class, 'updateWork'])->name('profile.work.update');
    Route::post('/profile/education',           [ProfileController::class, 'updateEducation'])->name('profile.education.update');
    Route::post('/profile/spouse',              [ProfileController::class, 'updateSpouse'])->name('profile.spouse.update');
    Route::put('/profile/spouse/{spouse}',      [ProfileController::class, 'editSpouse'])->name('profile.spouse.edit');
    Route::delete('/profile/spouse/{spouse}',   [ProfileController::class, 'deleteSpouse'])->name('profile.spouse.delete');
    Route::post('/profile/emergency',           [ProfileController::class, 'updateEmergency'])->name('profile.emergency.update');
    Route::post('/profile/children',            [ProfileController::class, 'updateChildren'])->name('profile.children.update');
    Route::post('/profile/aarf/upload',         [ProfileController::class, 'uploadAarf'])->name('profile.aarf.upload')->middleware('throttle:uploads');
    Route::post('/profile/consent',             [ProfileController::class, 'submitConsent'])->name('profile.consent');
    Route::get('/profile/re-consent',           [ProfileController::class, 'showReConsent'])->name('profile.re-consent.show');
    Route::post('/profile/re-consent',          [ProfileController::class, 'storeReConsent'])->name('profile.re-consent.store');
    Route::get('/profile/download/{type}',      [ProfileController::class, 'download'])->name('profile.download');

    // Account (all users)
    Route::get('/account',           [AccountController::class, 'show'])->name('account');
    Route::post('/account/change-password', [AccountController::class, 'changePassword'])->name('account.change-password');
    Route::post('/account/language',        [AccountController::class, 'setLanguage'])->name('account.language');
    Route::post('/account/avatar',          [AccountController::class, 'uploadProfilePicture'])->name('account.avatar')->middleware('throttle:uploads');

    // Onboarding (HR)
    Route::get('/onboarding',                   [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::post('/onboarding',                  [OnboardingController::class, 'store'])->name('onboarding.store')->middleware('throttle:uploads');
    Route::post('/onboarding/send-invite',      [OnboardingInviteController::class, 'send'])->name('onboarding.invite.send');
    Route::get('/onboarding/export/csv',        [OnboardingController::class, 'export'])->name('onboarding.export');
    Route::get('/onboarding/manager-email',     [OnboardingController::class, 'getManagerEmail'])->name('onboarding.managerEmail');
    Route::get('/onboarding/{onboarding}',                  [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::get('/onboarding/{onboarding}/edit',             [OnboardingController::class, 'edit'])->name('onboarding.edit');
    Route::put('/onboarding/{onboarding}',                  [OnboardingController::class, 'update'])->name('onboarding.update')->middleware('throttle:uploads');
    Route::get('/onboarding/{onboarding}/re-consent',       [OnboardingController::class, 'showReConsent'])->name('onboarding.re-consent.show');
    Route::post('/onboarding/{onboarding}/re-consent',      [OnboardingController::class, 'storeReConsent'])->name('onboarding.re-consent.store');
    Route::post('/onboarding/{onboarding}/avatar',          [OnboardingController::class, 'uploadAvatar'])->name('onboarding.avatar')->middleware('throttle:uploads');

    // HR: Employee Listing
    Route::get('/superadmin/roles',                          [EmployeeController::class, 'roleManagement'])->name('superadmin.roles.index');
    Route::put('/superadmin/roles/{employee}',               [EmployeeController::class, 'roleUpdate'])->name('superadmin.roles.update');
    Route::get('/superadmin/roles/{employee}/permissions',   [EmployeeController::class, 'getPermissions'])->name('superadmin.permissions.get');
    Route::post('/superadmin/roles/{employee}/permissions',  [EmployeeController::class, 'updatePermissions'])->name('superadmin.permissions.update');

    // Account Management (Superadmin / System Admin)
    Route::get('/superadmin/account-management',            [AccountManagementController::class, 'index'])->name('superadmin.accounts.index');
    Route::post('/superadmin/account-management/{user}/activate', [AccountManagementController::class, 'activate'])->name('superadmin.accounts.activate');

    Route::get('/superadmin/system-overview',           [DashboardController::class, 'systemOverview'])->name('superadmin.system-overview');

    // ══════════════════════════════════════════════════════════════════════
    // C-SUITE REPORTS & ANALYTICS
    // ══════════════════════════════════════════════════════════════════════
    Route::get('/reports',                              [ReportController::class, 'executiveDashboard'])->name('reports.executive');
    Route::get('/reports/workforce',                    [ReportController::class, 'workforceReport'])->name('reports.workforce');
    Route::get('/reports/financial',                    [ReportController::class, 'financialReport'])->name('reports.financial');
    Route::get('/reports/leave',                        [ReportController::class, 'leaveReport'])->name('reports.leave');
    Route::get('/reports/attendance',                   [ReportController::class, 'attendanceReport'])->name('reports.attendance');
    Route::get('/reports/assets',                       [ReportController::class, 'assetReport'])->name('reports.assets');

    Route::get('/superadmin/companies',            [CompanyController::class, 'index'])->name('superadmin.companies.index');
    Route::post('/superadmin/companies',           [CompanyController::class, 'store'])->name('superadmin.companies.store');
    Route::put('/superadmin/companies/{company}',  [CompanyController::class, 'update'])->name('superadmin.companies.update');
    Route::delete('/superadmin/companies/{company}',[CompanyController::class, 'destroy'])->name('superadmin.companies.destroy');

    Route::get('/hr/employees',                    [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/hr/employees/export',             [EmployeeController::class, 'export'])->name('employees.export');
    Route::get('/hr/employees/import-template',    [EmployeeController::class, 'importTemplate'])->name('employees.import.template');
    Route::post('/hr/employees/import',            [EmployeeController::class, 'importCsv'])->name('employees.import')->middleware('throttle:uploads');
    Route::get('/hr/employees/{employee}',     [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/hr/employees/{employee}/edit',[EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/hr/employees/{employee}',     [EmployeeController::class, 'update'])->name('employees.update')->middleware('throttle:uploads');
    Route::post('/hr/employees/{employee}/avatar', [EmployeeController::class, 'uploadAvatar'])->name('employees.avatar')->middleware('throttle:uploads');
    Route::post('/hr/employees/{employee}/education', [EmployeeController::class, 'updateEducation'])->name('employees.education.update');
    Route::post('/hr/employees/{employee}/spouse',            [EmployeeController::class, 'updateSpouse'])->name('employees.spouse.update');
    Route::put('/hr/employees/{employee}/spouse/{spouseId}',  [EmployeeController::class, 'editSpouse'])->name('employees.spouse.edit');
    Route::delete('/hr/employees/{employee}/spouse/{spouse}', [EmployeeController::class, 'deleteSpouse'])->name('employees.spouse.delete');
    Route::post('/hr/employees/{employee}/emergency', [EmployeeController::class, 'updateEmergency'])->name('employees.emergency.update');
    Route::post('/hr/employees/{employee}/children',  [EmployeeController::class, 'updateChildren'])->name('employees.children.update');
    // IT: manage assets assigned to an employee
    Route::post('/hr/employees/{employee}/assets/assign',  [EmployeeController::class, 'assignAsset'])->name('employees.assets.assign');
    Route::post('/hr/employees/{employee}/assets/{asset}/return', [EmployeeController::class, 'returnEmployeeAsset'])->name('employees.assets.return');

    // Employee Contracts (upload: HR Manager only; download: HR Manager + profile owner)
    Route::post('/hr/employees/{employee}/contracts',            [EmployeeController::class, 'contractUpload'])->name('employees.contracts.upload')->middleware('throttle:uploads');
    Route::get('/hr/employees/{employee}/contracts/{contract}/download', [EmployeeController::class, 'contractDownload'])->name('employees.contracts.download');
    Route::delete('/hr/employees/{employee}/contracts/{contract}',       [EmployeeController::class, 'contractDelete'])->name('employees.contracts.delete');

    // Per-employee handbook & orientation (HR Manager / Executive)
Route::post('/hr/employees/{employee}/handbook',    [EmployeeController::class, 'handbookUpload'])->name('employees.handbook.upload')->middleware('throttle:uploads');
Route::delete('/hr/employees/{employee}/handbook',  [EmployeeController::class, 'handbookDelete'])->name('employees.handbook.delete');
Route::post('/hr/employees/{employee}/orientation', [EmployeeController::class, 'orientationUpload'])->name('employees.orientation.upload')->middleware('throttle:uploads');
Route::delete('/hr/employees/{employee}/orientation',[EmployeeController::class, 'orientationDelete'])->name('employees.orientation.delete');
    // HR: Offboarding
    Route::get('/hr/offboarding',                              [OffboardingController::class, 'hrIndex'])->name('hr.offboarding.index');
    Route::get('/hr/offboarding/{offboarding}',                [OffboardingController::class, 'hrShow'])->name('hr.offboarding.show');
    Route::get('/hr/offboarding/{offboarding}/edit',           [OffboardingController::class, 'hrEdit'])->name('hr.offboarding.edit');
    Route::put('/hr/offboarding/{offboarding}',                [OffboardingController::class, 'hrUpdate'])->name('hr.offboarding.update')->middleware('throttle:uploads');

    // IT: Offboarding
    Route::get('/it/offboarding',                              [OffboardingController::class, 'itIndex'])->name('it.offboarding.index');
    Route::get('/it/offboarding/{offboarding}',                [OffboardingController::class, 'itShow'])->name('it.offboarding.show');
    Route::post('/it/offboarding/{offboarding}/assign-pic',    [ItTaskController::class, 'assignOffboardingPic'])->name('it.offboarding.assign.pic');

    // Shared: status update (HR + IT)
    Route::post('/offboarding/{offboarding}/status',           [OffboardingController::class, 'updateStatus'])->name('offboarding.status');

    // Keep legacy route pointing to HR index for any old links
    Route::get('/offboarding', fn() => redirect()->route('hr.offboarding.index'))->name('offboarding.index');


    // Announcements (HR Manager / Superadmin)
    Route::get('/hr/announcements',                        [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('/hr/announcements/create',                 [AnnouncementController::class, 'create'])->name('announcements.create');
    Route::post('/hr/announcements',                       [AnnouncementController::class, 'store'])->name('announcements.store')->middleware('throttle:uploads');
    Route::get('/hr/announcements/{announcement}/edit',    [AnnouncementController::class, 'edit'])->name('announcements.edit');
    Route::put('/hr/announcements/{announcement}',         [AnnouncementController::class, 'update'])->name('announcements.update')->middleware('throttle:uploads');
    Route::delete('/hr/announcements/{announcement}',      [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    // HR AARF view
    Route::get('/hr/aarf/{aarf}', [AarfController::class, 'hrView'])->name('hr.aarf.view');

    // IT AARF management routes removed — IT AARF pages decommissioned.
    // Employee acknowledgement (aarf.view / aarf.acknowledge) and HR view (hr.aarf.view) are unaffected.

    // Assets (IT)
    Route::get('/assets',              [AssetController::class, 'index'])->name('assets.index');
    Route::post('/assets',             [AssetController::class, 'store'])->name('assets.store')->middleware('throttle:uploads');
    Route::get('/assets/export/csv',          [AssetController::class, 'export'])->name('assets.export');
    Route::get('/assets/import-template',     [AssetController::class, 'importTemplate'])->name('assets.import.template');
    Route::post('/assets/import',             [AssetController::class, 'importCsv'])->name('assets.import')->middleware('throttle:uploads');
    Route::get('/assets/disposed',                 [AssetController::class, 'disposed'])->name('assets.disposed');
    Route::get('/assets/disposed/{asset}',         [AssetController::class, 'disposedShow'])->name('assets.disposed.show');
    Route::get('/assets/{asset}',             [AssetController::class, 'show'])->name('assets.show');
    Route::get('/assets/{asset}/edit',        [AssetController::class, 'edit'])->name('assets.edit');
    Route::put('/assets/{asset}',             [AssetController::class, 'update'])->name('assets.update')->middleware('throttle:uploads');
    Route::post('/assets/{asset}/reassign',   [AssetController::class, 'reassign'])->name('assets.reassign');
    Route::post('/assets/{asset}/return',     [AssetController::class, 'returnAsset'])->name('assets.return');
    Route::post('/assets/{asset}/release',    [AssetController::class, 'releaseAsset'])->name('assets.release');

    // ══════════════════════════════════════════════════════════════════════
    // LEAVE MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════

    // HR: Leave Types
    Route::get('/hr/leave/types',                       [LeaveController::class, 'types'])->name('hr.leave.types');
    Route::post('/hr/leave/types',                      [LeaveController::class, 'storeType'])->name('hr.leave.types.store');
    Route::put('/hr/leave/types/{leaveType}',           [LeaveController::class, 'updateType'])->name('hr.leave.types.update');

    // HR: Entitlements
    Route::get('/hr/leave/entitlements',                [LeaveController::class, 'entitlements'])->name('hr.leave.entitlements');
    Route::post('/hr/leave/entitlements',               [LeaveController::class, 'storeEntitlement'])->name('hr.leave.entitlements.store');
    Route::put('/hr/leave/entitlements/{entitlement}',  [LeaveController::class, 'updateEntitlement'])->name('hr.leave.entitlements.update');
    Route::delete('/hr/leave/entitlements/{entitlement}',[LeaveController::class, 'destroyEntitlement'])->name('hr.leave.entitlements.destroy');

    // HR: Public Holidays
    Route::get('/hr/leave/holidays',                    [LeaveController::class, 'holidays'])->name('hr.leave.holidays');
    Route::post('/hr/leave/holidays',                   [LeaveController::class, 'storeHoliday'])->name('hr.leave.holidays.store');
    Route::put('/hr/leave/holidays/{holiday}',           [LeaveController::class, 'updateHoliday'])->name('hr.leave.holidays.update');
    Route::delete('/hr/leave/holidays/{holiday}',       [LeaveController::class, 'destroyHoliday'])->name('hr.leave.holidays.destroy');

    // HR: Leave Applications
    Route::get('/hr/leave',                             [LeaveController::class, 'index'])->name('hr.leave.index');
    Route::post('/hr/leave/{application}/approve',      [LeaveController::class, 'approve'])->name('hr.leave.approve');
    Route::post('/hr/leave/{application}/reject',       [LeaveController::class, 'reject'])->name('hr.leave.reject');

    // HR: Leave Balances
    Route::get('/hr/leave/balances',                    [LeaveController::class, 'balances'])->name('hr.leave.balances');
    Route::post('/hr/leave/balances/initialize',        [LeaveController::class, 'initializeBalances'])->name('hr.leave.balances.initialize');

    // HR: Leave Calendar
    Route::get('/hr/leave/calendar',                    [LeaveController::class, 'calendar'])->name('hr.leave.calendar');

    // Self-Service: My Leave
    Route::get('/my/leave',                             [LeaveController::class, 'myLeave'])->name('user.leave.index');
    Route::post('/my/leave/apply',                      [LeaveController::class, 'apply'])->name('user.leave.apply')->middleware('throttle:uploads');
    Route::post('/my/leave/{application}/cancel',       [LeaveController::class, 'cancel'])->name('user.leave.cancel');

    // Manager: Team Leave Approval
    Route::get('/my/team-leave',                        [LeaveController::class, 'teamLeave'])->name('user.leave.team');
    Route::post('/my/team-leave/{application}/approve', [LeaveController::class, 'managerApprove'])->name('user.leave.team.approve');
    Route::post('/my/team-leave/{application}/reject',  [LeaveController::class, 'managerReject'])->name('user.leave.team.reject');

    // ══════════════════════════════════════════════════════════════════════
    // PAYROLL
    // ══════════════════════════════════════════════════════════════════════

    // HR: Payroll Items
    Route::get('/hr/payroll/items',                     [PayrollController::class, 'items'])->name('hr.payroll.items');
    Route::post('/hr/payroll/items',                    [PayrollController::class, 'storeItem'])->name('hr.payroll.items.store');
    Route::put('/hr/payroll/items/{item}',              [PayrollController::class, 'updateItem'])->name('hr.payroll.items.update');

    // HR: Employee Salary Setup
    Route::get('/hr/payroll/salaries',                  [PayrollController::class, 'salaries'])->name('hr.payroll.salaries');
    Route::post('/hr/payroll/salaries',                 [PayrollController::class, 'storeSalary'])->name('hr.payroll.salaries.store');
    Route::get('/hr/payroll/adjustments/{employee}',    [PayrollController::class, 'adjustments'])->name('hr.payroll.adjustments');

    // HR: Payroll Configuration (must be before {payRun} parameter route)
    Route::get('/hr/payroll/config',                    [PayrollController::class, 'config'])->name('hr.payroll.config');
    Route::put('/hr/payroll/config',                    [PayrollController::class, 'updateConfig'])->name('hr.payroll.config.update');

    // HR: Payslip Detail (must be before {payRun} parameter route)
    Route::get('/hr/payroll/payslip/{payslip}',         [PayrollController::class, 'viewPayslipHr'])->name('hr.payroll.payslip');

    // HR: EA Forms (Borang EA / CP.8D) — must be before {payRun} parameter route
    Route::get('/hr/payroll/ea-forms',                  [PayrollController::class, 'eaForms'])->name('hr.payroll.ea-forms.index');
    Route::post('/hr/payroll/ea-forms/generate',        [PayrollController::class, 'generateEaForms'])->name('hr.payroll.ea-forms.generate');
    Route::post('/hr/payroll/ea-forms/bulk-finalize',   [PayrollController::class, 'bulkFinalizeEaForms'])->name('hr.payroll.ea-forms.bulk-finalize');
    Route::get('/hr/payroll/ea-forms/{eaForm}',         [PayrollController::class, 'showEaForm'])->name('hr.payroll.ea-forms.show');
    Route::put('/hr/payroll/ea-forms/{eaForm}',         [PayrollController::class, 'updateEaForm'])->name('hr.payroll.ea-forms.update');
    Route::post('/hr/payroll/ea-forms/{eaForm}/finalize', [PayrollController::class, 'finalizeEaForm'])->name('hr.payroll.ea-forms.finalize');
    Route::delete('/hr/payroll/ea-forms/{eaForm}',      [PayrollController::class, 'deleteEaForm'])->name('hr.payroll.ea-forms.delete');

    // HR: Pay Runs
    Route::get('/hr/payroll',                           [PayrollController::class, 'index'])->name('hr.payroll.pay-runs.index');
    Route::get('/hr/payroll/create',                    [PayrollController::class, 'create'])->name('hr.payroll.pay-runs.create');
    Route::post('/hr/payroll',                          [PayrollController::class, 'store'])->name('hr.payroll.pay-runs.store');
    Route::get('/hr/payroll/{payRun}',                  [PayrollController::class, 'show'])->name('hr.payroll.pay-runs.show');
    Route::post('/hr/payroll/{payRun}/generate',        [PayrollController::class, 'generatePayslips'])->name('hr.payroll.pay-runs.generate');
    Route::post('/hr/payroll/{payRun}/approve',         [PayrollController::class, 'approvePayRun'])->name('hr.payroll.pay-runs.approve');
    Route::post('/hr/payroll/{payRun}/mark-paid',       [PayrollController::class, 'markPaid'])->name('hr.payroll.pay-runs.mark-paid');

    // Self-Service: My Payslips
    Route::get('/my/payslips',                          [PayrollController::class, 'myPayslips'])->name('user.payroll.index');
    Route::get('/my/payslips/{payslip}',                [PayrollController::class, 'viewPayslip'])->name('user.payroll.payslip');

    // Self-Service: My EA Form
    Route::get('/my/ea-form',                           [PayrollController::class, 'myEaForm'])->name('user.payroll.ea-form');

    // ══════════════════════════════════════════════════════════════════════
    // ATTENDANCE
    // ══════════════════════════════════════════════════════════════════════

    // HR: Work Schedules
    Route::get('/hr/attendance/schedules',              [AttendanceController::class, 'schedules'])->name('hr.attendance.schedules');
    Route::post('/hr/attendance/schedules',             [AttendanceController::class, 'storeSchedule'])->name('hr.attendance.schedules.store');

    // HR: Attendance Records
    Route::get('/hr/attendance',                        [AttendanceController::class, 'index'])->name('hr.attendance.index');

    // HR: Overtime Requests
    Route::get('/hr/attendance/overtime',               [AttendanceController::class, 'overtimeRequests'])->name('hr.attendance.overtime');
    Route::post('/hr/attendance/overtime/{overtime}/approve', [AttendanceController::class, 'approveOvertime'])->name('hr.attendance.overtime.approve');
    Route::post('/hr/attendance/overtime/{overtime}/reject',  [AttendanceController::class, 'rejectOvertime'])->name('hr.attendance.overtime.reject');

    // HR: Attendance Report
    Route::get('/hr/attendance/report',                 [AttendanceController::class, 'report'])->name('hr.attendance.report');

    // Self-Service: My Attendance
    Route::get('/my/attendance',                        [AttendanceController::class, 'myAttendance'])->name('user.attendance.index');
    Route::post('/my/attendance/clock-in',              [AttendanceController::class, 'clockIn'])->name('user.attendance.clock-in');
    Route::post('/my/attendance/clock-out',             [AttendanceController::class, 'clockOut'])->name('user.attendance.clock-out');
    Route::post('/my/attendance/overtime',              [AttendanceController::class, 'submitOvertime'])->name('user.attendance.overtime');

    // ══════════════════════════════════════════════════════════════════════
    // EXPENSE CLAIMS (eClaim)
    // ══════════════════════════════════════════════════════════════════════

    // HR: Claims Management
    Route::get('/hr/claims',                            [ExpenseClaimController::class, 'index'])->name('hr.claims.index');
    Route::get('/hr/claims/export',                     [ExpenseClaimController::class, 'export'])->name('hr.claims.export');
    Route::get('/hr/claims/categories',                 [ExpenseClaimController::class, 'categories'])->name('hr.claims.categories');
    Route::post('/hr/claims/categories',                [ExpenseClaimController::class, 'storeCategory'])->name('hr.claims.categories.store');
    Route::put('/hr/claims/categories/{category}',      [ExpenseClaimController::class, 'updateCategory'])->name('hr.claims.categories.update');
    Route::get('/hr/claims/policy',                     [ExpenseClaimController::class, 'policy'])->name('hr.claims.policy');
    Route::put('/hr/claims/policy',                     [ExpenseClaimController::class, 'updatePolicy'])->name('hr.claims.policy.update');
    Route::post('/hr/claims/bulk-approve',              [ExpenseClaimController::class, 'bulkApprove'])->middleware('throttle:30,1')->name('hr.claims.bulk-approve');
    Route::get('/hr/claims/{claim}',                    [ExpenseClaimController::class, 'show'])->name('hr.claims.show');
    Route::post('/hr/claims/{claim}/approve',           [ExpenseClaimController::class, 'hrApprove'])->name('hr.claims.approve');
    Route::post('/hr/claims/{claim}/reject',            [ExpenseClaimController::class, 'hrReject'])->name('hr.claims.reject');

    // Self-Service: My Claims
    Route::get('/my/claims',                            [ExpenseClaimController::class, 'myClaims'])->name('user.claims.index');
    Route::post('/my/claims/add-item',                  [ExpenseClaimController::class, 'addItem'])->name('user.claims.add-item')->middleware('throttle:uploads');
    Route::delete('/my/claims/{item}/remove-item',      [ExpenseClaimController::class, 'removeItem'])->name('user.claims.remove-item');
    Route::post('/my/claims/{claim}/submit',            [ExpenseClaimController::class, 'submit'])->name('user.claims.submit');
    Route::post('/my/claims/{claim}/cancel',            [ExpenseClaimController::class, 'cancel'])->name('user.claims.cancel');
    Route::post('/my/claims/detect-category',           [ExpenseClaimController::class, 'detectCategory'])->name('user.claims.detect-category');

    // Manager: Team Claims Approval
    Route::get('/my/team-claims',                       [ExpenseClaimController::class, 'teamClaims'])->name('user.claims.team');
    Route::post('/my/team-claims/{claim}/approve',      [ExpenseClaimController::class, 'managerApprove'])->name('user.claims.team.approve');
    Route::post('/my/team-claims/{claim}/reject',       [ExpenseClaimController::class, 'managerReject'])->name('user.claims.team.reject');

    // ══════════════════════════════════════════════════════════════════════
    // ACCOUNTING MODULE
    // ══════════════════════════════════════════════════════════════════════

    // Dashboard
    Route::get('/accounting',                                       [AccountingDashboardController::class, 'index'])->name('accounting.dashboard');
    Route::get('/accounting/executive-dashboard',                   [AccountingDashboardController::class, 'executiveDashboard'])->name('accounting.executive-dashboard');

    // Chart of Accounts
    Route::get('/accounting/chart-of-accounts',                     [ChartOfAccountController::class, 'index'])->name('accounting.chart-of-accounts.index');
    Route::get('/accounting/chart-of-accounts/create',              [ChartOfAccountController::class, 'create'])->name('accounting.chart-of-accounts.create');
    Route::post('/accounting/chart-of-accounts',                    [ChartOfAccountController::class, 'store'])->name('accounting.chart-of-accounts.store');
    Route::get('/accounting/chart-of-accounts/{account}/edit',      [ChartOfAccountController::class, 'edit'])->name('accounting.chart-of-accounts.edit');
    Route::put('/accounting/chart-of-accounts/{account}',           [ChartOfAccountController::class, 'update'])->name('accounting.chart-of-accounts.update');
    Route::delete('/accounting/chart-of-accounts/{account}',        [ChartOfAccountController::class, 'destroy'])->name('accounting.chart-of-accounts.destroy');

    // Journal Entries (General Ledger)
    Route::get('/accounting/journal-entries',                        [GeneralLedgerController::class, 'index'])->name('accounting.journal-entries.index');
    Route::get('/accounting/journal-entries/create',                 [GeneralLedgerController::class, 'create'])->name('accounting.journal-entries.create');
    Route::post('/accounting/journal-entries',                       [GeneralLedgerController::class, 'store'])->name('accounting.journal-entries.store');
    Route::get('/accounting/journal-entries/{entry}',                [GeneralLedgerController::class, 'show'])->name('accounting.journal-entries.show');
    Route::get('/accounting/journal-entries/{entry}/edit',           [GeneralLedgerController::class, 'edit'])->name('accounting.journal-entries.edit');
    Route::put('/accounting/journal-entries/{entry}',                [GeneralLedgerController::class, 'update'])->name('accounting.journal-entries.update');
    Route::post('/accounting/journal-entries/{entry}/post',          [GeneralLedgerController::class, 'post'])->name('accounting.journal-entries.post')->middleware('throttle:30,1');
    Route::post('/accounting/journal-entries/{entry}/void',          [GeneralLedgerController::class, 'void'])->name('accounting.journal-entries.void')->middleware('throttle:30,1');

    // Accounts Receivable — Customers
    Route::get('/accounting/customers',                              [AccountsReceivableController::class, 'customers'])->name('accounting.customers.index');
    Route::get('/accounting/customers/create',                       [AccountsReceivableController::class, 'createCustomer'])->name('accounting.customers.create');
    Route::post('/accounting/customers',                             [AccountsReceivableController::class, 'storeCustomer'])->name('accounting.customers.store');
    Route::get('/accounting/customers/{customer}/edit',              [AccountsReceivableController::class, 'editCustomer'])->name('accounting.customers.edit');
    Route::put('/accounting/customers/{customer}',                   [AccountsReceivableController::class, 'updateCustomer'])->name('accounting.customers.update');

    // Accounts Receivable — Sales Invoices
    Route::get('/accounting/invoices',                               [AccountsReceivableController::class, 'invoices'])->name('accounting.invoices.index');
    Route::get('/accounting/invoices/create',                        [AccountsReceivableController::class, 'createInvoice'])->name('accounting.invoices.create');
    Route::post('/accounting/invoices',                              [AccountsReceivableController::class, 'storeInvoice'])->name('accounting.invoices.store');
    Route::get('/accounting/invoices/{invoice}',                     [AccountsReceivableController::class, 'showInvoice'])->name('accounting.invoices.show');
    Route::get('/accounting/invoices/{invoice}/edit',                [AccountsReceivableController::class, 'editInvoice'])->name('accounting.invoices.edit');
    Route::put('/accounting/invoices/{invoice}',                     [AccountsReceivableController::class, 'updateInvoice'])->name('accounting.invoices.update');

    // Accounts Receivable — Customer Payments
    Route::get('/accounting/customer-payments/create',               [AccountsReceivableController::class, 'createPayment'])->name('accounting.customer-payments.create');
    Route::post('/accounting/customer-payments',                     [AccountsReceivableController::class, 'storePayment'])->name('accounting.customer-payments.store');

    // Accounts Receivable — Credit Notes
    Route::get('/accounting/credit-notes/create',                    [AccountsReceivableController::class, 'createCreditNote'])->name('accounting.credit-notes.create');
    Route::post('/accounting/credit-notes',                          [AccountsReceivableController::class, 'storeCreditNote'])->name('accounting.credit-notes.store');

    // Accounts Payable — Vendors
    Route::get('/accounting/vendors',                                [AccountsPayableController::class, 'vendors'])->name('accounting.vendors.index');
    Route::get('/accounting/vendors/create',                         [AccountsPayableController::class, 'createVendor'])->name('accounting.vendors.create');
    Route::post('/accounting/vendors',                               [AccountsPayableController::class, 'storeVendor'])->name('accounting.vendors.store');
    Route::get('/accounting/vendors/{vendor}/edit',                  [AccountsPayableController::class, 'editVendor'])->name('accounting.vendors.edit');
    Route::put('/accounting/vendors/{vendor}',                       [AccountsPayableController::class, 'updateVendor'])->name('accounting.vendors.update');

    // Accounts Payable — Bills
    Route::get('/accounting/bills',                                  [AccountsPayableController::class, 'bills'])->name('accounting.bills.index');
    Route::get('/accounting/bills/create',                           [AccountsPayableController::class, 'createBill'])->name('accounting.bills.create');
    Route::post('/accounting/bills',                                 [AccountsPayableController::class, 'storeBill'])->name('accounting.bills.store');
    Route::get('/accounting/bills/{bill}',                           [AccountsPayableController::class, 'showBill'])->name('accounting.bills.show');
    Route::get('/accounting/bills/{bill}/edit',                      [AccountsPayableController::class, 'editBill'])->name('accounting.bills.edit');
    Route::put('/accounting/bills/{bill}',                           [AccountsPayableController::class, 'updateBill'])->name('accounting.bills.update');
    Route::post('/accounting/bills/{bill}/approve',                  [AccountsPayableController::class, 'approveBill'])->name('accounting.bills.approve')->middleware('throttle:30,1');

    // Accounts Payable — Vendor Payments
    Route::get('/accounting/vendor-payments',                        [AccountsPayableController::class, 'payments'])->name('accounting.vendor-payments.index');
    Route::get('/accounting/vendor-payments/create',                 [AccountsPayableController::class, 'createPayment'])->name('accounting.vendor-payments.create');
    Route::post('/accounting/vendor-payments',                       [AccountsPayableController::class, 'storePayment'])->name('accounting.vendor-payments.store');

    // Accounts Payable — Purchase Orders
    Route::get('/accounting/purchase-orders',                        [AccountsPayableController::class, 'purchaseOrders'])->name('accounting.purchase-orders.index');
    Route::get('/accounting/purchase-orders/create',                 [AccountsPayableController::class, 'createPurchaseOrder'])->name('accounting.purchase-orders.create');
    Route::post('/accounting/purchase-orders',                       [AccountsPayableController::class, 'storePurchaseOrder'])->name('accounting.purchase-orders.store');

    // Banking
    Route::get('/accounting/banking',                                [BankingController::class, 'index'])->name('accounting.banking.index');
    Route::get('/accounting/banking/create',                         [BankingController::class, 'create'])->name('accounting.banking.create');
    Route::post('/accounting/banking',                               [BankingController::class, 'store'])->name('accounting.banking.store');
    Route::get('/accounting/banking/{account}/edit',                 [BankingController::class, 'edit'])->name('accounting.banking.edit');
    Route::put('/accounting/banking/{account}',                      [BankingController::class, 'update'])->name('accounting.banking.update');
    Route::get('/accounting/banking/{account}/transactions',         [BankingController::class, 'transactions'])->name('accounting.banking.transactions');
    Route::get('/accounting/banking/{account}/reconcile',            [BankingController::class, 'reconcile'])->name('accounting.banking.reconcile');
    Route::post('/accounting/banking/{account}/reconcile',           [BankingController::class, 'storeReconciliation'])->name('accounting.banking.store-reconciliation');
    Route::get('/accounting/bank-transfers',                         [BankingController::class, 'transfers'])->name('accounting.bank-transfers.index');
    Route::get('/accounting/bank-transfers/create',                  [BankingController::class, 'createTransfer'])->name('accounting.bank-transfers.create');
    Route::post('/accounting/bank-transfers',                        [BankingController::class, 'storeTransfer'])->name('accounting.bank-transfers.store');

    // Tax
    Route::get('/accounting/tax',                                    [TaxController::class, 'index'])->name('accounting.tax.index');
    Route::get('/accounting/tax/create',                             [TaxController::class, 'create'])->name('accounting.tax.create');
    Route::post('/accounting/tax',                                   [TaxController::class, 'store'])->name('accounting.tax.store');
    Route::get('/accounting/tax/{code}/edit',                        [TaxController::class, 'edit'])->name('accounting.tax.edit');
    Route::put('/accounting/tax/{code}',                             [TaxController::class, 'update'])->name('accounting.tax.update');
    Route::get('/accounting/tax-returns',                            [TaxController::class, 'returns'])->name('accounting.tax-returns.index');
    Route::get('/accounting/tax-returns/create',                     [TaxController::class, 'createReturn'])->name('accounting.tax-returns.create');
    Route::post('/accounting/tax-returns',                           [TaxController::class, 'storeReturn'])->name('accounting.tax-returns.store');
    Route::get('/accounting/tax-returns/{return}',                   [TaxController::class, 'showReturn'])->name('accounting.tax-returns.show');
    Route::post('/accounting/tax-returns/{return}/file',             [TaxController::class, 'fileReturn'])->name('accounting.tax-returns.file')->middleware('throttle:10,1');

    // Fixed Assets
    Route::get('/accounting/fixed-assets',                           [FixedAssetController::class, 'index'])->name('accounting.fixed-assets.index');
    Route::get('/accounting/fixed-assets/create',                    [FixedAssetController::class, 'create'])->name('accounting.fixed-assets.create');
    Route::post('/accounting/fixed-assets',                          [FixedAssetController::class, 'store'])->name('accounting.fixed-assets.store');
    Route::get('/accounting/fixed-assets/{asset}/edit',              [FixedAssetController::class, 'edit'])->name('accounting.fixed-assets.edit');
    Route::put('/accounting/fixed-assets/{asset}',                   [FixedAssetController::class, 'update'])->name('accounting.fixed-assets.update');
    Route::get('/accounting/fixed-assets/{asset}/depreciation',      [FixedAssetController::class, 'depreciationSchedule'])->name('accounting.fixed-assets.depreciation');
    Route::post('/accounting/fixed-assets/run-depreciation',         [FixedAssetController::class, 'runDepreciation'])->name('accounting.fixed-assets.run-depreciation')->middleware('throttle:5,1');
    Route::get('/accounting/asset-categories',                       [FixedAssetController::class, 'categories'])->name('accounting.asset-categories.index');
    Route::post('/accounting/asset-categories',                      [FixedAssetController::class, 'storeCategory'])->name('accounting.asset-categories.store');
    Route::get('/accounting/asset-categories/{category}/edit',       [FixedAssetController::class, 'editCategory'])->name('accounting.asset-categories.edit');
    Route::put('/accounting/asset-categories/{category}',            [FixedAssetController::class, 'updateCategory'])->name('accounting.asset-categories.update');

    // Budgets
    Route::get('/accounting/budgets',                                [BudgetController::class, 'index'])->name('accounting.budgets.index');
    Route::get('/accounting/budgets/create',                         [BudgetController::class, 'create'])->name('accounting.budgets.create');
    Route::post('/accounting/budgets',                               [BudgetController::class, 'store'])->name('accounting.budgets.store');
    Route::get('/accounting/budgets/{budget}',                       [BudgetController::class, 'show'])->name('accounting.budgets.show');
    Route::get('/accounting/budgets/{budget}/edit',                  [BudgetController::class, 'edit'])->name('accounting.budgets.edit');
    Route::put('/accounting/budgets/{budget}',                       [BudgetController::class, 'update'])->name('accounting.budgets.update');
    Route::post('/accounting/budgets/{budget}/approve',              [BudgetController::class, 'approve'])->name('accounting.budgets.approve')->middleware('throttle:30,1');

    // Financial Reports
    Route::get('/accounting/reports/trial-balance',                  [FinancialReportController::class, 'trialBalance'])->name('accounting.reports.trial-balance');
    Route::get('/accounting/reports/profit-loss',                    [FinancialReportController::class, 'profitAndLoss'])->name('accounting.reports.profit-loss');
    Route::get('/accounting/reports/balance-sheet',                  [FinancialReportController::class, 'balanceSheet'])->name('accounting.reports.balance-sheet');
    Route::get('/accounting/reports/cash-flow',                      [FinancialReportController::class, 'cashFlow'])->name('accounting.reports.cash-flow');
    Route::get('/accounting/reports/general-ledger',                 [FinancialReportController::class, 'generalLedger'])->name('accounting.reports.general-ledger');
    Route::get('/accounting/reports/aged-receivables',               [FinancialReportController::class, 'agedReceivables'])->name('accounting.reports.aged-receivables');
    Route::get('/accounting/reports/aged-payables',                  [FinancialReportController::class, 'agedPayables'])->name('accounting.reports.aged-payables');
    Route::get('/accounting/reports/tax-summary',                    [FinancialReportController::class, 'taxSummary'])->name('accounting.reports.tax-summary');

    // AI Accounting
    Route::get('/accounting/ai/invoice-scanner',                     [AiAccountingController::class, 'invoiceScanner'])->name('accounting.ai.invoice-scanner');
    Route::post('/accounting/ai/upload-invoice',                     [AiAccountingController::class, 'uploadInvoice'])->name('accounting.ai.upload-invoice')->middleware('throttle:10,1');
    Route::get('/accounting/ai/review-scan/{scan}',                  [AiAccountingController::class, 'reviewScan'])->name('accounting.ai.review-scan');
    Route::post('/accounting/ai/confirm-scan/{scan}',                [AiAccountingController::class, 'confirmScan'])->name('accounting.ai.confirm-scan')->middleware('throttle:10,1');
    Route::get('/accounting/ai/chatbot',                             [AiAccountingController::class, 'chatbot'])->name('accounting.ai.chatbot');
    Route::post('/accounting/ai/chat-new-session',                   [AiAccountingController::class, 'newChatSession'])->name('accounting.ai.chat-new-session')->middleware('throttle:10,1');
    Route::post('/accounting/ai/chat-send',                          [AiAccountingController::class, 'chatSend'])->name('accounting.ai.chat-send')->middleware('throttle:30,1');

    // Accounting Settings
    Route::get('/accounting/settings',                               [AccountingSettingController::class, 'index'])->name('accounting.settings');
    Route::put('/accounting/settings',                               [AccountingSettingController::class, 'update'])->name('accounting.settings.update')->middleware('throttle:10,1');
    Route::post('/accounting/settings/fiscal-year',                  [AccountingSettingController::class, 'storeFiscalYear'])->name('accounting.settings.store-fiscal-year');
    Route::post('/accounting/settings/currency',                     [AccountingSettingController::class, 'storeCurrency'])->name('accounting.settings.store-currency');
});