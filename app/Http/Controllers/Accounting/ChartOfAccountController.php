<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\AccountingAuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChartOfAccountController extends Controller
{
    private function authorizeView(): void
    {
        if (!Auth::user()->canViewAccounting()) abort(403);
    }

    private function authorizeManage(): void
    {
        if (!Auth::user()->canManageAccounting()) abort(403);
    }

    public function index(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');

        $accounts = ChartOfAccount::when($company, fn($q) => $q->where('company', $company))
            ->orderBy('account_code')
            ->get();

        // Group by type for tree view
        $grouped = $accounts->groupBy('type');
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');

        return view('accounting.chart-of-accounts.index', compact('accounts', 'grouped', 'company', 'companies'));
    }

    public function create()
    {
        $this->authorizeManage();
        $parents = ChartOfAccount::where('allow_direct_posting', false)->orderBy('account_code')->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.chart-of-accounts.form', compact('parents', 'companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'company'              => 'nullable|string|max:255',
            'account_code'         => 'required|string|max:20',
            'name'                 => 'required|string|max:255',
            'type'                 => 'required|in:asset,liability,equity,revenue,expense',
            'sub_type'             => 'nullable|string|max:80',
            'parent_id'            => 'nullable|exists:acc_chart_of_accounts,id',
            'description'          => 'nullable|string|max:1000',
            'opening_balance'      => 'nullable|numeric',
            'opening_balance_date' => 'nullable|date',
            'allow_direct_posting' => 'boolean',
        ]);

        $data['normal_balance'] = in_array($data['type'], ['asset', 'expense']) ? 'debit' : 'credit';
        $data['allow_direct_posting'] = $request->boolean('allow_direct_posting', true);

        $account = ChartOfAccount::create($data);
        AccountingAuditTrail::log('create', $account);

        return redirect()->route('accounting.chart-of-accounts.index', ['company' => $data['company']])
            ->with('success', "Account {$account->account_code} created.");
    }

    public function edit(ChartOfAccount $chartOfAccount)
    {
        $this->authorizeManage();
        $account = $chartOfAccount;
        $parents = ChartOfAccount::where('id', '!=', $account->id)
            ->where('allow_direct_posting', false)
            ->orderBy('account_code')->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.chart-of-accounts.form', compact('account', 'parents', 'companies'));
    }

    public function update(Request $request, ChartOfAccount $chartOfAccount)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            'sub_type'             => 'nullable|string|max:80',
            'parent_id'            => 'nullable|exists:acc_chart_of_accounts,id',
            'description'          => 'nullable|string|max:1000',
            'is_active'            => 'boolean',
            'allow_direct_posting' => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['allow_direct_posting'] = $request->boolean('allow_direct_posting', true);

        $old = $chartOfAccount->toArray();
        $chartOfAccount->update($data);
        AccountingAuditTrail::log('update', $chartOfAccount, $old, $chartOfAccount->fresh()->toArray());

        return redirect()->route('accounting.chart-of-accounts.index', ['company' => $chartOfAccount->company])
            ->with('success', "Account {$chartOfAccount->account_code} updated.");
    }

    public function destroy(ChartOfAccount $chartOfAccount)
    {
        $this->authorizeManage();

        if ($chartOfAccount->is_system) {
            return back()->with('error', 'System accounts cannot be deleted.');
        }

        if ($chartOfAccount->journalLines()->exists()) {
            return back()->with('error', 'Cannot delete account with journal entries. Deactivate it instead.');
        }

        AccountingAuditTrail::log('delete', $chartOfAccount);
        $chartOfAccount->delete();

        return back()->with('success', 'Account deleted.');
    }
}
