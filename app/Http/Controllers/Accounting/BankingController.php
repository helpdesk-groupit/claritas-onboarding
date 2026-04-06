<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{AccountingAuditTrail, BankAccount, BankReconciliation, BankTransaction, BankTransfer, ChartOfAccount};
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BankingController extends Controller
{
    private function authorizeView(): void  { if (!Auth::user()->canViewAccounting()) abort(403); }
    private function authorizeManage(): void { if (!Auth::user()->canManageAccounting()) abort(403); }

    // ── Bank Accounts ────────────────────────────────────────────
    public function index(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $accounts = BankAccount::when($company, fn($q) => $q->where('company', $company))
            ->with('glAccount')
            ->orderBy('bank_name')
            ->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.banking.index', compact('accounts', 'company', 'companies'));
    }

    public function create()
    {
        $this->authorizeManage();
        $glAccounts = ChartOfAccount::where('is_active', true)->where('type', 'asset')->orderBy('account_code')->get();
        $companies  = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.banking.form', compact('glAccounts', 'companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company'          => 'nullable|string|max:255',
            'bank_name'        => 'required|string|max:255',
            'account_name'     => 'required|string|max:255',
            'account_number'   => 'required|string|max:50',
            'account_type'     => 'required|in:checking,savings,credit_card,cash,other',
            'currency'         => 'required|string|size:3',
            'opening_balance'  => 'required|numeric',
            'gl_account_id'    => 'nullable|exists:acc_chart_of_accounts,id',
        ]);

        $account = BankAccount::create($data);
        AccountingAuditTrail::log('create', $account);

        return redirect()->route('accounting.banking.index', ['company' => $data['company']])
            ->with('success', "Bank account {$account->account_name} created.");
    }

    public function edit(BankAccount $bankAccount)
    {
        $this->authorizeManage();
        $glAccounts = ChartOfAccount::where('is_active', true)->where('type', 'asset')->orderBy('account_code')->get();
        $companies  = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.banking.form', ['account' => $bankAccount, 'glAccounts' => $glAccounts, 'companies' => $companies]);
    }

    public function update(Request $request, BankAccount $bankAccount)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'bank_name'      => 'required|string|max:255',
            'account_name'   => 'required|string|max:255',
            'account_number'  => 'required|string|max:50',
            'account_type'   => 'required|in:checking,savings,credit_card,cash,other',
            'gl_account_id'  => 'nullable|exists:acc_chart_of_accounts,id',
            'is_active'      => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $bankAccount->update($data);
        return redirect()->route('accounting.banking.index')->with('success', 'Bank account updated.');
    }

    // ── Bank Transactions ────────────────────────────────────────
    public function transactions(BankAccount $bankAccount, Request $request)
    {
        $this->authorizeView();
        $from = $request->get('from');
        $to   = $request->get('to');

        $transactions = BankTransaction::where('bank_account_id', $bankAccount->id)
            ->when($from, fn($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('date', '<=', $to))
            ->orderByDesc('date')
            ->paginate(50);

        return view('accounting.banking.transactions', compact('bankAccount', 'transactions', 'from', 'to'));
    }

    // ── Bank Reconciliation ──────────────────────────────────────
    public function reconciliation(BankAccount $bankAccount, Request $request)
    {
        $this->authorizeManage();
        $statementDate    = $request->get('statement_date', now()->toDateString());
        $statementBalance = $request->get('statement_balance');

        $unreconciled = BankTransaction::where('bank_account_id', $bankAccount->id)
            ->where('is_reconciled', false)
            ->orderBy('date')
            ->get();

        $bookBalance = $bankAccount->current_balance;

        return view('accounting.banking.reconciliation', compact(
            'bankAccount', 'unreconciled', 'bookBalance', 'statementDate', 'statementBalance'
        ));
    }

    public function storeReconciliation(Request $request, BankAccount $bankAccount)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'statement_date'    => 'required|date',
            'statement_balance' => 'required|numeric',
            'reconciled_ids'    => 'required|array|min:1',
            'reconciled_ids.*'  => 'exists:acc_bank_transactions,id',
        ]);

        DB::transaction(function () use ($data, $bankAccount) {
            $reconciliation = BankReconciliation::create([
                'bank_account_id'   => $bankAccount->id,
                'statement_date'    => $data['statement_date'],
                'statement_balance' => $data['statement_balance'],
                'book_balance'      => $bankAccount->current_balance,
                'difference'        => $data['statement_balance'] - $bankAccount->current_balance,
                'reconciled_by'     => Auth::id(),
            ]);

            BankTransaction::whereIn('id', $data['reconciled_ids'])->update([
                'is_reconciled'        => true,
                'reconciliation_id'    => $reconciliation->id,
            ]);
        });

        return redirect()->route('accounting.banking.transactions', $bankAccount)
            ->with('success', 'Bank reconciliation completed.');
    }

    // ── Bank Transfers ───────────────────────────────────────────
    public function transfers(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $transfers = BankTransfer::when($company, fn($q) => $q->where('company', $company))
            ->with('fromAccount', 'toAccount')
            ->latest('date')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.banking.transfers', compact('transfers', 'company', 'companies'));
    }

    public function storeTransfer(Request $request, AccountingService $svc)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company'             => 'nullable|string|max:255',
            'from_bank_account_id' => 'required|exists:acc_bank_accounts,id',
            'to_bank_account_id'   => 'required|exists:acc_bank_accounts,id|different:from_bank_account_id',
            'amount'              => 'required|numeric|min:0.01',
            'date'                => 'required|date',
            'reference'           => 'nullable|string|max:255',
            'description'         => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($data, $svc) {
            $transfer = BankTransfer::create(array_merge($data, [
                'created_by' => Auth::id(),
            ]));

            $from = BankAccount::find($data['from_bank_account_id']);
            $to   = BankAccount::find($data['to_bank_account_id']);

            $svc->recordBankTransaction($from->id, [
                'date'        => $data['date'],
                'description' => "Transfer to {$to->account_name}",
                'reference'   => $data['reference'],
                'debit'       => 0,
                'credit'      => $data['amount'],
                'source_type' => BankTransfer::class,
                'source_id'   => $transfer->id,
            ]);

            $svc->recordBankTransaction($to->id, [
                'date'        => $data['date'],
                'description' => "Transfer from {$from->account_name}",
                'reference'   => $data['reference'],
                'debit'       => $data['amount'],
                'credit'      => 0,
                'source_type' => BankTransfer::class,
                'source_id'   => $transfer->id,
            ]);

            AccountingAuditTrail::log('create', $transfer);
        });

        return redirect()->route('accounting.bank-transfers.index')
            ->with('success', 'Bank transfer recorded.');
    }
}
