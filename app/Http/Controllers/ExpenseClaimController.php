<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimItem;
use App\Models\ExpenseClaimPolicy;
use App\Mail\ClaimSubmittedMail;
use App\Mail\ClaimApprovedMail;
use App\Mail\ClaimRejectedMail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ExpenseClaimController extends Controller
{
    // ══════════════════════════════════════════════════════════════════════
    // SELF-SERVICE: Employee's Own Claims
    // ══════════════════════════════════════════════════════════════════════

    /**
     * My Claims — list all claims for the logged-in employee.
     */
    public function myClaims()
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $claims = $employee->expenseClaims()->with('items.category')->get();
        $policy = ExpenseClaimPolicy::forCompany($employee->company);

        // Current month claim (auto-create draft if doesn't exist)
        $now = Carbon::now();
        $currentClaim = $this->getOrCreateDraft($employee, $now->year, $now->month);

        $categories = ExpenseCategory::active()
            ->where(function ($q) use ($employee) {
                $q->where('company', $employee->company)->orWhereNull('company');
            })->get();

        return view('user.claims.index', compact('employee', 'claims', 'currentClaim', 'categories', 'policy'));
    }

    /**
     * Add an item to the current month's draft claim.
     */
    public function addItem(Request $request)
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $validated = $request->validate([
            'expense_date' => 'required|date|before_or_equal:today',
            'description' => 'required|string|max:500',
            'project_client' => 'nullable|string|max:255',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'amount' => 'required|numeric|min:0.01|max:99999.99',
            'gst_amount' => 'nullable|numeric|min:0|max:99999.99',
            'total_with_gst' => 'required|numeric|min:0.01|max:999999.99',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ]);

        $expenseDate = Carbon::parse($validated['expense_date']);
        $claim = $this->getOrCreateDraft($employee, $expenseDate->year, $expenseDate->month);

        if (!$claim->isEditable()) {
            return back()->with('error', 'This claim has already been submitted and cannot be edited.');
        }

        // Handle receipt upload
        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store(
                'claim_receipts/' . $employee->id . '/' . $expenseDate->format('Y-m'),
                'local'
            );
        }

        // Validate total integrity — server-side check that total = amount + GST
        $expectedTotal = round((float) $validated['amount'] + (float) ($validated['gst_amount'] ?? 0), 2);
        if (abs($expectedTotal - (float) $validated['total_with_gst']) > 0.01) {
            return back()->withErrors(['total_with_gst' => 'Total does not match amount + GST.'])->withInput();
        }

        // Enforce category monthly limit
        $category = ExpenseCategory::find($validated['expense_category_id']);
        if ($category && $category->monthly_limit) {
            $existingCategoryTotal = $claim->items()
                ->where('expense_category_id', $category->id)
                ->sum('amount');
            if (($existingCategoryTotal + (float) $validated['amount']) > $category->monthly_limit) {
                return back()->withErrors(['amount' => 'Exceeds monthly category limit of RM ' . number_format($category->monthly_limit, 2) . ' for ' . $category->name . '.'])->withInput();
            }
        }

        // Enforce submission deadline
        if ($claim->submission_deadline && Carbon::now()->gt($claim->submission_deadline)) {
            return back()->with('error', 'Submission deadline has passed for this claim period.');
        }

        $claim->items()->create([
            'expense_category_id' => $validated['expense_category_id'],
            'expense_date' => $validated['expense_date'],
            'description' => strip_tags($validated['description']),
            'project_client' => $validated['project_client'] ? strip_tags($validated['project_client']) : null,
            'amount' => $validated['amount'],
            'gst_amount' => $validated['gst_amount'] ?? 0,
            'total_with_gst' => $expectedTotal,
            'receipt_path' => $receiptPath,
        ]);

        $claim->recalculateTotals();

        return back()->with('success', 'Expense item added successfully.');
    }

    /**
     * Remove an item from a draft claim.
     */
    public function removeItem(ExpenseClaimItem $item)
    {
        $employee = Auth::user()->employee;
        $claim = $item->claim;

        if (!$claim || $claim->employee_id !== $employee->id) {
            abort(403);
        }

        if (!$claim->isEditable() || $item->is_locked) {
            return back()->with('error', 'This item cannot be removed.');
        }

        // Delete receipt file
        if ($item->receipt_path) {
            Storage::disk('local')->delete($item->receipt_path);
        }

        $item->delete();
        $claim->recalculateTotals();

        return back()->with('success', 'Expense item removed.');
    }

    /**
     * Submit a draft claim for manager approval.
     */
    public function submit(ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;

        if ($claim->employee_id !== $employee->id) {
            abort(403);
        }

        if (!$claim->isSubmittable()) {
            return back()->with('error', 'This claim cannot be submitted. Ensure it has at least one item.');
        }

        // Verify totals
        $claim->recalculateTotals();

        $claim->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'manager_id' => $employee->manager_id,
        ]);

        // Lock all items
        $claim->items()->update(['is_locked' => true]);

        // Notify manager
        $manager = $employee->manager;
        if ($manager && $manager->user) {
            Mail::to($manager->user->work_email)->send(
                new ClaimSubmittedMail($claim, $employee, 'manager')
            );
        }

        return back()->with('success', 'Claim submitted for approval.');
    }

    /**
     * Cancel a submitted claim (only if not yet approved).
     */
    public function cancel(ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;

        if ($claim->employee_id !== $employee->id) {
            abort(403);
        }

        if (!in_array($claim->status, ['submitted'])) {
            return back()->with('error', 'Only submitted claims can be cancelled.');
        }

        $claim->update(['status' => 'draft', 'submitted_at' => null, 'manager_id' => null]);
        $claim->items()->update(['is_locked' => false]);

        return back()->with('success', 'Claim recalled to draft.');
    }

    /**
     * Auto-detect expense category based on description.
     */
    public function detectCategory(Request $request)
    {
        $description = $request->input('description', '');
        $company = Auth::user()->employee?->company;

        $category = ExpenseCategory::detectFromDescription($description, $company);

        return response()->json([
            'category_id' => $category?->id,
            'category_name' => $category?->name,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // MANAGER: Team Claims Approval
    // ══════════════════════════════════════════════════════════════════════

    /**
     * List pending claims from direct reports.
     */
    public function teamClaims()
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $directReportIds = Employee::where('manager_id', $employee->id)->pluck('id');

        $pendingClaims = ExpenseClaim::whereIn('employee_id', $directReportIds)
            ->where('status', 'submitted')
            ->with(['employee', 'items.category'])
            ->orderBy('submitted_at')
            ->get();

        $historyClaims = ExpenseClaim::whereIn('employee_id', $directReportIds)
            ->whereNotIn('status', ['draft', 'submitted'])
            ->with(['employee', 'items.category'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return view('user.claims.team', compact('pendingClaims', 'historyClaims', 'employee'));
    }

    /**
     * Manager approves a submitted claim.
     */
    public function managerApprove(ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;

        if ($claim->status !== 'submitted') {
            return back()->with('error', 'This claim is not pending approval.');
        }

        // Verify the approver is the assigned manager or a superadmin
        if ($claim->manager_id !== $employee->id && !Auth::user()->isSuperadmin()) {
            abort(403);
        }

        // Verify the approver is the CURRENT manager (relationship may have changed)
        $claim->employee->refresh();
        if ($claim->employee->manager_id !== $employee->id && !Auth::user()->isSuperadmin()) {
            abort(403, 'You are no longer the manager of this employee.');
        }

        $claim->update([
            'status' => 'manager_approved',
            'manager_approved_by' => $employee->id,
            'manager_approved_at' => now(),
        ]);

        Log::info('Claim manager-approved', [
            'claim_id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'amount' => $claim->total_with_gst,
            'actor_id' => Auth::id(),
            'actor_role' => Auth::user()->role,
        ]);

        // Notify employee
        $claimEmployee = $claim->employee;
        if ($claimEmployee->user) {
            Mail::to($claimEmployee->user->work_email)->send(
                new ClaimApprovedMail($claim, $claimEmployee, 'manager')
            );
        }

        // Notify HR
        $this->notifyHr($claim, 'pending_hr_approval');

        return back()->with('success', 'Claim approved.');
    }

    /**
     * Manager rejects a submitted claim with remarks.
     */
    public function managerReject(Request $request, ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;

        $request->validate(['remarks' => 'required|string|max:1000']);

        if ($claim->status !== 'submitted') {
            return back()->with('error', 'This claim is not pending approval.');
        }

        // Verify the approver is the CURRENT manager
        $claim->employee->refresh();
        if ($claim->employee->manager_id !== $employee->id && !Auth::user()->isSuperadmin()) {
            abort(403, 'You are no longer the manager of this employee.');
        }

        $remarks = strip_tags($request->input('remarks'));

        $claim->update([
            'status' => 'manager_rejected',
            'manager_approved_by' => $employee->id,
            'manager_approved_at' => now(),
            'manager_remarks' => $remarks,
        ]);

        Log::info('Claim manager-rejected', [
            'claim_id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'amount' => $claim->total_with_gst,
            'actor_id' => Auth::id(),
            'actor_role' => Auth::user()->role,
            'remarks' => $remarks,
        ]);

        // Unlock items so employee can edit and resubmit
        $claim->items()->update(['is_locked' => false]);

        // Notify employee
        $claimEmployee = $claim->employee;
        if ($claimEmployee->user) {
            Mail::to($claimEmployee->user->work_email)->send(
                new ClaimRejectedMail($claim, $claimEmployee, 'manager')
            );
        }

        return back()->with('success', 'Claim rejected with remarks.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // HR / ADMIN: All Claims Management
    // ══════════════════════════════════════════════════════════════════════

    /**
     * HR: List all claims with filtering.
     */
    public function index(Request $request)
    {
        $this->authorizeViewClaims();

        $query = ExpenseClaim::with(['employee', 'items.category']);

        // Filters
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($year = $request->input('year')) {
            $query->where('year', $year);
        }
        if ($month = $request->input('month')) {
            $query->where('month', $month);
        }
        if ($employeeId = $request->input('employee_id')) {
            $query->where('employee_id', $employeeId);
        }
        if ($company = $request->input('company')) {
            $query->whereHas('employee', fn($q) => $q->where('company', $company));
        }

        $claims = $query->orderByDesc('year')->orderByDesc('month')->orderByDesc('submitted_at')->paginate(25);

        $employees = Employee::whereNull('active_until')->orderBy('full_name')->get();
        $stats = $this->getClaimStats();

        return view('hr.claims.index', compact('claims', 'employees', 'stats'));
    }

    /**
     * HR: View a single claim in detail.
     */
    public function show(ExpenseClaim $claim)
    {
        $this->authorizeViewClaims();

        $claim->load(['employee', 'items.category', 'manager', 'managerApprover', 'hrApprover']);

        return view('hr.claims.show', compact('claim'));
    }

    /**
     * HR: Approve a manager-approved claim.
     */
    public function hrApprove(ExpenseClaim $claim)
    {
        $this->authorizeManageClaims();

        if ($claim->status !== 'manager_approved') {
            return back()->with('error', 'This claim is not pending HR approval.');
        }

        $claim->update([
            'status' => 'hr_approved',
            'hr_approved_by' => Auth::id(),
            'hr_approved_at' => now(),
        ]);

        Log::info('Claim hr-approved', [
            'claim_id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'amount' => $claim->total_with_gst,
            'actor_id' => Auth::id(),
            'actor_role' => Auth::user()->role,
        ]);

        // Notify employee
        $employee = $claim->employee;
        if ($employee->user) {
            Mail::to($employee->user->work_email)->send(
                new ClaimApprovedMail($claim, $employee, 'hr')
            );
        }

        return back()->with('success', 'Claim approved by HR.');
    }

    /**
     * HR: Reject a manager-approved claim.
     */
    public function hrReject(Request $request, ExpenseClaim $claim)
    {
        $this->authorizeManageClaims();

        $request->validate(['remarks' => 'required|string|max:1000']);

        if ($claim->status !== 'manager_approved') {
            return back()->with('error', 'This claim is not pending HR approval.');
        }

        $remarks = strip_tags($request->input('remarks'));

        $claim->update([
            'status' => 'hr_rejected',
            'hr_approved_by' => Auth::id(),
            'hr_approved_at' => now(),
            'hr_remarks' => $remarks,
        ]);

        Log::info('Claim hr-rejected', [
            'claim_id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'amount' => $claim->total_with_gst,
            'actor_id' => Auth::id(),
            'actor_role' => Auth::user()->role,
            'remarks' => $remarks,
        ]);

        // Unlock items so employee can edit and resubmit
        $claim->items()->update(['is_locked' => false]);

        // Notify employee
        $employee = $claim->employee;
        if ($employee->user) {
            Mail::to($employee->user->work_email)->send(
                new ClaimRejectedMail($claim, $employee, 'hr')
            );
        }

        return back()->with('success', 'Claim rejected by HR.');
    }

    /**
     * HR: Bulk approve multiple manager-approved claims.
     */
    public function bulkApprove(Request $request)
    {
        $this->authorizeManageClaims();

        $validated = $request->validate([
            'claim_ids' => 'required|array|min:1',
            'claim_ids.*' => 'exists:expense_claims,id',
        ]);

        $count = 0;
        foreach ($validated['claim_ids'] as $claimId) {
            $claim = ExpenseClaim::find($claimId);
            if ($claim && $claim->status === 'manager_approved') {
                $claim->update([
                    'status' => 'hr_approved',
                    'hr_approved_by' => Auth::id(),
                    'hr_approved_at' => now(),
                ]);
                $count++;

                Log::info('Claim bulk-hr-approved', [
                    'claim_id' => $claim->id,
                    'claim_number' => $claim->claim_number,
                    'amount' => $claim->total_with_gst,
                    'actor_id' => Auth::id(),
                    'actor_role' => Auth::user()->role,
                ]);

                $employee = $claim->employee;
                if ($employee?->user) {
                    Mail::to($employee->user->work_email)->send(
                        new ClaimApprovedMail($claim, $employee, 'hr')
                    );
                }
            }
        }

        return back()->with('success', "{$count} claim(s) approved.");
    }

    /**
     * HR: Export claims to CSV.
     */
    public function export(Request $request)
    {
        $this->authorizeViewClaims();

        $query = ExpenseClaim::with(['employee', 'items.category']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($year = $request->input('year')) {
            $query->where('year', $year);
        }
        if ($month = $request->input('month')) {
            $query->where('month', $month);
        }

        $claims = $query->orderBy('employee_id')->orderBy('year')->orderBy('month')->get();

        $filename = 'expense_claims_' . now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($claims) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Claim Number', 'Employee', 'Department', 'Period', 'Status',
                'Item Date', 'Description', 'Project/Client', 'Category',
                'Amount (w/o GST)', 'GST', 'Total (w/ GST)',
                'Submitted', 'Manager Approved', 'HR Approved',
            ]);

            foreach ($claims as $claim) {
                foreach ($claim->items as $item) {
                    fputcsv($file, [
                        $claim->claim_number,
                        $this->sanitizeForCsv($claim->employee->full_name ?? '-'),
                        $this->sanitizeForCsv($claim->employee->department ?? '-'),
                        $claim->year . '-' . str_pad($claim->month, 2, '0', STR_PAD_LEFT),
                        $claim->status,
                        $item->expense_date->format('Y-m-d'),
                        $this->sanitizeForCsv($item->description),
                        $this->sanitizeForCsv($item->project_client ?? '-'),
                        $this->sanitizeForCsv($item->category->name ?? '-'),
                        number_format($item->amount, 2),
                        number_format($item->gst_amount, 2),
                        number_format($item->total_with_gst, 2),
                        $claim->submitted_at?->format('Y-m-d') ?? '-',
                        $claim->manager_approved_at?->format('Y-m-d') ?? '-',
                        $claim->hr_approved_at?->format('Y-m-d') ?? '-',
                    ]);
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * HR: Manage expense categories.
     */
    public function categories()
    {
        $this->authorizeManageClaims();

        $categories = ExpenseCategory::orderBy('sort_order')->get();

        return view('hr.claims.categories', compact('categories'));
    }

    /**
     * HR: Store a new expense category.
     */
    public function storeCategory(Request $request)
    {
        $this->authorizeManageClaims();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:30|unique:expense_categories,code',
            'description' => 'nullable|string|max:500',
            'monthly_limit' => 'nullable|numeric|min:0',
            'requires_receipt' => 'boolean',
            'keywords' => 'nullable|string',
        ]);

        $keywords = $validated['keywords']
            ? array_map('trim', explode(',', $validated['keywords']))
            : null;

        ExpenseCategory::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'monthly_limit' => $validated['monthly_limit'] ?? null,
            'requires_receipt' => $validated['requires_receipt'] ?? true,
            'keywords' => $keywords,
            'sort_order' => ExpenseCategory::max('sort_order') + 1,
        ]);

        return back()->with('success', 'Expense category created.');
    }

    /**
     * HR: Update an expense category.
     */
    public function updateCategory(Request $request, ExpenseCategory $category)
    {
        $this->authorizeManageClaims();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:30|unique:expense_categories,code,' . $category->id,
            'description' => 'nullable|string|max:500',
            'monthly_limit' => 'nullable|numeric|min:0',
            'requires_receipt' => 'boolean',
            'is_active' => 'boolean',
            'keywords' => 'nullable|string',
        ]);

        $keywords = $validated['keywords']
            ? array_map('trim', explode(',', $validated['keywords']))
            : null;

        $category->update([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'monthly_limit' => $validated['monthly_limit'] ?? null,
            'requires_receipt' => $validated['requires_receipt'] ?? true,
            'is_active' => $validated['is_active'] ?? true,
            'keywords' => $keywords,
        ]);

        return back()->with('success', 'Expense category updated.');
    }

    /**
     * HR: Manage claim policy.
     */
    public function policy()
    {
        $this->authorizeManageClaims();

        $policy = ExpenseClaimPolicy::forCompany(null);

        return view('hr.claims.policy', compact('policy'));
    }

    /**
     * HR: Update claim policy.
     */
    public function updatePolicy(Request $request)
    {
        $this->authorizeManageClaims();

        $validated = $request->validate([
            'submission_deadline_day' => 'required|integer|min:1|max:28',
            'require_manager_approval' => 'boolean',
            'require_hr_approval' => 'boolean',
            'auto_approve_below' => 'nullable|numeric|min:0',
            'reminder_days_before' => 'required|integer|min:1|max:10',
            'gst_enabled' => 'boolean',
            'gst_rate' => 'required|numeric|min:0|max:20',
            'general_rules' => 'nullable|string|max:5000',
        ]);

        ExpenseClaimPolicy::updateOrCreate(
            ['company' => null],
            $validated
        );

        return back()->with('success', 'Claim policy updated.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Private Helpers
    // ══════════════════════════════════════════════════════════════════════

    private function getOrCreateDraft(Employee $employee, int $year, int $month): ExpenseClaim
    {
        return ExpenseClaim::firstOrCreate(
            ['employee_id' => $employee->id, 'year' => $year, 'month' => $month],
            [
                'claim_number' => ExpenseClaim::generateClaimNumber($year, $month),
                'title' => Carbon::create($year, $month)->format('F Y') . ' — ' . $employee->full_name,
                'status' => 'draft',
                'submission_deadline' => Carbon::create($year, $month, ExpenseClaimPolicy::forCompany($employee->company)->submission_deadline_day),
                'manager_id' => $employee->manager_id,
            ]
        );
    }

    private function notifyHr(ExpenseClaim $claim, string $type): void
    {
        $hrUsers = \App\Models\User::whereIn('role', ['hr_manager', 'superadmin'])
            ->where('is_active', true)
            ->get();

        foreach ($hrUsers as $hr) {
            Mail::to($hr->work_email)->send(
                new ClaimSubmittedMail($claim, $claim->employee, 'hr')
            );
        }
    }

    private function getClaimStats(): array
    {
        return [
            'pending_manager' => ExpenseClaim::where('status', 'submitted')->count(),
            'pending_hr' => ExpenseClaim::where('status', 'manager_approved')->count(),
            'approved' => ExpenseClaim::where('status', 'hr_approved')->count(),
            'total_approved_amount' => ExpenseClaim::where('status', 'hr_approved')
                ->whereYear('created_at', now()->year)
                ->sum('total_with_gst'),
        ];
    }

    private function authorizeViewClaims(): void
    {
        if (!Auth::user()->canViewAllClaims()) {
            abort(403, 'You do not have permission to view all claims.');
        }
    }

    private function authorizeManageClaims(): void
    {
        if (!Auth::user()->canManageClaims()) {
            abort(403, 'You do not have permission to manage claims.');
        }
    }

    /**
     * Sanitize value for CSV export to prevent formula injection.
     * Prefixes dangerous characters to prevent Excel from interpreting them as formulas.
     */
    private function sanitizeForCsv(string $value): string
    {
        if ($value === '' || is_numeric($value)) {
            return $value;
        }
        $first = substr($value, 0, 1);
        if (in_array($first, ['=', '+', '@', '-', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
