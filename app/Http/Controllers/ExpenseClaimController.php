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

        $company = \App\Models\Company::forName($employee->company);

        return view('user.claims.index', compact('employee', 'claims', 'currentClaim', 'categories', 'policy', 'year', 'month', 'company'));
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
            'mileage_destination' => 'nullable|string|max:255',
            'mileage_origin' => 'nullable|string|max:255',
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

        // Petrol is always a per-km mileage claim (car/motorcycle rate). The origin
        // and destination are chosen by the employee; distance is the evidence, not a
        // receipt. (The legacy "by receipt" Petrol mode has been removed.)
        $mileageGl = config('claims.mileage.gl_code');
        $isPetrolMileage = $mileageGl && $category->gl_code === $mileageGl;

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
            'mileage_destination' => $isPetrolMileage ? mb_substr(strip_tags((string) $request->input('mileage_destination')), 0, 255) : null,
            'mileage_origin' => $isPetrolMileage ? (mb_substr(strip_tags((string) $request->input('mileage_origin')), 0, 255) ?: null) : null,
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
    /**
     * Claim Reports — view-only tracking page. The employee's submitted claims,
     * each grouped by approving manager, with live status and the full audit log.
     */
    public function reports(Request $request)
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $claims = ExpenseClaim::where('employee_id', $employee->id)
            ->where('status', '!=', 'draft')
            ->with(['items.category', 'items.approver', 'logs'])
            ->orderByDesc('year')->orderByDesc('month')
            ->get();

        $company = \App\Models\Company::forName($employee->company);

        return view('user.claims.reports', compact('employee', 'claims', 'company'));
    }

    /**
     * Printable EXPENSES CLAIMS FORM for a claim (optionally a single approver's
     * group via ?approver=). Access: the owner, a reviewer, or HR.
     */
    public function printReport(Request $request, ExpenseClaim $claim)
    {
        $user = Auth::user();
        $isOwner = $user->employee && $claim->employee_id === $user->employee->id;
        if (! $isOwner && ! $user->canViewAllClaims()) {
            $this->authorizeReview($claim); // claim's manager, else 403
        }

        $claim->load(['items.category', 'items.approver', 'employee']);
        $company = \App\Models\Company::forName($claim->employee->company);

        $approverId = $request->query('approver');
        $items = $approverId ? $claim->items->where('approver_id', (int) $approverId)->values() : $claim->items;
        $approver = $approverId ? Employee::find((int) $approverId) : null;

        return view('user.claims.report-print', compact('claim', 'company', 'items', 'approver'));
    }

    /** Save the claim's Event/purpose (header field). Creates the month's draft if needed. */
    public function saveDetails(Request $request)
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return back()->with('error', 'No employee profile found.');
        }
        $data = $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
            'event' => 'nullable|string|max:255',
        ]);
        $now = Carbon::now();
        if ((int) $data['year'] !== $now->year || (int) $data['month'] > $now->month) {
            return back()->with('error', 'Invalid claim month.');
        }

        $claim = $this->getOrCreateDraft($employee, (int) $data['year'], (int) $data['month']);
        if (! $claim->isEditable()) {
            return back()->with('error', 'This claim can no longer be edited.');
        }
        $claim->update(['event' => $data['event'] ? mb_substr(strip_tags($data['event']), 0, 255) : null]);

        return redirect()->route('user.claims.index', ['month' => $data['month'], 'year' => $data['year']])
            ->with('success', 'Event saved.');
    }

    /**
     * Submit step — pick the approving manager for each item before sending.
     * Defaults to the employee's reporting manager; event/programme items can be
     * routed to a different manager.
     */
    public function submitForm(ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;
        if ($claim->employee_id !== $employee->id) {
            abort(403);
        }
        if (! $claim->isSubmittable()) {
            return redirect()->route('user.claims.index', ['month' => $claim->month, 'year' => $claim->year])
                ->with('error', 'This claim cannot be submitted. Ensure it has at least one item.');
        }

        $claim->load('items.category');
        $approvers = ClaimRulesService::eligibleApprovers();
        $defaultApproverId = ClaimRulesService::defaultApproverId($employee);

        return view('user.claims.submit', compact('claim', 'approvers', 'defaultApproverId', 'employee'));
    }

    public function submit(Request $request, ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;

        if ($claim->employee_id !== $employee->id) {
            abort(403);
        }

        if (! $claim->isSubmittable()) {
            return back()->with('error', 'This claim cannot be submitted. Ensure it has at least one item.');
        }

        // Every item must be routed to an eligible (loggable) approving manager.
        $eligibleIds = ClaimRulesService::eligibleApprovers()->pluck('id')->all();
        $chosen = $request->input('approvers', []); // [item_id => approver employee id]
        $claim->load('items');
        foreach ($claim->items as $item) {
            if (! in_array((int) ($chosen[$item->id] ?? 0), $eligibleIds, true)) {
                return redirect()->route('user.claims.submit-form', $claim)
                    ->with('error', 'Please choose an approving manager for every item.');
            }
        }

        $claim->recalculateTotals();

        DB::transaction(function () use ($claim, $chosen) {
            foreach ($claim->items as $item) {
                $item->update([
                    'approver_id' => (int) $chosen[$item->id],
                    'manager_status' => 'pending',
                    'manager_remarks' => null,
                    'review_status' => 'approved', // reset HR stage in case of a resubmit
                    'is_locked' => true,
                ]);
            }
            $claim->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'manager_id' => $claim->employee->manager_id, // legacy/reporting reference only
            ]);
        });

        $this->logClaim($claim, 'submitted', 'Submitted to '.count($claim->approverIds()).' approving manager(s).');

        // Notify each DISTINCT approving manager that they have items to review.
        $claim->load('items');
        foreach ($claim->approverIds() as $approverId) {
            $manager = Employee::find($approverId);
            if ($manager && $manager->user) {
                Mail::to($manager->user->work_email)->send(
                    new ClaimSubmittedMail($claim, $employee, 'manager')
                );
            }
        }

        return redirect()->route('user.claims.index', ['month' => $claim->month, 'year' => $claim->year])
            ->with('success', 'Claim submitted — each item routed to its approving manager.');
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

        $myId = $employee->id;

        // Claims with at least one item routed to me that still awaits my decision.
        $pendingClaims = ExpenseClaim::where('status', 'submitted')
            ->whereHas('items', fn ($q) => $q->where('approver_id', $myId)->where('manager_status', 'pending'))
            ->with(['employee', 'items.category', 'items.approver'])
            ->orderBy('submitted_at')
            ->get();

        // History: claims I approved items on that have moved past the manager stage.
        $historyClaims = ExpenseClaim::whereNotIn('status', ['draft', 'submitted'])
            ->whereHas('items', fn ($q) => $q->where('approver_id', $myId))
            ->with(['employee', 'items.category'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return view('user.claims.team', compact('pendingClaims', 'historyClaims', 'employee'));
    }

    /**
     * Manager approves a submitted claim.
     */
    public function managerApprove(Request $request, ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;

        if ($claim->status !== 'submitted') {
            return back()->with('error', 'This claim is not pending approval.');
        }

        // A manager only decides the items routed to THEM (per-item approver).
        $claim->load('items');
        $myItems = $claim->items->where('approver_id', $employee->id)->where('manager_status', 'pending');
        if ($myItems->isEmpty() && ! Auth::user()->isSuperadmin()) {
            abort(403, 'You have no items to review on this claim.');
        }

        $rejectedIds = collect($request->input('rejected_items', []))->map(fn ($id) => (int) $id)->all();
        $remarks = $request->input('item_remarks', []);

        DB::transaction(function () use ($myItems, $rejectedIds, $remarks) {
            foreach ($myItems as $item) {
                if (in_array($item->id, $rejectedIds, true)) {
                    $item->update([
                        'manager_status' => 'rejected',
                        'manager_remarks' => isset($remarks[$item->id]) ? mb_substr(strip_tags((string) $remarks[$item->id]), 0, 500) : null,
                    ]);
                } else {
                    $item->update(['manager_status' => 'approved']);
                }
            }
        });

        $myRejected = $myItems->filter(fn ($it) => in_array($it->id, $rejectedIds, true))->count();
        $myApproved = $myItems->count() - $myRejected;
        $this->logClaim($claim, $myApproved > 0 ? 'manager_approved' : 'manager_rejected',
            $employee->full_name.' reviewed their items: '.$myApproved.' approved, '.$myRejected.' rejected.');

        Log::info('Claim manager-reviewed (per-item)', [
            'claim_id' => $claim->id, 'claim_number' => $claim->claim_number,
            'my_items' => $myItems->count(), 'actor_id' => Auth::id(),
        ]);

        return back()->with('success', $this->finalizeManagerStage($claim, $employee));
    }

    /**
     * Roll the claim up after a manager decides their items: if every item now has
     * a manager decision, advance to manager_approved (→ HR) or manager_rejected
     * (all items rejected). Otherwise it stays submitted, waiting on other managers.
     */
    private function finalizeManagerStage(ExpenseClaim $claim, Employee $employee): string
    {
        $claim->load('items');

        if (! $claim->allItemsManagerDecided()) {
            return 'Your decision was saved — waiting on '.$claim->managerPendingCount().' more item(s) from other managers.';
        }

        $anyApproved = $claim->items->where('manager_status', 'approved')->isNotEmpty();

        if ($anyApproved) {
            $claim->update([
                'status' => 'manager_approved',
                'manager_approved_by' => $employee->id,
                'manager_approved_at' => now(),
            ]);
            if ($claim->employee->user) {
                Mail::to($claim->employee->user->work_email)->send(new ClaimApprovedMail($claim, $claim->employee, 'manager'));
            }
            $this->notifyHr($claim, 'pending_hr_approval');
            $this->logClaim($claim, 'manager_stage_done', 'All managers reviewed — sent to HR (payable RM '.number_format($claim->approvedTotal(), 2).').');

            return $claim->hasRejectedItems()
                ? 'All items reviewed — '.$claim->rejectedItemCount().' rejected, the rest approved and sent to HR (payable RM '.number_format($claim->approvedTotal(), 2).').'
                : 'All items approved — claim sent to HR.';
        }

        // Every item was rejected across all managers — return to the employee.
        $claim->update([
            'status' => 'manager_rejected',
            'manager_approved_by' => $employee->id,
            'manager_approved_at' => now(),
        ]);
        $claim->items()->update(['is_locked' => false]);
        if ($claim->employee->user) {
            Mail::to($claim->employee->user->work_email)->send(new ClaimRejectedMail($claim, $claim->employee, 'manager'));
        }
        $this->logClaim($claim, 'manager_rejected', 'Every item was rejected — claim returned to the employee.');

        return 'All items were rejected — the claim was returned to the employee.';
    }

    /**
     * Apply per-item approve/reject decisions from the review form. Items whose id
     * is in `rejected_items[]` are marked rejected (reason from `item_remarks[id]`,
     * reusing the item's `remarks` column); all others are approved. Returns an
     * error string if EVERY item would be rejected (use the whole-claim Reject
     * instead), otherwise null.
     */
    private function applyItemReviews(ExpenseClaim $claim, Request $request): ?string
    {
        $rejectedIds = collect($request->input('rejected_items', []))->map(fn ($id) => (int) $id)->all();
        $remarks = $request->input('item_remarks', []);

        if ($claim->items->isNotEmpty()
            && $claim->items->reject(fn ($it) => in_array($it->id, $rejectedIds, true))->isEmpty()) {
            return 'Every item was rejected — use the Reject button to reject the whole claim instead.';
        }

        foreach ($claim->items as $item) {
            if (in_array($item->id, $rejectedIds, true)) {
                $item->update([
                    'review_status' => 'rejected',
                    'remarks' => isset($remarks[$item->id]) ? mb_substr(strip_tags((string) $remarks[$item->id]), 0, 500) : $item->remarks,
                ]);
            } else {
                $item->update(['review_status' => 'approved']);
            }
        }
        $claim->load('items');

        return null;
    }

    /** Success message that notes a partial approval when some items were rejected. */
    private function approvalMessage(ExpenseClaim $claim): string
    {
        if ($claim->hasRejectedItems()) {
            return $claim->rejectedItemCount().' item(s) rejected — claim approved for the rest (payable RM '
                .number_format($claim->approvedTotal(), 2).').';
        }

        return 'Claim approved.';
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

        // Reject all of MY pending items on this claim with one reason, then roll up.
        $claim->load('items');
        $myItems = $claim->items->where('approver_id', $employee->id)->where('manager_status', 'pending');
        if ($myItems->isEmpty() && ! Auth::user()->isSuperadmin()) {
            abort(403, 'You have no items to reject on this claim.');
        }

        $reason = mb_substr(strip_tags($request->input('remarks')), 0, 500);

        DB::transaction(function () use ($myItems, $reason) {
            foreach ($myItems as $item) {
                $item->update(['manager_status' => 'rejected', 'manager_remarks' => $reason]);
            }
        });

        $this->logClaim($claim, 'manager_rejected', $employee->full_name.' rejected their '.$myItems->count().' item(s): '.$reason);

        return back()->with('success', $this->finalizeManagerStage($claim, $employee));
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

        $claim->load(['employee', 'items.category', 'items.approver', 'manager', 'managerApprover', 'hrApprover']);

        // Employee spend context (#7) — this employee's claim history for the claim's year.
        $yearClaims = ExpenseClaim::where('employee_id', $claim->employee_id)
            ->where('year', $claim->year)
            ->get(['id', 'status', 'total_with_gst']);
        $approved = $yearClaims->whereIn('status', ['hr_approved', 'paid']);
        $spendStats = [
            'year' => $claim->year,
            'approved_total' => (float) $approved->sum('total_with_gst'),
            'pending_total' => (float) $yearClaims->whereIn('status', ['submitted', 'manager_approved'])->sum('total_with_gst'),
            'claim_count' => $yearClaims->whereNotIn('status', ['draft', 'cancelled'])->count(),
            'avg_claim' => $approved->count() ? (float) $approved->sum('total_with_gst') / $approved->count() : 0.0,
        ];

        return view('hr.claims.show', compact('claim', 'spendStats'));
    }

    /**
     * HR: Approve a manager-approved claim.
     */
    public function hrApprove(Request $request, ExpenseClaim $claim)
    {
        $this->authorizeManageClaims();

        if ($claim->status !== 'manager_approved') {
            return back()->with('error', 'This claim is not pending HR approval.');
        }

        // HR is the final gate — it can reject further line items before approving.
        if ($error = $this->applyItemReviews($claim, $request)) {
            return back()->with('error', $error);
        }

        $claim->update([
            'status' => 'hr_approved',
            'hr_approved_by' => Auth::id(),
            'hr_approved_at' => now(),
        ]);

        Log::info('Claim hr-approved', [
            'claim_id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'payable' => $claim->approvedTotal(),
            'rejected_items' => $claim->rejectedItemCount(),
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

        $this->logClaim($claim, 'hr_approved', 'HR approved — payable RM '.number_format($claim->approvedTotal(), 2).'.');

        return back()->with('success', 'HR approved. '.$this->approvalMessage($claim));
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

        $this->logClaim($claim, 'hr_rejected', 'HR rejected: '.$remarks);

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
        $request->validate([
            'destination' => 'required|string|max:255',
            'origin' => 'required|string|max:255',
        ]);

        // The employee now chooses the starting point (no fixed origin).
        $origin = trim(strip_tags((string) $request->input('origin')));

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
            // The origin is the employee-entered "From"; geocode it directly.
            $originCoords = $this->orsGeocode($key, (string) $origin);
            if (! $originCoords) {
                return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Could not locate the starting point — check the spelling or enter km manually.']);
            }

            // Bias the destination geocode toward the origin so ambiguous short names
            // resolve to the nearest match, not a same-named place far away.
            $destCoords = $this->orsGeocode($key, $destination, $originCoords);
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

    /**
     * Geocode free text to ORS [lon, lat], restricted to Malaysia; null if not found.
     * $focus ([lon, lat]) biases results toward that point so an ambiguous short name
     * (e.g. "Suria KLCC") resolves to the nearest match instead of a same-named place
     * hundreds of km away. Pass the origin coords when geocoding a destination.
     */
    private function orsGeocode(string $key, string $text, ?array $focus = null): ?array
    {
        if (trim($text) === '') {
            return null;
        }
        $params = [
            'api_key' => $key,
            'text' => $text,
            'boundary.country' => 'MY',
            'size' => 1,
        ];
        if ($focus && count($focus) === 2) {
            $params['focus.point.lon'] = $focus[0];
            $params['focus.point.lat'] = $focus[1];
            // Hard-restrict to a circle around the origin. focus.point alone is too
            // weak — it still scored "Suria Hotel" (Kelantan, 400+ km) over the real
            // "Suria KLCC". The circle excludes same-named places in other states so a
            // short landmark resolves to the nearby one. Trips beyond the radius simply
            // return no match and fall back to manual km / the screenshot's own value.
            $params['boundary.circle.lon'] = $focus[0];
            $params['boundary.circle.lat'] = $focus[1];
            $params['boundary.circle.radius'] = (float) config('claims.distance.max_radius_km', 150);
        }
        $resp = Http::timeout(10)->get('https://api.openrouteservice.org/geocode/search', $params);
        $coords = $resp->json('features.0.geometry.coordinates'); // [lon, lat]

        return (is_array($coords) && count($coords) === 2)
            ? [(float) $coords[0], (float) $coords[1]]
            : null;
    }

    /** Driving distance (km) from origin to destination via ORS; null on any failure. */
    private function orsDistanceKm(string $destination, ?string $origin): ?float
    {
        $key = config('claims.distance.ors_key');
        if (! $key || trim($destination) === '') {
            return null;
        }
        try {
            $originCoords = $this->orsGeocode($key, (string) $origin);
            $destCoords = $this->orsGeocode($key, $destination, $originCoords);
            if (! $originCoords || ! $destCoords) {
                return null;
            }
            $resp = Http::timeout(12)
                ->withHeaders(['Authorization' => $key, 'Content-Type' => 'application/json'])
                ->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                    'coordinates' => [$originCoords, $destCoords],
                ]);
            $meters = $resp->json('routes.0.summary.distance');

            return ($resp->successful() && $meters !== null) ? round($meters / 1000, 1) : null;
        } catch (\Throwable $e) {
            Log::warning('ORS verify distance failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Suggest place names for the From/To mileage fields (ORS autocomplete, MY only,
     * biased to the Klang Valley so local results rank first). Returns a flat list of
     * labels; config-gated on the ORS key — no key returns an empty list.
     */
    public function placeSuggest(Request $request)
    {
        $request->validate(['text' => 'required|string|max:255']);
        $text = trim($request->input('text'));
        $key = config('claims.distance.ors_key');
        if (! $key || mb_strlen($text) < 2) {
            return response()->json(['suggestions' => []]);
        }

        try {
            $resp = Http::timeout(8)->get('https://api.openrouteservice.org/geocode/autocomplete', [
                'api_key' => $key,
                'text' => $text,
                'boundary.country' => 'MY',
                'focus.point.lon' => 101.6869, // Klang Valley centre — ranks nearby places first
                'focus.point.lat' => 3.1390,
                'size' => 6,
            ]);
            $features = $resp->successful() ? ($resp->json('features') ?? []) : [];
            $labels = [];
            foreach ($features as $f) {
                $label = $f['properties']['label'] ?? null;
                if ($label && ! in_array($label, $labels, true)) {
                    $labels[] = $label;
                }
            }

            return response()->json(['suggestions' => $labels]);
        } catch (\Throwable $e) {
            return response()->json(['suggestions' => []]);
        }
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

    /**
     * Reviewer verification for a single item: cross-check the receipt amount via
     * OCR (#1) and re-calculate the mileage distance via ORS (#2). Returns flags
     * the review UI renders; assistive only — never changes the claim. Restricted
     * to HR/admins and the claim's manager.
     */
    public function verifyItem(ExpenseClaimItem $item)
    {
        $claim = $item->claim;
        if (! $claim) {
            abort(404);
        }
        $this->authorizeReview($claim);

        $result = ['receipt' => null, 'mileage' => null];

        // #1 — Receipt total vs claimed total (OCR).
        if ($item->receipt_path && Storage::disk('local')->exists($item->receipt_path)) {
            $company = $claim->employee->company ?? null;
            if (ClaimReceiptOcrService::enabled($company)) {
                $abs = Storage::disk('local')->path($item->receipt_path);
                $mime = Storage::disk('local')->mimeType($item->receipt_path);
                $data = ClaimReceiptOcrService::extract($abs, $mime, $company);
                if ($data && $data['amount'] !== null) {
                    $receiptAmt = (float) $data['amount'];
                    $claimed = (float) $item->total_with_gst;
                    $result['receipt'] = [
                        'ok' => true,
                        'receipt_amount' => $receiptAmt,
                        'claimed' => $claimed,
                        // tolerance: 50 sen or 2% of the claimed amount, whichever is larger
                        'match' => abs($receiptAmt - $claimed) <= max(0.50, $claimed * 0.02),
                        'vendor' => $data['vendor'] ?? null,
                    ];
                } else {
                    $result['receipt'] = ['ok' => false]; // couldn't read the receipt
                }
            } else {
                $result['receipt'] = ['ok' => false, 'disabled' => true];
            }
        }

        // #2 — Claimed km vs the system-calculated driving distance (ORS).
        if ($item->isMileage() && $item->mileage_destination) {
            $km = $this->orsDistanceKm($item->mileage_destination, $item->mileage_origin ?: config('claims.mileage.origin'));
            if ($km !== null) {
                $claimedKm = (float) $item->quantity;
                $result['mileage'] = [
                    'ok' => true,
                    'calc_km' => $km,
                    'claimed_km' => $claimedKm,
                    'destination' => $item->mileage_destination,
                    // flag if claimed is more than 15% (or 1 km) above the calculated distance
                    'match' => $claimedKm <= $km + max(1.0, $km * 0.15),
                ];
            } else {
                $result['mileage'] = ['ok' => false];
            }
        }

        return response()->json($result);
    }

    /**
     * Stream a claim item's receipt with a human-readable filename
     * (e.g. "EC-2026-06-0002-caltex.jpg") so reviewers can tell receipts apart,
     * instead of the random storage hash. Access: the owner, or a reviewer.
     */
    public function viewReceipt(ExpenseClaimItem $item)
    {
        $claim = $item->claim;
        if (! $claim || ! $item->receipt_path) {
            abort(404);
        }

        $user = Auth::user();
        $isOwner = $user->employee && $claim->employee_id === $user->employee->id;
        if (! $isOwner) {
            $this->authorizeReview($claim); // HR/admin or the claim's manager (aborts 403 otherwise)
        }

        if (! Storage::disk('local')->exists($item->receipt_path)) {
            abort(404);
        }

        $ext = pathinfo($item->receipt_path, PATHINFO_EXTENSION) ?: 'jpg';
        $slug = \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit($item->description, 30, '')) ?: 'receipt';
        $name = $claim->claim_number.'-'.$slug.'.'.$ext;

        return Storage::disk('local')->download($item->receipt_path, $name, [
            'Content-Type' => Storage::disk('local')->mimeType($item->receipt_path),
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /** Append an audit-log entry for the claim lifecycle (shown on Claim Reports). */
    private function logClaim(ExpenseClaim $claim, string $action, ?string $detail = null): void
    {
        \App\Models\ExpenseClaimLog::create([
            'expense_claim_id' => $claim->id,
            'action' => $action,
            'actor_id' => Auth::id(),
            'actor_name' => Auth::user()->employee->full_name ?? Auth::user()->name ?? 'System',
            'detail' => $detail,
        ]);
    }

    /** Allow HR/admins and the employee's current manager to run verification. */
    private function authorizeReview(ExpenseClaim $claim): void
    {
        $user = Auth::user();
        if ($user->canViewAllClaims()) {
            return;
        }
        $emp = $user->employee;
        if ($emp && $claim->employee && $claim->employee->manager_id === $emp->id) {
            return;
        }
        abort(403, 'You are not allowed to verify this claim.');
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
