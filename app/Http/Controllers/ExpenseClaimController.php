<?php

namespace App\Http\Controllers;

use App\Jobs\BuildClaimZipExport;
use App\Mail\ClaimApprovedMail;
use App\Mail\ClaimHrRejectedNoticeMail;
use App\Mail\ClaimRejectedMail;
use App\Mail\ClaimSubmittedMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimItem;
use App\Models\ExpenseClaimLog;
use App\Models\ExpenseClaimPolicy;
use App\Models\ExpenseClaimZipExport;
use App\Models\User;
use App\Services\ClaimReceiptOcrService;
use App\Services\ClaimReportRenderer;
use App\Services\ClaimRulesService;
use App\Services\ClaimZipExportService;
use App\Services\CompanyAttributionService;
use App\Support\ClaimPdfPreview;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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

        $claims = $employee->expenseClaims()->with(['items.category', 'correctionOf:id,claim_number,status'])->orderByDesc('created_at')->get();
        $policy = ExpenseClaimPolicy::forCompany($employee->company);
        $company = Company::forName($employee->company);

        $drafts = $claims->whereIn('status', ['draft', 'manager_rejected', 'hr_rejected', 'reversed'])->values();

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
                    } catch (UniqueConstraintViolationException $e) {
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
            // The covered period is the ONE Category-C field the employee may type, so it is
            // the one that needs real input validation rather than a silent normalise.
            'c_period_start' => 'nullable|date',
            'c_period_end' => 'nullable|date',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'receipt_attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'support_files' => 'nullable|array|max:10',
            'support_files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
        ]);

        $category = ExpenseCategory::findOrFail($validated['expense_category_id']);
        if (! ClaimRulesService::categoryAllowed($employee, $category)) {
            return response()->json(['ok' => false, 'errors' => ['expense_category_id' => 'You are not eligible to claim under this category.']], 422);
        }

        // A hand-typed covered period is told what is wrong with it, rather than being dropped
        // and reported as a wrong-month receipt.
        if ($coverageError = $this->coverageInputError($request)) {
            return response()->json(['ok' => false, 'message' => $coverageError, 'errors' => ['c_period_start' => $coverageError]], 422);
        }

        // Likewise a hand-corrected receipt total: an unreadable one is explained rather than
        // ignored, or the employee's correction is dropped and they are re-blocked against the
        // very figure they were fixing.
        if ($totalError = $this->receiptTotalInputError($request)) {
            return response()->json(['ok' => false, 'message' => $totalError, 'errors' => ['c_total' => $totalError]], 422);
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
        // manually-set Date of Expense. A receipt that states the PERIOD it pays for (a season
        // pass, a subscription term) is judged on that period instead; see the method.
        if ($ocrDate = $this->ocrReceiptDateOutOfPeriod($request, $claim)) {
            return response()->json([
                'ok' => false,
                'message' => $this->outOfMonthMessage($ocrDate, $claim, $this->receiptCoverage($request)),
                'errors' => ['expense_date' => 'The scanned receipt’s date is not from this claim’s month.'],
            ], 422);
        }

        $projectClient = $claim->project_client;

        // Amount: fixed-subsidy = flat rate; mileage = km × vehicle rate (server-authoritative);
        // everything else uses the entered amount.
        $isMileageCat = $category->isMileageClaim();
        $isCapped = ClaimRulesService::effectiveLimit($category, $employee) !== null;
        $gst = (float) ($validated['gst_amount'] ?? 0);
        $quantity = null;
        $unit = null;
        $rateApplied = null;
        $mileageDest = null;
        if ($category->isFixed() && ! $isCapped) {
            $amount = (float) ($category->rate_amount ?? 0);
            $gst = 0;
        } elseif ($isMileageCat) {
            $quantity = $request->input('c_km') !== null && $request->input('c_km') !== '' ? (float) $request->input('c_km') : null;
            if ($quantity === null || $quantity <= 0) {
                return response()->json(['ok' => false, 'errors' => ['amount' => 'Enter the distance (km) for the mileage claim.']], 422);
            }
            $rateApplied = ClaimRulesService::mileageRate($request->input('c_vehicle', 'car'));
            // Amount pre-fills from km × rate but is EDITABLE: the employee may claim LESS
            // (allowed), while claiming MORE than the calculated mileage is blocked.
            ['amount' => $amount, 'error' => $mileageErr] = $this->mileageAmount($validated, round($quantity * $rateApplied, 2));
            if ($mileageErr !== null) {
                return response()->json(['ok' => false, 'errors' => ['amount' => $mileageErr]], 422);
            }
            $gst = 0;
            $unit = 'km';
            $mileageDest = $request->input('c_itemdesc') ? mb_substr(strip_tags((string) $request->input('c_itemdesc')), 0, 255) : null;
        } elseif ($isCapped) {
            // Capped category (intern Medical, Optical & Dental, Support Allowance, Season parking):
            // the claimable is min(receipt total, remaining cap) — NOT a user-typed figure. Read the
            // OCR-captured receipt total (c_total); if OCR read nothing (it fails open) fall back to
            // the typed amount. Either way capAdjust below caps it to the remaining allowance, so the
            // stored amount is min(receipt, remaining). SST is folded into the receipt total.
            $receiptTotal = $request->input('c_total');
            if (is_numeric($receiptTotal) && (float) $receiptTotal > 0) {
                $amount = (float) $receiptTotal;
            } else {
                $amount = isset($validated['amount']) ? (float) $validated['amount'] : null;
                if ($amount === null || $amount <= 0) {
                    return response()->json(['ok' => false, 'errors' => ['amount' => 'Enter the amount for this item.']], 422);
                }
            }
            $gst = 0;
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
        $deadStatuses = ['manager_rejected', 'hr_rejected', 'reversed', 'cancelled'];
        $warnings = [];

        // Same-expense guard: an identical line (same date + description + amount) already on one
        // of the employee's active claims. For a RECEIPT this hard-blocks (catches the same expense
        // re-uploaded as a DIFFERENT image). For MILEAGE it is only a SOFT WARNING — the same route
        // on the same day can be a genuine repeat trip. Skipped for batch (statement) adds.
        if (! $request->boolean('batch')) {
            $cleanDescription = strip_tags((string) $request->input('description'));
            $dupItem = ExpenseClaimItem::whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses))
                ->where('expense_date', $expenseDate->toDateString())
                ->where('description', $cleanDescription)
                ->where('amount', $amount)
                ->first();
            if ($dupItem) {
                if ($isMileageCat) {
                    $warnings[] = 'You already claimed this route on '.$expenseDate->format('j M Y').' for the same distance (claim '.($dupItem->claim->claim_number ?? '—').') — add it only if this is a separate trip.';
                } else {
                    return response()->json(['ok' => false, 'errors' => ['description' => 'A similar expense already exists (same date, description & amount) in claim '.($dupItem->claim->claim_number ?? 'another claim').'.']], 422);
                }
            }
        }

        $receiptPath = null;
        $receiptHash = null;
        $receiptPaths = [];
        if ($request->hasFile('receipt')) {
            // Hash only for now (no storage) so a "needs confirm" bounce below has no side effects.
            $receiptHash = hash_file('sha256', $request->file('receipt')->getRealPath());
            // Batch add (multi-receipt review): the ONE image backs every row → skip dedup.
            if (! $request->boolean('batch')) {
                $dup = ExpenseClaimItem::whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses))
                    ->where('receipt_hash', $receiptHash)->with('claim')->first();
                if ($dup) {
                    if ($isMileageCat) {
                        // A Google Maps screenshot is a distance reference, not a one-time receipt —
                        // the same map is expected for a repeated trip. Warn (confirm), don't block.
                        $warnings[] = 'You’ve uploaded this same map screenshot before (claim '.($dup->claim->claim_number ?? '—').') — that’s fine for a repeat trip; just make sure this is a genuine separate journey.';
                    } else {
                        return response()->json(['ok' => false, 'errors' => ['receipt' => 'This receipt has already been uploaded in '.($dup->claim->claim_number ?? 'another claim').'.']], 422);
                    }
                }
            }
        }

        // Confirm-before-add: a mileage repeat (route and/or map already claimed) is not blocked,
        // but we ASK first — return needs_confirm and create the item only when the client
        // re-submits with confirm_duplicate set. Nothing has been stored/created up to here.
        if ($warnings && ! $request->boolean('confirm_duplicate')) {
            return response()->json(['ok' => false, 'needs_confirm' => true, 'warning' => implode(' ', array_unique($warnings))]);
        }

        if ($request->hasFile('receipt')) {
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
            // The covered period is the ONE Category-C field the employee may type, so it is
            // the one that needs real input validation rather than a silent normalise.
            'c_period_start' => 'nullable|date',
            'c_period_end' => 'nullable|date',
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

        // A hand-typed covered period is told what is wrong with it, rather than being dropped
        // and reported as a wrong-month receipt.
        if ($coverageError = $this->coverageInputError($request)) {
            return response()->json(['ok' => false, 'message' => $coverageError, 'errors' => ['c_period_start' => $coverageError]], 422);
        }

        // Same for a hand-corrected receipt total — see inlineAddItem().
        if ($totalError = $this->receiptTotalInputError($request)) {
            return response()->json(['ok' => false, 'message' => $totalError, 'errors' => ['c_total' => $totalError]], 422);
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

        // ...and the receipt's OWN printed (scanned) date must be in-month too — unless the
        // receipt states the period it pays for and that period reaches this claim's month.
        if ($ocrDate = $this->ocrReceiptDateOutOfPeriod($request, $claim)) {
            return response()->json([
                'ok' => false,
                'message' => $this->outOfMonthMessage($ocrDate, $claim, $this->receiptCoverage($request)),
                'errors' => ['expense_date' => 'The scanned receipt’s date is not from this claim’s month.'],
            ], 422);
        }

        $isMileageCat = $category->isMileageClaim();
        $isCapped = ClaimRulesService::effectiveLimit($category, $employee) !== null;
        $gst = (float) ($validated['gst_amount'] ?? 0);
        $quantity = null;
        $unit = null;
        $rateApplied = null;
        $mileageDest = null;
        if ($category->isFixed() && ! $isCapped) {
            $amount = (float) ($category->rate_amount ?? 0);
            $gst = 0;
        } elseif ($isMileageCat) {
            $quantity = $request->input('c_km') !== null && $request->input('c_km') !== '' ? (float) $request->input('c_km') : null;
            if ($quantity === null || $quantity <= 0) {
                return response()->json(['ok' => false, 'errors' => ['amount' => 'Enter the distance (km) for the mileage claim.']], 422);
            }
            $rateApplied = ClaimRulesService::mileageRate($request->input('c_vehicle', 'car'));
            // Amount pre-fills from km × rate but is EDITABLE: the employee may claim LESS
            // (allowed), while claiming MORE than the calculated mileage is blocked.
            ['amount' => $amount, 'error' => $mileageErr] = $this->mileageAmount($validated, round($quantity * $rateApplied, 2));
            if ($mileageErr !== null) {
                return response()->json(['ok' => false, 'errors' => ['amount' => $mileageErr]], 422);
            }
            $gst = 0;
            $unit = 'km';
            $mileageDest = $request->input('c_itemdesc') ? mb_substr(strip_tags((string) $request->input('c_itemdesc')), 0, 255) : null;
        } elseif ($isCapped) {
            // Capped category (intern Medical, Optical & Dental, Support Allowance, Season parking):
            // the claimable is min(receipt total, remaining cap) — NOT a user-typed figure. Read the
            // OCR-captured receipt total (c_total); if OCR read nothing (it fails open) fall back to
            // the typed amount. Either way capAdjust below caps it to the remaining allowance, so the
            // stored amount is min(receipt, remaining). SST is folded into the receipt total.
            $receiptTotal = $request->input('c_total');
            if (is_numeric($receiptTotal) && (float) $receiptTotal > 0) {
                $amount = (float) $receiptTotal;
            } else {
                $amount = isset($validated['amount']) ? (float) $validated['amount'] : null;
                if ($amount === null || $amount <= 0) {
                    return response()->json(['ok' => false, 'errors' => ['amount' => 'Enter the amount for this item.']], 422);
                }
            }
            $gst = 0;
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

        // Same-expense guard (excludes THIS item): don't let an edit turn this line into a
        // duplicate of another active-claim line with the same date + description + amount.
        // Hard block for receipts; soft warning for mileage (same route/day may be a real trip).
        $deadStatuses = ['manager_rejected', 'hr_rejected', 'reversed', 'cancelled'];
        $warnings = [];
        $cleanDescription = strip_tags((string) $request->input('description'));
        $dupItem = ExpenseClaimItem::whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses))
            ->where('id', '!=', $item->id)
            ->where('expense_date', $expenseDate->toDateString())
            ->where('description', $cleanDescription)
            ->where('amount', $amount)
            ->first();
        if ($dupItem) {
            if ($isMileageCat) {
                $warnings[] = 'You already claimed this route on '.$expenseDate->format('j M Y').' for the same distance (claim '.($dupItem->claim->claim_number ?? '—').') — keep it only if this is a separate trip.';
            } else {
                return response()->json(['ok' => false, 'errors' => ['description' => 'A similar expense already exists (same date, description & amount) in claim '.($dupItem->claim->claim_number ?? 'another claim').'.']], 422);
            }
        }

        // Optional receipt replacement (keeps the existing one when no new file).
        $receiptPath = $item->receipt_path;
        $receiptHash = $item->receipt_hash;
        $oldToDelete = null;
        $newHash = null;
        if ($request->hasFile('receipt')) {
            // Hash only for now; storage happens after the confirm gate below (no side effects).
            $newHash = hash_file('sha256', $request->file('receipt')->getRealPath());
            $dup = ExpenseClaimItem::whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)->whereNotIn('status', $deadStatuses))
                ->where('id', '!=', $item->id)->where('receipt_hash', $newHash)->with('claim')->first();
            if ($dup) {
                if ($isMileageCat) {
                    $warnings[] = 'You’ve uploaded this same map screenshot before (claim '.($dup->claim->claim_number ?? '—').') — that’s fine for a repeat trip; just make sure this is a genuine separate journey.';
                } else {
                    return response()->json(['ok' => false, 'errors' => ['receipt' => 'This receipt has already been uploaded in '.($dup->claim->claim_number ?? 'another claim').'.']], 422);
                }
            }
        }

        // Confirm-before-save for a mileage repeat (same as the add path): add only on confirm.
        if ($warnings && ! $request->boolean('confirm_duplicate')) {
            return response()->json(['ok' => false, 'needs_confirm' => true, 'warning' => implode(' ', array_unique($warnings))]);
        }

        if ($request->hasFile('receipt')) {
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
        // The item row already points at the new file, so nothing here needs excluding.
        $this->releaseReceiptFiles(array_merge((array) $oldToDelete, $oldSupportingToDelete));

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
        // Deleting a correction draft must not take the ORIGINAL claim's receipts with it —
        // makeCorrection() copies receipt paths by reference, so the two share files on disk.
        // Exclude this claim's own items (they are about to go) and keep anything still cited.
        $ownIds = $claim->items->pluck('id')->all();
        $paths = $claim->items->flatMap(fn ($it) => $it->attachmentPaths())->all();
        $this->releaseReceiptFiles($paths, $ownIds);
        $number = $claim->claim_number;
        $claim->items()->delete();
        $claim->delete();

        return redirect()->route('user.claims.index')->with('success', 'Draft '.$number.' deleted.');
    }

    /**
     * Resolve the claimable amount for a mileage (Petrol) line. The amount field is editable:
     * it pre-fills from the calculated km × rate ($computed), the employee may claim LESS
     * (allowed), but claiming MORE than $computed is blocked — mirroring the My Claims page's
     * counter-check. A blank amount falls back to the full calculated figure.
     *
     * @return array{amount: float, error: ?string}
     */
    private function mileageAmount(array $validated, float $computed): array
    {
        $entered = isset($validated['amount']) && $validated['amount'] !== '' ? (float) $validated['amount'] : null;
        if ($entered !== null && $entered > 0) {
            if (round($entered, 2) > $computed + 0.001) {
                return ['amount' => $computed, 'error' => 'You can’t claim more than the calculated mileage of RM '.number_format($computed, 2).'. Lower the amount and try again.'];
            }

            return ['amount' => round($entered, 2), 'error' => null];
        }

        return ['amount' => $computed, 'error' => null];
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
            // Name the field that clears this. When the scan misreads the total the block has no
            // other way out — lowering the amount to a figure the receipt does not carry is
            // under-claiming, not a correction. Mirrors outOfMonthMessage() naming "Date on
            // receipt" for the same class of misread.
            return 'You can’t claim more than the receipt total of RM '.number_format((float) $receiptTotal, 2)
                .'. Lower the amount — or, if the scan misread the receipt, correct “Total paid” under Receipt details.';
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
            'is_mileage' => $item->isMileage(),
            'ocr' => $item->ocr_details ?: null,
        ];
    }

    /**
     * Delete an item AND every sibling read from the SAME attachment (a bulk statement scan
     * splits one image into several items that share a receipt_hash) — there's no editing, so
     * a wrong line is fixed by deleting the whole attachment's items and adding them again.
     * MILEAGE items are the exception: the same Google Maps screenshot legitimately backs
     * SEPARATE trips (e.g. "Travelling" + "Travel back"), so they're always deleted on their
     * own even though they share a hash. Returns the deleted item IDs.
     */
    private function deleteItemGroup(ExpenseClaimItem $item): array
    {
        $claim = $item->claim;
        $group = ($item->receipt_hash && ! $item->isMileage())
            ? $claim->items()->where('receipt_hash', $item->receipt_hash)->get()
            : collect([$item]);
        if ($group->isEmpty()) {
            $group = collect([$item]);
        }

        // Same sharing caveat as destroyClaim(): a correction's items point at the ORIGINAL's
        // files, so the group's own ids are excluded and anything still cited elsewhere stays.
        $ids = $group->pluck('id')->all();
        $paths = $group->flatMap(fn ($gi) => array_merge($gi->attachmentPaths(), $gi->supportingPaths()))->all();
        $this->releaseReceiptFiles($paths, $ids);

        foreach ($group as $gi) {
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
        // The period the receipt says it pays for, normalised (a backwards, half-read or
        // absurdly long range becomes nothing rather than something the report would print
        // as fact). Stored because it is the ONLY thing on the record explaining why a
        // receipt dated outside the month was accepted into it.
        $coverage = $this->receiptCoverage($request);

        $details = array_filter([
            'company' => $request->input('c_company'),
            'item_description' => $request->input('c_itemdesc'),
            'date' => $request->input('c_date'),
            'period_start' => $coverage ? $coverage[0]->toDateString() : null,
            'period_end' => $coverage ? $coverage[1]->toDateString() : null,
            // WHO said so. The report prints this period as the justification for accepting a
            // receipt dated outside the claim month, so an approver has to be able to tell a
            // figure read off the document from one a person typed. Only stamped when the
            // period survived normalisation — labelling nothing would be noise.
            'period_source' => $coverage && $this->coverageWasTyped($request) ? 'manual' : null,
            // Same question about the printed date, which the employee may now correct when the
            // scan misreads it. Only stamped when there IS a date, for the same reason.
            'date_source' => trim((string) $request->input('c_date')) !== '' && $this->receiptDateWasTyped($request) ? 'manual' : null,
            'paid_by' => $request->input('c_paidby'),
            'total' => $request->input('c_total'),
            // ...and about the printed total, which the employee may now correct when the scan
            // misreads it. This is the figure the over-claim guard let the item through on, so
            // an approver comparing the report against the image must be able to see that a
            // person authored it. Only stamped when there IS a total, as above.
            'total_source' => trim((string) $request->input('c_total')) !== '' && $this->receiptTotalWasTyped($request) ? 'manual' : null,
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
        // Stamp the company for this claim's submission cycle from the timeline, so
        // it stays put if the employee later moves (and is correct even for a
        // back-dated claim submitted after a move). See CompanyAttributionService.
        $submitCompany = app(CompanyAttributionService::class)->companyForClaim($claim);
        DB::transaction(function () use ($claim, $approverId, $submitCompany) {
            $claim->items()->update([
                'approver_id' => $approverId,
                'manager_status' => 'pending',
                'manager_remarks' => null,
                'review_status' => 'approved',
                'is_locked' => true,
            ]);
            $claim->update(['status' => 'submitted', 'submitted_at' => now(), 'manager_id' => $approverId, 'company' => $submitCompany]);
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

        // Full eligibility — entity scope (incl. timeline-based 'ever' benefits), role, and any
        // per-employee restriction — via the single source of truth that also builds the dropdown.
        if (! ClaimRulesService::categoryAllowed($employee, $category)) {
            return back()->withErrors(['expense_category_id' => 'This category is not available to you.'])->withInput();
        }

        // Petrol is always a per-km mileage claim (car/motorcycle rate). The origin
        // and destination are chosen by the employee; distance is the evidence, not a
        // receipt. (The legacy "by receipt" Petrol mode has been removed.)
        $isPetrolMileage = $category->isMileageClaim();

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
        $deadStatuses = ['manager_rejected', 'hr_rejected', 'reversed', 'cancelled'];
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
        // Full eligibility (entity scope incl. timeline-based 'ever' benefits, role, per-employee).
        if (! ClaimRulesService::categoryAllowed($employee, $category)) {
            return back()->withErrors(['expense_category_id' => 'This category is not available to you.'])->withInput();
        }

        $isPetrolMileage = $category->isMileageClaim();

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
        $deadStatuses = ['manager_rejected', 'hr_rejected', 'reversed', 'cancelled'];
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

        // The item row above already points at the replacement, so nothing needs excluding —
        // but a correction shares its files with the frozen original, so still check.
        $this->releaseReceiptFiles(array_merge([$oldReceiptToDelete], $attachmentsToDelete));

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

        $company = Company::forName($employee->company);

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
        $company = Company::forName($claim->resolvedCompany());

        $approverId = $request->query('approver');
        $items = $approverId ? $claim->items->where('approver_id', (int) $approverId)->values() : $claim->items;
        $approver = $approverId ? Employee::find((int) $approverId) : null;

        return view('user.claims.report-print', compact('claim', 'company', 'items', 'approver'));
    }

    /**
     * Render one claim to raw PDF bytes: the dompdf form (with embedded receipt images)
     * plus, via ClaimReportRenderer, any PDF receipts/supporting documents reproduced as
     * real pages after it — dompdf itself can never embed another PDF.
     */
    private function buildClaimPdf(ExpenseClaim $claim): string
    {
        // Per-IMAGE cost, so this matters for a single claim too, not just the batch export:
        // one 5-megapixel receipt is ~22 MB of GD buffer inside dompdf and a claim may carry
        // several. Idempotent and cheap, so the ZIP loop calling it repeatedly is harmless.
        $this->raisePdfMemoryFloor();

        $claim->loadMissing('items.category', 'employee', 'managerApprover', 'manager', 'hrApprover');
        $company = Company::forName($claim->resolvedCompany());
        $items = $claim->items;

        return ClaimReportRenderer::render($claim, $company, $items);
    }

    /** Download one claim as a PDF, named like the original forms. Owner or reviewer. */
    public function downloadClaimPdf(ExpenseClaim $claim)
    {
        $user = Auth::user();
        $isOwner = $user->employee && $claim->employee_id === $user->employee->id;
        if (! $isOwner && ! $user->canViewAllClaims()) {
            $this->authorizeReview($claim);
        }

        $bytes = $this->buildClaimPdf($claim);
        $filename = $claim->pdfFilename();

        // Mirrors Barryvdh\DomPDF\PDF::download() exactly (content-type, content-length,
        // and an ASCII fallback filename for the Content-Disposition header) — the bytes
        // now come from ClaimReportRenderer rather than the dompdf wrapper's own output().
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                'attachment', $filename, str_replace('%', '', Str::ascii($filename))
            ),
            'Content-Length' => strlen($bytes),
        ]);
    }

    /**
     * HR: kick off a background export of all approved claims (optionally filtered) as a
     * single ZIP of PDFs, each named like the original form — ready to bulk-upload elsewhere.
     *
     * Rendering happens in BuildClaimZipExport, off the request entirely, so it is never
     * bound by a server timeout — every matching claim renders, however many there are (see
     * ExpenseClaimZipExport's migration for why this is no longer a synchronous download).
     * The page polls zipExportStatus() and offers downloadZipExport() once ready.
     */
    public function requestZipExport(Request $request, ClaimZipExportService $zipExportService)
    {
        $this->authorizeViewClaims();

        if (! class_exists(\ZipArchive::class)) {
            return response()->json(['ok' => false, 'error' => 'ZIP export isn’t available on this server (the PHP “zip” extension is disabled). Please download the claims individually, or ask IT to enable it.'], 422);
        }

        $year = ((int) $request->input('year')) ?: null;
        $month = ((int) $request->input('month')) ?: null;
        $companies = array_values(array_filter((array) $request->input('company', []), fn ($v) => $v !== '' && $v !== null));
        $employeeIds = array_values(array_filter((array) $request->input('employee_id', [])));

        // An explicit approval-date window, when the operator picked one, otherwise the cutoff
        // cycle. Reported as a 422 with the reason rather than silently falling back to the
        // cycle: a half-typed or reversed range that quietly exported a different period would
        // be indistinguishable from a correct export until somebody reconciled the totals.
        [$from, $to, $rangeError] = $this->claimDateRange($request);
        if ($rangeError) {
            return response()->json(['ok' => false, 'error' => $rangeError], 422);
        }

        // A request carrying NEITHER a window NOR a cycle has no period at all, and that is not
        // the same thing as asking for everything. matchingClaims() applies no date bound
        // whatsoever when $year is null (cycleFetchRange returns [null, null] and the cycle
        // filter short-circuits), so such a request quietly exported every approved claim in
        // the database — which is precisely how a period the operator DID pick, dropped in
        // transit, produced a plausible-looking export for the wrong period instead of an
        // error they could see. Refused for the same reason claimDateRange() refuses a
        // half-typed range: a wrong export is only ever discovered by reconciling totals.
        // The message names a STALE PAGE, not a missing choice, because that is the only way a
        // real operator reaches this. The modal always posts its hidden `year` — that input is
        // never disabled by any path — so a request from a current page cannot arrive with no
        // period at all. Arriving with nothing means the body was empty, which in practice
        // means the browser is still running a pre-deploy copy of the page (the form is locked
        // before it is read there, so nothing is submitted). Telling that operator to "pick a
        // period" points at two date fields they can see already filled in.
        if (! ($from && $to) && ! $year) {
            return response()->json([
                'ok' => false,
                'error' => 'The export form sent no period at all — this usually means your page is out of date. Please reload the page and try again.',
            ], 422);
        }

        // A quick synchronous check so an empty filter fails instantly instead of showing a
        // progress bar for a job that would immediately report nothing to do.
        $matched = $from && $to
            ? $zipExportService->claimsApprovedBetween($from, $to, $companies, $employeeIds)
            : $zipExportService->matchingClaims($year, $month, $companies, $employeeIds);

        if ($matched->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => $from && $to
                    ? 'No claims were approved by both Manager and HR between '.$from->format('j M Y').' and '.$to->format('j M Y').'.'
                    : 'No processed claims match the current filter.',
            ], 422);
        }

        $export = ExpenseClaimZipExport::create([
            'requested_by_id' => Auth::id(),
            // A request carries EITHER a window or a cycle, never both — storing the cycle
            // alongside a range would leave the row describing two different periods, and the
            // job would have to guess which the operator meant.
            'year' => $from && $to ? null : $year,
            'month' => $from && $to ? null : $month,
            'from_date' => $from?->toDateString(),
            'to_date' => $to?->toDateString(),
            'companies' => $companies ?: null,
            'employee_ids' => $employeeIds ?: null,
            'status' => ExpenseClaimZipExport::STATUS_QUEUED,
            'total_matched' => $matched->count(),
        ]);

        BuildClaimZipExport::dispatch($export->id);

        return response()->json(['ok' => true, 'export_id' => $export->id, 'total_matched' => $matched->count()]);
    }

    /** Poll target for the export modal. Any authorized viewer may check any export's status. */
    public function zipExportStatus(ExpenseClaimZipExport $export)
    {
        $this->authorizeViewClaims();

        // Parts are listed even when there is only one, so the page has a single shape to
        // render rather than two branches that can disagree about what is downloadable.
        $parts = $export->isReady()
            ? collect($export->partList())->values()->map(fn ($p, $i) => [
                'number' => $i + 1,
                'size' => (int) ($p['size'] ?? 0),
                'claims' => $p['claims'] ?? null,
                'url' => route('hr.claims.download-zip.file', ['export' => $export->id, 'part' => $i + 1]),
                // The page fetches the bytes itself to show progress, so it has to name the
                // saved file — the Content-Disposition never reaches a blob download. Taken
                // from the model so the naming convention lives in exactly one place.
                'filename' => $export->partFilename($i + 1),
            ])->all()
            : [];

        return response()->json([
            'status' => $export->status,
            'total_matched' => $export->total_matched,
            'rendered_count' => $export->rendered_count,
            'ready' => $export->isReady(),
            'failed' => $export->status === ExpenseClaimZipExport::STATUS_FAILED,
            'error' => $export->error,
            'failed_claims' => $export->failed_claims,
            'omitted_claims' => $export->omitted_claims,
            'file_size' => $export->file_size,
            'total_size' => $export->isReady() ? $export->totalSize() : null,
            'part_count' => count($parts),
            'parts' => $parts,
            'download_url' => $export->isReady() ? route('hr.claims.download-zip.file', $export) : null,
        ]);
    }

    /**
     * Serves the finished archive — RESUMABLY. The resumability is the whole point.
     *
     * Measured on production 2026-09-08: export #9 was 227.7 MiB (96 claims, mean 2.55 MiB
     * each) and HR could not get it down. Seventeen consecutive attempts across two exports,
     * every one of them ending part-way — nginx logged HTTP 200 with a $body_bytes_sent of
     * only 61–64 MiB each time, cloudflared logged "stream canceled by remote", and the
     * browser reported ERR_HTTP2_PING_FAILED (its HTTP/2 connection had gone dead). PHP,
     * Apache and nginx all logged no error whatsoever.
     *
     * The origin was never at fault, and this was proven rather than assumed: a 250 MB probe
     * pushed through the identical chain — the same 1 MB read/echo/flush loop this method
     * used to run, PHP-FPM → Apache → nginx → cloudflared → Cloudflare → client — completed
     * in full, twice, at 20 MB/s. What differed was that a dropped transfer of the probe
     * could be resumed and a dropped transfer of the export could not: the old
     * response()->stream() advertised no Accept-Ranges, carried no validator, and ignored a
     * Range: request, so every drop restarted from byte 0. At 227.7 MiB and growing (it was
     * ~217 MiB five days earlier) that is a download with no way to finish, which is exactly
     * what seventeen attempts and zero files look like.
     *
     * BinaryFileResponse answers Range/If-Range with 206 Partial Content, so an interrupted
     * download continues from where it stopped instead of starting again — that is what turns
     * a flaky link from a permanent failure into a pause. The ETag and Last-Modified are not
     * decoration: If-Range validates the resume against them, and without a validator the
     * browser must re-fetch the whole file.
     *
     * The ETag is built from the row id + byte size + mtime rather than Symfony's
     * setAutoEtag(), which hashes the entire file — a resumed download issues several
     * requests and each would re-read 227 MiB off the array to answer. The archive is written
     * exactly once by BuildClaimZipExport and never mutated afterwards, so that triple is a
     * strong validator at no cost.
     *
     * The output-buffer drain below is UNCHANGED and still load-bearing. This pool's php.ini
     * sets output_buffering=4096, which starts every script already wrapped in an implicit
     * output buffer that was observed not auto-flushing under this FastCGI pool — it silently
     * accumulated the entire response before it ever reached Apache, and killed a real export
     * with "Allowed memory size of 134217728 bytes exhausted (tried to allocate 227790848
     * bytes)" (in the Apache error log, 2026-09-04). BinaryFileResponse::sendContent() writes
     * to php://output and does NOT flush per chunk, so it is every bit as exposed to that
     * buffer as the fpassthru() that originally hit it. Draining first removes the buffer
     * from the pipeline entirely, after which each chunked fwrite() goes straight to the SAPI
     * and peak memory is bounded by setChunkSize() no matter how large a future export grows.
     *
     * Deliberately done HERE rather than at send time: this only ever closes a buffer that
     * predates the method (php.ini's implicit one), never one a caller opens afterwards to
     * capture the output — which is what ClaimZipExportTest::readZip() does.
     *
     * Skipped under the test runner: PHPUnit wraps each test in its own output buffer, which
     * is likewise "there before this method runs" and would be caught by the same drain,
     * closing a buffer this code doesn't own — harmless, but correctly flagged "risky". The
     * live pool's non-flushing implicit buffer does not exist in the CLI test SAPI anyway.
     */
    public function downloadZipExport(ExpenseClaimZipExport $export, ?string $part = null)
    {
        $this->authorizeViewClaims();

        // A large export is split into parts, because a single transfer that big could not be
        // made to arrive (see ExpenseClaimZipExport::partList()). Part 1 is the default so the
        // original one-argument URL — which is what the page's own JS and any bookmark still
        // use — keeps meaning what it always did for a single-part export.
        $number = max(1, (int) $part);
        $entry = $export->partAt($number);

        if (! $export->isReady() || ! $entry || empty($entry['path']) || ! Storage::disk('local')->exists($entry['path'])) {
            abort(404, 'This export is not ready, failed, or has already expired.');
        }

        if (! app()->runningUnitTests()) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }

        $absolutePath = Storage::disk('local')->path($entry['path']);
        $filename = $export->partFilename($number);

        $response = new BinaryFileResponse(
            $absolutePath,
            200,
            ['Content-Type' => 'application/zip'],
            // NOT public. BinaryFileResponse's constructor defaults this to true, which emits
            // `Cache-Control: public` — and this archive is every approved claimant's receipts
            // travelling over a public CDN. A shared cache would be entitled to store it and
            // hand it back without the auth check above ever running again.
            public: false,
            // Set by hand below: setAutoEtag() hashes the whole file, and a resumed download
            // asks more than once.
            autoEtag: false,
            // One half of what If-Range validates a resume against.
            autoLastModified: true,
        );

        $response->setPrivate();

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
            str_replace('%', '', Str::ascii($filename))
        );

        // Matches the 1 MB the hand-rolled loop used. Symfony's own default is 16 KB, which
        // would be ~14,600 write syscalls for an archive this size.
        $response->setChunkSize(1024 * 1024);

        // Part number included so two parts of one export can never collide on a validator —
        // an If-Range that matched the wrong part would splice two different archives together
        // into a file that looks downloaded and is not a readable ZIP.
        $response->setEtag(sha1($export->id.'|'.$number.'|'.filesize($absolutePath).'|'.filemtime($absolutePath)));

        return $response;
    }

    /**
     * Delete receipt files that are genuinely finished with — and ONLY those.
     *
     * A receipt file is NOT owned by one row. `makeCorrection()` copies `receipt_path` and
     * `receipt_paths` straight into the new claim's items, so a correction and the frozen
     * original it corrects point at the SAME bytes on disk. Every delete here used to assume
     * sole ownership, which meant replacing a receipt while fixing a rejected claim — or
     * simply deleting the correction draft — silently destroyed the evidence attached to a
     * claim that is deliberately frozen as history. Two live correction chains in production
     * share 10 files this way, one side of each being an hr_approved (paid) report.
     *
     * So: drop the file only when no OTHER claim item still cites it. `$exceptItemIds` is for
     * callers that delete files before deleting the rows (destroyClaim, deleteItemGroup) —
     * those rows are on their way out and must not count as a reason to keep the file.
     * Callers that have already re-pointed the row can pass nothing.
     */
    private function releaseReceiptFiles(array $paths, array $exceptItemIds = []): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            $stillReferenced = ExpenseClaimItem::query()
                ->when($exceptItemIds, fn ($q) => $q->whereNotIn('id', $exceptItemIds))
                ->where(fn ($q) => $q->where('receipt_path', $path)
                    ->orWhereJsonContains('receipt_paths', $path)
                    ->orWhereJsonContains('supporting_paths', $path))
                ->exists();

            if (! $stillReferenced) {
                Storage::disk('local')->delete($path);
                // The rasterised pages go with the document they depict. Left behind they are
                // a picture of a receipt nothing can trace to a claim — worse than no preview,
                // because it still renders. Safe under the shared-file rule above for the same
                // reason the delete itself is: we only reach here when NO row cites the file.
                ClaimPdfPreview::forget($path);
            }
        }
    }

    /**
     * Raise this request's memory ceiling to config('claims.pdf_memory_limit') — never
     * lower it, and never touch an unlimited (-1) or already-generous limit. Same convention
     * (and same reasoning) as CaptureService::raiseMemoryFloor() in the Email Workflow engine:
     * a flat ini_set is a ceiling as often as it is a floor, and quietly capping a pool that
     * was deliberately given more room is the opposite of what this call is for.
     *
     * Best-effort by design: a pool that pins memory_limit via php_admin_value wins, and the
     * export's streaming already keeps peak memory flat without any of this.
     */
    private function raisePdfMemoryFloor(): void
    {
        $floor = (string) config('claims.pdf_memory_limit', '');
        if ($floor === '') {
            return;
        }

        $current = ini_get('memory_limit');
        // -1 means unlimited: already better than anything we would set.
        if ($current === false || trim((string) $current) === '-1') {
            return;
        }

        $toBytes = function (string $value): int {
            $value = trim($value);
            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };
        };

        if ($toBytes((string) $current) < $toBytes($floor)) {
            @ini_set('memory_limit', $floor);
        }
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

        // Stamp the company for this claim's submission cycle from the timeline (see
        // the first submit path above and CompanyAttributionService::companyForClaim).
        $submitCompany = app(CompanyAttributionService::class)->companyForClaim($claim);
        DB::transaction(function () use ($claim, $approverId, $submitCompany) {
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
                'company' => $submitCompany,
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
        $employee = Auth::user()->employee;
        // Score only the employee's eligible categories, so timeline-based 'ever' benefits
        // (e.g. Optical & Dental for an ex-Claritas mover) are detectable and no ineligible
        // category is ever suggested.
        $candidates = $employee ? ClaimRulesService::categoriesFor($employee) : null;

        $category = ExpenseCategory::detectFromDescription($description, $employee?->company, $candidates);

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
        $query = ExpenseClaim::whereIn('status', ['submitted', 'manager_approved', 'manager_rejected', 'hr_approved', 'hr_rejected', 'reversed', 'paid'])
            ->with(['employee', 'items.category', 'items.approver', 'correctionOf'])
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

        $company = Company::forName($claim->resolvedCompany());
        $items = $claim->items;
        $approver = $claim->manager ?? $claim->managerApprover;

        // Reverse: HR can un-approve a FULLY-approved claim right here on the review page,
        // reusing the reject page's per-item flag mechanism. Only for hr_approved + HR role.
        $canReverse = $claim->status === 'hr_approved' && $user->canApproveRejectClaims();
        $reverseUrl = route('hr.claims.reverse', $claim);

        // Where Approve/Reject post to, and where to return, depend on the stage.
        $approveUrl = $stage === 'hr' ? route('hr.claims.approve', $claim) : route('user.claims.team.approve', $claim);
        $rejectUrl = $stage === 'hr' ? route('hr.claims.reject', $claim) : route('user.claims.team.reject', $claim);
        $backUrl = ($stage === 'hr' || $canReverse) ? route('hr.claims.index') : route('user.claims.team');

        return view('user.claims.review', compact('claim', 'company', 'items', 'approver', 'stage', 'approveUrl', 'rejectUrl', 'backUrl', 'canReverse', 'reverseUrl'));
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
        $hrStatuses = ['manager_approved', 'hr_approved', 'hr_rejected', 'reversed', 'paid'];

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

        $claims = ExpenseClaim::with(['employee', 'items.category', 'correctionOf'])
            ->whereIn('status', $hrStatuses)
            ->where('year', $selectedYear)
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('submitted_at')
            ->get();

        $stats = $this->getClaimStats();

        // Approved-PDF ZIP export groups by the APPROVAL CUTOFF CYCLE (e.g. 21 Jun–20 Jul = the
        // "July" cycle) using each company's own cutoff day and the date each claim was fully
        // approved (processed_at, stamped at HR approval) — only PROCESSED claims are included
        // at all. Drive the modal's month/company options from the same ClaimZipExportService
        // the background export job uses, so the dropdown can never disagree with what the
        // download actually returns. The page's $selectedYear (reporting-year, for the
        // accordion) is reused as the cycle-year for the export.
        $zipExportService = app(ClaimZipExportService::class);
        $matchedThisYear = $zipExportService->matchingClaims($selectedYear, null, []);
        $inYear = $matchedThisYear->map(fn ($c) => ['claim' => $c, 'cycle' => $zipExportService->claimCycle($c), 'company' => $c->resolvedCompany()]);
        $approvedForExport = $inYear->pluck('claim')->values();
        $exportMonths = $inYear->pluck('cycle.month')->unique()->sort()->values();
        $exportCompanies = $inYear->pluck('company')->filter()->unique()->sort()->values();

        // Each cycle's window as actual DATES, used ONLY to open the pickers on the cycle in
        // progress below. These are no longer handed to the view: the modal's quick-pick
        // dropdown was removed on 2026-09-09 (operators set it AND the dates, then could not
        // tell which the download obeyed), and the month labels that dressed its options went
        // with it. Using the default (null-company) cutoff as a representative window —
        // per-company cutoffs may differ slightly.
        $defaultCutoff = (int) (ExpenseClaimPolicy::forCompany()->submission_deadline_day ?? 20);
        $exportCycleWindows = [];
        foreach ($exportMonths as $m) {
            $w = ClaimRulesService::cycleWindow($selectedYear, (int) $m, $defaultCutoff);
            $exportCycleWindows[(int) $m] = [
                'from' => $w['start']->toDateString(),
                // cycleWindow's end is EXCLUSIVE; the pickers treat their end date as inclusive,
                // so step back one day or the default would cover a day more than its cycle.
                'to' => $w['endExclusive']->copy()->subDay()->toDateString(),
            ];
        }

        // What the pickers open on. The cycle currently in progress (i.e. the one today's
        // approvals fall into) is the period HR run most often, so defaulting to it keeps the
        // monthly pack a two-click job now that the export takes free dates.
        // Only when the current cycle actually belongs to the year on screen — in January the
        // live cycle is next year's, and reusing its month number here would silently offer
        // THIS year's January window instead, which is a different period entirely.
        $currentCycle = ClaimRulesService::submissionCycle(now(), $defaultCutoff);
        $exportDefaultRange = $currentCycle['year'] === $selectedYear
            ? ($exportCycleWindows[$currentCycle['month']] ?? $this->defaultRangeFor($selectedYear, $currentCycle['month']))
            : $this->defaultRangeFor($selectedYear, null);

        return view('hr.claims.index', compact(
            'claims', 'stats', 'availableYears', 'selectedYear',
            'approvedForExport', 'exportCompanies', 'exportDefaultRange'
        ));
    }

    /**
     * HR: View a single claim in detail.
     */
    public function show(ExpenseClaim $claim)
    {
        $this->authorizeViewClaims();

        $claim->load(['employee', 'items.category', 'items.approver', 'manager', 'managerApprover', 'hrApprover']);
        $company = Company::forName($claim->resolvedCompany());

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
            // HR approval is the claim module's terminal state — mark it processed so it lands
            // in the approved-PDF ZIP for its submission cutoff cycle.
            'processed_at' => now(),
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
     * HR: Reverse a fully-approved claim (manager + HR approved). Used when management decides,
     * after the fact, that an already-approved claim should not stand. Behaves like a rejection
     * — reason + optional per-item flags, employee files a correction — but lands in the distinct
     * terminal status `reversed`, and drops the claim out of the approved-PDF export.
     */
    public function hrReverse(Request $request, ExpenseClaim $claim)
    {
        $this->authorizeApproveRejectClaims();

        $request->validate(['remarks' => 'nullable|string|max:1000']);

        // Only a fully-approved claim can be reversed — HR must have approved it first.
        if ($claim->status !== 'hr_approved') {
            return back()->with('error', 'Only a fully-approved claim can be reversed.');
        }

        $remarks = mb_substr(strip_tags((string) $request->input('remarks')), 0, 1000);

        // Freeze as `reversed` and clear processed_at so it leaves the approved-PDF ZIP.
        $claim->update([
            'status' => 'reversed',
            'reversed_by' => Auth::id(),
            'reversed_at' => now(),
            'reverse_remarks' => $remarks,
            'processed_at' => null,
        ]);

        // Per-item flags/comments the reviewer left for the employee's reference.
        $this->saveItemRejectComments($claim, $request->input('item_comments'));

        Log::info('Claim reversed', [
            'claim_id' => $claim->id, 'claim_number' => $claim->claim_number,
            'actor_id' => Auth::id(), 'actor_role' => Auth::user()->role, 'remarks' => $remarks,
        ]);

        $employee = $claim->employee;

        // Tell the employee they can correct + resubmit straight away.
        if ($employee->user) {
            Mail::to($employee->user->work_email)->send(new ClaimRejectedMail($claim, $employee, 'reversed'));
        }

        // Notify the approving manager/PIC that the claim they approved was reversed — same as
        // the HR-rejection flow (informational; the employee handles the correction).
        $manager = $claim->managerApprover ?? $claim->manager;
        if ($manager && $manager->user) {
            Mail::to($manager->user->work_email)->send(new ClaimHrRejectedNoticeMail($claim, $manager, 'reversed'));
        }

        $this->logClaim($claim, 'reversed', 'HR reversed the approved claim: '.$remarks.' (employee can correct immediately; approving manager notified).');

        return redirect()->route('hr.claims.index')->with('success', 'Claim reversed — '.$employee->full_name.' can correct it now; the approving manager has been notified.');
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
                    // Must mirror hrApprove() exactly. Without this stamp a bulk-approved claim
                    // is fully approved yet permanently absent from the approved-PDF ZIP and
                    // from the finance report's cutoff-cycle basis — visible everywhere else,
                    // so nothing would ever report it missing.
                    'processed_at' => now(),
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

    /**
     * The approval cutoff cycle — 21st of the previous month to the 20th of this one, using
     * each company's own cutoff day and the date each claim was FULLY APPROVED (processed_at,
     * stamped when HR approves — not when it was submitted). The DEFAULT, because it is the
     * period the approved-PDF ZIP export archives by, and finance reconciles the CSV against
     * that ZIP. Changed 2026-09-06 from a submission-dated cycle to an approval-dated one: a
     * claim submitted in one cycle is routinely approved in a later one (corrections, rejection/
     * resubmission, a manager on leave), and both packs are meant to reflect what actually
     * landed as approved spend in that 21st–20th window.
     */
    public const REPORT_BASIS_CYCLE = 'cycle';

    /**
     * The reporting-month stamp (expense_claims.year/month) the employee picks in the New Claim
     * modal — i.e. the month the EXPENSE belongs to, regardless of when it was submitted or
     * approved. Kept as an option for anyone posting by expense period, but it does NOT tally
     * with the ZIP: a claim for July expenses is normally submitted (and approved) in August
     * and so sits in the August cycle.
     */
    public const REPORT_BASIS_EXPENSE_MONTH = 'expense_month';

    /**
     * An explicit approval-date window the operator picked (`?from=&to=`, both inclusive) —
     * "everything Manager and HR signed off between these two dates". Selected automatically
     * whenever both dates are present and valid, because it is the most specific period a
     * request can carry; the 21st-to-20th cycle remains the default when they are not.
     */
    public const REPORT_BASIS_RANGE = 'range';

    /**
     * The period axis for every claim export — the finance report, its CSV, and the HR CSV.
     * One resolver, so the three cannot come to disagree about what `?basis=` means, and an
     * unrecognised value falls back to the cycle everywhere rather than in two places out of
     * three. Reads `input()` rather than `query()` so it behaves the same whichever verb a
     * future caller uses.
     */
    /**
     * The operator's own approval-date window, if they picked one.
     *
     * Returns `[from, to, error]`. All three exports read this one helper, so "what does
     * ?from=/?to= mean" has a single answer and a rejected range is rejected identically
     * everywhere.
     *
     * Every failure is REPORTED, never silently ignored:
     *  - one date without the other would have to invent the missing end, and inventing
     *    "today" makes the same saved URL mean something different every time it is opened;
     *  - a reversed range is a typo, and swapping the dates for the operator would hand them a
     *    plausible-looking export for a period they did not ask for;
     *  - an unparseable date is a bug or a hand-edited URL, and falling back to the cutoff
     *    cycle would produce a correct-looking file covering the wrong window.
     * Each of those would be discovered only by reconciling totals, which is exactly the
     * failure this whole area of the module has been fixing.
     */
    private function claimDateRange(Request $request): array
    {
        $rawFrom = trim((string) $request->input('from'));
        $rawTo = trim((string) $request->input('to'));

        if ($rawFrom === '' && $rawTo === '') {
            return [null, null, null];
        }

        if ($rawFrom === '' || $rawTo === '') {
            return [null, null, 'Pick both a start date and an end date for the period.'];
        }

        try {
            $from = Carbon::createFromFormat('Y-m-d', $rawFrom)->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $rawTo)->startOfDay();
        } catch (\Throwable) {
            return [null, null, 'The period dates could not be read. Please pick them again.'];
        }

        // createFromFormat does NOT reject an impossible date that still MATCHES the format:
        // "2026-13-45" parses and silently rolls forward into 2027, which would export a window
        // nobody asked for without a word. Round-tripping is the only cheap way to catch it.
        if ($from->format('Y-m-d') !== $rawFrom || $to->format('Y-m-d') !== $rawTo) {
            return [null, null, 'The period dates could not be read. Please pick them again.'];
        }

        if ($to->lt($from)) {
            return [null, null, 'The end date cannot be before the start date.'];
        }

        return [$from, $to, null];
    }

    private function claimReportBasis(Request $request): string
    {
        // An explicit, valid window wins over everything: it is the most specific thing the
        // operator can have asked for. A REJECTED range deliberately does not fall through to
        // the cycle here — financeReports() surfaces the reason and shows nothing, rather than
        // quietly answering for a different period than the one on screen.
        [$from, $to] = $this->claimDateRange($request);
        if ($from && $to) {
            return self::REPORT_BASIS_RANGE;
        }

        return $request->input('basis') === self::REPORT_BASIS_EXPENSE_MONTH
            ? self::REPORT_BASIS_EXPENSE_MONTH
            : self::REPORT_BASIS_CYCLE;
    }

    /** Shared, filtered query for the finance report (used by the page and the export). */
    private function financeReportClaims(Request $request, int $year, string $basis)
    {
        $category = $request->query('category');
        $month = $request->query('month') ? (int) $request->query('month') : null;
        $company = $request->query('company');

        $q = ExpenseClaim::query()
            ->with(['employee', 'items' => function ($q) use ($category) {
                $q->with('category');
                if ($category) {
                    $q->where('expense_category_id', (int) $category);
                }
            }])
            ->when($category, fn ($q, $cat) => $q->whereHas('items', fn ($i) => $i->where('expense_category_id', (int) $cat)));

        if ($basis === self::REPORT_BASIS_RANGE) {
            // Same engine, same window, same inclusive end day as the ZIP built for this
            // period — that identity is the only reason the two downloads reconcile, so this
            // must never grow its own date arithmetic.
            [$from, $to] = $this->claimDateRange($request);
            $ids = app(ClaimZipExportService::class)
                ->claimsApprovedBetween($from, $to, array_filter([$company]))
                ->pluck('id')->all();

            return $q->whereIn('id', $ids)->orderByDesc('processed_at')->get();
        }

        if ($basis === self::REPORT_BASIS_CYCLE) {
            // Take the claim set straight from the engine the ZIP export runs on, rather than
            // re-deriving the cycle here. Any second implementation of "which cycle is this
            // claim in" drifts from submissionCycle() the moment a company's cutoff day
            // changes — and a finance CSV that disagrees with the archive it is reconciled
            // against is the exact failure this basis exists to remove.
            $ids = app(ClaimZipExportService::class)
                ->matchingClaims($year, $month, array_filter([$company]))
                ->pluck('id')->all();

            // An empty whereIn compiles to `0 = 1`, so no filter matching nothing is needed.
            return $q->whereIn('id', $ids)->orderByDesc('processed_at')->get();
        }

        return $q->whereIn('status', self::FINANCE_REPORT_STATUSES)
            ->where('year', $year)
            ->when($month, fn ($q, $m) => $q->where('month', $m))
            ->when($company, fn ($q, $c) => $q->where('company', $c))
            ->orderByDesc('year')->orderByDesc('month')
            ->get();
    }

    /**
     * The period a claim is reported under, for the chosen basis. One helper across all three
     * exports — the finance accordion, the finance CSV and the HR CSV — so the same claim can
     * never be labelled with one period in one of them and a different period in another.
     */
    private function claimReportPeriod(ExpenseClaim $claim, string $basis, ClaimZipExportService $zipExportService): array
    {
        // A range export still labels each claim with its approval CYCLE. The window decides
        // which claims are in the file; the cycle is what a reader groups and reconciles by,
        // and a window that spans two cycles would otherwise have no period column at all.
        return $basis === self::REPORT_BASIS_CYCLE || $basis === self::REPORT_BASIS_RANGE
            ? $zipExportService->claimCycle($claim)
            : ['year' => (int) $claim->year, 'month' => (int) $claim->month];
    }

    /** Finance-facing report of approved claims, grouped Year > Month > Company > Employee. */
    public function financeReports(Request $request)
    {
        if (! Auth::user()->canViewClaimReports()) {
            abort(403);
        }

        $basis = $this->claimReportBasis($request);
        $zipExportService = app(ClaimZipExportService::class);
        [$rangeFrom, $rangeTo, $rangeError] = $this->claimDateRange($request);

        $availableYears = $basis === self::REPORT_BASIS_EXPENSE_MONTH
            ? ExpenseClaim::whereIn('status', self::FINANCE_REPORT_STATUSES)
                ->distinct()->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y)->all()
            : $zipExportService->availableCycleYears();

        $selectedYear = (int) $request->query('year', (int) now()->year);
        if (! empty($availableYears) && ! in_array($selectedYear, $availableYears, true)) {
            $selectedYear = $availableYears[0];
        }

        // A rejected window shows the reason and NO rows. Falling back to the cycle would put a
        // different period's figures under the dates still sitting in the form — the operator
        // would have no way to tell they were reading something other than what they asked for.
        $claims = $rangeError ? collect() : $this->financeReportClaims($request, $selectedYear, $basis);

        // Flatten to one row per item, then nest Year > Month > Company > Employee.
        $rows = collect();
        foreach ($claims as $claim) {
            $period = $this->claimReportPeriod($claim, $basis, $zipExportService);
            foreach ($claim->items as $item) {
                $rows->push([
                    'year' => $period['year'],
                    'month' => $period['month'],
                    'company' => $claim->resolvedCompany() ?: '—',
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
            'companies' => Company::orderBy('name')->pluck('name'),
            'categories' => ExpenseCategory::active()->orderBy('name')->get(['id', 'name', 'gl_code']),
            'filterMonth' => $request->query('month'),
            'filterCompany' => $request->query('company'),
            'filterCategory' => $request->query('category'),
            'basis' => $basis,
            'basisIsCycle' => $basis === self::REPORT_BASIS_CYCLE,
            'basisIsRange' => $basis === self::REPORT_BASIS_RANGE,
            'cycleMonthLabels' => $this->cycleMonthLabels($selectedYear),
            'unstampedClaims' => $this->unstampedApprovedClaims($basis),
            // Echoed back as the raw strings the operator typed, so a rejected range keeps
            // their dates in the form to correct rather than silently blanking them.
            'filterFrom' => trim((string) $request->query('from')),
            'filterTo' => trim((string) $request->query('to')),
            'rangeError' => $rangeError,
            'rangeLabel' => $rangeFrom && $rangeTo
                ? $rangeFrom->format('j M Y').' – '.$rangeTo->format('j M Y')
                : null,
            // What the date pickers start on when the operator has not chosen a window: the
            // cycle currently on screen, so the default download is the monthly pack they
            // already ran and the range is an override rather than a new chore.
            'defaultRange' => $this->defaultRangeFor($selectedYear, $request->query('month')),
        ]);
    }

    /**
     * The start/end dates the pickers open on — the selected cutoff cycle's own window, or the
     * whole year when no cycle is chosen. Keeps "just download this month's pack" a two-click
     * job after the range picker was added, instead of making every export a date-entry task.
     */
    private function defaultRangeFor(int $year, $month): array
    {
        $cutoff = (int) (ExpenseClaimPolicy::forCompany()->submission_deadline_day ?? 20);

        if ($month) {
            $window = ClaimRulesService::cycleWindow($year, (int) $month, $cutoff);

            return [
                'from' => $window['start']->toDateString(),
                // cycleWindow's end is EXCLUSIVE; the pickers are inclusive, so step back a day
                // or the default would offer a window one day longer than the cycle it names.
                'to' => $window['endExclusive']->copy()->subDay()->toDateString(),
            ];
        }

        $first = ClaimRulesService::cycleWindow($year, 1, $cutoff);
        $last = ClaimRulesService::cycleWindow($year, 12, $cutoff);

        return [
            'from' => $first['start']->toDateString(),
            'to' => $last['endExclusive']->copy()->subDay()->toDateString(),
        ];
    }

    /**
     * Claims that are approved by status but carry no processed_at.
     *
     * The ZIP export cannot archive such a claim, so the cycle basis cannot report it either —
     * and a finance total that is quietly short is the failure this whole reconciliation exists
     * to remove. Nothing in production is in this state (checked 2026-09-05: 0 of 91 approved
     * claims), and nothing should ever be: what puts a claim here is an approval path that
     * forgets the stamp, which is precisely the defect bulkApprove() carried until then. So it
     * is reported on the page by name rather than tolerated, and the count is deliberately not
     * scoped to the selected year — it is a data-integrity anomaly, not a period.
     */
    private function unstampedApprovedClaims(string $basis)
    {
        if ($basis !== self::REPORT_BASIS_CYCLE) {
            return collect();
        }

        return ExpenseClaim::whereIn('status', self::FINANCE_REPORT_STATUSES)
            ->whereNull('processed_at')
            ->with('employee:id,full_name')
            ->orderByDesc('hr_approved_at')
            ->limit(25)
            ->get(['id', 'claim_number', 'employee_id', 'status']);
    }

    /**
     * "21 Jul – 20 Aug" per month of a cycle year, for the month dropdown. Uses the default
     * (null-company) cutoff as a representative window — exactly as the HR claims page does —
     * since a per-company cutoff may differ by a day or two and one label has to serve all.
     */
    private function cycleMonthLabels(int $year): array
    {
        $cutoff = (int) (ExpenseClaimPolicy::forCompany()->submission_deadline_day ?? 20);
        $labels = [];
        foreach (range(1, 12) as $month) {
            $window = ClaimRulesService::cycleWindow($year, $month, $cutoff);
            $labels[$month] = $window['start']->format('j M').' – '.$window['endExclusive']->copy()->subDay()->format('j M');
        }

        return $labels;
    }

    /** CSV export of the finance report, honouring the same filters. */
    public function financeReportsExport(Request $request)
    {
        if (! Auth::user()->canViewClaimReports()) {
            abort(403);
        }

        $basis = $this->claimReportBasis($request);
        $zipExportService = app(ClaimZipExportService::class);
        [$from, $to, $rangeError] = $this->claimDateRange($request);

        // A rejected window must not produce a file at all. Streaming a CSV for a different
        // period than the operator asked for is the one outcome nothing downstream can detect.
        if ($rangeError) {
            return back()
                ->withInput()
                ->with('error', $rangeError);
        }

        $selectedYear = (int) $request->query('year', (int) now()->year);
        $claims = $this->financeReportClaims($request, $selectedYear, $basis);

        $isRange = $basis === self::REPORT_BASIS_RANGE;
        $isCycle = $basis === self::REPORT_BASIS_CYCLE;

        // The filename carries the exact window, because a range export has no other place to
        // record what period it covers — two files downloaded minutes apart are otherwise
        // indistinguishable once they are sitting in a folder.
        $filename = $isRange
            ? 'claim_reports_approved_'.$from->format('Y-m-d').'_to_'.$to->format('Y-m-d').'_'.now()->format('Ymd_His').'.csv'
            : 'claim_reports_'.($isCycle ? 'cycle_' : 'expense_month_').$selectedYear.'_'.now()->format('Ymd_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($claims, $basis, $isCycle, $isRange, $zipExportService) {
            $file = fopen('php://output', 'w');
            // The period columns are NAMED for the basis, because "Month" alone cannot tell a
            // reader whether a row is filed by cutoff cycle or by expense month — and the two
            // put roughly a third of all claims in different buckets. Claim Number is appended
            // LAST so existing column positions are untouched; it is what lets a row be matched
            // against the PDF of the same name in the approved-PDF ZIP.
            //
            // A range export still labels rows by approval CYCLE (see claimReportPeriod) — the
            // window chose the rows, the cycle groups them — so it keeps the cycle headings.
            fputcsv($file, [
                $isCycle || $isRange ? 'Cycle Year' : 'Expense Year',
                $isCycle || $isRange ? 'Cycle Month' : 'Expense Month',
                'Company', 'Employee', 'GL Code', 'Category', 'Description', 'Amount (RM)',
                'Claim Number',
            ]);
            foreach ($claims as $claim) {
                $period = $this->claimReportPeriod($claim, $basis, $zipExportService);
                foreach ($claim->items as $item) {
                    fputcsv($file, [
                        $period['year'],
                        str_pad((string) $period['month'], 2, '0', STR_PAD_LEFT),
                        $this->sanitizeForCsv($claim->resolvedCompany() ?? '-'),
                        $this->sanitizeForCsv($claim->employee->full_name ?? '-'),
                        $this->sanitizeForCsv($item->category->gl_code ?? '-'),
                        $this->sanitizeForCsv($item->category->name ?? '-'),
                        $this->sanitizeForCsv($item->description),
                        number_format($item->total_with_gst, 2),
                        $this->sanitizeForCsv($claim->claim_number ?? '-'),
                    ]);
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * HR: Export claims to CSV.
     *
     * Periods by approval cutoff cycle (21st–20th) by default, like the finance report and
     * HR's approved-PDF ZIP, so an HR-approved claim appears in the same period in all three.
     * `?basis=expense_month` keeps the old reporting-stamp answer.
     *
     * **This deliberately does NOT go through ClaimZipExportService::matchingClaims(), and must
     * not be "simplified" to.** That method gates on `processed_at`, i.e. HR-approved only —
     * correct for an archive of approved PDFs, and fatal here: this export's whole purpose
     * includes claims that are NOT approved (submitted, manager_rejected, hr_rejected, reversed,
     * cancelled, and drafts via ?status=draft), so routing it through that gate would silently
     * empty most of the file. It instead uses the same two primitives — cycleFetchRange() to
     * bound the fetch and claimCycle() to decide membership — which are pure date logic and
     * answer for a claim of any status: an approved claim keys off processed_at exactly like the
     * ZIP does, and an unapproved one (which has none) falls back to submitted_at/created_at as a
     * placeholder period. For approved claims that is the same answer the ZIP gives, which is
     * what makes the three tally.
     */
    public function export(Request $request)
    {
        $this->authorizeViewClaims();

        $basis = $this->claimReportBasis($request);
        $zipExportService = app(ClaimZipExportService::class);
        $year = $request->input('year') ? (int) $request->input('year') : null;
        $month = $request->input('month') ? (int) $request->input('month') : null;

        $query = ExpenseClaim::with(['employee', 'items.category']);

        // Exclude drafts from export unless explicitly filtered
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'draft');
        }

        if ($basis === self::REPORT_BASIS_CYCLE) {
            // The cutoff day is per company, so a cycle cannot be expressed as one SQL range.
            // Bound the fetch to the calendar months a cycle can touch, then decide membership
            // per claim below — exactly what matchingClaims() does. COALESCE mirrors
            // claimCycle()'s own fallback chain exactly (processed_at, else submitted_at, else
            // created_at) — this export (unlike matchingClaims()) also carries claims with no
            // processed_at yet, so the bound has to cover their placeholder period too.
            [$rangeStart, $rangeEnd] = $zipExportService->cycleFetchRange($year, $month);
            if ($rangeStart) {
                $query->whereRaw('COALESCE(processed_at, submitted_at, created_at) >= ?', [$rangeStart->toDateTimeString()]);
            }
            if ($rangeEnd) {
                $query->whereRaw('COALESCE(processed_at, submitted_at, created_at) < ?', [$rangeEnd->toDateTimeString()]);
            }
        } else {
            if ($year) {
                $query->where('year', $year);
            }
            if ($month) {
                $query->where('month', $month);
            }
        }

        $claims = $query->orderBy('employee_id')->orderBy('year')->orderBy('month')->get();

        if ($basis === self::REPORT_BASIS_CYCLE) {
            $claims = $claims->filter(function (ExpenseClaim $claim) use ($year, $month, $zipExportService) {
                $cycle = $zipExportService->claimCycle($claim);

                return (! $year || $cycle['year'] === $year) && (! $month || $cycle['month'] === $month);
            })->values();
        }

        $isCycle = $basis === self::REPORT_BASIS_CYCLE;
        $filename = 'expense_claims_'.($isCycle ? 'cycle_' : 'expense_month_').now()->format('Y-m-d_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($claims, $basis, $isCycle, $zipExportService) {
            $file = fopen('php://output', 'w');
            // The Period column keeps its position but is NAMED for the basis. This route has
            // no UI anywhere — it is reached by URL or bookmark — so the header is the only
            // thing that can tell a reader which axis they are holding. Nothing is lost by the
            // column changing meaning: `Item Date` is on every row, and an item's expense date
            // is always inside the claim's own reporting month (ClaimRulesService::
            // itemDateInPeriod), so the stamp remains derivable from the file either way.
            fputcsv($file, [
                'Claim Number', 'Employee', 'Department',
                $isCycle ? 'Cycle (21st-20th)' : 'Expense Period',
                'Status',
                'Item Date', 'Description', 'Project/Client', 'Category',
                'Amount (w/o GST)', 'GST', 'Total (w/ GST)',
                'Submitted', 'Manager Approved', 'HR Approved',
            ]);

            foreach ($claims as $claim) {
                $period = $this->claimReportPeriod($claim, $basis, $zipExportService);
                foreach ($claim->items as $item) {
                    fputcsv($file, [
                        $claim->claim_number,
                        $this->sanitizeForCsv($claim->employee->full_name ?? '-'),
                        $this->sanitizeForCsv($claim->employee->department ?? '-'),
                        $period['year'].'-'.str_pad((string) $period['month'], 2, '0', STR_PAD_LEFT),
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
            $resp = Http::timeout(10)->get(
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

        // A multi-page PDF statement is split into one image PER PAGE, so a batch can hold ~20
        // images, each needing its own vision call — give the loop room past PHP's 30s default.
        @set_time_limit(180);

        $request->validate([
            'receipt' => 'required_without:receipt_files|nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'receipt_files' => 'required_without:receipt|nullable|array|max:25',
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

            // A collage of two-or-more independent routes pasted into one screenshot — refuse
            // to auto-fill a combined/guessed distance (a real bug: two unrelated one-way trips
            // read as one longer multi-stop journey). Ask the user to upload one screenshot per
            // trip instead; this flows through the existing "!d.ok → show d.message" handling.
            if (! empty($m['multi_routes'])) {
                return response()->json([
                    'enabled' => true, 'ok' => false,
                    'message' => 'This image looks like it holds more than one route/trip. Upload one route screenshot at a time — add this trip, then upload and add each further trip as its own item.',
                ]);
            }

            return response()->json([
                'enabled' => true, 'ok' => true, 'multi' => false,
                'amount' => null, 'date' => null, 'vendor' => null,
                'item_description' => null, 'paid_by' => null,
                'category_id' => null, 'category_name' => null,
                'distance_km' => $m['distance_km'],
                'route_from' => $m['route_from'],
                'route_to' => $m['route_to'],
                'route_stops' => $m['route_stops'],
                'issue' => $doc['issue'] ?? null,
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
                'period_start' => $one['period_start'] ?? null,
                'period_end' => $one['period_end'] ?? null,
                'vendor' => $one['vendor'] ?? null,
                'item_description' => $one['item_description'] ?? null,
                'paid_by' => $one['paid_by'] ?? null,
                'category_id' => $one['category_id'] ?? null,
                'category_name' => $one['category_name'] ?? null,
                'distance_km' => null, 'route_from' => null, 'route_to' => null, 'route_stops' => null,
                'issue' => $doc['issue'] ?? null,
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
            // Detect within the employee's eligible set (honours 'ever' scope; nothing off-list).
            $kwCat = ExpenseCategory::detectFromDescription($hint, $company, $categories);
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
            // The period this payment covers, when the receipt states one — the form uses it to
            // pick the Date of Expense and the month guard judges the receipt on it.
            'period_start' => $it['period_start'] ?? null,
            'period_end' => $it['period_end'] ?? null,
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
            $company = $claim->resolvedCompany();
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
        $slug = Str::slug(Str::limit($item->description, 30, '')) ?: 'receipt';
        $name = $claim->claim_number.'-'.$slug.'.'.$ext;

        return Storage::disk('local')->download($item->receipt_path, $name, [
            'Content-Type' => Storage::disk('local')->mimeType($item->receipt_path),
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * Store one browser-rasterised page of a PDF attachment, so the claim row can show a
     * picture where it currently shows "not embeddable in this PDF".
     *
     * The rasterising happens in pdf.js because the server cannot do it — no Imagick, no
     * Ghostscript, no Poppler on the NAS. That means the IMAGE ARRIVES FROM THE CLIENT and is
     * therefore untrusted in a way an ordinary receipt upload is not: the browser is asserting
     * "this is what page N of that PDF looks like", and nothing here can check that claim. It
     * is accepted because of what it is allowed to affect — a preview beside a document that
     * is still reproduced, unaltered and in full, in the appended pages. The appendix, not
     * this, remains the record. Anyone who could post a misleading preview here can already
     * upload a misleading receipt through the front door.
     *
     * What IS enforced: the caller must be able to reach the claim the file belongs to, the
     * target must be a PDF genuinely cited by a claim item (never an arbitrary path), and the
     * body must decode as a real image — the same `valid_file_content` reasoning the upload
     * rules use, since an extension proves nothing.
     */
    public function storeReceiptPreview(Request $request)
    {
        if (! (bool) config('claims.pdf_preview.enabled', true)) {
            return response()->json(['ok' => false, 'error' => 'previews are disabled'], 422);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:1000'],
            // Bounded by the STORAGE cap, not the row cap: a PDF the server cannot open is
            // captured in full because these images are then its only route into the download.
            'page' => ['required', 'integer', 'min:1', 'max:'.ClaimPdfPreview::storeLimit()],
            // The source's own page count, which only the browser can read. Optional, so a
            // cached copy of the old script keeps working — it just cannot mark completion.
            'total' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg', 'max:'.max(64, (int) config('claims.pdf_preview.max_upload_kb', 4096))],
        ]);

        $path = $data['path'];

        // The path is caller-supplied, so it decides which file gets written. Resolve it to a
        // claim item that actually cites it rather than trusting it: without this the endpoint
        // would write a chosen JPEG to any path on the private disk that ends ".pdf".
        $item = $this->claimItemCiting($path);
        if (! $item || ! ClaimPdfPreview::isPdf($path)) {
            abort(404);
        }

        $claim = $item->claim;
        if (! $claim) {
            abort(404);
        }

        $user = Auth::user();
        $isOwner = $user->employee && $claim->employee_id === $user->employee->id;
        if (! $isOwner) {
            $this->authorizeReview($claim); // HR/admin or the claim's manager (aborts 403 otherwise)
        }

        // No source file means nothing to be a preview OF — refuse rather than leave a picture
        // that outlives the document it claims to depict.
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $target = ClaimPdfPreview::pathFor($path, (int) $data['page']);

        // Already generated: answer success without rewriting. The client regenerates on every
        // view of a claim whose previews are incomplete, so this is the common case, not an
        // error — and re-encoding the same page would churn the disk for nothing.
        // Recorded BEFORE the already-stored short-circuit, so a PDF rasterised before this
        // marker existed still learns its page count on the next visit. Behind that
        // short-circuit it would never be written for exactly those files, and they would
        // re-rasterise on every view forever.
        if (! empty($data['total'])) {
            ClaimPdfPreview::recordTotal($path, (int) $data['total']);
        }

        if (Storage::disk('local')->exists($target)) {
            return response()->json(['ok' => true, 'stored' => false]);
        }

        // Decoded with the renderer, not the header: getimagesize() reads only the first bytes
        // and accepts a corrupt file that GD then chokes on downstream — the same trap
        // DecommissionReportRenderer documents for FPDF::Image().
        $raw = (string) file_get_contents($request->file('image')->getRealPath());
        if ($raw === '' || @imagecreatefromstring($raw) === false) {
            return response()->json(['ok' => false, 'error' => 'not a readable image'], 422);
        }

        Storage::disk('local')->put($target, $raw);

        return response()->json(['ok' => true, 'stored' => true]);
    }

    /**
     * The claim item that cites this exact attachment path, if any.
     *
     * Mirrors releaseReceiptFiles()'s ownership test — a path counts as cited whether it sits
     * in receipt_path, receipt_paths or supporting_paths — so "may this file be previewed?"
     * and "is this file still referenced?" can never answer differently about the same file.
     */
    private function claimItemCiting(string $path): ?ExpenseClaimItem
    {
        return ExpenseClaimItem::query()
            ->where(fn ($q) => $q->where('receipt_path', $path)
                ->orWhereJsonContains('receipt_paths', $path)
                ->orWhereJsonContains('supporting_paths', $path))
            ->with('claim')
            ->first();
    }

    /** Append an audit-log entry for the claim lifecycle (shown on Claim Reports). */
    private function logClaim(ExpenseClaim $claim, string $action, ?string $detail = null): void
    {
        ExpenseClaimLog::create([
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

    /**
     * Polite, specific message when a receipt doesn't belong to the claim's month.
     *
     * $coverage — the period the receipt SAYS it pays for, when it stated one that still
     * didn't reach the claim month. Naming it matters: without it the message blames a date
     * the user can see is not the whole story, and they retry the same upload.
     *
     * @param  array{0:Carbon,1:Carbon}|null  $coverage
     */
    private function outOfMonthMessage(Carbon $receiptDate, ExpenseClaim $claim, ?array $coverage = null): string
    {
        $receiptMonth = $receiptDate->format('F Y');           // e.g. "April 2026"
        $claimMonth = Carbon::create($claim->year, $claim->month, 1)->format('F Y'); // "June 2026"

        if ($coverage) {
            $covers = $coverage[0]->format('j M Y').' – '.$coverage[1]->format('j M Y');
            $moveTo = $coverage[0]->format('F Y');

            return "Sorry — this receipt can’t be added to this report. This report is for {$claimMonth}, "
                ."but the receipt is dated {$receiptDate->format('j M Y')} and says it covers {$covers} — "
                .'neither of which falls in '.$claimMonth.'. '
                ."Please open or create a {$moveTo} claim and add this receipt there instead.";
        }

        // The date named here is the one the SCAN read, and the scan can be wrong — a faint
        // thermal receipt misread by a single digit puts an in-month receipt out of month with
        // no hint that a misreading is even possible. So the message names the field that fixes
        // it. Without this line the employee's only visible option is to file the claim under a
        // month their receipt does not belong to.
        return "Sorry — this receipt can’t be added to this report. This report is for {$claimMonth}, "
            ."but the receipt is dated {$receiptDate->format('j M Y')}, which falls in {$receiptMonth}. "
            .'Each receipt must be claimed under a report for its own month. '
            ."Please open or create a {$receiptMonth} claim and add this receipt there instead — "
            .'or, if the scan misread the printed date, correct it in “Date on receipt” under Receipt details and add it again.';
    }

    /**
     * The billing / validity period the scan read off the receipt (Category-C `c_period_*`).
     *
     * Normalised through ClaimRulesService so a backwards, half-read or absurdly long range is
     * simply "no period" rather than something a guard or a printed report has to believe.
     *
     * @return array{0:Carbon,1:Carbon}|null
     */
    private function receiptCoverage(Request $request): ?array
    {
        return ClaimRulesService::coveragePeriod(
            $request->input('c_period_start'),
            $request->input('c_period_end'),
        );
    }

    /** Did the employee type the covered period themselves, rather than the scan reading it? */
    private function coverageWasTyped(Request $request): bool
    {
        return $request->boolean('c_period_manual');
    }

    /**
     * Did the employee correct the date printed on the receipt, rather than the scan reading it?
     *
     * Provenance only — it never decides whether the date is accepted, so a forged flag buys
     * nothing. Exactly the rule c_period_manual follows: what it changes is what the report
     * SAYS about the figure, which is what lets an approver holding the receipt image tell a
     * corrected reading from a machine-read one.
     */
    private function receiptDateWasTyped(Request $request): bool
    {
        return $request->boolean('c_date_manual');
    }

    /**
     * Did the employee correct the total printed on the receipt, rather than the scan reading it?
     *
     * Provenance only, on the same terms as c_date_manual — it never decides whether the total
     * is accepted, so a forged flag buys nothing. What it changes is what the report SAYS about
     * the figure, and here that matters more than anywhere else in Category C: the total is the
     * ceiling this claim was let through on, and on a capped category it IS the claimed amount,
     * so the approver holding the receipt image has to be able to tell a correction from a
     * machine reading before they sign.
     */
    private function receiptTotalWasTyped(Request $request): bool
    {
        return $request->boolean('c_total_manual');
    }

    /**
     * A HAND-ENTERED receipt total that can't be believed, as a message the employee can act on.
     *
     * Only fires for a typed total — same shape as coverageInputError(), and for the same
     * reason. A figure the SCAN produced is left alone: overClaimError() already ignores a
     * non-numeric one, because OCR must never block a claim. A figure a PERSON just typed is
     * different: silently ignoring "RM19.65" or "19,65" would drop the correction they made,
     * re-block them against the misread total with a message about the amount, and — for a
     * capped category, where this figure becomes the claimed amount — file a claim whose report
     * prints "Total paid: RM 0.00" against a receipt that plainly reads otherwise.
     *
     * Deliberately strict rather than clever: guessing that "19,65" means 19.65 rather than
     * 1965 is exactly the kind of silent re-interpretation this module refuses everywhere else.
     */
    private function receiptTotalInputError(Request $request): ?string
    {
        if (! $this->receiptTotalWasTyped($request)) {
            return null;
        }

        $raw = trim((string) $request->input('c_total'));
        if ($raw === '') {
            return null; // cleared the field again — no ceiling claimed, nothing to complain about
        }
        if (! is_numeric($raw) || (float) $raw <= 0) {
            return 'Enter the receipt total as a plain number, e.g. 19.65 — without “RM”, spaces or a comma.';
        }

        return null;
    }

    /**
     * A HAND-ENTERED coverage period that can't be believed, as a message the employee can act on.
     *
     * Only fires for a typed period. The same rules already live in coveragePeriod(), which
     * simply returns null — right for a value the SCAN produced, because OCR must never block a
     * claim, and wrong for a value a person just filled in: dropping their input silently
     * rejects the receipt with a message about its printed date that says nothing about the
     * field they were typing in. Same validation, different reporting, decided by who authored
     * the value. The flag is client-supplied, but getting it wrong only ever costs the employee
     * a clearer error — it can never admit a receipt, because acceptance still runs through
     * coveragePeriod() either way.
     */
    private function coverageInputError(Request $request): ?string
    {
        if (! $this->coverageWasTyped($request)) {
            return null;
        }

        $start = trim((string) $request->input('c_period_start'));
        $end = trim((string) $request->input('c_period_end'));

        if ($start === '' && $end === '') {
            return null; // cleared the fields again — nothing claimed, nothing to complain about
        }
        if ($start === '' || $end === '') {
            return 'Enter BOTH the start and end date of the period this receipt covers — one date on its own is not a period.';
        }
        if (ClaimRulesService::coveragePeriod($start, $end) === null) {
            return 'That covered period doesn’t look right — the end date must be on or after the start date, and a period can’t be longer than a year.';
        }

        return null;
    }

    /**
     * A receipt can only be claimed under the month it BELONGS to. The user-entered "Date of
     * Expense" is guarded separately, but the scan ALSO captures the receipt's printed date
     * into the read-only Category-C field (`c_date`). Enforce THAT date is in the claim's
     * period too, so a receipt the OCR read as April can't be filed under a July claim just
     * because the Date of Expense was left/typed as July.
     *
     * The one exception is a receipt that STATES the period it pays for: a Jaya One season
     * pass dated 30/07/2026 for "1/08/2026 - 31/08/2026" is an August expense settled in July,
     * and its payment date is not the month it belongs to. When the stated coverage reaches
     * the claim month, the printed date is no longer the deciding fact.
     *
     * Returns the offending receipt date, or null when nothing was scanned (manual / mileage
     * entries have no c_date), the date is in period, or the stated coverage rescues it.
     *
     * NB `c_period_*`, like `c_date`, arrives from the browser — a crafted POST can assert a
     * period the document does not carry. That is no weaker than what stood here before
     * (omitting `c_date` entirely already skipped this guard): it is a guard against the
     * honest mistake, not an authorization control. What backs it is that the period is
     * STORED in ocr_details and PRINTED in the report's Receipt-details block, so an asserted
     * coverage is visible to the manager and HR against the receipt image they approve.
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

        if (ClaimRulesService::itemDateInPeriod($d, $claim->year, $claim->month)) {
            return null;
        }

        if (ClaimRulesService::coverageInPeriod(
            $request->input('c_period_start'),
            $request->input('c_period_end'),
            $claim->year,
            $claim->month,
        )) {
            return null;
        }

        return $d;
    }

    private function notifyHr(ExpenseClaim $claim, string $type): void
    {
        // HR approvers (incl. HR Executives) + superadmin — see User::scopeClaimHrRole.
        $hrUsers = User::claimHrRole()
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
            'reversed' => ExpenseClaim::where('status', 'reversed')->count(),
            'total' => ExpenseClaim::whereIn('status', ['manager_approved', 'hr_approved', 'hr_rejected', 'reversed', 'paid'])->count(),
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
        // Delegates to the shared helper so this module and the onboarding / employee /
        // asset exports can never disagree about what a dangerous cell looks like —
        // three separate copies of this rule is how those three ended up without one at
        // all. Kept as a method because it has call sites throughout this controller.
        return csv_safe($value);
    }
}
