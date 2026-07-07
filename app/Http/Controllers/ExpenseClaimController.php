<?php

namespace App\Http\Controllers;

use App\Mail\ClaimApprovedMail;
use App\Mail\ClaimHrRejectedNoticeMail;
use App\Mail\ClaimRejectedMail;
use App\Mail\ClaimSubmittedMail;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimItem;
use App\Models\ExpenseClaimPolicy;
use App\Services\ClaimReceiptOcrService;
use App\Services\ClaimRulesService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    /**
     * My Claims — a LIST of the employee's claims, one per event. Drafts/rejected are
     * editable; submitted/done are read-only. Claims are no longer keyed to a month.
     */
    public function myClaims(Request $request)
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $claims = $employee->expenseClaims()->with(['items.category', 'correctionOf:id,claim_number'])->orderByDesc('created_at')->get();
        $policy = ExpenseClaimPolicy::forCompany($employee->company);
        $company = \App\Models\Company::forName($employee->company);

        $drafts = $claims->whereIn('status', ['draft', 'manager_rejected', 'hr_rejected'])->values();

        // Draft claims (one per event). The inline editor loads a draft ONLY when it is
        // explicitly opened via ?open (e.g. "Continue editing" from the list, or right after
        // creating one). On a plain load / refresh the form is empty — drafts are never lost,
        // they live (auto-saved) in the list below and reopen on demand.
        $draftClaims = $claims->where('status', 'draft')->sortByDesc('created_at')->values();
        $openId = $request->query('open');
        $activeDraft = $openId ? $draftClaims->firstWhere('id', (int) $openId) : null;

        // The year → month accordion holds ALL claims (drafts included, as summary rows).
        $byMonth = $claims
            ->sortByDesc(fn ($c) => $c->year * 100 + $c->month)
            ->groupBy(fn ($c) => sprintf('%04d-%02d', $c->year, $c->month));
        $currentYear = Carbon::now()->year;

        // Event-name suggestions (company-wide) to standardise events across staff.
        $companyEmpIds = Employee::where('company', $employee->company)->pluck('id');
        $eventSuggestions = ExpenseClaim::whereIn('employee_id', $companyEmpIds)
            ->whereNotNull('event')->where('event', '!=', '')
            ->pluck('event')->map(fn ($e) => trim($e))->filter()->unique()->sort()->values();

        // After the monthly cutoff (e.g. the 20th), submissions still work but may roll into
        // next month's processing — the view shows a heads-up banner when this is true.
        $deadlineDay = $policy->submission_deadline_day ?? 20;
        $pastCutoff = Carbon::now()->day > $deadlineDay;

        // Pipeline-stage counts for the top cards (all-time, this employee).
        $stageCounts = [
            'draft' => $claims->where('status', 'draft')->count(),
            'awaiting_manager' => $claims->where('status', 'submitted')->count(),
            'awaiting_hr' => $claims->where('status', 'manager_approved')->count(),
            'completed' => $claims->whereIn('status', ['hr_approved', 'paid'])->count(),
        ];

        // For the inline claim builder: the categories this employee may file under and
        // who can approve (Category B). The approver list is ALL active employees (not
        // just managers) — a manager may ask the event lead to sign.
        $categories = ClaimRulesService::categoriesFor($employee);
        $approvers = ClaimRulesService::signableApprovers();
        $defaultApproverId = ClaimRulesService::defaultApproverId($employee);
        $ocrEnabled = ClaimReceiptOcrService::enabled($employee->company);
        $projectRequired = ! self::isSalesTeam($employee);
        $openClaimId = $request->query('open');

        // Remaining allowance per capped category (e.g. intern Medical RM100/mo) so the inline
        // form can preview the claimable amount and auto-cap before the item is even added.
        $capInfo = [];
        foreach ($categories as $c) {
            $lim = ClaimRulesService::effectiveLimit($c, $employee);
            if ($lim) {
                $used = ClaimRulesService::usedInPeriod($employee, $c, Carbon::now(), $lim['period']);
                $capInfo[$c->id] = [
                    'remaining' => round(max(0, $lim['amount'] - $used), 2),
                    'limit' => (float) $lim['amount'],
                    'period' => $lim['period'],
                    'name' => $c->name,
                ];
            }
        }

        return view('user.claims.index', compact('employee', 'claims', 'drafts', 'draftClaims', 'activeDraft', 'byMonth', 'currentYear', 'eventSuggestions', 'policy', 'company', 'stageCounts', 'deadlineDay', 'pastCutoff', 'categories', 'approvers', 'defaultApproverId', 'ocrEnabled', 'projectRequired', 'openClaimId', 'capInfo'));
    }

    /**
     * Start (or resume) the single in-progress draft claim, then open it inline. Only
     * one draft exists at a time — if one is already open, we reuse it rather than
     * stacking a new one (the form is one continuous claim builder).
     */
    public function createClaim(Request $request)
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $data = $request->validate([
            'event' => 'nullable|string|max:255',
            'period' => 'nullable|date_format:Y-m', // reporting month this claim is filed under
            'manager_id' => 'nullable|integer',
            'event_date' => 'nullable|date|before_or_equal:today',
            'project_client' => 'nullable|string|max:255',
        ]);

        $now = Carbon::now();
        $period = ! empty($data['period']) ? Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth() : $now->copy();
        if ($period->greaterThan($now)) {
            $period = $now->copy();
        }

        // A claim can only be filed for a month that is still open: the current year, or
        // (until the January grace day) the previous year. Blocks reviving a closed year.
        if (! ClaimRulesService::isPeriodOpenForFiling($period->year, $period->month, $now)) {
            $graceDay = (int) config('claims.year_end_grace_day', 20);

            return back()->with('error',
                'You can only file claims for '.$now->year.'. '
                .($now->year - 1).' claims are closed (they could be filed only up to '
                .$graceDay.' January '.$now->year.'). Please pick a month in '.$now->year.'.'
            );
        }

        // Reuse an EMPTY draft (0 items) so repeated clicks don't pile up blanks — but ALWAYS
        // re-stamp it to the CHOSEN reporting month, so the month picked in the picker is what
        // the claim is filed under (and its number/deadline follow that month).
        $emptyDraft = $employee->expenseClaims()->where('status', 'draft')->where('item_count', 0)->latest()->first();
        if ($emptyDraft) {
            if ((int) $emptyDraft->year !== $period->year || (int) $emptyDraft->month !== $period->month) {
                // Only relabel a still-default "General Claim …" name; keep a custom event.
                $reEvent = preg_match('/^general claim/i', (string) $emptyDraft->event)
                    ? 'General Claim '.$period->format('F')
                    : $emptyDraft->event;
                // Re-stamping to a new period needs a NEW number from that period's sequence —
                // allocate it under a lock+retry so it can't collide with a concurrent creator.
                $deadline = ClaimRulesService::submissionDeadline(
                    ExpenseClaimPolicy::forCompany($employee->company)->submission_deadline_day,
                    $period->copy()
                );
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        DB::transaction(function () use ($emptyDraft, $period, $reEvent, $employee, $deadline) {
                            $emptyDraft->update([
                                'year' => $period->year,
                                'month' => $period->month,
                                'event' => $reEvent,
                                'title' => $reEvent.' — '.$employee->full_name,
                                'claim_number' => ExpenseClaim::nextClaimNumber($period->year, $period->month, true),
                                'submission_deadline' => $deadline,
                            ]);
                        });
                        break;
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        usleep(random_int(5_000, 25_000));
                    }
                }
            }

            return redirect()->route('user.claims.index', ['open' => $emptyDraft->id]);
        }

        // A claim starts with sensible defaults; the employee fills in the Category B
        // details (event, approver, date, project) inside the claim card.
        $event = ! empty($data['event']) ? mb_substr(strip_tags($data['event']), 0, 255) : 'General Claim '.$period->format('F');
        // An employee can never be their own approver (isValidApproverFor excludes self).
        $managerId = ClaimRulesService::isValidApproverFor($employee->id, (int) ($data['manager_id'] ?? 0))
            ? (int) $data['manager_id']
            : ($employee->manager_id ?: ClaimRulesService::defaultApproverId($employee));
        if ($managerId === $employee->id) {
            $managerId = null;
        }

        $claim = ExpenseClaim::createWithClaimNumber([
            'employee_id' => $employee->id,
            'year' => $period->year,
            'month' => $period->month,
            'event' => $event,
            'event_date' => $data['event_date'] ?? null,
            'project_client' => ! empty($data['project_client']) ? mb_substr(strip_tags($data['project_client']), 0, 255) : null,
            'title' => $event.' — '.$employee->full_name,
            'status' => 'draft',
            'submission_deadline' => ClaimRulesService::submissionDeadline(
                ExpenseClaimPolicy::forCompany($employee->company)->submission_deadline_day,
                $period->copy()
            ),
            'manager_id' => $managerId,
        ]);

        return redirect()->route('user.claims.index', ['open' => $claim->id])
            ->with('success', 'New claim added — fill in the details and add items below.');
    }

    /**
     * Inline builder (My Claims): save the claim's Category B header — event name,
     * approving manager, date of event, project/client. Owner + draft only.
     */
    public function inlineSaveDetails(Request $request, ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;
        if (! $employee || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        if (! $claim->isEditable()) {
            return back()->with('error', 'This claim can no longer be edited.');
        }

        $data = $request->validate([
            'event' => 'required|string|max:255',
            'manager_id' => 'nullable|integer',
            'event_date' => 'nullable|date|before_or_equal:today',
            'project_client' => 'nullable|string|max:255',
        ]);

        // An employee can never be their own approver (isValidApproverFor excludes self).
        $managerId = ClaimRulesService::isValidApproverFor($employee->id, (int) ($data['manager_id'] ?? 0))
            ? (int) $data['manager_id']
            : $claim->manager_id;
        if ($managerId === $employee->id) {
            $managerId = null;
        }

        $claim->update([
            'event' => mb_substr(strip_tags($data['event']), 0, 255),
            'event_date' => $data['event_date'] ?? null,
            'project_client' => ! empty($data['project_client']) ? mb_substr(strip_tags($data['project_client']), 0, 255) : null,
            'manager_id' => $managerId,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'event' => $claim->eventName()]);
        }

        return redirect()->route('user.claims.index', ['open' => $claim->id])->with('success', 'Claim details saved.');
    }

    /**
     * Inline builder (My Claims): add one item to a draft claim. Project/client + the
     * approving manager are inherited from the claim's Category B; the date is per item
     * (each receipt keeps its own date, defaulting to the claim's event date). JSON.
     */
    public function inlineAddItem(Request $request, ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;
        if (! $employee || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        if (! $claim->isEditable()) {
            return response()->json(['ok' => false, 'message' => 'This claim can no longer be edited.'], 422);
        }

        $validated = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'description' => 'required|string|max:500',
            'expense_date' => 'nullable|date|before_or_equal:today|after_or_equal:'.now()->subMonths(18)->toDateString(),
            'amount' => 'nullable|numeric|min:0|max:99999.99',
            'gst_amount' => 'nullable|numeric|min:0|max:99999.99',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'receipt_attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'support_files' => 'nullable|array|max:10',
            'support_files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ]);

        $category = ExpenseCategory::findOrFail($validated['expense_category_id']);
        if (! ClaimRulesService::categoryAllowed($employee, $category)) {
            return response()->json(['ok' => false, 'errors' => ['expense_category_id' => 'You are not eligible to claim under this category.']], 422);
        }

        // Date is per item (each receipt keeps its own date); falls back to the claim's
        // event date, then today. Project/client + approver are inherited from the claim.
        $expenseDate = ! empty($validated['expense_date'])
            ? Carbon::parse($validated['expense_date'])
            : ($claim->event_date ? $claim->event_date->copy() : now());

        // A receipt can only be claimed under a report for its OWN month.
        if (! ClaimRulesService::itemDateInPeriod($expenseDate, $claim->year, $claim->month)) {
            return response()->json([
                'ok' => false,
                'message' => $this->outOfMonthMessage($expenseDate, $claim),
                'errors' => ['expense_date' => 'This receipt is not from this claim’s month.'],
            ], 422);
        }

        // ...and the receipt's OWN printed date (read into the read-only Category-C field by
        // the scan) must be in-month too — so a wrong-month receipt can't slip in under a
        // manually-set Date of Expense.
        if ($ocrDate = $this->ocrReceiptDateOutOfPeriod($request, $claim)) {
            return response()->json([
                'ok' => false,
                'message' => $this->outOfMonthMessage($ocrDate, $claim),
                'errors' => ['expense_date' => 'The scanned receipt’s date is not from this claim’s month.'],
            ], 422);
        }

        $projectClient = $claim->project_client;

        // Amount: fixed-subsidy = flat rate; mileage = km × vehicle rate (server-authoritative);
        // everything else uses the entered amount.
        $mileageGl = config('claims.mileage.gl_code');
        $isMileageCat = $mileageGl && $category->gl_code === $mileageGl;
        $gst = (float) ($validated['gst_amount'] ?? 0);
        $quantity = null;
        $unit = null;
        $rateApplied = null;
        $mileageDest = null;
        if ($category->isFixed()) {
            $amount = (float) ($category->rate_amount ?? 0);
            $gst = 0;
        } elseif ($isMileageCat) {
            $quantity = $request->input('c_km') !== null && $request->input('c_km') !== '' ? (float) $request->input('c_km') : null;
            if ($quantity === null || $quantity <= 0) {
                return response()->json(['ok' => false, 'errors' => ['amount' => 'Enter the distance (km) for the mileage claim.']], 422);
            }
            $rateApplied = ClaimRulesService::mileageRate($request->input('c_vehicle', 'car'));
            $amount = round($quantity * $rateApplied, 2);
            $gst = 0;
            $unit = 'km';
            $mileageDest = $request->input('c_itemdesc') ? mb_substr(strip_tags((string) $request->input('c_itemdesc')), 0, 255) : null;
        } else {
            $amount = isset($validated['amount']) ? (float) $validated['amount'] : null;
            if ($amount === null || $amount <= 0) {
                return response()->json(['ok' => false, 'errors' => ['amount' => 'Enter the amount for this item.']], 422);
            }
            // Hard block over-claiming on a plain receipt category (mirrors the client check).
            if ($overClaim = $this->overClaimError($request, $category, $employee, $amount, $gst)) {
                return response()->json(['ok' => false, 'errors' => ['amount' => $overClaim]], 422);
            }
        }

        // Cap-to-remaining on the CLAIMABLE TOTAL (incl. SST); block only when fully used.
        $capAdjust = ClaimRulesService::capAdjust($employee, $category, round($amount + $gst, 2), $expenseDate);
        if ($capAdjust['allowed'] <= 0) {
            return response()->json(['ok' => false, 'errors' => ['amount' => $capAdjust['message']]], 422);
        }
        $capNote = null;
        if ($capAdjust['capped']) {
            $cappedTotal = $capAdjust['allowed'];
            // Keep the SST if it still fits under the cap; otherwise drop it.
            if ($gst > 0 && $gst < $cappedTotal) {
                $amount = round($cappedTotal - $gst, 2);
            } else {
                $gst = 0.0;
                $amount = $cappedTotal;
            }
            $capNote = $capAdjust['message'];
        }
        $total = round($amount + $gst, 2);

        // Receipt + extra attachments (SHA-256 dedup like addItem; dead claims excluded).
        $deadStatuses = ['manager_rejected', 'hr_rejected', 'cancelled'];
        $receiptPath = null;
        $receiptHash = null;
        $receiptPaths = [];
        if ($request->hasFile('receipt')) {
            $receiptHash = hash_file('sha256', $request->file('receipt')->getRealPath());
            // Batch add (multi-receipt review table): the ONE scanned image legitimately
            // backs every row it was split into, so skip the single-receipt dedup that
            // would otherwise reject rows 2..N. Normal (single) adds still dedup.
            if (! $request->boolean('batch')) {
                $dup = ExpenseClaimItem::whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses))
                    ->where('receipt_hash', $receiptHash)->with('claim')->first();
                if ($dup) {
                    return response()->json(['ok' => false, 'errors' => ['receipt' => 'This receipt has already been uploaded in '.($dup->claim->claim_number ?? 'another claim').'.']], 422);
                }
            }
            $receiptPath = $request->file('receipt')->store('claim_receipts/'.$employee->id.'/'.$expenseDate->format('Y-m'), 'local');
        }
        if ($request->hasFile('receipt_attachments')) {
            foreach ($request->file('receipt_attachments') as $file) {
                if (! $file) {
                    continue;
                }
                $receiptPaths[] = $file->store('claim_receipts/'.$employee->id.'/'.$expenseDate->format('Y-m'), 'local');
            }
        }

        // Optional supporting documents — stored separately from the receipt (not scanned).
        $supportingPaths = [];
        if ($request->hasFile('support_files')) {
            foreach ($request->file('support_files') as $file) {
                if ($file) {
                    $supportingPaths[] = $file->store('claim_supporting/'.$employee->id.'/'.$expenseDate->format('Y-m'), 'local');
                }
            }
        }

        $item = $claim->items()->create([
            'expense_category_id' => $category->id,
            'expense_date' => $expenseDate,
            'description' => strip_tags($validated['description']),
            'project_client' => $projectClient,
            'amount' => number_format($amount, 2, '.', ''),
            'quantity' => $quantity,
            'unit' => $unit,
            'rate_applied' => $rateApplied,
            'mileage_destination' => $mileageDest,
            'gst_amount' => $gst,
            'total_with_gst' => $total,
            'receipt_path' => $receiptPath,
            'receipt_paths' => $receiptPaths,
            'receipt_hash' => $receiptHash,
            'supporting_paths' => $supportingPaths,
            'ocr_details' => $this->ocrDetailsFromRequest($request),
            'approver_id' => $claim->manager_id,
            'manager_status' => 'pending',
            'review_status' => 'approved',
        ]);

        $claim->recalculateTotals();
        $claim->refresh();

        return response()->json([
            'ok' => true,
            'cap_note' => $capNote,
            'item' => $this->inlineItemPayload($item->fresh(), $category),
            'claim_total' => number_format($claim->total_with_gst, 2),
            'item_count' => $claim->item_count,
        ]);
    }

    /** Inline builder: remove one item from a draft claim (AJAX). Owner + draft only. */
    public function inlineRemoveItem(ExpenseClaimItem $item)
    {
        $employee = Auth::user()->employee;
        $claim = $item->claim;
        if (! $claim || ! $employee || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        if (! $claim->isEditable() || $item->is_locked) {
            return response()->json(['ok' => false, 'message' => 'This item cannot be removed.'], 422);
        }

        // Removing one bulk-scanned line removes ALL items read from that same attachment.
        $removedIds = $this->deleteItemGroup($item);
        $claim->recalculateTotals();
        $claim->refresh();

        return response()->json([
            'ok' => true,
            'removed_ids' => $removedIds,
            'claim_total' => number_format($claim->total_with_gst, 2),
            'item_count' => $claim->item_count,
        ]);
    }

    /** Inline builder: edit one item on a draft claim (AJAX). Owner + draft only. */
    public function inlineUpdateItem(Request $request, ExpenseClaimItem $item)
    {
        $employee = Auth::user()->employee;
        $claim = $item->claim;
        if (! $claim || ! $employee || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        if (! $claim->isEditable() || $item->is_locked) {
            return response()->json(['ok' => false, 'message' => 'This item can no longer be edited.'], 422);
        }

        $validated = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'description' => 'required|string|max:500',
            'expense_date' => 'nullable|date|before_or_equal:today|after_or_equal:'.now()->subMonths(18)->toDateString(),
            'amount' => 'nullable|numeric|min:0|max:99999.99',
            'gst_amount' => 'nullable|numeric|min:0|max:99999.99',
            // Editing an item REQUIRES re-uploading the receipt — the old attachment is replaced.
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'support_files' => 'nullable|array|max:10',
            'support_files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ], [
            'receipt.required' => 'Please re-upload the receipt to save your changes — the previous attachment will be replaced.',
        ]);

        $category = ExpenseCategory::findOrFail($validated['expense_category_id']);
        if (! ClaimRulesService::categoryAllowed($employee, $category)) {
            return response()->json(['ok' => false, 'errors' => ['expense_category_id' => 'You are not eligible to claim under this category.']], 422);
        }

        $expenseDate = ! empty($validated['expense_date'])
            ? Carbon::parse($validated['expense_date'])
            : ($claim->event_date ? $claim->event_date->copy() : now());

        // A receipt can only be claimed under a report for its OWN month.
        if (! ClaimRulesService::itemDateInPeriod($expenseDate, $claim->year, $claim->month)) {
            return response()->json([
                'ok' => false,
                'message' => $this->outOfMonthMessage($expenseDate, $claim),
                'errors' => ['expense_date' => 'This receipt is not from this claim’s month.'],
            ], 422);
        }

        // ...and the receipt's OWN printed (scanned) date must be in-month too.
        if ($ocrDate = $this->ocrReceiptDateOutOfPeriod($request, $claim)) {
            return response()->json([
                'ok' => false,
                'message' => $this->outOfMonthMessage($ocrDate, $claim),
                'errors' => ['expense_date' => 'The scanned receipt’s date is not from this claim’s month.'],
            ], 422);
        }

        $mileageGl = config('claims.mileage.gl_code');
        $isMileageCat = $mileageGl && $category->gl_code === $mileageGl;
        $gst = (float) ($validated['gst_amount'] ?? 0);
        $quantity = null;
        $unit = null;
        $rateApplied = null;
        $mileageDest = null;
        if ($category->isFixed()) {
            $amount = (float) ($category->rate_amount ?? 0);
            $gst = 0;
        } elseif ($isMileageCat) {
            $quantity = $request->input('c_km') !== null && $request->input('c_km') !== '' ? (float) $request->input('c_km') : null;
            if ($quantity === null || $quantity <= 0) {
                return response()->json(['ok' => false, 'errors' => ['amount' => 'Enter the distance (km) for the mileage claim.']], 422);
            }
            $rateApplied = ClaimRulesService::mileageRate($request->input('c_vehicle', 'car'));
            $amount = round($quantity * $rateApplied, 2);
            $gst = 0;
            $unit = 'km';
            $mileageDest = $request->input('c_itemdesc') ? mb_substr(strip_tags((string) $request->input('c_itemdesc')), 0, 255) : null;
        } else {
            $amount = isset($validated['amount']) ? (float) $validated['amount'] : null;
            if ($amount === null || $amount <= 0) {
                return response()->json(['ok' => false, 'errors' => ['amount' => 'Enter the amount for this item.']], 422);
            }
            // Hard block over-claiming on a plain receipt category (mirrors the client check).
            if ($overClaim = $this->overClaimError($request, $category, $employee, $amount, $gst)) {
                return response()->json(['ok' => false, 'errors' => ['amount' => $overClaim]], 422);
            }
        }

        // Cap-to-remaining on the CLAIMABLE TOTAL (incl. SST), excluding THIS item.
        $capAdjust = ClaimRulesService::capAdjust($employee, $category, round($amount + $gst, 2), $expenseDate, $item->id);
        if ($capAdjust['allowed'] <= 0) {
            return response()->json(['ok' => false, 'errors' => ['amount' => $capAdjust['message']]], 422);
        }
        if ($capAdjust['capped']) {
            $cappedTotal = $capAdjust['allowed'];
            if ($gst > 0 && $gst < $cappedTotal) {
                $amount = round($cappedTotal - $gst, 2);
            } else {
                $gst = 0.0;
                $amount = $cappedTotal;
            }
        }
        $total = round($amount + $gst, 2);

        // Optional receipt replacement (keeps the existing one when no new file).
        $receiptPath = $item->receipt_path;
        $receiptHash = $item->receipt_hash;
        $oldToDelete = null;
        if ($request->hasFile('receipt')) {
            $deadStatuses = ['manager_rejected', 'hr_rejected', 'cancelled'];
            $newHash = hash_file('sha256', $request->file('receipt')->getRealPath());
            $dup = ExpenseClaimItem::whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses))
                ->where('id', '!=', $item->id)->where('receipt_hash', $newHash)->with('claim')->first();
            if ($dup) {
                return response()->json(['ok' => false, 'errors' => ['receipt' => 'This receipt has already been uploaded in '.($dup->claim->claim_number ?? 'another claim').'.']], 422);
            }
            $receiptPath = $request->file('receipt')->store('claim_receipts/'.$employee->id.'/'.$expenseDate->format('Y-m'), 'local');
            $receiptHash = $newHash;
            // Replacing the receipt supersedes any old extra-attachment paths too.
            $oldToDelete = array_merge($item->attachmentPaths());
        }

        // Supporting documents: replace with the newly uploaded set when provided; keep the
        // existing ones otherwise.
        $supportingPaths = $item->supportingPaths();
        $oldSupportingToDelete = [];
        if ($request->hasFile('support_files')) {
            $oldSupportingToDelete = $supportingPaths;
            $supportingPaths = [];
            foreach ($request->file('support_files') as $file) {
                if ($file) {
                    $supportingPaths[] = $file->store('claim_supporting/'.$employee->id.'/'.$expenseDate->format('Y-m'), 'local');
                }
            }
        }

        $update = [
            'expense_category_id' => $category->id,
            'expense_date' => $expenseDate,
            'description' => strip_tags($validated['description']),
            'amount' => number_format($amount, 2, '.', ''),
            'quantity' => $quantity,
            'unit' => $unit,
            'rate_applied' => $rateApplied,
            'mileage_destination' => $mileageDest,
            'gst_amount' => $gst,
            'total_with_gst' => $total,
            'receipt_path' => $receiptPath,
            'receipt_paths' => [], // the single re-uploaded receipt replaces any old extras
            'receipt_hash' => $receiptHash,
            'supporting_paths' => $supportingPaths,
        ];
        // Category C only changes if the user re-scanned during the edit; otherwise keep it.
        $newOcr = $this->ocrDetailsFromRequest($request);
        if ($newOcr !== null) {
            $update['ocr_details'] = $newOcr;
        }
        $item->update($update);
        foreach (array_merge((array) $oldToDelete, $oldSupportingToDelete) as $del) {
            if ($del) {
                Storage::disk('local')->delete($del);
            }
        }

        $claim->recalculateTotals();
        $claim->refresh();

        return response()->json([
            'ok' => true,
            'item' => $this->inlineItemPayload($item->fresh(), $category),
            'claim_total' => number_format($claim->total_with_gst, 2),
            'item_count' => $claim->item_count,
        ]);
    }

    /** Inline builder: delete a whole draft claim (discard). Owner + draft only. */
    public function discardDraft(ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;
        if (! $employee || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        if ($claim->status !== 'draft') {
            return back()->with('error', 'Only a draft claim can be deleted.');
        }

        $claim->load('items');
        foreach ($claim->items as $it) {
            foreach ($it->attachmentPaths() as $path) {
                Storage::disk('local')->delete($path);
            }
        }
        $number = $claim->claim_number;
        $claim->items()->delete();
        $claim->delete();

        return redirect()->route('user.claims.index')->with('success', 'Draft '.$number.' deleted.');
    }

    /**
     * Over-claim guard (server mirror of applyReceiptCheck in the My Claims page): for a plain
     * receipt category (not capped/computed), the claimed total (amount + SST) may NOT exceed
     * the receipt total the scan read. Under-claims are allowed. Returns an error message to
     * block with, or null when fine. No-op when no receipt total was captured.
     */
    private function overClaimError(Request $request, ExpenseCategory $category, Employee $employee, float $amount, float $gst): ?string
    {
        // Capped categories (Medical, Optical & Dental, etc.) intentionally claim ≠ receipt.
        if (ClaimRulesService::effectiveLimit($category, $employee) !== null) {
            return null;
        }
        $receiptTotal = $request->input('c_total');
        if (! is_numeric($receiptTotal) || (float) $receiptTotal <= 0) {
            return null; // nothing read off the receipt to compare against.
        }
        if (round($amount + $gst, 2) > round((float) $receiptTotal, 2) + 0.001) {
            return 'You can’t claim more than the receipt total of RM '.number_format((float) $receiptTotal, 2).'. Lower the amount and try again.';
        }

        return null;
    }

    /** Shared JSON shape for one inline item row. */
    private function inlineItemPayload(ExpenseClaimItem $item, ExpenseCategory $category): array
    {
        return [
            'id' => $item->id,
            'date' => $item->expense_date->format('d/m/Y'),
            'date_input' => $item->expense_date->format('Y-m-d'),
            'description' => $item->description,
            'category' => $category->name,
            'category_id' => $category->id,
            'amount' => number_format($item->amount, 2),
            'gst' => number_format($item->gst_amount, 2),
            'total' => number_format($item->total_with_gst, 2),
            'has_receipt' => (bool) $item->receipt_path || count((array) $item->receipt_paths) > 0,
            'receipt_url' => ((bool) $item->receipt_path || count((array) $item->receipt_paths) > 0)
                ? route('user.claims.items.receipt', $item)
                : null,
            'receipt_hash' => $item->receipt_hash ?: '',
            'ocr' => $item->ocr_details ?: null,
        ];
    }

    /**
     * Delete an item AND every sibling read from the SAME attachment (a bulk scan splits one
     * image into several items that share a receipt_hash) — there's no editing, so a wrong
     * line is fixed by deleting the whole attachment's items and adding them again. Items with
     * no receipt_hash (e.g. mileage) are deleted on their own. Returns the deleted item IDs.
     */
    private function deleteItemGroup(ExpenseClaimItem $item): array
    {
        $claim = $item->claim;
        $group = $item->receipt_hash
            ? $claim->items()->where('receipt_hash', $item->receipt_hash)->get()
            : collect([$item]);
        if ($group->isEmpty()) {
            $group = collect([$item]);
        }

        $ids = [];
        foreach ($group as $gi) {
            foreach (array_merge($gi->attachmentPaths(), $gi->supportingPaths()) as $path) {
                Storage::disk('local')->delete($path);
            }
            $ids[] = $gi->id;
            $gi->delete();
        }

        return $ids;
    }

    /**
     * Category C — the read-only receipt details the OCR read at scan (company, item
     * description, date, who paid, total paid). Returns null when nothing was captured.
     */
    private function ocrDetailsFromRequest(Request $request): ?array
    {
        $details = array_filter([
            'company' => $request->input('c_company'),
            'item_description' => $request->input('c_itemdesc'),
            'date' => $request->input('c_date'),
            'paid_by' => $request->input('c_paidby'),
            'total' => $request->input('c_total'),
            'calculation' => $request->input('c_calc'),
            'km' => $request->input('c_km'),
            'vehicle' => $request->input('c_vehicle'),
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        return $details ?: null;
    }

    /**
     * Inline builder: submit a draft claim to its Category B approver (whole claim,
     * single approver). Mirrors submit() but takes the approver from the claim, not a
     * per-item picker. Owner + draft only.
     */
    public function inlineSubmitClaim(Request $request, ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;
        if (! $employee || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        // Validation bounces re-open THIS draft (?open) so the form stays put — the user isn't
        // dumped back to an empty page having to scroll and find the claim again.
        $bounce = fn (string $msg) => redirect()->route('user.claims.index', ['open' => $claim->id])->with('error', $msg);

        if (! $claim->isSubmittable()) {
            return $bounce('Add at least one item before submitting.');
        }
        if (empty($claim->project_client) && ! self::isSalesTeam($employee)) {
            return $bounce('Enter the project / client name before submitting.');
        }

        $approverId = $claim->manager_id;
        if ((int) $approverId === (int) $claim->employee_id) {
            return $bounce('You cannot be your own approving PIC / manager — choose someone else.');
        }
        if (! ClaimRulesService::isValidApproverFor($claim->employee_id, (int) $approverId)) {
            return $bounce('Choose an approving PIC / manager before submitting.');
        }

        // No receipt, no claim (mileage exempt) — enforced at submit so drafts save freely.
        $claim->load('items.category');
        $missing = $claim->items->filter(fn ($it) => $it->needsReceipt());
        if ($missing->isNotEmpty()) {
            return $bounce('Attach a receipt to: '.$missing->take(5)->pluck('description')->implode(', ').($missing->count() > 5 ? ', …' : '').'.');
        }

        // The claim's month must still be open, and every receipt must fall inside it.
        $claimMonth = Carbon::create($claim->year, $claim->month, 1)->format('F Y');
        if (! ClaimRulesService::isPeriodOpenForFiling($claim->year, $claim->month)) {
            return $bounce('This is a '.$claimMonth.' claim, and that period is now closed for filing.');
        }
        $wrongMonth = $claim->items->filter(fn ($it) => $it->expense_date && ! ClaimRulesService::itemDateInPeriod($it->expense_date, $claim->year, $claim->month));
        if ($wrongMonth->isNotEmpty()) {
            return $bounce('This '.$claimMonth.' report has receipt(s) dated in another month: '
                .$wrongMonth->take(5)->pluck('description')->implode(', ').($wrongMonth->count() > 5 ? ', …' : '')
                .'. Please move each receipt to a claim for its own month before submitting.');
        }

        $claim->recalculateTotals();
        DB::transaction(function () use ($claim, $approverId) {
            $claim->items()->update([
                'approver_id' => $approverId,
                'manager_status' => 'pending',
                'manager_remarks' => null,
                'review_status' => 'approved',
                'is_locked' => true,
            ]);
            $claim->update(['status' => 'submitted', 'submitted_at' => now(), 'manager_id' => $approverId]);
        });

        $this->logClaim($claim, 'submitted', 'Submitted to the approving Manager/PIC.');

        $manager = Employee::find($approverId);
        if ($manager && $manager->user) {
            Mail::to($manager->user->work_email)->send(new ClaimSubmittedMail($claim, $employee, 'manager'));
        }

        return redirect()->route('user.claims.index', ['open' => $claim->id])
            ->with('success', 'Claim submitted to '.($manager->full_name ?? 'your approver').' for approval.');
    }

    /**
     * Add an item to a specific (event) claim. Claims are per-event now, so the target
     * claim is passed explicitly (claim_id) rather than derived from a viewed month.
     */
    public function addItem(Request $request)
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $claim = ExpenseClaim::find($request->input('claim_id'));
        if (! $claim || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        if (! $claim->isEditable()) {
            return back()->with('error', 'This claim has already been submitted and cannot be edited.');
        }

        // Claims routinely span months (an event's legs can fall across weeks/months and
        // are filed later), so back-dating is allowed — bounded to the last 18 months to
        // catch typos. Only FUTURE dates are blocked.
        $now = Carbon::now();
        $floor = $now->copy()->subMonths(18)->toDateString();

        // Project/client is mandatory for everyone EXCEPT the Sales team (per the form rules).
        $projectRequired = ! self::isSalesTeam($employee);

        $validated = $request->validate([
            'expense_date' => "required|date|after_or_equal:{$floor}|before_or_equal:today",
            'description' => 'required|string|max:500',
            'project_client' => ($projectRequired ? 'required' : 'nullable').'|string|max:255',
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
            'receipt_attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ], [
            'expense_date.after_or_equal' => 'The expense date is too far in the past (older than 18 months).',
            'expense_date.before_or_equal' => 'The expense date cannot be in the future.',
            'project_client.required' => 'State the project / client name for this expense.',
        ]);

        $expenseDate = Carbon::parse($validated['expense_date']);

        // A receipt can only be claimed under a report for its OWN month.
        if (! ClaimRulesService::itemDateInPeriod($expenseDate, $claim->year, $claim->month)) {
            return back()->with('error', $this->outOfMonthMessage($expenseDate, $claim))->withInput();
        }
        // ...and the receipt's OWN printed (scanned) date must be in-month too.
        if ($ocrDate = $this->ocrReceiptDateOutOfPeriod($request, $claim)) {
            return back()->with('error', $this->outOfMonthMessage($ocrDate, $claim))->withInput();
        }

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
        if (! ClaimRulesService::categoryAllowed($employee, $category)) {
            return back()->withErrors(['expense_category_id' => 'You are not eligible to claim under this category.'])->withInput();
        }

        // Petrol is always a per-km mileage claim (car/motorcycle rate). The origin
        // and destination are chosen by the employee; distance is the evidence, not a
        // receipt. (The legacy "by receipt" Petrol mode has been removed.)
        $mileageGl = config('claims.mileage.gl_code');
        $isPetrolMileage = $mileageGl && $category->gl_code === $mileageGl;

        // A receipt is NOT required to save a draft item — the employee can add the
        // expense now and attach the receipt later (e.g. a trip planned for tomorrow).
        // The "no receipt, no claim" rule is enforced at SUBMIT time instead (see submit()).

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
            } elseif ($category->isFixed()) {
                // Flat-subsidy category (e.g. season parking RM80) — the claimable
                // amount is always rate_amount, regardless of the receipt total. No
                // quantity/rate, so no qty×rate sanity badge fires on it.
                $computed = ClaimRulesService::computeAmount($category, []);
                $quantity = null;
                $unit = null;
                $rateApplied = null;
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
        // Dead claims (rejected/cancelled) are excluded — they're void, and a
        // correction legitimately re-uses the rejected report's lines (#12a).
        $deadStatuses = ['manager_rejected', 'hr_rejected', 'cancelled'];
        $cleanDescription = strip_tags($validated['description']);
        $duplicateItem = ExpenseClaimItem::whereHas('claim', function ($q) use ($employee, $deadStatuses) {
            $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses);
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
        $receiptPaths = [];
        $newAttachmentPaths = [];
        if ($request->hasFile('receipt')) {
            // ── Receipt duplicate detection via SHA-256 hash ──
            $receiptHash = hash_file('sha256', $request->file('receipt')->getRealPath());

            $existingReceipt = ExpenseClaimItem::whereHas('claim', function ($q) use ($employee, $deadStatuses) {
                $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses);
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

        if ($request->hasFile('receipt_attachments')) {
            foreach ($request->file('receipt_attachments') as $file) {
                if (! $file) {
                    continue;
                }
                $path = $file->store(
                    'claim_receipts/'.$employee->id.'/'.$expenseDate->format('Y-m'),
                    'local'
                );
                $receiptPaths[] = $path;
                $newAttachmentPaths[] = $path;
            }
        }

        // Validate total integrity — server-side check that total = amount + GST
        $expectedTotal = round((float) $validated['amount'] + (float) ($validated['gst_amount'] ?? 0), 2);
        if (abs($expectedTotal - (float) $validated['total_with_gst']) > 0.01) {
            if ($receiptPath) {
                Storage::disk('local')->delete($receiptPath);
            }
            foreach ($newAttachmentPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            return back()->withErrors(['total_with_gst' => 'Total does not match amount + GST.'])->withInput();
        }

        // Period-aware cap: instead of rejecting an over-cap receipt, claim only what's
        // left of the allowance (e.g. RM250 receipt, RM100 left → claim RM100). Block
        // only when the allowance is fully used up.
        $reqGst = (float) ($validated['gst_amount'] ?? 0);
        $capAdjust = ClaimRulesService::capAdjust($employee, $category, round((float) $validated['amount'] + $reqGst, 2), $expenseDate);
        if ($capAdjust['allowed'] <= 0) {
            if ($receiptPath) {
                Storage::disk('local')->delete($receiptPath);
            }
            foreach ($newAttachmentPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            return back()->withErrors(['amount' => $capAdjust['message']])->withInput();
        }
        $capNote = null;
        if ($capAdjust['capped']) {
            $cappedTotal = $capAdjust['allowed'];
            // Keep the SST if it still fits under the cap; otherwise drop it.
            if ($reqGst > 0 && $reqGst < $cappedTotal) {
                $validated['amount'] = number_format($cappedTotal - $reqGst, 2, '.', '');
                $validated['gst_amount'] = number_format($reqGst, 2, '.', '');
            } else {
                $validated['amount'] = number_format($cappedTotal, 2, '.', '');
                $validated['gst_amount'] = 0;
            }
            $expectedTotal = (float) $validated['amount'] + (float) $validated['gst_amount'];
            $capNote = $capAdjust['message'];
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
            'receipt_paths' => $receiptPaths ?: null,
            'receipt_hash' => $receiptHash,
        ]);

        $claim->recalculateTotals();

        return redirect()->route('user.claims.index', ['open' => $claim->id])
            ->with($capNote ? 'warning' : 'success', $capNote ?: 'Expense item added.');
    }

    /**
     * Update a single line item on an editable (draft/rejected) claim. Mirrors
     * addItem's rules — computed amounts, duplicate + cap checks (excluding this
     * item itself) — and keeps the existing receipt unless a new file is uploaded.
     */
    public function updateItem(Request $request, ExpenseClaimItem $item)
    {
        $employee = Auth::user()->employee;
        $claim = $item->claim;

        if (! $employee || ! $claim || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        if (! $claim->isEditable() || $item->is_locked) {
            return back()->with('error', 'This item can no longer be edited.');
        }

        $now = Carbon::now();
        $floor = $now->copy()->subMonths(18)->toDateString();
        $projectRequired = ! self::isSalesTeam($employee);

        $validated = $request->validate([
            'expense_date' => "required|date|after_or_equal:{$floor}|before_or_equal:today",
            'description' => 'required|string|max:500',
            'project_client' => ($projectRequired ? 'required' : 'nullable').'|string|max:255',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'amount' => 'required|numeric|min:0.01|max:99999.99',
            'gst_amount' => 'nullable|numeric|min:0|max:99999.99',
            'total_with_gst' => 'required|numeric|min:0.01|max:999999.99',
            'quantity' => 'nullable|numeric|min:0.01|max:99999.99',
            'vehicle' => 'nullable|in:car,motorcycle',
            'mileage_destination' => 'nullable|string|max:255',
            'mileage_origin' => 'nullable|string|max:255',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'receipt_attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ], [
            'expense_date.after_or_equal' => 'The expense date is too far in the past (older than 18 months).',
            'expense_date.before_or_equal' => 'The expense date cannot be in the future.',
            'project_client.required' => 'State the project / client name for this expense.',
        ]);

        $expenseDate = Carbon::parse($validated['expense_date']);

        // A receipt can only be claimed under a report for its OWN month.
        if (! ClaimRulesService::itemDateInPeriod($expenseDate, $claim->year, $claim->month)) {
            return back()->with('error', $this->outOfMonthMessage($expenseDate, $claim))->withInput();
        }
        // ...and the receipt's OWN printed (scanned) date must be in-month too.
        if ($ocrDate = $this->ocrReceiptDateOutOfPeriod($request, $claim)) {
            return back()->with('error', $this->outOfMonthMessage($ocrDate, $claim))->withInput();
        }

        $category = ExpenseCategory::find($validated['expense_category_id']);
        if (! $category || ! $category->is_active) {
            return back()->withErrors(['expense_category_id' => 'Invalid expense category.'])->withInput();
        }
        if ($category->company && $employee->company && $category->company !== $employee->company) {
            return back()->withErrors(['expense_category_id' => 'This category is not available for your company.'])->withInput();
        }
        if (! ClaimRulesService::categoryAllowed($employee, $category)) {
            return back()->withErrors(['expense_category_id' => 'You are not eligible to claim under this category.'])->withInput();
        }

        $mileageGl = config('claims.mileage.gl_code');
        $isPetrolMileage = $mileageGl && $category->gl_code === $mileageGl;

        // No receipt required to save edits — the attachment can still be added later;
        // it's enforced at submit time (see submit()).

        // Computed amounts (server authoritative).
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
            } elseif ($category->isFixed()) {
                // Flat-subsidy category (e.g. season parking RM80) — claimable amount
                // is always rate_amount, irrespective of the receipt total.
                $computed = ClaimRulesService::computeAmount($category, []);
                $quantity = null;
                $unit = null;
                $rateApplied = null;
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

        // Duplicate detection — same date+description+amount on another item.
        // Dead claims (rejected/cancelled) excluded so a correction can legitimately
        // re-use the rejected report's lines (#12a).
        $deadStatuses = ['manager_rejected', 'hr_rejected', 'cancelled'];
        $cleanDescription = strip_tags($validated['description']);
        $duplicateItem = ExpenseClaimItem::whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses))
            ->where('id', '!=', $item->id)
            ->where('expense_date', $validated['expense_date'])
            ->where('description', $cleanDescription)
            ->where('amount', $validated['amount'])
            ->first();
        if ($duplicateItem) {
            return back()->withErrors(['description' => 'A similar expense already exists (same date, description & amount) in claim '.$duplicateItem->claim->claim_number.'.'])->withInput();
        }

        // Receipt: replace only when a new file is uploaded; otherwise keep the old one.
        $receiptPath = $item->receipt_path;
        $receiptHash = $item->receipt_hash;
        $receiptPaths = $item->receipt_paths ?: [];
        $newAttachmentPaths = [];
        $oldReceiptToDelete = null;
        if ($request->hasFile('receipt')) {
            $newHash = hash_file('sha256', $request->file('receipt')->getRealPath());
            $existingReceipt = ExpenseClaimItem::whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses))
                ->where('id', '!=', $item->id)
                ->where('receipt_hash', $newHash)
                ->first();
            if ($existingReceipt) {
                return back()->withErrors(['receipt' => 'This receipt has already been uploaded in claim '.$existingReceipt->claim->claim_number.'.'])->withInput();
            }
            $receiptPath = $request->file('receipt')->store('claim_receipts/'.$employee->id.'/'.$expenseDate->format('Y-m'), 'local');
            $receiptHash = $newHash;
            $oldReceiptToDelete = $item->receipt_path;
        }

        if ($request->hasFile('receipt_attachments')) {
            foreach ($request->file('receipt_attachments') as $file) {
                if (! $file) {
                    continue;
                }
                $path = $file->store(
                    'claim_receipts/'.$employee->id.'/'.$expenseDate->format('Y-m'),
                    'local'
                );
                $receiptPaths[] = $path;
                $newAttachmentPaths[] = $path;
            }
        }

        // Remove attachments the employee unticked (#1). Handles both the extra
        // receipt_paths and the primary receipt_path; files are deleted after save.
        $removePaths = array_values(array_filter((array) $request->input('receipt_remove', [])));
        $attachmentsToDelete = [];
        if ($removePaths) {
            $receiptPaths = array_values(array_diff($receiptPaths, $removePaths));
            if ($receiptPath !== null && in_array($receiptPath, $removePaths, true) && ! $request->hasFile('receipt')) {
                $attachmentsToDelete[] = $receiptPath;
                $receiptPath = null;
                $receiptHash = null;
            }
            $attachmentsToDelete = array_merge($attachmentsToDelete, $removePaths);
        }

        // Total integrity.
        $expectedTotal = round((float) $validated['amount'] + (float) ($validated['gst_amount'] ?? 0), 2);
        if (abs($expectedTotal - (float) $validated['total_with_gst']) > 0.01) {
            if ($oldReceiptToDelete !== null && $receiptPath) {
                Storage::disk('local')->delete($receiptPath); // roll back the just-stored new file
            }
            foreach ($newAttachmentPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            return back()->withErrors(['total_with_gst' => 'Total does not match amount + GST.'])->withInput();
        }

        // Cap → claim only what's left (exclude this item's own current amount). Block
        // only if the allowance is fully used by the employee's OTHER items.
        $reqGst = (float) ($validated['gst_amount'] ?? 0);
        $capAdjust = ClaimRulesService::capAdjust($employee, $category, round((float) $validated['amount'] + $reqGst, 2), $expenseDate, $item->id);
        if ($capAdjust['allowed'] <= 0) {
            if ($oldReceiptToDelete !== null && $receiptPath) {
                Storage::disk('local')->delete($receiptPath);
            }
            foreach ($newAttachmentPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            return back()->withErrors(['amount' => $capAdjust['message']])->withInput();
        }
        $capNote = null;
        if ($capAdjust['capped']) {
            $cappedTotal = $capAdjust['allowed'];
            if ($reqGst > 0 && $reqGst < $cappedTotal) {
                $validated['amount'] = number_format($cappedTotal - $reqGst, 2, '.', '');
                $validated['gst_amount'] = number_format($reqGst, 2, '.', '');
            } else {
                $validated['amount'] = number_format($cappedTotal, 2, '.', '');
                $validated['gst_amount'] = 0;
            }
            $expectedTotal = (float) $validated['amount'] + (float) $validated['gst_amount'];
            $capNote = $capAdjust['message'];
        }

        $item->update([
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
            'receipt_paths' => array_values($receiptPaths),
            'receipt_hash' => $receiptHash,
        ]);

        if ($oldReceiptToDelete) {
            Storage::disk('local')->delete($oldReceiptToDelete);
        }
        foreach (array_unique($attachmentsToDelete) as $path) {
            Storage::disk('local')->delete($path);
        }

        $claim->recalculateTotals();

        return redirect()->route('user.claims.index', ['open' => $claim->id])
            ->with($capNote ? 'warning' : 'success', $capNote ?: 'Expense item updated.');
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

        // Removing one bulk-scanned line removes ALL items read from that same attachment.
        $count = count($this->deleteItemGroup($item));
        $claim->recalculateTotals();

        return back()->with('success', $count > 1
            ? $count.' items (read from the same attachment) were removed.'
            : 'Expense item removed.');
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

    /** Render one claim to a dompdf PDF instance (form + embedded receipt images). */
    private function buildClaimPdf(ExpenseClaim $claim)
    {
        $claim->loadMissing('items.category', 'employee', 'managerApprover', 'manager', 'hrApprover');
        $company = \App\Models\Company::forName($claim->employee->company);
        $items = $claim->items;

        return Pdf::loadView('user.claims.report-pdf', compact('claim', 'company', 'items'))->setPaper('a4');
    }

    /** Download one claim as a PDF, named like the original forms. Owner or reviewer. */
    public function downloadClaimPdf(ExpenseClaim $claim)
    {
        $user = Auth::user();
        $isOwner = $user->employee && $claim->employee_id === $user->employee->id;
        if (! $isOwner && ! $user->canViewAllClaims()) {
            $this->authorizeReview($claim);
        }

        return $this->buildClaimPdf($claim)->download($claim->pdfFilename());
    }

    /**
     * HR: download all approved claims (optionally filtered) as a single ZIP of PDFs,
     * each named like the original form — ready to bulk-upload elsewhere.
     */
    public function downloadApprovedZip(Request $request)
    {
        $this->authorizeViewClaims();

        $q = ExpenseClaim::whereIn('status', ['hr_approved', 'paid'])->with(['employee', 'items.category']);
        if ($y = $request->input('year')) {
            $q->where('year', $y);
        }
        if ($m = $request->input('month')) {
            $q->where('month', $m);
        }
        // Employee / company accept one OR many values (employee_id[]=.. / company[]=..).
        // The (array) cast keeps old single-value links working too.
        $employeeIds = array_values(array_filter((array) $request->input('employee_id', [])));
        if (! empty($employeeIds)) {
            $q->whereIn('employee_id', $employeeIds);
        }
        $companies = array_values(array_filter((array) $request->input('company', []), fn ($v) => $v !== '' && $v !== null));
        if (! empty($companies)) {
            $q->whereHas('employee', fn ($x) => $x->whereIn('company', $companies));
        }
        $claims = $q->orderByDesc('hr_approved_at')->limit(200)->get();

        if ($claims->isEmpty()) {
            return back()->with('error', 'No approved claims match the current filter.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'claims').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $used = [];
        foreach ($claims as $claim) {
            $name = $claim->pdfFilename();
            $unique = $name;
            $i = 2;
            while (isset($used[$unique])) {
                $unique = preg_replace('/\.pdf$/', '', $name)." ({$i}).pdf";
                $i++;
            }
            $used[$unique] = true;
            $zip->addFromString($unique, $this->buildClaimPdf($claim)->output());
        }
        $zip->close();

        return response()->download($tmp, 'approved-claims-'.now()->format('Y-m-d').'.zip')->deleteFileAfterSend(true);
    }

    /** Save the Event/purpose on a specific claim. */
    public function saveDetails(Request $request, ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;
        if (! $employee || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        if (! $claim->isEditable()) {
            return back()->with('error', 'This claim can no longer be edited.');
        }
        $data = $request->validate(['event' => 'required|string|max:255']);
        $claim->update(['event' => mb_substr(strip_tags($data['event']), 0, 255)]);

        return redirect()->route('user.claims.index', ['open' => $claim->id])->with('success', 'Event saved.');
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
            return redirect()->route('user.claims.index', ['open' => $claim->id])
                ->with('error', 'This claim cannot be submitted. Ensure it has at least one item.');
        }

        $claim->load('items.category');

        // Block early if any item still needs a receipt — send them back to attach it.
        $missing = $claim->items->filter(fn ($it) => $it->needsReceipt());
        if ($missing->isNotEmpty()) {
            $names = $missing->take(5)->pluck('description')->implode(', ');

            return redirect()->route('user.claims.index', ['open' => $claim->id])
                ->with('error', 'Attach a receipt before submitting these item(s): '.$names.($missing->count() > 5 ? ', …' : '').'.');
        }

        // An employee can never approve their own claim — exclude self from the picker.
        $approvers = ClaimRulesService::eligibleApprovers()->where('id', '!=', $employee->id)->values();
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

        // "No receipt, no claim" is enforced here (not at add time) so drafts can be
        // saved without attachments and completed later.
        $claim->load('items.category');
        $missing = $claim->items->filter(fn ($it) => $it->needsReceipt());
        if ($missing->isNotEmpty()) {
            $names = $missing->take(5)->pluck('description')->implode(', ');

            return redirect()->route('user.claims.index', ['open' => $claim->id])
                ->with('error', 'Attach a receipt before submitting these item(s): '.$names.($missing->count() > 5 ? ', …' : '').'.');
        }

        // The claim's month must still be open, and every receipt must fall inside it.
        $claimMonth = Carbon::create($claim->year, $claim->month, 1)->format('F Y');
        if (! ClaimRulesService::isPeriodOpenForFiling($claim->year, $claim->month)) {
            return redirect()->route('user.claims.index', ['open' => $claim->id])
                ->with('error', 'This is a '.$claimMonth.' claim, and that period is now closed for filing.');
        }
        $wrongMonth = $claim->items->filter(fn ($it) => $it->expense_date && ! ClaimRulesService::itemDateInPeriod($it->expense_date, $claim->year, $claim->month));
        if ($wrongMonth->isNotEmpty()) {
            return redirect()->route('user.claims.index', ['open' => $claim->id])
                ->with('error', 'This '.$claimMonth.' report has receipt(s) dated in another month: '
                    .$wrongMonth->take(5)->pluck('description')->implode(', ').($wrongMonth->count() > 5 ? ', …' : '')
                    .'. Please move each receipt to a claim for its own month before submitting.');
        }

        // One approving manager for the whole event-claim (the event owner / reporting manager).
        $eligibleIds = ClaimRulesService::eligibleApprovers()->pluck('id')->all();
        $approverId = (int) $request->input('approver_id');
        if ($approverId === $employee->id) {
            return redirect()->route('user.claims.submit-form', $claim)
                ->with('error', 'You cannot be your own approving manager — choose someone else.');
        }
        if (! in_array($approverId, $eligibleIds, true)) {
            return redirect()->route('user.claims.submit-form', $claim)
                ->with('error', 'Please choose an approving manager for this claim.');
        }

        $claim->recalculateTotals();

        DB::transaction(function () use ($claim, $approverId) {
            $claim->items()->update([
                'approver_id' => $approverId,
                'manager_status' => 'pending',
                'manager_remarks' => null,
                'review_status' => 'approved', // reset HR stage in case of a resubmit
                'is_locked' => true,
            ]);
            $claim->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'manager_id' => $approverId,
            ]);
        });

        $this->logClaim($claim, 'submitted', 'Submitted to the approving Manager/PIC.');

        // Notify the approving manager.
        $manager = Employee::find($approverId);
        if ($manager && $manager->user) {
            Mail::to($manager->user->work_email)->send(new ClaimSubmittedMail($claim, $employee, 'manager'));
        }

        return redirect()->route('user.claims.index', ['open' => $claim->id])
            ->with('success', 'Claim submitted to '.($manager->full_name ?? 'your manager').' for approval.');
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
        $user = Auth::user();
        $employee = $user->employee;
        $isSuper = $user->isSuperadmin();
        if (! $employee && ! $isSuper) {
            return back()->with('error', 'No employee profile found.');
        }

        // Every claim routed to this manager — the single approving manager chosen at submit
        // (manager_id), NOT individual item approvers (which can be stale legacy data).
        // Superadmin gets oversight of ALL team claims, not just ones routed to them.
        $query = ExpenseClaim::whereIn('status', ['submitted', 'manager_approved', 'manager_rejected', 'hr_approved', 'hr_rejected', 'paid'])
            ->with(['employee', 'items.category', 'items.approver'])
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('submitted_at');
        if (! $isSuper) {
            $query->where('manager_id', $employee->id);
        }
        $myClaims = $query->get();

        // Manager-perspective card counts — each status maps to exactly one bucket.
        $cardCounts = [
            'pending' => $myClaims->where('status', 'submitted')->count(),
            'approved' => $myClaims->whereIn('status', ['manager_approved', 'hr_approved', 'paid'])->count(),
            'rejected' => $myClaims->where('status', 'manager_rejected')->count(),
            'hr_rejected' => $myClaims->where('status', 'hr_rejected')->count(),
        ];

        return view('user.claims.team', compact('myClaims', 'cardCounts', 'employee'));
    }

    /**
     * Dedicated review page for a Manager/PIC (stage 1) or HR (stage 2): the claim AS a
     * report + Approve/Reject, and on reject the reviewer can flag specific items with a
     * per-item comment for the employee. Stage is derived from the claim's current status.
     */
    public function reviewClaim(ExpenseClaim $claim)
    {
        $user = Auth::user();
        $emp = $user->employee;
        $claim->load(['items.category', 'items.approver', 'employee', 'manager', 'managerApprover', 'hrApprover']);

        $isOwner = $emp && $claim->employee_id === $emp->id;
        $isManager = $emp && (int) $claim->manager_id === (int) $emp->id; // the approving manager

        // Must have some relationship to the claim to even view it.
        if (! $isOwner && ! $isManager && ! $user->canViewAllClaims() && ! $user->isSuperadmin()) {
            abort(403);
        }

        // Stage = who can ACT now, based on BOTH the claim status AND this viewer's role.
        // Everyone else (e.g. a superadmin viewing for oversight, or a manager looking at an
        // HR-stage claim) gets a read-only view. The manager Approve/Reject is shown ONLY to the
        // claim's actual chosen approver — superadmin does NOT get it just for being superadmin.
        if ($claim->status === 'submitted' && $isManager) {
            $stage = 'manager';
        } elseif ($claim->status === 'manager_approved' && $user->canApproveRejectClaims()) {
            $stage = 'hr';
        } else {
            $stage = 'view';
        }

        $company = \App\Models\Company::forName($claim->employee->company);
        $items = $claim->items;
        $approver = $claim->manager ?? $claim->managerApprover;

        // Where Approve/Reject post to, and where to return, depend on the stage.
        $approveUrl = $stage === 'hr' ? route('hr.claims.approve', $claim) : route('user.claims.team.approve', $claim);
        $rejectUrl = $stage === 'hr' ? route('hr.claims.reject', $claim) : route('user.claims.team.reject', $claim);
        $backUrl = $stage === 'hr' ? route('hr.claims.index') : route('user.claims.team');

        return view('user.claims.review', compact('claim', 'company', 'items', 'approver', 'stage', 'approveUrl', 'rejectUrl', 'backUrl'));
    }

    /** Save per-item rejection comments (reviewer flagged specific lines for the employee). */
    private function saveItemRejectComments(ExpenseClaim $claim, $comments): void
    {
        if (! is_array($comments) || empty($comments)) {
            return;
        }
        $claimItemIds = $claim->items->pluck('id')->all();
        foreach ($comments as $itemId => $text) {
            $text = trim(strip_tags((string) $text));
            if ($text === '' || ! in_array((int) $itemId, $claimItemIds, true)) {
                continue;
            }
            ExpenseClaimItem::where('id', (int) $itemId)->update(['reject_comment' => mb_substr($text, 0, 1000)]);
        }
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

        // One approving manager per claim (the one chosen at submit = manager_id). They — or a
        // superadmin — approve the WHOLE claim (single-approver model).
        if ((int) $claim->manager_id !== (int) $employee->id && ! Auth::user()->isSuperadmin()) {
            abort(403, 'You are not the approving manager for this claim.');
        }

        $claim->load('items');
        DB::transaction(function () use ($claim) {
            $claim->items()->update(['manager_status' => 'approved']);
        });

        $this->logClaim($claim, 'manager_approved', $employee->full_name.' approved the claim.');

        Log::info('Claim manager-approved', [
            'claim_id' => $claim->id, 'claim_number' => $claim->claim_number, 'actor_id' => Auth::id(),
        ]);

        return redirect()->route('user.claims.team')->with('success', $this->finalizeManagerStage($claim, $employee));
    }

    /**
     * Roll the claim up after a manager approves their items: once every item has its
     * manager's approval (all managers done), advance to manager_approved (→ HR).
     * Otherwise it stays submitted, waiting on the other managers. Rejection is a
     * separate whole-claim action (managerReject) — items are never rejected here.
     */
    private function finalizeManagerStage(ExpenseClaim $claim, Employee $employee): string
    {
        $claim->load('items');

        if (! $claim->allItemsManagerDecided()) {
            return 'Your items were approved — waiting on '.$claim->managerPendingCount().' more item(s) from other managers.';
        }

        $claim->update([
            'status' => 'manager_approved',
            'manager_approved_by' => $employee->id,
            'manager_approved_at' => now(),
        ]);
        if ($claim->employee->user) {
            Mail::to($claim->employee->user->work_email)->send(new ClaimApprovedMail($claim, $claim->employee, 'manager'));
        }
        $this->notifyHr($claim, 'pending_hr_approval');
        $this->logClaim($claim, 'manager_stage_done', 'All managers approved — sent to HR (RM '.number_format($claim->total_with_gst, 2).').');

        return 'All items approved — claim sent to HR.';
    }

    /**
     * Manager rejects the WHOLE claim with remarks. Even if only one item is wrong,
     * the entire claim is returned to the employee to fix and resubmit — there is no
     * per-item rejection. Any approver on the claim (or superadmin) may reject.
     */
    public function managerReject(Request $request, ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;

        $request->validate(['remarks' => 'nullable|string|max:1000']);

        if ($claim->status !== 'submitted') {
            return back()->with('error', 'This claim is not pending approval.');
        }

        $claim->load('items');
        if ((int) $claim->manager_id !== (int) $employee->id && ! Auth::user()->isSuperadmin()) {
            abort(403, 'You are not the approving manager for this claim.');
        }

        $reason = mb_substr(strip_tags((string) $request->input('remarks')), 0, 1000);

        // Manager rejection is terminal for this report; the employee can immediately file
        // a correction (a brand-new report). The rejected report is kept as history.
        $claim->update([
            'status' => 'manager_rejected',
            'manager_remarks' => $reason,
            'manager_approved_by' => $employee->id,
            'manager_approved_at' => now(),
        ]);

        // Per-item flags/comments the reviewer left for the employee's reference.
        $this->saveItemRejectComments($claim, $request->input('item_comments'));

        if ($claim->employee->user) {
            Mail::to($claim->employee->user->work_email)->send(new ClaimRejectedMail($claim, $claim->employee, 'manager'));
        }

        $this->logClaim($claim, 'manager_rejected', $employee->full_name.' rejected the claim: '.$reason);
        Log::info('Claim manager-rejected (whole claim)', [
            'claim_id' => $claim->id, 'claim_number' => $claim->claim_number, 'actor_id' => Auth::id(),
        ]);

        return redirect()->route('user.claims.team')->with('success', 'Claim rejected — '.$claim->employee->full_name.' can now file a correction.');
    }

    /**
     * Employee files a correction of a rejected claim: a NEW draft claim is created with a
     * copy of the rejected one's items (and event), linked back to the original. The
     * original stays as a frozen rejected record.
     */
    public function makeCorrection(ExpenseClaim $claim)
    {
        $employee = Auth::user()->employee;
        if (! $employee || $claim->employee_id !== $employee->id) {
            abort(403);
        }
        // Only one correction is allowed per rejected report.
        if ($claim->hasCorrection()) {
            return back()->with('error', 'A correction has already been filed for '.$claim->claim_number.'. You can only correct a rejected claim once.');
        }
        if ($claim->correctionWindowClosed()) {
            return back()->with('error', 'The correction window for '.$claim->claim_number.' closed at the end of '.($claim->year ?: optional($claim->created_at)->year).'. This rejected claim can no longer be corrected.');
        }
        if (! $claim->canCorrect()) {
            return back()->with('error', 'This claim is not ready for correction yet.');
        }
        $claim->load('items');

        $new = DB::transaction(function () use ($claim, $employee) {
            $new = ExpenseClaim::create([
                'employee_id' => $employee->id,
                'year' => $claim->year, 'month' => $claim->month,
                'event' => $claim->event, 'title' => $claim->title,
                'claim_number' => ExpenseClaim::nextClaimNumber($claim->year, $claim->month, true),
                'status' => 'draft', 'correction_of_id' => $claim->id,
                'submission_deadline' => $claim->submission_deadline,
                'manager_id' => $employee->manager_id,
                'total_amount' => 0, 'total_gst' => 0, 'total_with_gst' => 0, 'item_count' => 0,
            ]);
            foreach ($claim->items as $it) {
                $new->items()->create([
                    'expense_category_id' => $it->expense_category_id, 'expense_date' => $it->expense_date,
                    'description' => $it->description, 'project_client' => $it->project_client,
                    'amount' => $it->amount, 'quantity' => $it->quantity, 'unit' => $it->unit,
                    'rate_applied' => $it->rate_applied, 'gst_amount' => $it->gst_amount,
                    'total_with_gst' => $it->total_with_gst, 'mileage_origin' => $it->mileage_origin,
                    'mileage_destination' => $it->mileage_destination, 'receipt_path' => $it->receipt_path,
                    'receipt_paths' => $it->receipt_paths, 'receipt_hash' => $it->receipt_hash, 'is_locked' => false,
                    'manager_status' => 'pending', 'review_status' => 'approved',
                ]);
            }
            $new->recalculateTotals();

            return $new;
        });

        $this->logClaim($claim, 'correction_created', 'Employee started a correction — new report '.$new->claim_number.'.');
        $this->logClaim($new, 'created_as_correction', 'Created as a correction of '.$claim->claim_number.'.');

        // Open the correction in the SAME inline editor used for normal drafts (Category B
        // auto-save + add-item + delete), so corrections behave exactly like editing a draft.
        return redirect()->route('user.claims.index', ['open' => $new->id])
            ->with('success', 'Correction started from '.$claim->claim_number.' — edit the details and resubmit.');
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

        // HR only ever sees claims that have been APPROVED by the Manager/PIC (and beyond) —
        // submitted (pending manager), manager-rejected and drafts never reach HR. Filtering
        // (status pills + search by name/event/month/date) is done client-side, like Team Claims.
        $hrStatuses = ['manager_approved', 'hr_approved', 'hr_rejected', 'paid'];

        // Scalability: the page renders every claim as a year→month→employee accordion and
        // filters client-side, so it needs the full set for the view it shows — but loading ALL
        // years unbounded doesn't scale. Scope to ONE year (default current), with a year
        // selector for older years. Each year loads fully, so the client-side filters still work.
        $availableYears = ExpenseClaim::whereIn('status', $hrStatuses)
            ->distinct()->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y)->all();
        $selectedYear = (int) $request->query('year', (int) now()->year);
        if (! empty($availableYears) && ! in_array($selectedYear, $availableYears, true)) {
            $selectedYear = $availableYears[0]; // fall back to the most recent year that has claims
        }

        $claims = ExpenseClaim::with(['employee', 'items.category'])
            ->whereIn('status', $hrStatuses)
            ->where('year', $selectedYear)
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('submitted_at')
            ->get();

        $stats = $this->getClaimStats();

        return view('hr.claims.index', compact('claims', 'stats', 'availableYears', 'selectedYear'));
    }

    /**
     * HR: View a single claim in detail.
     */
    public function show(ExpenseClaim $claim)
    {
        $this->authorizeViewClaims();

        $claim->load(['employee', 'items.category', 'items.approver', 'manager', 'managerApprover', 'hrApprover']);
        $company = \App\Models\Company::forName($claim->employee->company);

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

        return view('hr.claims.show', compact('claim', 'spendStats', 'company'));
    }

    /**
     * HR: Approve a manager-approved claim.
     */
    public function hrApprove(Request $request, ExpenseClaim $claim)
    {
        $this->authorizeApproveRejectClaims();

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

        $this->logClaim($claim, 'hr_approved', 'HR approved — RM '.number_format($claim->total_with_gst, 2).'.');

        return redirect()->route('hr.claims.index')->with('success', 'HR approved.');
    }

    /**
     * HR: Reject a manager-approved claim.
     */
    public function hrReject(Request $request, ExpenseClaim $claim)
    {
        $this->authorizeApproveRejectClaims();

        $request->validate(['remarks' => 'nullable|string|max:1000']);

        if ($claim->status !== 'manager_approved') {
            return back()->with('error', 'This claim is not pending HR approval.');
        }

        $remarks = mb_substr(strip_tags((string) $request->input('remarks')), 0, 1000);

        // HR rejection is terminal for this report; the employee may file a correction
        // immediately (no manager "release" gate). The report is frozen as hr_rejected.
        $claim->update([
            'status' => 'hr_rejected',
            'hr_approved_by' => Auth::id(),
            'hr_approved_at' => now(),
            'hr_remarks' => $remarks,
        ]);

        // Per-item flags/comments the reviewer left for the employee's reference.
        $this->saveItemRejectComments($claim, $request->input('item_comments'));

        Log::info('Claim hr-rejected', [
            'claim_id' => $claim->id, 'claim_number' => $claim->claim_number,
            'actor_id' => Auth::id(), 'actor_role' => Auth::user()->role, 'remarks' => $remarks,
        ]);

        $employee = $claim->employee;

        // Tell the employee they can correct + resubmit straight away.
        if ($employee->user) {
            Mail::to($employee->user->work_email)->send(new ClaimRejectedMail($claim, $employee, 'hr'));
        }

        // Notify the approving manager/PIC that HR rejected the claim (informational — no
        // action required from them; the employee handles the correction).
        $manager = $claim->managerApprover ?? $claim->manager;
        if ($manager && $manager->user) {
            Mail::to($manager->user->work_email)->send(new ClaimHrRejectedNoticeMail($claim, $manager));
        }

        $this->logClaim($claim, 'hr_rejected', 'HR rejected: '.$remarks.' (employee can correct immediately; approving manager notified).');

        return redirect()->route('hr.claims.index')->with('success', 'Claim rejected by HR — '.$employee->full_name.' can correct it now; the approving manager has been notified.');
    }

    /**
     * HR: Bulk approve multiple manager-approved claims.
     */
    public function bulkApprove(Request $request)
    {
        $this->authorizeApproveRejectClaims();

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

    // ── Finance: Claim Reports ────────────────────────────────────────────

    /** Fully-approved claims (manager + HR) only. Statuses that satisfy "approved by both". */
    private const FINANCE_REPORT_STATUSES = ['hr_approved', 'paid'];

    /** Shared, filtered query for the finance report (used by the page and the export). */
    private function financeReportClaims(Request $request, int $year)
    {
        $category = $request->query('category');

        return ExpenseClaim::query()
            ->with(['employee', 'items' => function ($q) use ($category) {
                $q->with('category');
                if ($category) {
                    $q->where('expense_category_id', (int) $category);
                }
            }])
            ->whereIn('status', self::FINANCE_REPORT_STATUSES)
            ->where('year', $year)
            ->when($request->query('month'), fn ($q, $m) => $q->where('month', (int) $m))
            ->when($request->query('company'), fn ($q, $c) => $q->whereHas('employee', fn ($e) => $e->where('company', $c)))
            ->when($category, fn ($q, $cat) => $q->whereHas('items', fn ($i) => $i->where('expense_category_id', (int) $cat)))
            ->orderByDesc('year')->orderByDesc('month')
            ->get();
    }

    /** Finance-facing report of approved claims, grouped Year > Month > Company > Employee. */
    public function financeReports(Request $request)
    {
        if (! Auth::user()->canViewClaimReports()) {
            abort(403);
        }

        $availableYears = ExpenseClaim::whereIn('status', self::FINANCE_REPORT_STATUSES)
            ->distinct()->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y)->all();
        $selectedYear = (int) $request->query('year', (int) now()->year);
        if (! empty($availableYears) && ! in_array($selectedYear, $availableYears, true)) {
            $selectedYear = $availableYears[0];
        }

        $claims = $this->financeReportClaims($request, $selectedYear);

        // Flatten to one row per item, then nest Year > Month > Company > Employee.
        $rows = collect();
        foreach ($claims as $claim) {
            foreach ($claim->items as $item) {
                $rows->push([
                    'year' => (int) $claim->year,
                    'month' => (int) $claim->month,
                    'company' => $claim->employee->company ?: '—',
                    'employee' => $claim->employee->full_name ?: '—',
                    'gl_code' => $item->category->gl_code ?: '—',
                    'category' => $item->category->name ?: '—',
                    'description' => $item->description,
                    'amount' => (float) $item->total_with_gst,
                ]);
            }
        }

        return view('finance.claim-reports', [
            'rows' => $rows,
            'grandTotal' => $rows->sum('amount'),
            'availableYears' => $availableYears,
            'selectedYear' => $selectedYear,
            'companies' => \App\Models\Company::orderBy('name')->pluck('name'),
            'categories' => ExpenseCategory::active()->orderBy('name')->get(['id', 'name', 'gl_code']),
            'filterMonth' => $request->query('month'),
            'filterCompany' => $request->query('company'),
            'filterCategory' => $request->query('category'),
        ]);
    }

    /** CSV export of the finance report, honouring the same filters. */
    public function financeReportsExport(Request $request)
    {
        if (! Auth::user()->canViewClaimReports()) {
            abort(403);
        }

        $selectedYear = (int) $request->query('year', (int) now()->year);
        $claims = $this->financeReportClaims($request, $selectedYear);

        $filename = 'claim_reports_'.$selectedYear.'_'.now()->format('Ymd_His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($claims) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Year', 'Month', 'Company', 'Employee', 'GL Code', 'Category', 'Description', 'Amount (RM)']);
            foreach ($claims as $claim) {
                foreach ($claim->items as $item) {
                    fputcsv($file, [
                        $claim->year,
                        str_pad((string) $claim->month, 2, '0', STR_PAD_LEFT),
                        $this->sanitizeForCsv($claim->employee->company ?? '-'),
                        $this->sanitizeForCsv($claim->employee->full_name ?? '-'),
                        $this->sanitizeForCsv($item->category->gl_code ?? '-'),
                        $this->sanitizeForCsv($item->category->name ?? '-'),
                        $this->sanitizeForCsv($item->description),
                        number_format($item->total_with_gst, 2),
                    ]);
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
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
     * Multi-stop driving distance via ORS — geocodes every stop in order and asks the
     * directions API for the TOTAL distance across all legs (e.g. A → B → A). Used to
     * auto-fill the mileage distance when a Google Maps screenshot has no km on it but
     * the route addresses were read. Fails open (ok=false / enabled=false → manual km).
     */
    public function mileageDistanceRoute(Request $request)
    {
        $data = $request->validate([
            'stops' => 'required|array|min:2|max:12',
            'stops.*' => 'nullable|string|max:255',
        ]);

        $stops = array_values(array_filter(array_map(fn ($s) => trim(strip_tags((string) $s)), $data['stops']), fn ($s) => $s !== '' && $s !== '?'));
        if (count($stops) < 2) {
            return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Need at least two stops to measure the distance.']);
        }

        $key = config('claims.distance.ors_key');
        if (config('claims.distance.provider', 'google') !== 'ors' || ! $key) {
            return response()->json(['enabled' => false]);
        }

        try {
            $coords = [];
            $prev = null;
            foreach ($stops as $stop) {
                $c = $this->orsGeocode($key, $stop, $prev);
                if (! $c) {
                    return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Could not locate “'.$stop.'” — enter the km manually.']);
                }
                $coords[] = $c;
                $prev = $c;
            }

            $resp = Http::timeout(15)
                ->withHeaders(['Authorization' => $key, 'Content-Type' => 'application/json'])
                ->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                    'coordinates' => $coords,
                ]);

            $meters = $resp->json('routes.0.summary.distance');
            if (! $resp->successful() || $meters === null) {
                return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Distance lookup failed — please enter the km manually.']);
            }

            return response()->json([
                'enabled' => true,
                'ok' => true,
                'km' => round($meters / 1000, 1),
                'stops' => $stops,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ORS multi-stop distance failed', ['error' => $e->getMessage()]);

            return response()->json(['enabled' => true, 'ok' => false, 'message' => 'Distance lookup failed — please enter the km manually.']);
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
            'receipt' => 'required_without:receipt_files|nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'receipt_files' => 'required_without:receipt|nullable|array|max:12',
            'receipt_files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ]);

        // Offer the employee's eligible categories so the AI can also classify the receipt.
        $employee = Auth::user()->employee;
        $categories = $employee ? ClaimRulesService::categoriesFor($employee) : collect();
        $catList = $categories->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'description' => $c->description])->all();

        // ── Multi-FILE upload: OCR each file, aggregate every transaction into one
        // review list. Each item remembers its source file index so the right image
        // attaches on add. (Maps are receipt-irrelevant here and are skipped.)
        if ($request->hasFile('receipt_files')) {
            $allItems = [];
            $truncated = false;
            foreach (array_values($request->file('receipt_files')) as $idx => $f) {
                if (! $f) {
                    continue;
                }
                $sub = ClaimReceiptOcrService::scanDocument($f->getRealPath(), $f->getMimeType(), $company, $catList);
                if ($sub === null) {
                    continue;
                }
                $truncated = $truncated || ! empty($sub['truncated']);
                foreach ($sub['items'] as $it) {
                    $row = $this->resolveScannedItem($it, $categories, $company);
                    $row['file_index'] = $idx;
                    $allItems[] = $row;
                }
            }

            return response()->json([
                'enabled' => true, 'ok' => count($allItems) > 0, 'multi' => true,
                'items' => $allItems, 'truncated' => $truncated,
            ]);
        }

        $file = $request->file('receipt');
        // The vision model reads IMAGES only; a PDF can't be auto-scanned (it would need
        // server-side rasterisation we don't run on the NAS). Say so plainly rather than
        // returning the generic "couldn't read it".
        if (str_contains((string) $file->getMimeType(), 'pdf') || strtolower((string) $file->getClientOriginalExtension()) === 'pdf') {
            return response()->json(['enabled' => true, 'ok' => false, 'message' => 'PDF receipts can’t be auto-scanned — please type the details in manually.']);
        }
        $doc = ClaimReceiptOcrService::scanDocument($file->getRealPath(), $file->getMimeType(), $company, $catList);

        if ($doc === null) {
            return response()->json(['enabled' => true, 'ok' => false]);
        }

        // ── Map / route screenshot → single mileage response (the JS map branch reads
        // distance_km / route_* at the top level, unchanged). Multi never applies to maps.
        // Guard: only treat as a mileage map when there are NO receipt items — a ride-hailing
        // receipt (Grab, etc.) shows a route map too but is a Travelling RECEIPT, not mileage.
        if (! empty($doc['map']) && empty($doc['items'])) {
            $m = $doc['map'];

            return response()->json([
                'enabled' => true, 'ok' => true, 'multi' => false,
                'amount' => null, 'date' => null, 'vendor' => null,
                'item_description' => null, 'paid_by' => null,
                'category_id' => null, 'category_name' => null,
                'distance_km' => $m['distance_km'],
                'route_from' => $m['route_from'],
                'route_to' => $m['route_to'],
                'route_stops' => $m['route_stops'],
            ]);
        }

        // ── Receipt(s): resolve each item's category (AI guess + keyword override). ──
        $items = array_map(fn ($it) => $this->resolveScannedItem($it, $categories, $company), $doc['items']);

        // Single receipt → flatten to the top level so the existing single-fill path is
        // untouched (zero behaviour change for the common one-receipt case).
        if (count($items) <= 1) {
            $one = $items[0] ?? [];

            return response()->json([
                'enabled' => true, 'ok' => true, 'multi' => false,
                'amount' => $one['amount'] ?? null,
                'gst' => $one['gst'] ?? null,
                'date' => $one['date'] ?? null,
                'vendor' => $one['vendor'] ?? null,
                'item_description' => $one['item_description'] ?? null,
                'paid_by' => $one['paid_by'] ?? null,
                'category_id' => $one['category_id'] ?? null,
                'category_name' => $one['category_name'] ?? null,
                'distance_km' => null, 'route_from' => null, 'route_to' => null, 'route_stops' => null,
            ]);
        }

        // Multiple receipts / a dated statement → return the list for the review table.
        return response()->json([
            'enabled' => true, 'ok' => true, 'multi' => true, 'items' => $items,
            'truncated' => (bool) ($doc['truncated'] ?? false),
        ]);
    }

    /**
     * Resolve a scanned receipt object's category: validated AI code → dropdown id, then
     * the deterministic keyword override (same precedence as the single-scan path).
     */
    private function resolveScannedItem(array $it, $categories, ?string $company): array
    {
        $catId = null;
        $catName = null;
        $catCode = $it['category'] ?? null;
        if ($catCode) {
            $match = $categories->firstWhere('code', $catCode);
            $catId = $match?->id;
            $catName = $match?->name;
        }
        $hint = trim(($it['vendor'] ?? '').' '.($it['item_description'] ?? ''));
        if ($hint !== '') {
            $kwCat = ExpenseCategory::detectFromDescription($hint, $company);
            if ($kwCat && $categories->contains('id', $kwCat->id)) {
                $catId = $kwCat->id;
                $catName = $kwCat->name;
                $catCode = $kwCat->code;
            }
        }

        // A SEASON / monthly car-park pass (Season Holder, CAR PARK SEASON, WSI/Jaya One season,
        // a whole-month billing) is the flat RM80 office-parking subsidy — it must land on the
        // fixed season category, never the per-trip 916-000 line. The model flags it via
        // transaction_type; force the fixed category here so the claimable becomes RM80.
        $isSeasonParking = ($it['transaction_type'] ?? null) === 'season_parking';
        if ($isSeasonParking) {
            $seasonCat = $categories->firstWhere('code', 'PARKING_JAYAONE');
            if ($seasonCat) {
                $catId = $seasonCat->id;
                $catName = $seasonCat->name;
                $catCode = $seasonCat->code;
            }
        }

        // Non-claimable rows (TnG reloads/top-ups, service fees, "other charges") default
        // OFF in the review table. Use the model's transaction_type when present, plus a
        // deterministic keyword fallback so it works even when that field is missing.
        $type = $it['transaction_type'] ?? null;
        $blob = strtolower(trim(($it['vendor'] ?? '').' '.($it['item_description'] ?? '')));
        $nonClaimable = in_array($type, ['reload', 'fee'], true)
            || $blob !== '' && (bool) preg_match('/\b(reload|top[\s-]?up|topup|internet reload|other charges?|service charge|admin (?:fee|charge)|balance b\/f)\b/', $blob);

        // "Item" (receipt-detail) preference:
        //  1. A toll row's ROUTE (Entry → Exit plaza) — most useful, beats a generic "TOLL".
        //  2. The model's own item_description.
        //  3. The merchant/location name (vendor) so it's never blank for a statement row.
        $entry = trim((string) ($it['entry_location'] ?? ''));
        $exit = trim((string) ($it['exit_location'] ?? ''));
        $txnType = $it['transaction_type'] ?? null;
        $route = null;
        if ($entry !== '' || $exit !== '') {
            $loc = $entry !== '' ? $entry : $exit;
            if ($txnType === 'parking') {
                // Parking → single location, "PARKING - <place>" (not a toll plaza route).
                $route = 'PARKING - '.$loc;
            } elseif ($entry !== '' && $exit !== '') {
                // Road toll → show BOTH plazas, "TOLL - Entry - Exit" (even when the same).
                $route = 'TOLL - '.$entry.' - '.$exit;
            } else {
                $route = 'TOLL - '.$loc;
            }
        }
        // A ride / e-hailing receipt (Grab, taxi) → "Pickup → Dropoff" route, like the toll.
        $pickup = trim((string) ($it['pickup_location'] ?? ''));
        $dropoff = trim((string) ($it['dropoff_location'] ?? ''));
        $rideRoute = null;
        if ($pickup !== '' && $dropoff !== '') {
            $rideRoute = $pickup.' → '.$dropoff;
        } elseif ($pickup !== '' || $dropoff !== '') {
            $rideRoute = $pickup !== '' ? $pickup : $dropoff;
        }
        $modelItem = is_string($it['item_description'] ?? null) && trim($it['item_description']) !== ''
            ? trim($it['item_description'])
            : null;
        // A forwarded e-receipt email → use its Subject line (e.g. "Your Grab E-Receipt").
        $emailSubject = is_string($it['email_subject'] ?? null) && trim($it['email_subject']) !== ''
            ? trim($it['email_subject'])
            : null;
        // Priority: season label → toll route → ride route → email subject → model's item → merchant.
        // A season pass always reads "Season parking" (the receipt's own "Current Billing" line is
        // meaningless on the report), so it overrides every other description source.
        $itemDesc = $isSeasonParking
            ? 'Season parking'
            : ($route ?: ($rideRoute ?: ($emailSubject ?: ($modelItem ?: ($it['vendor'] ?? null)))));

        // Who paid: explicit payer → "Bill to" name → account email (when the receipt shows
        // no exact payer, fall back to who it was billed to / the account owner).
        $paidBy = trim((string) ($it['paid_by'] ?? '')) ?: (trim((string) ($it['bill_to'] ?? '')) ?: (trim((string) ($it['account_email'] ?? '')) ?: null));

        return [
            'amount' => $it['amount'] ?? null,
            'gst' => $it['tax_amount'] ?? null,
            'date' => $it['date'] ?? null,
            'vendor' => $it['vendor'] ?? null,
            'item_description' => $itemDesc,
            'paid_by' => $paidBy,
            'category_id' => $catId,
            'category_name' => $catName,
            'category_code' => $catCode,
            'highlighted' => (bool) ($it['highlighted'] ?? false),
            'transaction_type' => $type,
            'non_claimable' => $nonClaimable,
        ];
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

        // #1 — Receipt total vs claimed total (OCR). Skipped for mileage items: their
        // evidence is the route/distance (often a Google Maps screenshot, which has no
        // receipt amount to read), so a "receipt amount" check would be meaningless.
        if (! $item->isMileage() && $item->receipt_path && Storage::disk('local')->exists($item->receipt_path)) {
            $company = $claim->employee->company ?? null;
            if (ClaimReceiptOcrService::enabled($company)) {
                $abs = Storage::disk('local')->path($item->receipt_path);
                $mime = Storage::disk('local')->mimeType($item->receipt_path);
                $data = ClaimReceiptOcrService::extract($abs, $mime, $company);
                if ($data && $data['amount'] !== null) {
                    $receiptAmt = (float) $data['amount'];
                    $claimed = (float) $item->total_with_gst;

                    // A claimed amount BELOW the receipt is legitimate (not a discrepancy)
                    // for fixed subsidies and capped categories — the flat rate / cap
                    // deliberately limits the claim under an expensive receipt (#12b).
                    // We only flag the dangerous direction (claiming MORE than the
                    // receipt) for those; uncapped categories still need an exact match.
                    $category = $item->category;
                    $isFixed = $category && $category->isFixed();
                    $isCapped = $category && ClaimRulesService::effectiveLimit($category, $claim->employee) !== null;
                    $tolerance = max(0.50, $claimed * 0.02);

                    if ($isFixed) {
                        // Flat subsidy — the receipt is evidence only, amount is fixed.
                        $match = true;
                        $reason = 'fixed';
                    } elseif (($isCapped) && $receiptAmt + $tolerance >= $claimed) {
                        // Claimed at/under the receipt because a cap limited it — fine.
                        $match = true;
                        $reason = $receiptAmt > $claimed + $tolerance ? 'capped' : null;
                    } else {
                        $match = abs($receiptAmt - $claimed) <= $tolerance;
                        $reason = null;
                    }

                    $result['receipt'] = [
                        'ok' => true,
                        'receipt_amount' => $receiptAmt,
                        'claimed' => $claimed,
                        'match' => $match,
                        'reason' => $reason, // 'fixed' | 'capped' | null
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
        // A manager who had any item on this claim routed to them for approval may
        // review it — including re-viewing it after it's approved/rejected (#4), even
        // if the employee's reporting line has since changed.
        if ($emp) {
            $isItemApprover = $claim->relationLoaded('items')
                ? $claim->items->contains('approver_id', $emp->id)
                : $claim->items()->where('approver_id', $emp->id)->exists();
            if ($isItemApprover) {
                return;
            }
        }
        abort(403, 'You are not allowed to verify this claim.');
    }

    /** Sales staff are exempt from the mandatory project/client name on each item. */
    private static function isSalesTeam(?Employee $employee): bool
    {
        return $employee && str_contains(strtolower((string) $employee->department), 'sales');
    }

    /** Polite, specific message when a receipt's date doesn't fall in the claim's month. */
    private function outOfMonthMessage(Carbon $receiptDate, ExpenseClaim $claim): string
    {
        $receiptMonth = $receiptDate->format('F Y');           // e.g. "April 2026"
        $claimMonth = Carbon::create($claim->year, $claim->month, 1)->format('F Y'); // "June 2026"

        return "Sorry — this receipt can’t be added to this report. This report is for {$claimMonth}, "
            ."but the receipt is dated {$receiptDate->format('j M Y')}, which falls in {$receiptMonth}. "
            .'Each receipt must be claimed under a report for its own month. '
            ."Please open or create a {$receiptMonth} claim and add this receipt there instead.";
    }

    /**
     * A receipt can only be claimed under its OWN month. The user-entered "Date of Expense"
     * is guarded separately, but the scan ALSO captures the receipt's printed date into the
     * read-only Category-C field (`c_date`). Enforce THAT date is in the claim's period too,
     * so a receipt the OCR read as (say) April can't be filed under a July claim just because
     * the Date of Expense was left/typed as July. Returns the offending receipt date, or null
     * when nothing was scanned (manual / mileage entries have no c_date) or it is in period.
     */
    private function ocrReceiptDateOutOfPeriod(Request $request, ExpenseClaim $claim): ?Carbon
    {
        $raw = trim((string) $request->input('c_date'));
        if ($raw === '') {
            return null;
        }
        try {
            $d = Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null; // unreadable date → fall back to the Date-of-Expense guard, never block on noise
        }

        return ClaimRulesService::itemDateInPeriod($d, $claim->year, $claim->month) ? null : $d;
    }

    private function notifyHr(ExpenseClaim $claim, string $type): void
    {
        // HR approvers (incl. HR Executives) + superadmin — see User::scopeClaimHrRole.
        $hrUsers = \App\Models\User::claimHrRole()
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
        // HR-perspective counts — only claims that reached HR (manager-approved and beyond).
        return [
            'pending_hr' => ExpenseClaim::where('status', 'manager_approved')->count(),
            'hr_approved' => ExpenseClaim::whereIn('status', ['hr_approved', 'paid'])->count(),
            'hr_rejected' => ExpenseClaim::where('status', 'hr_rejected')->count(),
            'total' => ExpenseClaim::whereIn('status', ['manager_approved', 'hr_approved', 'hr_rejected', 'paid'])->count(),
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

    /** Only HR Manager / HR Executive may approve or reject a claim (NOT superadmin). */
    private function authorizeApproveRejectClaims(): void
    {
        if (! Auth::user()->canApproveRejectClaims()) {
            abort(403, 'Only HR may approve or reject claims.');
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
