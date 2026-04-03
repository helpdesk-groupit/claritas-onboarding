<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeEditLog;
use App\Models\Offboarding;
use App\Models\Company;
use App\Mail\AarfAcknowledgementMail;
use App\Mail\EmployeeConsentRequestMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Response;

class EmployeeController extends Controller
{
    private function authorizeHr(): void
    {
        $u = Auth::user();
        if (!$u->isHr() && !$u->isSuperadmin() && !$u->isSystemAdmin()) abort(403);
    }

    private function authorizeItOrHr(): void
    {
        $u = Auth::user();
        if (!$u->isHr() && !$u->isIt() && !$u->isSuperadmin() && !$u->isSystemAdmin()) abort(403);
    }

    // ── Superadmin: Role Management ───────────────────────────────────────
    public function roleManagement(Request $request)
    {
        if (!Auth::user()->isSuperadmin() && !Auth::user()->isItManager()) abort(403);

        $query = Employee::whereNull('active_until');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('full_name', 'like', "%{$s}%")
                  ->orWhere('preferred_name', 'like', "%{$s}%")
                  ->orWhere('company_email', 'like', "%{$s}%")
            );
        }
        if ($request->filled('company')) {
            $query->where('company', 'like', "%{$request->company}%");
        }

        $employees = $query->orderBy('full_name')->paginate(20)->withQueryString();
        $companies = Employee::whereNull('active_until')->distinct()->pluck('company')->filter()->sort()->values();

        return view('superadmin.role-management', compact('employees', 'companies'));
    }

    public function getPermissions(Employee $employee)
    {
        if (!Auth::user()->isSuperadmin()) abort(403);

        $user = $employee->user;
        if (!$user) {
            return response()->json(['permissions' => [], 'has_account' => false]);
        }

        $perms = $user->permissions()->pluck('access_level', 'resource');
        return response()->json(['permissions' => $perms, 'has_account' => true]);
    }

    public function updatePermissions(Request $request, Employee $employee)
    {
        if (!Auth::user()->isSuperadmin()) abort(403);

        $user = $employee->user;
        if (!$user) {
            return back()->with('error', ($employee->full_name ?? 'Employee') . ' has no user account linked.');
        }

        $submitted   = $request->input('permissions', []);
        $validKeys   = \App\Models\UserPermission::validResources();
        $validLevels = ['full', 'view', 'edit', 'none'];

        foreach ($submitted as $resource => $level) {
            if (!in_array($resource, $validKeys)) continue;

            if ($level === '' || $level === null) {
                // Empty = remove custom override (fall back to role)
                \App\Models\UserPermission::where('user_id', $user->id)
                    ->where('resource', $resource)->delete();
            } elseif (in_array($level, $validLevels)) {
                \App\Models\UserPermission::updateOrCreate(
                    ['user_id' => $user->id, 'resource' => $resource],
                    ['access_level' => $level]
                );
            }
        }

        // Remove any saved rows for resources not present in the submission
        \App\Models\UserPermission::where('user_id', $user->id)
            ->whereNotIn('resource', array_keys($submitted))->delete();

        return back()->with('success', ($employee->full_name ?? 'Employee') . '\'s access permissions updated.');
    }

    public function roleUpdate(Request $request, Employee $employee)
    {
        if (!Auth::user()->isSuperadmin() && !Auth::user()->isItManager()) abort(403);

        $request->validate([
            'work_role' => 'required|in:manager,senior_executive,executive_associate,director_hod,hr_manager,hr_executive,hr_intern,it_manager,it_executive,it_intern,superadmin,system_admin,others',
        ]);

        $employee->update(['work_role' => $request->work_role]);

        // Sync users.role so permissions take effect immediately.
        // users.role is a restricted ENUM; map org-level roles (others, manager, etc.) to 'employee'.
        if ($employee->user_id) {
            $systemRoles = ['hr_manager','hr_executive','hr_intern','it_manager','it_executive','it_intern','superadmin','system_admin','employee'];
            $userRole = in_array($request->work_role, $systemRoles) ? $request->work_role : 'employee';
            \App\Models\User::where('id', $employee->user_id)
                ->update(['role' => $userRole]);
        }

        return back()->with('success', $employee->full_name . '\'s role updated to ' . ucfirst(str_replace('_', ' ', $request->work_role)) . '.');
    }

    // ── HR: Employee Listing ──────────────────────────────────────────────
    public function index(Request $request)
    {
        $this->authorizeItOrHr();

        $query = Employee::whereNull('active_until'); // active only

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('full_name','like',"%{$s}%")
                  ->orWhere('preferred_name','like',"%{$s}%")
                  ->orWhere('company_email','like',"%{$s}%")
                  ->orWhere('designation','like',"%{$s}%")
            );
        }
        if ($request->filled('company'))     $query->where('company','like',"%{$request->company}%");
        if ($request->filled('department'))  $query->where('department','like',"%{$request->department}%");
        if ($request->filled('designation')) $query->where('designation','like',"%{$request->designation}%");
        if ($request->filled('work_role'))   $query->where('work_role','like',"%{$request->work_role}%");

        $employees = $query->latest()->paginate(10)->withQueryString();

        // Filter options
        $companies    = Employee::whereNull('active_until')->distinct()->pluck('company')->filter()->sort()->values();
        $departments  = Employee::whereNull('active_until')->distinct()->pluck('department')->filter()->sort()->values();
        $designations = Employee::whereNull('active_until')->distinct()->pluck('designation')->filter()->sort()->values();
        $workRoles    = Employee::whereNull('active_until')->distinct()->pluck('work_role')->filter()->sort()->values();

        // Stats for summary cards (always based on ALL active employees, ignoring current filters)
        $allActive = Employee::whereNull('active_until');

        // Card 1: count per registered company — exact full name match
        $registeredCompanies = \App\Models\Company::orderBy('name')->get(['name']);
        $rawByCompany = (clone $allActive)->selectRaw('company, count(*) as total')
            ->whereNotNull('company')->groupBy('company')->get()
            ->keyBy('company');
        $statsByCompany = $registeredCompanies->map(fn($c) => (object)[
            'company' => $c->name,
            'total'   => $rawByCompany->get($c->name)?->total ?? 0,
        ]);

        $statsByDept = (clone $allActive)->selectRaw('department, company, count(*) as total')
            ->whereNotNull('department')->groupBy('department','company')->orderByDesc('total')->get();

        $statsByType = (clone $allActive)->selectRaw('employment_type, company, count(*) as total')
            ->whereNotNull('employment_type')->groupBy('employment_type','company')->orderByDesc('total')->get();

        return view('hr.employees.index', compact(
            'employees','companies','departments','designations','workRoles',
            'statsByCompany','statsByDept','statsByType','registeredCompanies'
        ));
    }

    // ── HR: Export CSV ────────────────────────────────────────────────────
    public function export(Request $request)
    {
        $u = Auth::user();
        if (!$u->isSuperadmin() && !$u->isHrManager() && !$u->isHrExecutive() && !$u->isItManager() && !$u->isItExecutive()) {
            abort(403);
        }

        $query = Employee::whereNull('active_until');
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('full_name','like',"%{$s}%")
                  ->orWhere('preferred_name','like',"%{$s}%")
                  ->orWhere('company_email','like',"%{$s}%")
                  ->orWhere('designation','like',"%{$s}%")
            );
        }
        if ($request->filled('company'))     $query->where('company','like',"%{$request->company}%");
        if ($request->filled('department'))  $query->where('department','like',"%{$request->department}%");
        if ($request->filled('designation')) $query->where('designation','like',"%{$request->designation}%");
        if ($request->filled('work_role'))   $query->where('work_role','like',"%{$request->work_role}%");

        $employees = $query->get();
        $headers   = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="employees_' . date('Ymd') . '.csv"',
        ];

        $callback = function () use ($employees) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Full Name','Designation','Company','Department','Work Role',
                              'Company Email','Start Date','Employment Type']);
            foreach ($employees as $e) {
                fputcsv($handle, [
                    $e->full_name, $e->designation, $e->company, $e->department, $e->work_role,
                    $e->company_email, $e->start_date?->format('d/m/Y'), $e->employment_type,
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── HR Manager: Download CSV import template ──────────────────────────
    public function importTemplate()
    {
        if (!in_array(Auth::user()->role, ['hr_manager', 'superadmin'])) abort(403);

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="employee_import_template.csv"',
        ];

        $callback = function () {
            $handle = fopen('php://output', 'w');

            // Column headers
            fputcsv($handle, [
                'full_name',
                'preferred_name',
                'personal_contact_number',
                'employment_type',
                'designation',
                'department',
                'reporting_manager',
                'start_date',
                'official_document_id',
                'date_of_birth',
                'sex',
                'marital_status',
                'religion',
                'race',
                'residential_address',
                'personal_email',
                'bank_account_number',
                'company',
                'office_location',
                'company_email',
                'google_id',
                'work_role',
                'exit_date',
            ]);

            // Example row — required fields filled, optional fields can be left blank
            fputcsv($handle, [
                'Ahmad bin Abdullah',        // full_name               * REQUIRED
                'Ahmad',                     // preferred_name          * REQUIRED
                '0123456789',                // personal_contact_number * REQUIRED
                'permanent',                 // employment_type         * REQUIRED: permanent / intern / contract (default if blank: contract)
                'Marketing Executive',       // designation             * REQUIRED (position/job title)
                'Marketing',                 // department              * REQUIRED
                'Aisha Rahman',              // reporting_manager       * REQUIRED
                '01-03-2026',                // start_date              * REQUIRED (DD-MM-YYYY)
                '990101-14-1234',            // official_document_id    (optional)
                '01-01-1999',                // date_of_birth           (optional, DD-MM-YYYY)
                'male',                      // sex                     (optional: male / female)
                'single',                    // marital_status          (optional: single / married / divorced / widowed)
                'Islam',                     // religion                (optional)
                'Malay',                     // race                    (optional)
                '123, Jalan Ampang, KL',     // residential_address     (optional)
                'ahmad@gmail.com',           // personal_email          (optional)
                '1234567890',                // bank_account_number     (optional)
                'Claritas Asia Sdn. Bhd.',   // company                 (optional)
                'Kuala Lumpur',              // office_location         (optional)
                'ahmad@claritas.asia',       // company_email           (optional)
                'ahmad@claritas.asia',       // google_id               (optional, usually same as company_email)
                'executive_associate',       // work_role               (optional: manager / senior_executive / executive_associate / director_hod / hr_manager / hr_executive / hr_intern / it_manager / it_executive / it_intern / others)
                '',                          // exit_date               (optional, YYYY-MM-DD or leave blank)
            ]);

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── HR Manager: Import employees from CSV ─────────────────────────────
    public function importCsv(Request $request)
    {
        if (!in_array(Auth::user()->role, ['hr_manager', 'superadmin'])) abort(403);

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file   = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        $headers    = null;
        $imported   = 0;
        $skipped    = 0;
        $errors     = [];
        $rowNumber  = 1;

        $requiredColumns = [
            'full_name', 'preferred_name', 'personal_contact_number',
            'employment_type', 'designation', 'department',
            'reporting_manager', 'start_date',
        ];

        while (($row = fgetcsv($handle)) !== false) {
            // First row = headers
            if ($headers === null) {
                $headers = array_map('trim', $row);
                $rowNumber++;
                continue;
            }

            $rowNumber++;

            // Skip completely empty rows
            if (empty(array_filter($row))) continue;

            $data = array_combine($headers, array_pad($row, count($headers), ''));

            // Validate required fields
            $missing = [];
            foreach ($requiredColumns as $col) {
                if (empty(trim($data[$col] ?? ''))) $missing[] = $col;
            }
            if ($missing) {
                $errors[] = "Row {$rowNumber}: Missing required fields: " . implode(', ', $missing);
                $skipped++;
                continue;
            }

            // Parse dates — accepts DD-MM-YYYY, D-M-YYYY, DD/MM/YYYY, D/M/YYYY, YYYY-MM-DD
            $parseDate = function ($val) {
                if (empty(trim($val))) return null;
                $val = trim($val);
                // YYYY-MM-DD (ISO)
                if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $val)) return $val;
                // DD-MM-YYYY or D-M-YYYY (dash separator)
                if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $val, $m)) {
                    return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                }
                // DD/MM/YYYY or D/M/YYYY (slash separator)
                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
                    return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                }
                return null;
            };

            $startDate = $parseDate($data['start_date'] ?? '');
            if (!$startDate) {
                $errors[] = "Row {$rowNumber}: Invalid start_date '{$data['start_date']}'. Use DD-MM-YYYY (e.g. 15-03-2026).";
                $skipped++;
                continue;
            }

            $companyEmail = trim($data['company_email'] ?? '');
            $googleId     = trim($data['google_id']     ?? '') ?: $companyEmail;

            // Normalize sex — accept m/f/male/female/M/F
            $rawSex = strtolower(trim($data['sex'] ?? ''));
            $sex = match($rawSex) {
                'm', 'male'   => 'male',
                'f', 'female' => 'female',
                default       => null,
            };

            // Normalize marital_status
            $rawMarital = strtolower(trim($data['marital_status'] ?? ''));
            $maritalStatus = in_array($rawMarital, ['single','married','divorced','widowed'])
                ? $rawMarital : null;

            // Normalize employment_type
            $rawEmpType = strtolower(trim($data['employment_type'] ?? ''));
            $employmentType = in_array($rawEmpType, ['permanent','intern','contract'])
                ? $rawEmpType : 'contract';

            Employee::create([
                'active_from'             => $startDate,
                'full_name'               => trim($data['full_name']),
                'preferred_name'          => trim($data['preferred_name']          ?? '') ?: null,
                'official_document_id'    => trim($data['official_document_id']    ?? '') ?: null,
                'date_of_birth'           => $parseDate($data['date_of_birth']     ?? ''),
                'sex'                     => $sex,
                'marital_status'          => $maritalStatus,
                'religion'                => trim($data['religion']                ?? '') ?: null,
                'race'                    => trim($data['race']                    ?? '') ?: null,
                'residential_address'     => trim($data['residential_address']     ?? '') ?: null,
                'personal_contact_number' => trim($data['personal_contact_number'] ?? '') ?: null,
                'personal_email'          => trim($data['personal_email']          ?? '') ?: null,
                'bank_account_number'     => trim($data['bank_account_number']     ?? '') ?: null,
                'designation'             => trim($data['designation']),
                'department'              => trim($data['department']              ?? '') ?: null,
                'company'                 => trim($data['company']                 ?? '') ?: null,
                'office_location'         => trim($data['office_location']         ?? '') ?: null,
                'reporting_manager'       => trim($data['reporting_manager']       ?? '') ?: null,
                'company_email'           => $companyEmail ?: null,
                'google_id'               => $googleId ?: null,
                'employment_type'         => $employmentType,
                'work_role'               => trim($data['work_role']               ?? '') ?: null,
                'start_date'              => $startDate,
                'exit_date'               => $parseDate($data['exit_date']         ?? ''),
            ]);

            $imported++;
        }

        fclose($handle);

        $message = "{$imported} employee(s) imported successfully.";
        if ($skipped) $message .= " {$skipped} row(s) skipped.";

        return back()
            ->with('success', $message)
            ->with('import_errors', $errors);
    }

    // ── HR + IT: View employee detail ─────────────────────────────────────
    public function show(Employee $employee)
    {
        $this->authorizeItOrHr();
        $employee->load([
            'user',
            'onboarding.aarf',
            'onboarding.personalDetail',
            'onboarding.assetProvisioning',
            'onboarding.assetAssignments.asset',
            'contracts.uploader',
            'educationHistories',
            'spouseDetails',
            'emergencyContacts',
            'childRegistration',
            'editLogs',
        ]);

        // Load assets directly assigned to this employee via asset_inventories.assigned_employee_id
        // Also include assets assigned via onboarding (auto-assigned) where assigned_employee_id may be null
        $directAssets = \App\Models\AssetInventory::where(function ($q) use ($employee) {
            $q->where('assigned_employee_id', $employee->id);
            // Also catch auto-assigned assets linked via onboarding asset_assignments
            if ($employee->onboarding_id) {
                $onboardingAssetIds = \App\Models\AssetAssignment::where('onboarding_id', $employee->onboarding_id)
                    ->where('status', 'assigned')
                    ->pluck('asset_inventory_id');
                if ($onboardingAssetIds->isNotEmpty()) {
                    $q->orWhereIn('id', $onboardingAssetIds);
                }
            }
        })
            ->whereIn('status', ['assigned', 'unavailable'])
            ->orderBy('asset_type')
            ->get();

        // Available assets for the assign modal (IT manager/executive only)
        $availableAssets = \App\Models\AssetInventory::where('status', 'available')
            ->orderBy('asset_type')->orderBy('brand')
            ->get();

        // Resolve AARF: onboarding-linked OR direct employee-linked
        $aarf = $employee->onboarding?->aarf
             ?? \App\Models\Aarf::where('employee_id', $employee->id)->first();

        return view('hr.employees.show', compact('employee', 'directAssets', 'availableAssets', 'aarf'));
    }

    // ── Upload / change employee profile photo (HR Manager / SuperAdmin) ────
    public function uploadAvatar(Request $request, Employee $employee)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager', 'superadmin', 'system_admin'])) abort(403);

        $request->validate(['avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048']);

        $user = $employee->user;
        if (!$user) {
            return back()->with('error', 'This employee does not have a linked user account yet.');
        }

        if ($user->profile_picture) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->profile_picture);
        }

        $path = $request->file('avatar')->store('profile-pictures', 'public');
        $user->update(['profile_picture' => $path]);

        return back()->with('success', 'Profile photo updated successfully.');
    }

    // ── IT Manager / Executive: Assign an additional asset to employee ─────
    public function assignAsset(Request $request, Employee $employee)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['it_manager', 'it_executive', 'superadmin'])) abort(403);

        $request->validate([
            'asset_id'      => 'required|exists:asset_inventories,id',
            'assigned_date' => 'required|date',
        ]);

        $asset     = \App\Models\AssetInventory::findOrFail($request->asset_id);
        $actorName = $user->name ?? $user->work_email ?? 'IT Team';
        $empName   = $employee->full_name ?? "Employee #{$employee->id}";

        if ($asset->status !== 'available') {
            return back()->with('error', "Asset [{$asset->asset_tag}] is not available for assignment.");
        }

        \App\Models\AssetAssignment::create([
            'onboarding_id'      => $employee->onboarding_id,
            'employee_id'        => $employee->id,
            'asset_inventory_id' => $asset->id,
            'assigned_date'      => $request->assigned_date,
            'status'             => 'assigned',
        ]);

        $asset->update([
            'status'               => 'assigned',
            'assigned_employee_id' => $employee->id,
            'asset_assigned_date'  => $request->assigned_date,
        ]);

        // Specific remark on asset record
        $asset->appendRemark(
            "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
            "assigned to {$empName} by {$actorName}."
        );

        // Resolve or create AARF (covers both onboarded and imported employees)
        $aarf = $employee->onboarding_id
            ? \App\Models\Aarf::where('onboarding_id', $employee->onboarding_id)->first()
            : \App\Models\Aarf::where('employee_id', $employee->id)->first();

        // If no AARF exists yet (imported employee, first assignment), create one
        if (!$aarf) {
            $aarf = \App\Models\Aarf::create([
                'onboarding_id'         => $employee->onboarding_id ?? null,
                'employee_id'           => $employee->onboarding_id ? null : $employee->id,
                'aarf_reference'        => \App\Models\Onboarding::generateAarfReference(),
                'acknowledgement_token' => \Illuminate\Support\Str::random(64),
            ]);
        }

        // Specific entry in AARF change log
        $aarf->appendAssetChange(
            "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
            "assigned to {$empName} by {$actorName}."
        );

        // Reset acknowledgement so employee must re-sign
        $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
        $aarf->addPendingAsset($asset->id);

        // Send AARF acknowledgement email
        $recipientEmail = $employee->company_email
                       ?? $employee->personal_email
                       ?? $employee->onboarding?->workDetail?->company_email
                       ?? $employee->onboarding?->personalDetail?->personal_email
                       ?? null;

        if ($recipientEmail && $aarf->acknowledgement_token) {
            try {
                Mail::to($recipientEmail)->send(new AarfAcknowledgementMail($aarf, $empName, 'assigned'));
            } catch (\Throwable $e) {
                \Log::error("AARF email failed for employee #{$employee->id}: " . $e->getMessage());
            }
        }

        return back()->with('success', "Asset [{$asset->asset_tag}] assigned to {$empName} successfully.");
    }

    // ── IT Manager / Executive: Return an asset from an employee ──────────
    public function returnEmployeeAsset(Request $request, Employee $employee, \App\Models\AssetInventory $asset)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['it_manager', 'it_executive', 'superadmin'])) abort(403);

        $actorName = $user->name ?? $user->work_email ?? 'IT Team';
        $empName   = $employee->full_name ?? "Employee #{$employee->id}";

        // Close any open asset_assignment record
        \App\Models\AssetAssignment::where('asset_inventory_id', $asset->id)
            ->where('status', 'assigned')
            ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

        // Reset asset
        $asset->update([
            'status'               => 'available',
            'assigned_employee_id' => null,
            'asset_assigned_date'  => null,
            'expected_return_date' => null,
        ]);

        // Specific remark on asset record
        $asset->appendRemark(
            "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
            "returned by {$empName} to IT department. Processed by {$actorName}."
        );

        // Resolve AARF (covers both onboarded and imported employees)
        $aarf = $employee->onboarding_id
            ? \App\Models\Aarf::where('onboarding_id', $employee->onboarding_id)->first()
            : \App\Models\Aarf::where('employee_id', $employee->id)->first();

        if ($aarf) {
            $aarf->appendAssetChange(
                "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                "returned by {$empName} to IT department. Processed by {$actorName}."
            );

            // Reset acknowledgement so employee must re-sign the updated AARF
            $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
            $aarf->removePendingAsset($asset->id);

            $recipientEmail = $employee->company_email
                           ?? $employee->personal_email
                           ?? $employee->onboarding?->workDetail?->company_email
                           ?? $employee->onboarding?->personalDetail?->personal_email
                           ?? null;

            if ($recipientEmail && $aarf->acknowledgement_token) {
                try {
                    Mail::to($recipientEmail)->send(new AarfAcknowledgementMail($aarf, $empName, 'returned'));
                } catch (\Throwable $e) {
                    \Log::error("AARF return email failed for employee #{$employee->id}: " . $e->getMessage());
                }
            }
        }

        return back()->with('success', "Asset [{$asset->asset_tag}] marked as returned.");
    }

    // ── HR Manager only: Edit employee detail ────────────────────────────
    public function edit(Employee $employee)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager', 'superadmin'])) abort(403);
        $employee->load(['user', 'onboarding.personalDetail', 'contracts', 'educationHistories', 'spouseDetails', 'emergencyContacts', 'childRegistration', 'editLogs']);
        $managers  = \App\Models\User::whereIn('role', ['hr_manager','it_manager','superadmin','system_admin'])->orderBy('name')->with('employee')->get();
        $companies = Company::orderBy('name')->get(['name','address']);

        // Match the same directAssets logic as show() — includes auto-assigned (onboarding) assets
        $directAssets = \App\Models\AssetInventory::where(function ($q) use ($employee) {
            $q->where('assigned_employee_id', $employee->id);
            if ($employee->onboarding_id) {
                $onboardingAssetIds = \App\Models\AssetAssignment::where('onboarding_id', $employee->onboarding_id)
                    ->where('status', 'assigned')
                    ->pluck('asset_inventory_id');
                if ($onboardingAssetIds->isNotEmpty()) {
                    $q->orWhereIn('id', $onboardingAssetIds);
                }
            }
        })
            ->whereIn('status', ['assigned', 'unavailable'])
            ->orderBy('asset_type')
            ->get();

        return view('hr.employees.edit', compact('employee', 'managers', 'companies', 'directAssets'));
    }

    // ── Helper: send consent request email for employee record edits ─────────
    private function triggerEmployeeConsent(Employee $employee, array $sectionsChanged, ?string $changeNotes, bool $consentRequired): void
    {
        $token     = $consentRequired ? EmployeeEditLog::generateToken() : null;
        $expiresAt = $consentRequired ? now()->addDays(7) : null;

        // Collect both personal and work email, deduplicated
        $recipients = array_filter(array_unique([
            $employee->personal_email,
            $employee->company_email,
        ]));
        $recipientStr = implode(', ', $recipients);

        $log = EmployeeEditLog::create([
            'employee_id'              => $employee->id,
            'edited_by_user_id'        => Auth::id(),
            'edited_by_name'           => Auth::user()->name,
            'edited_by_role'           => Auth::user()->role,
            'sections_changed'         => $sectionsChanged,
            'change_notes'             => $changeNotes,
            'consent_required'         => $consentRequired,
            'consent_token'            => $token,
            'consent_token_expires_at' => $expiresAt,
            'consent_requested_at'     => $consentRequired ? now() : null,
            'consent_sent_to_email'    => $consentRequired ? ($recipientStr ?: null) : null,
        ]);

        if ($consentRequired && !empty($recipients)) {
            foreach ($recipients as $email) {
                try {
                    Mail::to($email)->send(new EmployeeConsentRequestMail($employee, $log));
                } catch (\Exception $e) {
                    \Log::warning("Employee consent email failed for employee #{$employee->id} to {$email}: " . $e->getMessage());
                }
            }
        }
    }

    public function update(Request $request, Employee $employee)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager', 'superadmin'])) abort(403);

        $rules = [
            // Personal
            'full_name'               => 'nullable|string|max:255',
            'preferred_name'          => 'nullable|string|max:100',
            'official_document_id'    => 'nullable|string|max:100',
            'date_of_birth'           => 'nullable|date',
            'sex'                     => 'nullable|string',
            'marital_status'          => 'nullable|string',
            'religion'                => 'nullable|string|max:100',
            'race'                    => 'nullable|string|max:100',
            'is_disabled'             => 'nullable|boolean',
            'residential_address'     => 'nullable|string',
            'personal_contact_number' => 'nullable|string|max:50',
            'house_tel_no'            => 'nullable|string|max:20',
            'personal_email'          => 'nullable|email',
            'bank_account_number'     => 'nullable|string|max:100',
            'bank_name'               => 'nullable|string|max:100',
            'bank_name_other'         => 'nullable|string|max:100',
            'epf_no'                  => 'nullable|string|max:50',
            'income_tax_no'           => 'nullable|string|max:50',
            'socso_no'                => 'nullable|string|max:50',
            // NRIC files
            'nric_files'              => 'nullable|array|max:5',
            'nric_files.*'            => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            // Work
            'designation'             => 'nullable|string|max:255',
            'department'              => 'nullable|string|max:255',
            'company'                 => 'nullable|string|max:255',
            'office_location'         => 'nullable|string|max:255',
            'reporting_manager'       => 'nullable|string|max:255',
            'company_email'           => 'nullable|email',
            'google_id'               => 'nullable|string|max:255',
            'employment_type'         => 'nullable|string',
            'work_role'               => 'nullable|string',
            'start_date'              => 'nullable|date',
            'exit_date'               => 'nullable|date|after_or_equal:start_date',
            'last_salary_date'        => 'nullable|date',
            'employment_status'       => 'nullable|in:active,resigned,terminated,contract_ended',
            'remarks'                 => 'nullable|string|max:2000',
            // Section F - Education
            'edu_qualification.*'    => 'nullable|string|max:255',
            'edu_institution.*'      => 'nullable|string|max:255',
            'edu_year.*'             => 'nullable|integer|min:1950|max:2099',
            'edu_experience_total'   => 'nullable|string|max:10',
            'edu_certificate.*.*'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'edu_cert_keep.*.*'      => 'nullable|string',
            'edu_delete_ids'         => 'nullable|string',
            // Section G - Spouse
            'del_spouse_ids'         => 'nullable|string',
            'spouses'                => 'nullable|array',
            'spouses.*.id'           => 'nullable|integer',
            'spouses.*.name'         => 'nullable|string|max:255',
            'spouses.*.nric_no'      => 'nullable|string|max:50',
            'spouses.*.tel_no'       => 'nullable|string|max:30',
            'spouses.*.occupation'   => 'nullable|string|max:255',
            'spouses.*.income_tax_no'=> 'nullable|string|max:50',
            'spouses.*.address'      => 'nullable|string',
            'spouses.*.is_working'   => 'nullable|boolean',
            'spouses.*.is_disabled'  => 'nullable|boolean',
            // Section H - Emergency Contacts
            'emergency.1.name'         => 'nullable|string|max:255',
            'emergency.1.tel_no'       => 'nullable|string|max:30',
            'emergency.1.relationship' => 'nullable|string|max:100',
            'emergency.2.name'         => 'nullable|string|max:255',
            'emergency.2.tel_no'       => 'nullable|string|max:30',
            'emergency.2.relationship' => 'nullable|string|max:100',
            // Section I - Child Registration
            'cat_a_100'=>'nullable|integer|min:0|max:99','cat_a_50'=>'nullable|integer|min:0|max:99',
            'cat_b_100'=>'nullable|integer|min:0|max:99','cat_b_50'=>'nullable|integer|min:0|max:99',
            'cat_c_100'=>'nullable|integer|min:0|max:99','cat_c_50'=>'nullable|integer|min:0|max:99',
            'cat_d_100'=>'nullable|integer|min:0|max:99','cat_d_50'=>'nullable|integer|min:0|max:99',
            'cat_e_100'=>'nullable|integer|min:0|max:99','cat_e_50'=>'nullable|integer|min:0|max:99',
        ];

        $data = $request->validate($rules);

        // Capture Section A old values BEFORE any update (for consent change detection)
        $oldSectionA = [
            'full_name'               => $employee->full_name,
            'official_document_id'    => $employee->official_document_id,
            'date_of_birth'           => $employee->date_of_birth?->toDateString(),
            'sex'                     => $employee->sex,
            'marital_status'          => $employee->marital_status,
            'religion'                => $employee->religion,
            'race'                    => $employee->race,
            'is_disabled'             => $employee->is_disabled,
            'residential_address'     => $employee->residential_address,
            'personal_contact_number' => $employee->personal_contact_number,
            'personal_email'          => $employee->personal_email,
            'bank_account_number'     => $employee->bank_account_number,
            'bank_name'               => $employee->bank_name,
            'epf_no'                  => $employee->epf_no,
            'income_tax_no'           => $employee->income_tax_no,
            'socso_no'                => $employee->socso_no,
        ];

        // Resolve bank_name (Other fallback)
        if (isset($data['bank_name']) && in_array($data['bank_name'], ['Other', 'other'])) {
            $data['bank_name'] = $data['bank_name_other'] ?? null;
        }
        unset($data['bank_name_other']);
        unset($data['nric_files']); // handled separately below

        // Ensure boolean cast
        $data['is_disabled'] = $request->boolean('is_disabled');

        // Handle NRIC file uploads and deletions
        // nric_keep_paths[] carries the paths the user chose to keep; anything not in that list is removed
        $keptNric = $request->input('nric_keep_paths', null);
        $existingNric = $employee->nric_file_paths ?? ($employee->nric_file_path ? [$employee->nric_file_path] : []);
        if ($keptNric !== null) {
            // User explicitly submitted the keep list — honour it (may be empty = all removed)
            $existingNric = array_values(array_intersect($existingNric, (array)$keptNric));
        }
        $newNricPaths = [];
        if ($request->hasFile('nric_files')) {
            foreach ($request->file('nric_files') as $file) {
                if ($file && $file->isValid()) {
                    $newNricPaths[] = $file->store('nric_documents', 'public');
                }
            }
        }
        $mergedNric = array_values(array_merge($existingNric, $newNricPaths));
        if (!empty($mergedNric)) {
            $data['nric_file_paths'] = $mergedNric;
            $data['nric_file_path']  = $mergedNric[0];
        } elseif ($keptNric !== null) {
            // All files were removed
            $data['nric_file_paths'] = null;
            $data['nric_file_path']  = null;
        }

        // HR Executive and HR Intern can only edit Sections A & B (personal + work details)
        // Section D fields (work_role, employment_status, exit_date) — work_role is superadmin only
        if (!in_array($u->role, ['hr_manager', 'hr_executive', 'superadmin'])) {
            unset($data['employment_status'], $data['exit_date']);
        }
        // work_role can only be changed by superadmin
        if (!$u->isSuperadmin()) {
            unset($data['work_role']);
        }
        // last_salary_date can only be set by HR Manager
        if (!$u->isHrManager()) {
            unset($data['last_salary_date']);
        }

        // Auto-sync google_id from company_email if not explicitly set
        if (!empty($data['company_email']) && empty($data['google_id'])) {
            $data['google_id'] = $data['company_email'];
        }

        // Create offboarding record when status changes to resigned/terminated/contract_ended.
        // Works with OR without an exit date — exit date is optional at this stage.
        $isLeaving = in_array(
            $data['employment_status'] ?? $employee->employment_status ?? 'active',
            ['resigned', 'terminated', 'contract_ended']
        );

        if ($isLeaving) {
            $matchKey = $employee->onboarding_id
                ? ['onboarding_id' => $employee->onboarding_id]
                : ['employee_id'   => $employee->id];

            // Resolve reporting manager email from employee or onboarding work details
            $reportingManagerEmail = $employee->reporting_manager_email
                ?? $employee->onboarding?->workDetail?->reporting_manager_email
                ?? null;

            $offboardingData = [
                'onboarding_id'           => $employee->onboarding_id,
                'employee_id'             => $employee->id,
                'full_name'               => $data['full_name']     ?? $employee->full_name,
                'company'                 => $data['company']       ?? $employee->company,
                'department'              => $data['department']    ?? $employee->department,
                'designation'             => $data['designation']   ?? $employee->designation,
                'company_email'           => $data['company_email'] ?? $employee->company_email,
                'reporting_manager_email' => $reportingManagerEmail,
            ];

            // Only set exit_date if provided
            if (!empty($data['exit_date'])) {
                $offboardingData['exit_date'] = $data['exit_date'];
            }

            $offboardingRecord = Offboarding::updateOrCreate($matchKey, $offboardingData);

            // If exit date is set and less than 1 month away — send notice + calendar immediately
            if (!empty($data['exit_date'])) {
                $exitDate      = \Carbon\Carbon::parse($data['exit_date']);
                $isShortNotice = $exitDate->isBefore(now()->addMonth());
                $obId          = $offboardingRecord->id;

                if ($isShortNotice && ($offboardingRecord->notice_email_status ?? 'pending') === 'pending') {
                    app()->terminating(function () use ($obId) {
                        try {
                            $ob = Offboarding::find($obId);
                            if (!$ob || $ob->notice_email_status !== 'pending') return;

                            $hrEmails = \App\Models\User::whereIn('role', ['hr_manager','hr_executive','hr_intern'])
                                ->where('is_active', true)->pluck('work_email')->filter()->unique()->values()->toArray();
                            $itEmails = \App\Models\User::whereIn('role', ['it_manager','it_executive','it_intern'])
                                ->where('is_active', true)->pluck('work_email')->filter()->unique()->values()->toArray();
                            $teamEmails = array_values(array_unique(array_merge($hrEmails, $itEmails)));

                            // Also CC reporting manager
                            if ($ob->reporting_manager_email) {
                                $teamEmails = array_values(array_unique(array_merge($teamEmails, [$ob->reporting_manager_email])));
                            }

                            $sent = false;

                            if ($ob->company_email) {
                                \Illuminate\Support\Facades\Mail::to($ob->company_email)
                                    ->cc($teamEmails)
                                    ->send(new \App\Mail\OffboardingNoticeMail($ob, 'employee'));
                                $sent = true;
                            }

                            if (!empty($teamEmails)) {
                                $command    = app(\App\Console\Commands\OffboardingNotifications::class);
                                $icsContent = $command->buildIcsPublic($ob);
                                \Illuminate\Support\Facades\Mail::to($teamEmails[0])
                                    ->cc(array_slice($teamEmails, 1))
                                    ->send(
                                        (new \App\Mail\OffboardingNoticeMail($ob, 'team'))
                                            ->attachData($icsContent, 'offboarding-reminder.ics', ['mime' => 'text/calendar'])
                                    );
                                $sent = true;
                            }

                            if ($sent) {
                                $ob->update(['notice_email_status' => 'sent', 'calendar_reminder_status' => 'sent']);
                            }
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error("Short-notice offboarding email failed for #{$obId}: " . $e->getMessage());
                        }
                    });
                }
            }
        }

        // ── Block if new company_email is already taken by a different user ─
        if (!empty($data['company_email']) && $employee->user_id) {
            $newEmail = $data['company_email'];
            $taken = \App\Models\User::where('work_email', $newEmail)
                ->where('id', '!=', $employee->user_id)
                ->exists();
            if ($taken) {
                return back()
                    ->withInput()
                    ->withErrors(['company_email' => 'This email is already used by another user account. Please use a different email.']);
            }
        }

        // Load relationships for change detection (before main update)
        $employee->load(['educationHistories', 'spouseDetails', 'emergencyContacts', 'childRegistration']);

        // ── Snapshot before F/G/H/I updates ─────────────────────────────────────
        $oldEduHash = md5(serialize($employee->educationHistories->map(fn($e) => [$e->id, $e->qualification, $e->institution, $e->year_graduated])->toArray()));
        $oldSpouseHash = md5(serialize($employee->spouseDetails->map(fn($s) => [$s->id, $s->name, $s->tel_no])->toArray()));
        $oldEcHash = md5(serialize($employee->emergencyContacts->sortBy('contact_order')->map(fn($c) => [$c->name, $c->tel_no, $c->relationship, $c->contact_order])->values()->toArray()));
        $oldChHash = md5(serialize($employee->childRegistration?->only(['cat_a_100','cat_a_50','cat_b_100','cat_b_50','cat_c_100','cat_c_50','cat_d_100','cat_d_50','cat_e_100','cat_e_50']) ?? []));

        $employee->update($data);

        // ── Sync users.work_email when company_email changes ─────────────
        if (!empty($data['company_email']) && $employee->user_id) {
            $linkedUser = \App\Models\User::find($employee->user_id);
            if ($linkedUser && $linkedUser->work_email !== $data['company_email']) {
                $linkedUser->update(['work_email' => $data['company_email']]);
            }
        }

        // Sync users.role when work_role changes — permissions are driven by users.role.
        // users.role is a restricted ENUM; map org-level roles (others, manager, etc.) to 'employee'.
        if (!empty($data['work_role']) && $employee->user_id) {
            $systemRoles = ['hr_manager','hr_executive','hr_intern','it_manager','it_executive','it_intern','superadmin','system_admin','employee'];
            $syncRole = in_array($data['work_role'], $systemRoles) ? $data['work_role'] : 'employee';
            \App\Models\User::where('id', $employee->user_id)
                ->update(['role' => $syncRole]);
        }

        // ── Sync back to linked onboarding personal_details + work_details ──
        $employee->refresh();
        if ($employee->onboarding_id) {
            $ob = \App\Models\Onboarding::with(['personalDetail', 'workDetail'])->find($employee->onboarding_id);
            if ($ob?->personalDetail) {
                $ob->personalDetail->update([
                    'full_name'               => $employee->full_name,
                    'preferred_name'          => $employee->preferred_name,
                    'official_document_id'    => $employee->official_document_id,
                    'date_of_birth'           => $employee->date_of_birth,
                    'sex'                     => $employee->sex,
                    'marital_status'          => $employee->marital_status,
                    'religion'                => $employee->religion,
                    'race'                    => $employee->race,
                    'is_disabled'             => $employee->is_disabled,
                    'residential_address'     => $employee->residential_address,
                    'personal_contact_number' => $employee->personal_contact_number,
                    'house_tel_no'            => $employee->house_tel_no,
                    'personal_email'          => $employee->personal_email,
                    'bank_account_number'     => $employee->bank_account_number,
                    'bank_name'               => $employee->bank_name,
                    'epf_no'                  => $employee->epf_no,
                    'income_tax_no'           => $employee->income_tax_no,
                    'socso_no'                => $employee->socso_no,
                ]);
            }
            if ($ob?->workDetail) {
                $ob->workDetail->update([
                    'designation'       => $employee->designation,
                    'department'        => $employee->department,
                    'company'           => $employee->company,
                    'office_location'   => $employee->office_location,
                    'reporting_manager' => $employee->reporting_manager,
                    'company_email'     => $employee->company_email,
                    'google_id'         => $employee->google_id,
                    'employment_type'   => $employee->employment_type,
                    'start_date'        => $employee->start_date,
                    'exit_date'         => $employee->exit_date,
                ]);
            }
        }

        // ── Sync to existing offboarding record ──────────────────────────
        $existingOffboarding = \App\Models\Offboarding::where(function ($q) use ($employee) {
            $q->where('employee_id', $employee->id);
            if ($employee->onboarding_id) {
                $q->orWhere('onboarding_id', $employee->onboarding_id);
            }
        })->first();
        if ($existingOffboarding) {
            $offSyncData = [
                'full_name'      => $employee->full_name,
                'company'        => $employee->company,
                'department'     => $employee->department,
                'designation'    => $employee->designation,
                'company_email'  => $employee->company_email,
                'personal_email' => $employee->personal_email,
            ];
            if ($employee->exit_date) {
                $offSyncData['exit_date'] = $employee->exit_date;
            }
            $existingOffboarding->update($offSyncData);
        }

        // ── Section A change detection — trigger consent if HR Manager/Superadmin ──
        $newSectionA = [
            'full_name'               => $employee->full_name,
            'official_document_id'    => $employee->official_document_id,
            'date_of_birth'           => $employee->date_of_birth?->toDateString(),
            'sex'                     => $employee->sex,
            'marital_status'          => $employee->marital_status,
            'religion'                => $employee->religion,
            'race'                    => $employee->race,
            'is_disabled'             => $employee->is_disabled,
            'residential_address'     => $employee->residential_address,
            'personal_contact_number' => $employee->personal_contact_number,
            'personal_email'          => $employee->personal_email,
            'bank_account_number'     => $employee->bank_account_number,
            'bank_name'               => $employee->bank_name,
            'epf_no'                  => $employee->epf_no,
            'income_tax_no'           => $employee->income_tax_no,
            'socso_no'                => $employee->socso_no,
        ];

        // ── Section F: Education ──────────────────────────────────────────────────
        $eduDeleteIds = array_filter(explode(',', $request->input('edu_delete_ids', '')));
        if (!empty($eduDeleteIds)) {
            \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)
                ->whereIn('id', $eduDeleteIds)->delete();
        }
        $expTotal = $request->input('edu_experience_total');
        foreach ($request->input('edu_qualification', []) as $i => $qual) {
            if (empty(trim((string)$qual))) continue;
            $existingId = $request->input("edu_id.{$i}");
            $yearsExp   = ($i === 0) ? $expTotal : null;
            $keepPaths  = $request->input("edu_cert_keep.{$i}", null);
            if ($existingId) {
                $row = \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                $allExisting = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
                $keptExisting = $keepPaths !== null ? array_values(array_intersect($allExisting, (array)$keepPaths)) : $allExisting;
            } else {
                $row = null; $keptExisting = [];
            }
            $newCertPaths = [];
            if ($request->hasFile("edu_certificate.{$i}")) {
                foreach ((array)$request->file("edu_certificate.{$i}") as $certFile) {
                    if ($certFile instanceof \Illuminate\Http\UploadedFile && $certFile->isValid()) {
                        $newCertPaths[] = $certFile->store('education_certificates', 'public');
                    }
                }
            }
            $mergedCerts = array_slice(array_values(array_merge($keptExisting, $newCertPaths)), 0, 5);
            if ($existingId && $row) {
                $row->update(['qualification' => $qual, 'institution' => $request->input("edu_institution.{$i}"), 'year_graduated' => $request->input("edu_year.{$i}"), 'years_experience' => $yearsExp, 'certificate_path' => $mergedCerts[0] ?? null, 'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null]);
            } elseif (!$existingId) {
                \App\Models\EmployeeEducationHistory::create(['employee_id' => $employee->id, 'qualification' => $qual, 'institution' => $request->input("edu_institution.{$i}"), 'year_graduated' => $request->input("edu_year.{$i}"), 'years_experience' => $yearsExp, 'certificate_path' => $mergedCerts[0] ?? null, 'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null]);
            }
        }

        // ── Section G: Spouse ─────────────────────────────────────────────────────
        $delSpouseIds = array_filter(explode(',', $request->input('del_spouse_ids', '')));
        if (!empty($delSpouseIds)) {
            \App\Models\EmployeeSpouseDetail::where('employee_id', $employee->id)->whereIn('id', $delSpouseIds)->delete();
        }
        foreach ($request->input('spouses', []) as $sp) {
            if (empty($sp['name'])) continue;
            $spId = !empty($sp['id']) ? (int)$sp['id'] : null;
            $spFields = ['name' => $sp['name'], 'nric_no' => $sp['nric_no'] ?? null, 'tel_no' => $sp['tel_no'] ?? null, 'occupation' => $sp['occupation'] ?? null, 'income_tax_no' => $sp['income_tax_no'] ?? null, 'address' => $sp['address'] ?? null, 'is_working' => !empty($sp['is_working']), 'is_disabled' => !empty($sp['is_disabled'])];
            if ($spId) {
                \App\Models\EmployeeSpouseDetail::where('employee_id', $employee->id)->where('id', $spId)->update($spFields);
            } else {
                $spFields['employee_id'] = $employee->id;
                \App\Models\EmployeeSpouseDetail::create($spFields);
            }
        }

        // ── Section H: Emergency Contacts ────────────────────────────────────────
        foreach ([1, 2] as $order) {
            $ec = $request->input("emergency.{$order}");
            if (!empty($ec['name'])) {
                \App\Models\EmployeeEmergencyContact::updateOrCreate(
                    ['employee_id' => $employee->id, 'contact_order' => $order],
                    ['name' => $ec['name'], 'tel_no' => $ec['tel_no'] ?? null, 'relationship' => $ec['relationship'] ?? null]
                );
            }
        }

        // ── Section I: Child Registration ─────────────────────────────────────────
        $childData = [];
        foreach (['a','b','c','d','e'] as $key) {
            $childData["cat_{$key}_100"] = (int)($request->input("cat_{$key}_100") ?? 0);
            $childData["cat_{$key}_50"]  = (int)($request->input("cat_{$key}_50") ?? 0);
        }
        if (!empty(array_filter($childData))) {
            \App\Models\EmployeeChildRegistration::updateOrCreate(['employee_id' => $employee->id], $childData);
        }

        // ── Detect section changes for consent email ──────────────────────────────
        $employee->load(['educationHistories', 'spouseDetails', 'emergencyContacts', 'childRegistration']);
        $newEduHash = md5(serialize($employee->educationHistories->map(fn($e) => [$e->id, $e->qualification, $e->institution, $e->year_graduated])->toArray()));
        $newSpouseHash = md5(serialize($employee->spouseDetails->map(fn($s) => [$s->id, $s->name, $s->tel_no])->toArray()));
        $newEcHash = md5(serialize($employee->emergencyContacts->sortBy('contact_order')->map(fn($c) => [$c->name, $c->tel_no, $c->relationship, $c->contact_order])->values()->toArray()));
        $newChHash = md5(serialize($employee->childRegistration?->only(['cat_a_100','cat_a_50','cat_b_100','cat_b_50','cat_c_100','cat_c_50','cat_d_100','cat_d_50','cat_e_100','cat_e_50']) ?? []));

        $changedSections = [];
        if ($oldSectionA !== $newSectionA) {
            $changedSections[] = 'Section A — Personal Details';
        }
        if ($oldEduHash !== $newEduHash) $changedSections[] = 'Section F — Education & Work History';
        if ($oldSpouseHash !== $newSpouseHash) $changedSections[] = 'Section G — Spouse Information';
        if ($oldEcHash !== $newEcHash) $changedSections[] = 'Section H — Emergency Contacts';
        if ($oldChHash !== $newChHash) $changedSections[] = 'Section I — Child Registration';

        $flashMessage = 'Employee record updated.';
        if (!empty($changedSections)) {
            $consentRequired = $u->isHrManager() || $u->isSuperadmin() || $u->isSystemAdmin();
            $this->triggerEmployeeConsent($employee, $changedSections, $request->input('remarks'), $consentRequired);
            if ($consentRequired) {
                $flashMessage = 'Employee record updated. A consent re-acknowledgement email has been sent to ' . ($employee->full_name ?? 'the employee') . '.';
            }
        }

        return redirect()->route('employees.show', $employee)->with('success', $flashMessage);
    }

    // ── Employee Contracts ────────────────────────────────────────────────

    /** Upload a contract — HR Manager only */
    // ── HR: Update education histories ────────────────────────────────────
    public function updateEducation(Request $request, Employee $employee)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager','superadmin'])) abort(403);

        $request->validate([
            'edu_qualification.*'    => 'nullable|string|max:255',
            'edu_institution.*'      => 'nullable|string|max:255',
            'edu_year.*'             => 'nullable|integer|min:1950|max:2099',
            'edu_experience_total'   => 'nullable|string|max:10',
            'edu_certificate.*.*'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'edu_cert_keep.*.*'      => 'nullable|string',
            'edu_delete_ids'         => 'nullable|string',
        ]);

        $deleteIds = array_filter(explode(',', $request->input('edu_delete_ids', '')));
        if (!empty($deleteIds)) {
            \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)
                ->whereIn('id', $deleteIds)->delete();
        }

        // Store total experience only on the first qualification row
        $expTotal = $request->input('edu_experience_total');

        foreach ($request->input('edu_qualification', []) as $i => $qual) {
            if (empty(trim((string)$qual))) continue;

            $existingId = $request->input("edu_id.{$i}");
            $yearsExp   = ($i === 0) ? $expTotal : null;

            // Determine which existing cert paths to keep (user may have removed some)
            $keepPaths = $request->input("edu_cert_keep.{$i}", null);
            if ($existingId && $keepPaths !== null) {
                $row = \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                $allExisting = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
                $keptExisting = array_values(array_intersect($allExisting, (array)$keepPaths));
            } elseif ($existingId) {
                $row = \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                $keptExisting = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
            } else {
                $keptExisting = [];
            }

            // Upload new cert files for this entry (edu_certificate[i][])
            $newCertPaths = [];
            if ($request->hasFile("edu_certificate.{$i}")) {
                foreach ((array)$request->file("edu_certificate.{$i}") as $certFile) {
                    if ($certFile instanceof \Illuminate\Http\UploadedFile && $certFile->isValid()) {
                        $newCertPaths[] = $certFile->store('education_certificates', 'public');
                    }
                }
            }

            $mergedCerts = array_values(array_merge($keptExisting, $newCertPaths));
            // Enforce 5-file cap
            $mergedCerts = array_slice($mergedCerts, 0, 5);

            if ($existingId) {
                if (!isset($row)) {
                    $row = \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                }
                if ($row) {
                    $row->update([
                        'qualification'     => $qual,
                        'institution'       => $request->input("edu_institution.{$i}"),
                        'year_graduated'    => $request->input("edu_year.{$i}"),
                        'years_experience'  => $yearsExp,
                        'certificate_path'  => $mergedCerts[0] ?? null,
                        'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null,
                    ]);
                }
            } else {
                \App\Models\EmployeeEducationHistory::create([
                    'employee_id'       => $employee->id,
                    'qualification'     => $qual,
                    'institution'       => $request->input("edu_institution.{$i}"),
                    'year_graduated'    => $request->input("edu_year.{$i}"),
                    'years_experience'  => $yearsExp,
                    'certificate_path'  => $mergedCerts[0] ?? null,
                    'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null,
                ]);
            }
        }
        $consentRequired = $u->isHrManager() || $u->isSuperadmin() || $u->isSystemAdmin();
        $this->triggerEmployeeConsent($employee, ['Section F — Education & Work History'], $request->input('remarks'), $consentRequired);
        $msg = $consentRequired
            ? 'Education history updated. A consent re-acknowledgement email has been sent to ' . ($employee->full_name ?? 'the employee') . '.'
            : 'Education history updated.';
        return back()->with('success', $msg);
    }

    // ── HR: Update spouse details ─────────────────────────────────────────
    public function updateSpouse(Request $request, Employee $employee)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager','superadmin'])) abort(403);

        $validated = $request->validate([
            'spouse_name'          => 'required|string|max:255',
            'spouse_address'       => 'nullable|string',
            'spouse_nric_no'       => 'nullable|string|max:50',
            'spouse_tel_no'        => $employee->marital_status === 'married' ? 'required|string|max:30' : 'nullable|string|max:30',
            'spouse_occupation'    => 'nullable|string|max:255',
            'spouse_income_tax_no' => 'nullable|string|max:50',
            'spouse_is_working'    => 'nullable|boolean',
            'spouse_is_disabled'   => 'nullable|boolean',
        ]);

        \App\Models\EmployeeSpouseDetail::create([
            'employee_id'   => $employee->id,
            'name'          => $validated['spouse_name'],
            'address'       => $validated['spouse_address'],
            'nric_no'       => $validated['spouse_nric_no'],
            'tel_no'        => $validated['spouse_tel_no'],
            'occupation'    => $validated['spouse_occupation'],
            'income_tax_no' => $validated['spouse_income_tax_no'],
            'is_working'    => $request->boolean('spouse_is_working'),
            'is_disabled'   => $request->boolean('spouse_is_disabled'),
        ]);

        $consentRequired = $u->isHrManager() || $u->isSuperadmin() || $u->isSystemAdmin();
        $this->triggerEmployeeConsent($employee, ['Section G — Spouse Information'], null, $consentRequired);
        $msg = $consentRequired
            ? 'Spouse information added. A consent re-acknowledgement email has been sent to ' . ($employee->full_name ?? 'the employee') . '.'
            : 'Spouse information added.';
        return back()->with('success', $msg);
    }

    // ── HR: Edit a specific spouse record ────────────────────────────────
    public function editSpouse(Request $request, Employee $employee, int $spouseId)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager','superadmin'])) abort(403);

        $validated = $request->validate([
            'spouse_name'          => 'required|string|max:255',
            'spouse_address'       => 'nullable|string',
            'spouse_nric_no'       => 'nullable|string|max:50',
            'spouse_tel_no'        => $employee->marital_status === 'married' ? 'required|string|max:30' : 'nullable|string|max:30',
            'spouse_occupation'    => 'nullable|string|max:255',
            'spouse_income_tax_no' => 'nullable|string|max:50',
            'spouse_is_working'    => 'nullable|boolean',
            'spouse_is_disabled'   => 'nullable|boolean',
        ]);

        \App\Models\EmployeeSpouseDetail::where('employee_id', $employee->id)
            ->where('id', $spouseId)
            ->update([
                'name'          => $validated['spouse_name'],
                'address'       => $validated['spouse_address'] ?? null,
                'nric_no'       => $validated['spouse_nric_no'] ?? null,
                'tel_no'        => $validated['spouse_tel_no'] ?? null,
                'occupation'    => $validated['spouse_occupation'] ?? null,
                'income_tax_no' => $validated['spouse_income_tax_no'] ?? null,
                'is_working'    => $request->boolean('spouse_is_working'),
                'is_disabled'   => $request->boolean('spouse_is_disabled'),
            ]);

        $consentRequired = $u->isHrManager() || $u->isSuperadmin() || $u->isSystemAdmin();
        $this->triggerEmployeeConsent($employee, ['Section G — Spouse Information'], null, $consentRequired);
        $msg = $consentRequired
            ? 'Spouse record updated. A consent re-acknowledgement email has been sent to ' . ($employee->full_name ?? 'the employee') . '.'
            : 'Spouse record updated.';
        return back()->with('success', $msg);
    }

    // ── HR: Delete a spouse record ────────────────────────────────────────
    public function deleteSpouse(Request $request, Employee $employee, int $spouseId)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager','superadmin'])) abort(403);
        \App\Models\EmployeeSpouseDetail::where('employee_id', $employee->id)
            ->where('id', $spouseId)->delete();

        $consentRequired = $u->isHrManager() || $u->isSuperadmin() || $u->isSystemAdmin();
        $this->triggerEmployeeConsent($employee, ['Section G — Spouse Information'], null, $consentRequired);
        $msg = $consentRequired
            ? 'Spouse record removed. A consent re-acknowledgement email has been sent to ' . ($employee->full_name ?? 'the employee') . '.'
            : 'Spouse record removed.';
        return back()->with('success', $msg);
    }

    // ── HR: Update emergency contacts ─────────────────────────────────────
    public function updateEmergency(Request $request, Employee $employee)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager','superadmin'])) abort(403);

        $request->validate([
            'emergency.1.name'         => 'required|string|max:255',
            'emergency.1.tel_no'       => 'required|string|max:30',
            'emergency.1.relationship' => 'required|string|max:100',
            'emergency.2.name'         => 'required|string|max:255',
            'emergency.2.tel_no'       => 'required|string|max:30',
            'emergency.2.relationship' => 'required|string|max:100',
        ]);

        foreach ([1, 2] as $order) {
            $ec = $request->input("emergency.{$order}");
            \App\Models\EmployeeEmergencyContact::updateOrCreate(
                ['employee_id' => $employee->id, 'contact_order' => $order],
                ['name' => $ec['name'], 'tel_no' => $ec['tel_no'], 'relationship' => $ec['relationship']]
            );
        }

        $consentRequired = $u->isHrManager() || $u->isSuperadmin() || $u->isSystemAdmin();
        $this->triggerEmployeeConsent($employee, ['Section H — Emergency Contacts'], null, $consentRequired);
        $msg = $consentRequired
            ? 'Emergency contacts updated. A consent re-acknowledgement email has been sent to ' . ($employee->full_name ?? 'the employee') . '.'
            : 'Emergency contacts updated.';
        return back()->with('success', $msg);
    }

    // ── HR: Update child registration ─────────────────────────────────────
    public function updateChildren(Request $request, Employee $employee)
    {
        $u = Auth::user();
        if (!in_array($u->role, ['hr_manager','superadmin'])) abort(403);

        $validated = $request->validate([
            'cat_a_100'=>'nullable|integer|min:0|max:99','cat_a_50'=>'nullable|integer|min:0|max:99',
            'cat_b_100'=>'nullable|integer|min:0|max:99','cat_b_50'=>'nullable|integer|min:0|max:99',
            'cat_c_100'=>'nullable|integer|min:0|max:99','cat_c_50'=>'nullable|integer|min:0|max:99',
            'cat_d_100'=>'nullable|integer|min:0|max:99','cat_d_50'=>'nullable|integer|min:0|max:99',
            'cat_e_100'=>'nullable|integer|min:0|max:99','cat_e_50'=>'nullable|integer|min:0|max:99',
        ]);

        \App\Models\EmployeeChildRegistration::updateOrCreate(
            ['employee_id' => $employee->id],
            array_map(fn($v) => (int)($v ?? 0), $validated)
        );

        $consentRequired = $u->isHrManager() || $u->isSuperadmin() || $u->isSystemAdmin();
        $this->triggerEmployeeConsent($employee, ['Section I — Child Registration'], null, $consentRequired);
        $msg = $consentRequired
            ? 'Child registration updated. A consent re-acknowledgement email has been sent to ' . ($employee->full_name ?? 'the employee') . '.'
            : 'Child registration updated.';
        return back()->with('success', $msg);
    }

    public function contractUpload(Request $request, Employee $employee)
    {
        if (!in_array(Auth::user()->role, ['hr_manager', 'superadmin'])) abort(403);

        $request->validate([
            'contract_file' => 'required|file|mimes:pdf,doc,docx|max:10240',
            'notes'         => 'nullable|string|max:500',
        ]);

        $file = $request->file('contract_file');
        $path = $file->store('employee_contracts/' . $employee->id, 'public');

        $employee->contracts()->create([
            'uploaded_by'       => Auth::id(),
            'original_filename' => $file->getClientOriginalName(),
            'file_path'         => $path,
            'file_size'         => $file->getSize(),
            'notes'             => $request->notes,
        ]);

        return back()->with('success', 'Contract uploaded successfully.');
    }

    /** Download a contract — HR Manager or the profile owner */
    public function contractDownload(Employee $employee, EmployeeContract $contract)
    {
        $user = Auth::user();
        $isOwner   = $employee->user_id && $user->id === $employee->user_id;
        $isManager = in_array($user->role, ['hr_manager', 'superadmin']);

        if (!$isOwner && !$isManager) abort(403);
        if ($contract->employee_id !== $employee->id) abort(404);

        return \Illuminate\Support\Facades\Storage::disk('public')
            ->download($contract->file_path, $contract->original_filename);
    }

    /** Delete a contract — HR Manager only */
    public function contractDelete(Employee $employee, EmployeeContract $contract)
    {
        if (!in_array(Auth::user()->role, ['hr_manager', 'superadmin'])) abort(403);
        if ($contract->employee_id !== $employee->id) abort(404);

        \Illuminate\Support\Facades\Storage::disk('public')->delete($contract->file_path);
        $contract->delete();

        return back()->with('success', 'Contract deleted.');
    }

    // ── HR Manager: Upload / replace employee handbook ───────────────────
    public function handbookUpload(Request $request, Employee $employee)
    {
        if (!in_array(Auth::user()->role, ['hr_manager', 'superadmin'])) abort(403);

        $request->validate([
            'handbook_file' => 'required|file|mimes:pdf|max:20480',
        ]);

        // Delete old file if one already exists
        if ($employee->handbook_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($employee->handbook_path);
        }

        $path = $request->file('handbook_file')
            ->store('employee_documents/' . $employee->id . '/handbook', 'public');

        $employee->update(['handbook_path' => $path]);

        return back()->with('success', 'Employee handbook uploaded successfully.');
    }

    /** Remove the employee's personalised handbook — HR Manager only */
    public function handbookDelete(Employee $employee)
    {
        if (!in_array(Auth::user()->role, ['hr_manager', 'superadmin'])) abort(403);

        if ($employee->handbook_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($employee->handbook_path);
            $employee->update(['handbook_path' => null]);
        }

        return back()->with('success', 'Handbook removed.');
    }

    // ── HR Manager: Upload / replace orientation slide ────────────────────
    public function orientationUpload(Request $request, Employee $employee)
    {
        if (!in_array(Auth::user()->role, ['hr_manager', 'superadmin'])) abort(403);

        $request->validate([
            'orientation_file' => 'required|file|mimes:pdf|max:20480',
        ]);

        if ($employee->orientation_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($employee->orientation_path);
        }

        $path = $request->file('orientation_file')
            ->store('employee_documents/' . $employee->id . '/orientation', 'public');

        $employee->update(['orientation_path' => $path]);

        return back()->with('success', 'Orientation slide uploaded successfully.');
    }

    /** Remove the employee's orientation slide — HR Manager only */
    public function orientationDelete(Employee $employee)
    {
        if (!in_array(Auth::user()->role, ['hr_manager', 'superadmin'])) abort(403);

        if ($employee->orientation_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($employee->orientation_path);
            $employee->update(['orientation_path' => null]);
        }

        return back()->with('success', 'Orientation slide removed.');
    }

    // ── HR + IT: Offboarding page ─────────────────────────────────────────
    public function offboarding(Request $request)
    {
        $this->authorizeItOrHr();

        $query = Offboarding::with('onboarding');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('full_name','like',"%{$s}%")
                  ->orWhere('company_email','like',"%{$s}%")
                  ->orWhere('company','like',"%{$s}%")
            );
        }
        if ($request->filled('company'))    $query->where('company','like',"%{$request->company}%");
        if ($request->filled('department')) $query->where('department','like',"%{$request->department}%");
        if ($request->filled('exit_date'))  $query->whereDate('exit_date', $request->exit_date);

        $offboardings = $query->orderByDesc('exit_date')->paginate(20)->withQueryString();
        $companies    = Offboarding::distinct()->pluck('company')->filter()->sort()->values();
        $months       = ['1'=>'January','2'=>'February','3'=>'March','4'=>'April','5'=>'May','6'=>'June',
                         '7'=>'July','8'=>'August','9'=>'September','10'=>'October','11'=>'November','12'=>'December'];
        $years        = range(now()->year - 2, now()->year + 2);

        $month = $request->input('month', now()->month);
        $year  = $request->input('year',  now()->year);
        return view('shared.offboarding', compact('offboardings','companies','months','years','month','year'));
    }

    // ── IT: update offboarding status ─────────────────────────────────────
    public function updateOffboardingStatus(Request $request, Offboarding $offboarding)
    {
        $u = Auth::user();
        if (!$u->isIt() && !$u->isHr() && !$u->isSuperadmin()) abort(403);

        $request->validate([
            'field'  => 'required|in:calendar_reminder_status,exiting_email_status,aarf_status',
            'status' => 'required',
        ]);

        $offboarding->update([$request->field => $request->status]);
        return back()->with('success', 'Status updated.');
    }
}