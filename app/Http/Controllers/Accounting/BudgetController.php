<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{AccountingAuditTrail, Budget, BudgetLine, ChartOfAccount, FiscalYear};
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BudgetController extends Controller
{
    private function authorizeView(): void  { if (!Auth::user()->canViewAccounting()) abort(403); }
    private function authorizeManage(): void { if (!Auth::user()->canManageAccounting()) abort(403); }

    public function index(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $budgets = Budget::when($company, fn($q) => $q->where('company', $company))
            ->with('fiscalYear')
            ->latest('created_at')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.budgets.index', compact('budgets', 'company', 'companies'));
    }

    public function create()
    {
        $this->authorizeManage();
        $fiscalYears = FiscalYear::orderByDesc('start_date')->get();
        $accounts    = ChartOfAccount::where('is_active', true)->whereIn('type', ['revenue', 'expense', 'cogs'])->orderBy('account_code')->get();
        $companies   = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.budgets.form', compact('fiscalYears', 'accounts', 'companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company'       => 'nullable|string|max:255',
            'fiscal_year_id' => 'required|exists:acc_fiscal_years,id',
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string|max:500',
            'lines'          => 'required|array|min:1',
            'lines.*.account_id' => 'required|exists:acc_chart_of_accounts,id',
            'lines.*.m1'   => 'nullable|numeric|min:0',
            'lines.*.m2'   => 'nullable|numeric|min:0',
            'lines.*.m3'   => 'nullable|numeric|min:0',
            'lines.*.m4'   => 'nullable|numeric|min:0',
            'lines.*.m5'   => 'nullable|numeric|min:0',
            'lines.*.m6'   => 'nullable|numeric|min:0',
            'lines.*.m7'   => 'nullable|numeric|min:0',
            'lines.*.m8'   => 'nullable|numeric|min:0',
            'lines.*.m9'   => 'nullable|numeric|min:0',
            'lines.*.m10'  => 'nullable|numeric|min:0',
            'lines.*.m11'  => 'nullable|numeric|min:0',
            'lines.*.m12'  => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($data) {
            $budget = Budget::create([
                'company'        => $data['company'],
                'fiscal_year_id' => $data['fiscal_year_id'],
                'name'           => $data['name'],
                'description'    => $data['description'] ?? null,
                'status'         => 'draft',
                'created_by'     => Auth::id(),
            ]);

            $fy = FiscalYear::find($data['fiscal_year_id']);
            $startDate = \Carbon\Carbon::parse($fy->start_date);

            foreach ($data['lines'] as $line) {
                $total = 0;
                for ($m = 1; $m <= 12; $m++) {
                    $amount = $line['m' . $m] ?? 0;
                    if ($amount > 0) {
                        $periodStart = $startDate->copy()->addMonths($m - 1)->startOfMonth();
                        BudgetLine::create([
                            'budget_id'    => $budget->id,
                            'account_id'   => $line['account_id'],
                            'period_start' => $periodStart->toDateString(),
                            'period_end'   => $periodStart->copy()->endOfMonth()->toDateString(),
                            'amount'       => $amount,
                        ]);
                        $total += $amount;
                    }
                }
            }

            $budget->update(['total_budget' => BudgetLine::where('budget_id', $budget->id)->sum('amount')]);
            AccountingAuditTrail::log('create', $budget);
        });

        return redirect()->route('accounting.budgets.index')->with('success', 'Budget created.');
    }

    public function show(Budget $budget, AccountingService $svc)
    {
        $this->authorizeView();
        $budget->load('lines.account', 'fiscalYear');

        // Budget vs Actual
        $fy = $budget->fiscalYear;
        $actualsByAccount = [];

        $lines = $budget->lines->groupBy('account_id');
        foreach ($lines as $accountId => $budgetLines) {
            $account = ChartOfAccount::find($accountId);
            $actual = DB::table('acc_journal_entry_lines as jl')
                ->join('acc_journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('jl.account_id', $accountId)
                ->where('je.status', 'posted')
                ->whereBetween('je.date', [$fy->start_date, $fy->end_date])
                ->selectRaw('SUM(jl.debit) as total_debit, SUM(jl.credit) as total_credit')
                ->first();

            $actualAmount = ($account && in_array($account->type, ['expense', 'cogs']))
                ? ($actual->total_debit ?? 0) - ($actual->total_credit ?? 0)
                : ($actual->total_credit ?? 0) - ($actual->total_debit ?? 0);

            $budgetAmount = $budgetLines->sum('amount');
            $actualsByAccount[$accountId] = [
                'budget'   => $budgetAmount,
                'actual'   => abs($actualAmount),
                'variance' => $budgetAmount - abs($actualAmount),
            ];
        }

        return view('accounting.budgets.show', compact('budget', 'actualsByAccount'));
    }

    public function approve(Budget $budget)
    {
        if (!Auth::user()->canApproveTransactions()) abort(403);
        if ($budget->status !== 'draft') return back()->with('error', 'Only draft budgets can be approved.');
        $budget->update([
            'status'      => 'approved',
            'approved_by' => Auth::id(),
        ]);
        return back()->with('success', 'Budget approved.');
    }
}
