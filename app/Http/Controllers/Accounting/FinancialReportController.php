<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{ChartOfAccount, FiscalYear};
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FinancialReportController extends Controller
{
    private function authorizeView(): void { if (!Auth::user()->canViewAccounting()) abort(403); }

    public function trialBalance(Request $request, AccountingService $svc)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $asAt    = $request->get('as_at', now()->toDateString());
        $data    = $svc->getTrialBalance($company, $asAt);
        return view('accounting.reports.trial-balance', compact('data', 'company', 'asAt'));
    }

    public function profitAndLoss(Request $request, AccountingService $svc)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $from    = $request->get('from', now()->startOfYear()->toDateString());
        $to      = $request->get('to', now()->toDateString());
        $data    = $svc->getProfitAndLoss($company, $from, $to);
        return view('accounting.reports.profit-loss', compact('data', 'company', 'from', 'to'));
    }

    public function balanceSheet(Request $request, AccountingService $svc)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $asAt    = $request->get('as_at', now()->toDateString());
        $data    = $svc->getBalanceSheet($company, $asAt);
        return view('accounting.reports.balance-sheet', compact('data', 'company', 'asAt'));
    }

    public function cashFlow(Request $request, AccountingService $svc)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $from    = $request->get('from', now()->startOfYear()->toDateString());
        $to      = $request->get('to', now()->toDateString());
        $data    = $svc->getCashFlow($company, $from, $to);
        return view('accounting.reports.cash-flow', compact('data', 'company', 'from', 'to'));
    }

    public function generalLedger(Request $request)
    {
        $this->authorizeView();
        $company   = $request->get('company');
        $accountId = $request->get('account_id');
        $from      = $request->get('from', now()->startOfMonth()->toDateString());
        $to        = $request->get('to', now()->toDateString());

        $accounts = ChartOfAccount::where('is_active', true)->orderBy('account_code')->get();

        $entries = collect();
        if ($accountId) {
            $entries = DB::table('acc_journal_entry_lines as jl')
                ->join('acc_journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('jl.account_id', $accountId)
                ->where('je.status', 'posted')
                ->when($company, fn($q) => $q->where('je.company', $company))
                ->whereBetween('je.date', [$from, $to])
                ->select('je.date', 'je.entry_number', 'je.description', 'jl.description as line_description', 'jl.debit', 'jl.credit')
                ->orderBy('je.date')
                ->orderBy('je.id')
                ->get();
        }

        return view('accounting.reports.general-ledger', compact('entries', 'accounts', 'accountId', 'company', 'from', 'to'));
    }

    public function agedReceivables(Request $request, AccountingService $svc)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $asAt    = $request->get('as_at', now()->toDateString());
        $data    = $svc->getAgedReceivables($company, $asAt);
        return view('accounting.reports.aged-receivables', compact('data', 'company', 'asAt'));
    }

    public function agedPayables(Request $request, AccountingService $svc)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $asAt    = $request->get('as_at', now()->toDateString());
        $data    = $svc->getAgedPayables($company, $asAt);
        return view('accounting.reports.aged-payables', compact('data', 'company', 'asAt'));
    }

    public function taxSummary(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $from    = $request->get('from', now()->startOfYear()->toDateString());
        $to      = $request->get('to', now()->toDateString());

        // Output tax (sales invoices)
        $outputTax = DB::table('acc_sales_invoice_items as si')
            ->join('acc_sales_invoices as inv', 'inv.id', '=', 'si.invoice_id')
            ->join('acc_tax_codes as tc', 'tc.id', '=', 'si.tax_code_id')
            ->when($company, fn($q) => $q->where('inv.company', $company))
            ->whereBetween('inv.date', [$from, $to])
            ->whereIn('inv.status', ['sent', 'paid', 'partial'])
            ->selectRaw('tc.code, tc.name, tc.rate, SUM(si.line_total) as taxable_amount, SUM(si.tax_amount) as tax_amount')
            ->groupBy('tc.code', 'tc.name', 'tc.rate')
            ->get();

        // Input tax (bills)
        $inputTax = DB::table('acc_bill_items as bi')
            ->join('acc_bills as b', 'b.id', '=', 'bi.bill_id')
            ->join('acc_tax_codes as tc', 'tc.id', '=', 'bi.tax_code_id')
            ->when($company, fn($q) => $q->where('b.company', $company))
            ->whereBetween('b.date', [$from, $to])
            ->whereIn('b.status', ['received', 'paid', 'partial'])
            ->selectRaw('tc.code, tc.name, tc.rate, SUM(bi.line_total) as taxable_amount, SUM(bi.tax_amount) as tax_amount')
            ->groupBy('tc.code', 'tc.name', 'tc.rate')
            ->get();

        return view('accounting.reports.tax-summary', compact('outputTax', 'inputTax', 'company', 'from', 'to'));
    }
}
