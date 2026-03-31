<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItTaskController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\AarfController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\OffboardingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\OnboardingInviteController;
use Illuminate\Support\Facades\Route;

// Root redirect
Route::get('/', fn() => redirect()->route('login'));

// ── Guest ──────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login',                  [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',                 [AuthController::class, 'login']);
    Route::get('/register',               [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register/check-email',  [AuthController::class, 'checkEmail'])->name('register.checkEmail');
    Route::get('/register/set-password',  [AuthController::class, 'showSetPassword'])->name('register.setPassword');
    Route::post('/register',              [AuthController::class, 'register']);
    Route::get('/forgot-password',        [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password',       [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password',        [AuthController::class, 'resetPassword'])->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// ── Public AARF token link (for employee acknowledgement via email) ─────────
Route::get('/aarf/{token}',              [AarfController::class, 'viewAarf'])->name('aarf.view');
Route::post('/aarf/{token}/acknowledge', [AarfController::class, 'acknowledge'])->name('aarf.acknowledge');

// Public onboarding invite routes (no auth required)
Route::get('/onboarding-invite/success',          [OnboardingInviteController::class, 'success'])->name('onboarding.invite.success');
Route::get('/onboarding-invite/{token}',          [OnboardingInviteController::class, 'showForm'])->name('onboarding.invite.form');
Route::post('/onboarding-invite/{token}/verify',  [OnboardingInviteController::class, 'verifyEmail'])->name('onboarding.invite.verify');
Route::post('/onboarding-invite/{token}/consent', [OnboardingInviteController::class, 'acceptConsent'])->name('onboarding.invite.consent');
Route::post('/onboarding-invite/{token}/submit',  [OnboardingInviteController::class, 'submit'])->name('onboarding.invite.submit');

// ── Authenticated ──────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {

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
    Route::put('/profile/biodata',              [ProfileController::class, 'updateBiodata'])->name('profile.biodata.update');
    Route::put('/profile/work',                 [ProfileController::class, 'updateWork'])->name('profile.work.update');
    Route::post('/profile/education',           [ProfileController::class, 'updateEducation'])->name('profile.education.update');
    Route::post('/profile/spouse',              [ProfileController::class, 'updateSpouse'])->name('profile.spouse.update');
    Route::delete('/profile/spouse/{spouse}',   [ProfileController::class, 'deleteSpouse'])->name('profile.spouse.delete');
    Route::post('/profile/emergency',           [ProfileController::class, 'updateEmergency'])->name('profile.emergency.update');
    Route::post('/profile/children',            [ProfileController::class, 'updateChildren'])->name('profile.children.update');
    Route::post('/profile/aarf/upload',         [ProfileController::class, 'uploadAarf'])->name('profile.aarf.upload');
    Route::post('/profile/consent',             [ProfileController::class, 'submitConsent'])->name('profile.consent');
    Route::get('/profile/re-consent',           [ProfileController::class, 'showReConsent'])->name('profile.re-consent.show');
    Route::post('/profile/re-consent',          [ProfileController::class, 'storeReConsent'])->name('profile.re-consent.store');
    Route::get('/profile/download/{type}',      [ProfileController::class, 'download'])->name('profile.download');

    // Account (all users)
    Route::get('/account',           [AccountController::class, 'show'])->name('account');
    Route::post('/account/change-password', [AccountController::class, 'changePassword'])->name('account.change-password');
    Route::post('/account/language',        [AccountController::class, 'setLanguage'])->name('account.language');
    Route::post('/account/avatar',          [AccountController::class, 'uploadProfilePicture'])->name('account.avatar');

    // Onboarding (HR)
    Route::get('/onboarding',                   [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::post('/onboarding',                  [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::post('/onboarding/send-invite',      [OnboardingInviteController::class, 'send'])->name('onboarding.invite.send');
    Route::get('/onboarding/export/csv',        [OnboardingController::class, 'export'])->name('onboarding.export');
    Route::get('/onboarding/manager-email',     [OnboardingController::class, 'getManagerEmail'])->name('onboarding.managerEmail');
    Route::get('/onboarding/{onboarding}',                  [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::get('/onboarding/{onboarding}/edit',             [OnboardingController::class, 'edit'])->name('onboarding.edit');
    Route::put('/onboarding/{onboarding}',                  [OnboardingController::class, 'update'])->name('onboarding.update');
    Route::get('/onboarding/{onboarding}/re-consent',       [OnboardingController::class, 'showReConsent'])->name('onboarding.re-consent.show');
    Route::post('/onboarding/{onboarding}/re-consent',      [OnboardingController::class, 'storeReConsent'])->name('onboarding.re-consent.store');

    // HR: Employee Listing
    Route::get('/superadmin/roles',                [EmployeeController::class, 'roleManagement'])->name('superadmin.roles.index');
    Route::put('/superadmin/roles/{employee}',     [EmployeeController::class, 'roleUpdate'])->name('superadmin.roles.update');

    Route::get('/superadmin/companies',            [CompanyController::class, 'index'])->name('superadmin.companies.index');
    Route::post('/superadmin/companies',           [CompanyController::class, 'store'])->name('superadmin.companies.store');
    Route::put('/superadmin/companies/{company}',  [CompanyController::class, 'update'])->name('superadmin.companies.update');
    Route::delete('/superadmin/companies/{company}',[CompanyController::class, 'destroy'])->name('superadmin.companies.destroy');

    Route::get('/hr/employees',                    [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/hr/employees/export',             [EmployeeController::class, 'export'])->name('employees.export');
    Route::get('/hr/employees/import-template',    [EmployeeController::class, 'importTemplate'])->name('employees.import.template');
    Route::post('/hr/employees/import',            [EmployeeController::class, 'importCsv'])->name('employees.import');
    Route::get('/hr/employees/{employee}',     [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/hr/employees/{employee}/edit',[EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/hr/employees/{employee}',     [EmployeeController::class, 'update'])->name('employees.update');
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
    Route::post('/hr/employees/{employee}/contracts',            [EmployeeController::class, 'contractUpload'])->name('employees.contracts.upload');
    Route::get('/hr/employees/{employee}/contracts/{contract}/download', [EmployeeController::class, 'contractDownload'])->name('employees.contracts.download');
    Route::delete('/hr/employees/{employee}/contracts/{contract}',       [EmployeeController::class, 'contractDelete'])->name('employees.contracts.delete');

    // Per-employee handbook & orientation (HR Manager / Executive)
Route::post('/hr/employees/{employee}/handbook',    [EmployeeController::class, 'handbookUpload'])->name('employees.handbook.upload');
Route::delete('/hr/employees/{employee}/handbook',  [EmployeeController::class, 'handbookDelete'])->name('employees.handbook.delete');
Route::post('/hr/employees/{employee}/orientation', [EmployeeController::class, 'orientationUpload'])->name('employees.orientation.upload');
Route::delete('/hr/employees/{employee}/orientation',[EmployeeController::class, 'orientationDelete'])->name('employees.orientation.delete');
    // HR: Offboarding
    Route::get('/hr/offboarding',                              [OffboardingController::class, 'hrIndex'])->name('hr.offboarding.index');
    Route::get('/hr/offboarding/{offboarding}',                [OffboardingController::class, 'hrShow'])->name('hr.offboarding.show');
    Route::get('/hr/offboarding/{offboarding}/edit',           [OffboardingController::class, 'hrEdit'])->name('hr.offboarding.edit');
    Route::put('/hr/offboarding/{offboarding}',                [OffboardingController::class, 'hrUpdate'])->name('hr.offboarding.update');

    // IT: Offboarding
    Route::get('/it/offboarding',                              [OffboardingController::class, 'itIndex'])->name('it.offboarding.index');
    Route::get('/it/offboarding/{offboarding}',                [OffboardingController::class, 'itShow'])->name('it.offboarding.show');
    Route::post('/it/offboarding/{offboarding}/assign-pic',    [ItTaskController::class, 'assignOffboardingPic'])->name('it.offboarding.assign.pic');

    // Shared: status update (HR + IT)
    Route::post('/offboarding/{offboarding}/status',           [OffboardingController::class, 'updateStatus'])->name('offboarding.status');

    // Keep legacy route pointing to HR index for any old links
    Route::get('/offboarding', fn() => redirect()->route('hr.offboarding.index'))->name('offboarding.index');


    // HR AARF view
    Route::get('/hr/aarf/{aarf}', [AarfController::class, 'hrView'])->name('hr.aarf.view');

    // IT AARF management routes removed — IT AARF pages decommissioned.
    // Employee acknowledgement (aarf.view / aarf.acknowledge) and HR view (hr.aarf.view) are unaffected.

    // Assets (IT)
    Route::get('/assets',              [AssetController::class, 'index'])->name('assets.index');
    Route::post('/assets',             [AssetController::class, 'store'])->name('assets.store');
    Route::get('/assets/export/csv',          [AssetController::class, 'export'])->name('assets.export');
    Route::get('/assets/import-template',     [AssetController::class, 'importTemplate'])->name('assets.import.template');
    Route::post('/assets/import',             [AssetController::class, 'importCsv'])->name('assets.import');
    Route::get('/assets/disposed',                 [AssetController::class, 'disposed'])->name('assets.disposed');
    Route::get('/assets/disposed/{asset}',         [AssetController::class, 'disposedShow'])->name('assets.disposed.show');
    Route::get('/assets/{asset}',             [AssetController::class, 'show'])->name('assets.show');
    Route::get('/assets/{asset}/edit',        [AssetController::class, 'edit'])->name('assets.edit');
    Route::put('/assets/{asset}',             [AssetController::class, 'update'])->name('assets.update');
    Route::post('/assets/{asset}/reassign',   [AssetController::class, 'reassign'])->name('assets.reassign');
    Route::post('/assets/{asset}/return',     [AssetController::class, 'returnAsset'])->name('assets.return');
    Route::post('/assets/{asset}/release',    [AssetController::class, 'releaseAsset'])->name('assets.release');
});