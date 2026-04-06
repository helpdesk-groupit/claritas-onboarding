<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{BankAccount, Bill, ChartOfAccount, Customer, SalesInvoice, Vendor, JournalEntry};
use App\Services\AccountingService;
use Illuminate\Support\Facades\Auth;

class AccountingDashboardController extends Controller
{
    private function authorize(): void
    {
        if (!Auth::user()->canViewAccounting()) {
            abort(403, 'Unauthorized access to accounting.');
        }
    }

    public function index(AccountingService $svc)
    {
        $this->authorize();
        $company = request('company');
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $yearStart = now()->startOfYear()->toDateString();

        $pnlMonth = $svc->getProfitAndLoss($company, $monthStart, $today);
        $pnlYear  = $svc->getProfitAndLoss($company, $yearStart, $today);

        $totalReceivable = SalesInvoice::when($company, fn($q) => $q->where('company', $company))
            ->whereNotIn('status', ['paid', 'void'])->sum('balance_due');
        $totalPayable = Bill::when($company, fn($q) => $q->where('company', $company))
            ->whereNotIn('status', ['paid', 'void'])->sum('balance_due');
        $overdueInvoices = SalesInvoice::when($company, fn($q) => $q->where('company', $company))
            ->where('due_date', '<', $today)->whereNotIn('status', ['paid', 'void'])->count();
        $overdueBills = Bill::when($company, fn($q) => $q->where('company', $company))
            ->where('due_date', '<', $today)->whereNotIn('status', ['paid', 'void'])->count();

        $bankAccounts = BankAccount::when($company, fn($q) => $q->where('company', $company))
            ->where('is_active', true)->get();
        $totalCash = $bankAccounts->sum(fn($ba) => $ba->current_balance);

        $recentInvoices = SalesInvoice::when($company, fn($q) => $q->where('company', $company))
            ->with('customer')->latest('date')->limit(5)->get();
        $recentBills = Bill::when($company, fn($q) => $q->where('company', $company))
            ->with('vendor')->latest('date')->limit(5)->get();

        $customerCount = Customer::when($company, fn($q) => $q->where('company', $company))->where('is_active', true)->count();
        $vendorCount   = Vendor::when($company, fn($q) => $q->where('company', $company))->where('is_active', true)->count();
        $accountCount  = ChartOfAccount::when($company, fn($q) => $q->where('company', $company))->where('is_active', true)->count();

        // Monthly revenue trend (last 12 months)
        $revenueTrend = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $mStart = $m->copy()->startOfMonth()->toDateString();
            $mEnd = $m->copy()->endOfMonth()->toDateString();
            $mPnl = $svc->getProfitAndLoss($company, $mStart, $mEnd);
            $revenueTrend[] = [
                'month'    => $m->format('M Y'),
                'revenue'  => $mPnl['revenue']['total'],
                'expenses' => $mPnl['expenses']['total'],
                'profit'   => $mPnl['net_profit'],
            ];
        }

        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');

        return view('accounting.dashboard', compact(
            'pnlMonth', 'pnlYear', 'totalReceivable', 'totalPayable',
            'overdueInvoices', 'overdueBills', 'bankAccounts', 'totalCash',
            'recentInvoices', 'recentBills', 'customerCount', 'vendorCount',
            'accountCount', 'revenueTrend', 'company', 'companies'
        ));
    }

    public function executiveDashboard(AccountingService $svc)
    {
        $this->authorize();
        $company = request('company');
        $today = now()->toDateString();
        $yearStart = now()->startOfYear()->toDateString();

        $pnlYear  = $svc->getProfitAndLoss($company, $yearStart, $today);
        $bs       = $svc->getBalanceSheet($company, $today);
        $cashFlow = $svc->getCashFlow($company, $yearStart, $today);
        $agedAR   = $svc->getAgedReceivables($company);
        $agedAP   = $svc->getAgedPayables($company);

        $totalAssets      = $bs['assets']['total'];
        $totalLiabilities = $bs['liabilities']['total'];
        $totalEquity      = $bs['equity']['total'];
        $currentRatio = $totalLiabilities > 0 ? $totalAssets / $totalLiabilities : 0;
        $profitMargin = $pnlYear['revenue']['total'] > 0
            ? ($pnlYear['net_profit'] / $pnlYear['revenue']['total']) * 100 : 0;

        // Monthly P&L trend
        $monthlyTrend = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $pnl = $svc->getProfitAndLoss($company, $m->copy()->startOfMonth()->toDateString(), $m->copy()->endOfMonth()->toDateString());
            $monthlyTrend[] = [
                'month'    => $m->format('M'),
                'revenue'  => $pnl['revenue']['total'],
                'expenses' => $pnl['expenses']['total'],
                'profit'   => $pnl['net_profit'],
            ];
        }

        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');

        return view('accounting.executive-dashboard', compact(
            'pnlYear', 'bs', 'cashFlow', 'agedAR', 'agedAP',
            'totalAssets', 'totalLiabilities', 'totalEquity',
            'currentRatio', 'profitMargin', 'monthlyTrend', 'company', 'companies'
        ));
    }
}
