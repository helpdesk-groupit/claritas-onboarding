<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{ChartOfAccount, JournalEntry, JournalEntryLine, AccountingAuditTrail};
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GeneralLedgerController extends Controller
{
    private function authorizeView(): void  { if (!Auth::user()->canViewAccounting()) abort(403); }
    private function authorizeManage(): void { if (!Auth::user()->canManageAccounting()) abort(403); }

    public function index(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');

        $entries = JournalEntry::when($company, fn($q) => $q->where('company', $company))
            ->with('createdByUser')
            ->latest('date')
            ->paginate(25);

        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.journal-entries.index', compact('entries', 'company', 'companies'));
    }

    public function create()
    {
        $this->authorizeManage();
        $accounts = ChartOfAccount::where('is_active', true)->where('allow_direct_posting', true)->orderBy('account_code')->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.journal-entries.form', compact('accounts', 'companies'));
    }

    public function store(Request $request, AccountingService $svc)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'company'               => 'nullable|string|max:255',
            'date'                  => 'required|date',
            'reference'             => 'nullable|string|max:255',
            'description'           => 'nullable|string|max:1000',
            'lines'                 => 'required|array|min:2',
            'lines.*.account_id'    => 'required|exists:acc_chart_of_accounts,id',
            'lines.*.description'   => 'nullable|string|max:500',
            'lines.*.debit'         => 'nullable|numeric|min:0',
            'lines.*.credit'        => 'nullable|numeric|min:0',
        ]);

        $autoPost = $request->boolean('auto_post');

        $entry = $svc->createJournalEntry(
            ['company' => $data['company'], 'date' => $data['date'], 'reference' => $data['reference'], 'description' => $data['description']],
            $data['lines'],
            $autoPost
        );

        return redirect()->route('accounting.journal-entries.show', $entry)
            ->with('success', "Journal entry {$entry->entry_number} created." . ($autoPost ? ' (Posted)' : ''));
    }

    public function show(JournalEntry $journalEntry)
    {
        $this->authorizeView();
        $entry = $journalEntry->load('lines.account', 'createdByUser', 'postedByUser');
        return view('accounting.journal-entries.show', compact('entry'));
    }

    public function post(JournalEntry $journalEntry, AccountingService $svc)
    {
        $this->authorizeManage();

        try {
            $svc->postJournalEntry($journalEntry);
            return back()->with('success', "Journal entry {$journalEntry->entry_number} posted.");
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(JournalEntry $journalEntry, AccountingService $svc)
    {
        if (!Auth::user()->canApproveTransactions()) abort(403);

        try {
            $reversal = $svc->voidJournalEntry($journalEntry);
            return back()->with('success', "Entry voided. Reversal: {$reversal->entry_number}");
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function ledgerReport(Request $request, AccountingService $svc)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $accountId = $request->get('account_id');
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());

        $accounts = ChartOfAccount::when($company, fn($q) => $q->where('company', $company))
            ->where('is_active', true)->orderBy('account_code')->get();

        $lines = collect();
        $account = null;

        if ($accountId) {
            $account = ChartOfAccount::find($accountId);
            $lines = JournalEntryLine::where('account_id', $accountId)
                ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                    $q->where('status', 'posted')
                      ->whereBetween('date', [$startDate, $endDate]);
                })
                ->with('journalEntry')
                ->get()
                ->sortBy(fn($l) => $l->journalEntry->date);
        }

        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');

        return view('accounting.reports.general-ledger', compact(
            'accounts', 'account', 'lines', 'startDate', 'endDate', 'company', 'companies'
        ));
    }
}
