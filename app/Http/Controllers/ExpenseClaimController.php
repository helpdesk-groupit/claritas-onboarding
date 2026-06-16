<?php

namespace App\Http\Controllers;

use App\Mail\ClaimApprovedMail;
use App\Mail\ClaimRejectedMail;
use App\Mail\ClaimSubmittedMail;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimItem;
use App\Models\ExpenseClaimPolicy;
use App\Services\ClaimReceiptOcrService;
use App\Services\ClaimRulesService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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
     * Supports ?month=N&year=YYYY to view any month within current year.
     */
    public function myClaims(Request $request)
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $now = Carbon::now();
        $year = (int) $request->input('year', $now->year);
        $month = (int) $request->input('month', $now->month);

        // Only allow current year, valid month range, no future months
        if ($year !== $now->year || $month < 1 || $month > 12 || $month > $now->month) {
            $year = $now->year;
            $month = $now->month;
        }

        $claims = $employee->expenseClaims()->with('items.category')->get();
        $policy = ExpenseClaimPolicy::forCompany($employee->company);

        // Find existing claim for selected month (don't auto-create empty drafts)
        $currentClaim = ExpenseClaim::where('employee_id', $employee->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $categories = ExpenseCategory::active()
            ->where(function ($q) use ($employee) {
                $q->where('company', $employee->company)->orWhereNull('company');
            })->get();

        return view('user.claims.index', compact('employee', 'claims', 'currentClaim', 'categories', 'policy', 'year', 'month'));
    }

    /**
     * Add an item to a draft claim (any month within current year).
     */
    public function addItem(Request $request)
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return back()->with('error', 'No employee profile found.');
        }

        // The claim period is the month the user is currently viewing (hidden claim_year/
        // claim_month), so every item added there accumulates in that one month's claim —
        // instead of being scattered (and the page navigating away) by each item's own date.
        // The expense date must fall within that month and not be in the future.
        $now = Carbon::now();
        $claimYear = (int) $request->input('claim_year', $now->year);
        $claimMonth = (int) $request->input('claim_month', $now->month);
        if ($claimYear !== $now->year || $claimMonth < 1 || $claimMonth > 12
            || ($claimYear === $now->year && $claimMonth > $now->month)) {
            return back()->with('error', 'Invalid claim month selected.')->withInput();
        }
        $monthStart = Carbon::create($claimYear, $claimMonth, 1)->startOfDay(); // label for this claim

        // Expenses may be back-dated to any earlier date this year — only FUTURE dates are
        // blocked (e.g. in June you can't claim a July expense). The item still accumulates
        // in the claim month being viewed (claim_year/claim_month above), not its own date.
        $startOfYear = Carbon::create($now->year, 1, 1)->toDateString();

        $validated = $request->validate([
            'expense_date' => "required|date|after_or_equal:{$startOfYear}|before_or_equal:today",
            'description' => 'required|string|max:500',
            'project_client' => 'nullable|string|max:255',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'amount' => 'required|numeric|min:0.01|max:99999.99',
            'gst_amount' => 'nullable|numeric|min:0|max:99999.99',
            'total_with_gst' => 'required|numeric|min:0.01|max:999999.99',
            'quantity' => 'nullable|numeric|min:0.01|max:99999.99',
            'vehicle' => 'nullable|in:car,motorcycle',
            'claim_mode' => 'nullable|in:receipt,mileage',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ], [
            'expense_date.after_or_equal' => 'The expense date must be within '.$now->year.'.',
            'expense_date.before_or_equal' => 'The expense date cannot be in the future — you can claim past dates, just not future ones.',
        ]);

        $expenseDate = Carbon::parse($validated['expense_date']);
        $claim = $this->getOrCreateDraft($employee, $claimYear, $claimMonth);

        if (! $claim->isEditable()) {
            return back()->with('error', 'This claim has already been submitted and cannot be edited.');
        }

        // ── Rules engine: eligibility, computed amounts, receipt requirement ──
        $category = ExpenseCategory::find($validated['expense_category_id']);
        if (! $category || ! $category->is_active) {
            return back()->withErrors(['expense_category_id' => 'Invalid expense category.'])->withInput();
        }

        // Entity scoping: a company-specific category is only for that company.
        if ($category->company && $employee->company && $category->company !== $employee->company) {
            return back()->withErrors(['expense_category_id' => 'This category is not available for your company.'])->withInput();
        }

        // Role eligibility (e.g. intern-only categories).
        if (! ClaimRulesService::roleAllows($employee, $category->applies_to_role)) {
            return back()->withErrors(['expense_category_id' => 'You are not eligible to claim under this category.'])->withInput();
        }

        // Mileage-on-Petrol: the Petrol account can be claimed by receipt OR as a
        // per-km mileage claim (car/motorcycle rate, measured from Jaya One).
        $mileageGl = config('claims.mileage.gl_code');
        $isPetrolMileage = $request->input('claim_mode') === 'mileage'
            && $mileageGl && $category->gl_code === $mileageGl;

        // Strict "no receipt, no claim" for receipt-required categories — except a
        // mileage claim, where the distance (not a receipt) is the evidence.
        if ($category->requires_receipt && ! $isPetrolMileage && ! $request->hasFile('receipt')) {
            return back()->withErrors(['receipt' => 'A receipt is required for '.$category->name.' (no receipt, no claim).'])->withInput();
        }

        // Computed amounts: per-day/per-hour categories, or a mileage-mode Petrol
        // claim — all derive the amount server-side from a quantity (the server is
        // authoritative and overrides any client-sent amount).
        $quantity = null;
        $unit = null;
        $rateApplied = null;
        if ($category->isComputed() || $isPetrolMileage) {
            if ($isPetrolMileage) {
                $km = isset($validated['quantity']) && $validated['quantity'] !== '' ? (float) $validated['quantity'] : null;
                if ($km === null) {
                    return back()->withErrors(['quantity' => 'Enter the distance in km for the mileage claim.'])->withInput();
                }
                $rateApplied = ClaimRulesService::mileageRate($request->input('vehicle', 'car'));
                $computed = round($km * $rateApplied, 2);
                $quantity = $km;
                $unit = 'km';
            } else {
                $computed = ClaimRulesService::computeAmount($category, [
                    'quantity' => $validated['quantity'] ?? null,
                    'vehicle' => $request->input('vehicle', 'car'),
                ]);
                if ($computed === null) {
                    $unitLabel = ClaimRulesService::unitFor($category) ?? 'quantity';

                    return back()->withErrors(['quantity' => 'Please enter the '.$unitLabel.' for '.$category->name.'.'])->withInput();
                }
                $quantity = (float) $validated['quantity'];
                $unit = ClaimRulesService::unitFor($category);
                $rateApplied = $category->rate_type === 'per_km'
                    ? ClaimRulesService::mileageRate($request->input('vehicle', 'car'))
                    : ($category->rate_amount !== null ? (float) $category->rate_amount : null);
            }

            $validated['amount'] = number_format($computed, 2, '.', '');
            $validated['gst_amount'] = 0;
            $validated['total_with_gst'] = $validated['amount'];
        }

        // ── Duplicate item detection (same date + description + amount across active claims) ──
        $cleanDescription = strip_tags($validated['description']);
        $duplicateItem = ExpenseClaimItem::whereHas('claim', function ($q) use ($employee) {
            $q->where('employee_id', $employee->id);
        })
            ->where('expense_date', $validated['expense_date'])
            ->where('description', $cleanDescription)
            ->where('amount', $validated['amount'])
            ->first();

        if ($duplicateItem) {
            return back()->withErrors([
                'description' => 'A similar expense already exists (same date, description & amount) in claim '.$duplicateItem->claim->claim_number.'.',
            ])->withInput();
        }

        // Handle receipt upload
        $receiptPath = null;
        $receiptHash = null;
        if ($request->hasFile('receipt')) {
            // ── Receipt duplicate detection via SHA-256 hash ──
            $receiptHash = hash_file('sha256', $request->file('receipt')->getRealPath());

            $existingReceipt = ExpenseClaimItem::whereHas('claim', function ($q) use ($employee) {
                $q->where('employee_id', $employee->id);
            })
                ->where('receipt_hash', $receiptHash)
                ->first();

            if ($existingReceipt) {
                return back()->withErrors([
                    'receipt' => 'This receipt has already been uploaded in claim '.$existingReceipt->claim->claim_number.'.',
                ])->withInput();
            }

            $receiptPath = $request->file('receipt')->store(
                'claim_receipts/'.$employee->id.'/'.$expenseDate->format('Y-m'),
                'local'
            );
        }

        // Validate total integrity — server-side check that total = amount + GST
        $expectedTotal = round((float) $validated['amount'] + (float) ($validated['gst_amount'] ?? 0), 2);
        if (abs($expectedTotal - (float) $validated['total_with_gst']) > 0.01) {
            if ($receiptPath) {
                Storage::disk('local')->delete($receiptPath);
            }

            return back()->withErrors(['total_with_gst' => 'Total does not match amount + GST.'])->withInput();
        }

        // Period-aware (monthly/annual) + role-based cap enforcement
        $capError = ClaimRulesService::capError($employee, $category, (float) $validated['amount'], $expenseDate);
        if ($capError) {
            if ($receiptPath) {
                Storage::disk('local')->delete($receiptPath);
            }

            return back()->withErrors(['amount' => $capError])->withInput();
        }

        $claim->items()->create([
            'expense_category_id' => $validated['expense_category_id'],
            'expense_date' => $validated['expense_date'],
            'description' => $cleanDescription,
            'project_client' => $validated['project_client'] ? strip_tags($validated['project_client']) : null,
            'amount' => $validated['amount'],
            'quantity' => $quantity,
            'unit' => $unit,
            'rate_applied' => $rateApplied,
            'gst_amount' => $validated['gst_amount'] ?? 0,
            'total_with_gst' => $expectedTotal,
            'receipt_path' => $receiptPath,
            'receipt_hash' => $receiptHash,
        ]);

        $claim->recalculateTotals();

        return redirect()->route('user.claims.index', ['month' => $claimMonth, 'year' => $claimYear])
            ->with('success', 'Expense item added to '.$monthStart->format('F Y').' claim.');
    }

    /**
     * Remove an item from a draft claim.
     */
    public function removeItem(ExpenseClaimItem $item)
    {
        $employee = Auth::user()->employee;
        $claim = $item->claim;

        if (! $claim || $claim->employee_id !== $employee->id) {
            abort(403);
        }

        if (! $claim->isEditable() || $item->is_locked) {
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

        if (! $claim->isSubmittable()) {
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

        if (! in_array($claim->status, ['submitted'])) {
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
        if (! $employee) {
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
        if ($claim->manager_id !== $employee->id && ! Auth::user()->isSuperadmin()) {
            abort(403);
        }

        // Verify the approver is the CURRENT manager (relationship may have changed)
        $claim->employee->refresh();
        if ($claim->employee->manager_id !== $employee->id && ! Auth::user()->isSuperadmin()) {
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
        if ($claim->employee->manager_id !== $employee->id && ! Auth::user()->isSuperadmin()) {
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

        // Filters — exclude drafts by default; only show when explicitly selected
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'draft');
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
            $query->whereHas('employee', fn ($q) => $q->where('company', $company));
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

        // Exclude drafts from export unless explicitly filtered
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'draft');
        }
        if ($year = $request->input('year')) {
            $query->where('year', $year);
        }
        if ($month = $request->input('month')) {
            $query->where('month', $month);
        }

        $claims = $query->orderBy('employee_id')->orderBy('year')->orderBy('month')->get();

        $filename = 'expense_claims_'.now()->format('Y-m-d_His').'.csv';

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
                        $claim->year.'-'.str_pad($claim->month, 2, '0', STR_PAD_LEFT),
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
            'code' => 'required|string|max:30|unique:expense_categories,code,'.$category->id,
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

    /**
     * Look up driving distance from the configured origin (Jaya One) to a typed
     * destination, for the Petrol "claim by mileage" mode. Provider is selected by
     * config('claims.distance.provider'): 'ors' (OpenRouteService — free, no card)
     * or 'google' (Distance Matrix). Config-gated end-to-end: with no key, returns
     * enabled=false so the form falls back to manual km entry. Never blocks a claim.
     */
    public function mileageDistance(Request $request)
    {
        $request->validate(['destination' => 'required|string|max:255']);

        $origin = config('claims.mileage.origin');

        if (config('claims.distance.provider', 'google') === 'ors') {
            return $this->mileageDistanceOrs($request->destination, $origin);
        }

        $key = config('claims.google_maps.key');
        if (! $key) {
            return response()->json(['enabled' => false]);
        }

        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(10)->get(
                'https://maps.googleapis.com/maps/api/distancematrix/json',
                [
                    'origins' => $origin,
                    'destinations' => $request->destination,
                    'units' => 'metric',
                    'key' => $key,
                ]
            );
            $data = $resp->json();
            $element = $data['rows'][0]['elements'][0] ?? null;

            if (($data['status'] ?? '') !== 'OK' || ! $element || ($element['status'] ?? '') !== 'OK') {
                return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Could not find that destination.']);
            }

            $km = round(($element['distance']['value'] ?? 0) / 1000, 1);

            return response()->json([
                'enabled' => true,
                'ok' => true,
                'km' => $km,
                'text' => $element['distance']['text'] ?? ($km.' km'),
                'origin' => $origin,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Distance lookup failed — please enter km manually.']);
        }
    }

    /**
     * OpenRouteService driving distance (free, no credit card): geocode the origin
     * + destination to coordinates, then ask the directions API for the driving
     * distance. Fails open — any missing key/error returns ok=false and the user
     * types km manually. Origin coords can be pinned via config to skip a geocode.
     */
    private function mileageDistanceOrs(string $destination, ?string $origin)
    {
        $key = config('claims.distance.ors_key');
        if (! $key) {
            return response()->json(['enabled' => false]);
        }

        try {
            // Origin: use pinned "lat,lon" from config if present, else geocode the string.
            $originCoords = $this->orsParseCoords(config('claims.mileage.origin_coords'))
                ?? $this->orsGeocode($key, (string) $origin);
            if (! $originCoords) {
                return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Could not locate the origin — enter km manually.']);
            }

            $destCoords = $this->orsGeocode($key, $destination);
            if (! $destCoords) {
                return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Could not find that destination — check the spelling or enter km manually.']);
            }

            $resp = Http::timeout(12)
                ->withHeaders(['Authorization' => $key, 'Content-Type' => 'application/json'])
                ->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                    'coordinates' => [$originCoords, $destCoords],
                ]);

            $meters = $resp->json('routes.0.summary.distance');
            if (! $resp->successful() || $meters === null) {
                return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Distance lookup failed — please enter km manually.']);
            }

            $km = round($meters / 1000, 1);

            return response()->json([
                'enabled' => true,
                'ok' => true,
                'km' => $km,
                'text' => $km.' km',
                'origin' => $origin,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ORS mileage distance failed', ['error' => $e->getMessage()]);

            return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Distance lookup failed — please enter km manually.']);
        }
    }

    /** Geocode free text to ORS [lon, lat], restricted to Malaysia; null if not found. */
    private function orsGeocode(string $key, string $text): ?array
    {
        if (trim($text) === '') {
            return null;
        }
        $resp = Http::timeout(10)->get('https://api.openrouteservice.org/geocode/search', [
            'api_key' => $key,
            'text' => $text,
            'boundary.country' => 'MY',
            'size' => 1,
        ]);
        $coords = $resp->json('features.0.geometry.coordinates'); // [lon, lat]

        return (is_array($coords) && count($coords) === 2)
            ? [(float) $coords[0], (float) $coords[1]]
            : null;
    }

    /** Parse a config "lat,lon" string into ORS [lon, lat] order; null if invalid. */
    private function orsParseCoords(?string $latLon): ?array
    {
        if (! $latLon) {
            return null;
        }
        $parts = array_map('trim', explode(',', $latLon));
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        return [(float) $parts[1], (float) $parts[0]]; // config is lat,lon → ORS wants lon,lat
    }

    /**
     * OCR a just-uploaded receipt to pre-fill amount/date/vendor. Config-gated:
     * with OCR disabled or no AI key, returns enabled=false and the form leaves
     * fields for manual entry. Never stores the file — reads the temp upload only.
     */
    public function scanReceipt(Request $request)
    {
        $company = Auth::user()->employee?->company;

        if (! ClaimReceiptOcrService::enabled($company)) {
            return response()->json(['enabled' => false]);
        }

        $request->validate([
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ]);

        // Offer the employee's eligible categories so the AI can also classify the receipt.
        $employee = Auth::user()->employee;
        $categories = $employee ? ClaimRulesService::categoriesFor($employee) : collect();
        $catList = $categories->map(fn ($c) => ['code' => $c->code, 'name' => $c->name])->all();

        $file = $request->file('receipt');
        $data = ClaimReceiptOcrService::extract($file->getRealPath(), $file->getMimeType(), $company, $catList);

        if ($data === null) {
            return response()->json(['enabled' => true, 'ok' => false]);
        }

        // Map the validated category code back to the dropdown's id so the form can pre-select it.
        if (! empty($data['category'])) {
            $match = $categories->firstWhere('code', $data['category']);
            $data['category_id'] = $match?->id;
            $data['category_name'] = $match?->name;
        }

        return response()->json(array_merge(['enabled' => true, 'ok' => true], $data));
    }

    private function getOrCreateDraft(Employee $employee, int $year, int $month): ExpenseClaim
    {
        return ExpenseClaim::firstOrCreate(
            ['employee_id' => $employee->id, 'year' => $year, 'month' => $month],
            [
                'claim_number' => ExpenseClaim::generateClaimNumber($year, $month),
                'title' => Carbon::create($year, $month)->format('F Y').' — '.$employee->full_name,
                'status' => 'draft',
                'submission_deadline' => ClaimRulesService::submissionDeadline(
                    ExpenseClaimPolicy::forCompany($employee->company)->submission_deadline_day,
                    Carbon::create($year, $month, 1)
                ),
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
            'pending' => ExpenseClaim::whereIn('status', ['submitted', 'manager_approved'])->count(),
            'approved' => ExpenseClaim::where('status', 'hr_approved')->count(),
            'total_approved' => ExpenseClaim::where('status', 'hr_approved')
                ->whereYear('created_at', now()->year)
                ->sum('total_with_gst'),
            'total' => ExpenseClaim::where('status', '!=', 'draft')->count(),
        ];
    }

    private function authorizeViewClaims(): void
    {
        if (! Auth::user()->canViewAllClaims()) {
            abort(403, 'You do not have permission to view all claims.');
        }
    }

    private function authorizeManageClaims(): void
    {
        if (! Auth::user()->canManageClaims()) {
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
            return "'".$value;
        }

        return $value;
    }
}
