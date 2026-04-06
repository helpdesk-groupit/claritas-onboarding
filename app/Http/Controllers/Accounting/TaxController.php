<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{AccountingAuditTrail, TaxCode, TaxReturn, TaxReturnLine};
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TaxController extends Controller
{
    private function authorizeView(): void  { if (!Auth::user()->canViewAccounting()) abort(403); }
    private function authorizeManage(): void { if (!Auth::user()->canManageAccounting()) abort(403); }

    // ── Tax Codes ────────────────────────────────────────────────
    public function index(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $codes = TaxCode::when($company, fn($q) => $q->where('company', $company))
            ->orderBy('code')
            ->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.tax.index', compact('codes', 'company', 'companies'));
    }

    public function createCode()
    {
        $this->authorizeManage();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.tax.code-form', compact('companies'));
    }

    public function storeCode(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company'     => 'nullable|string|max:255',
            'code'        => 'required|string|max:20',
            'name'        => 'required|string|max:255',
            'rate'        => 'required|numeric|min:0|max:100',
            'type'        => 'required|in:sales,purchase,both,withholding',
            'description' => 'nullable|string|max:500',
        ]);

        $code = TaxCode::create($data);
        AccountingAuditTrail::log('create', $code);

        return redirect()->route('accounting.tax.index', ['company' => $data['company']])
            ->with('success', "Tax code {$code->code} created.");
    }

    public function editCode(TaxCode $taxCode)
    {
        $this->authorizeManage();
        return view('accounting.tax.code-form', ['code' => $taxCode]);
    }

    public function updateCode(Request $request, TaxCode $taxCode)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'rate'        => 'required|numeric|min:0|max:100',
            'type'        => 'required|in:sales,purchase,both,withholding',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $taxCode->update($data);
        return redirect()->route('accounting.tax.index')->with('success', 'Tax code updated.');
    }

    // ── Tax Returns ──────────────────────────────────────────────
    public function returns(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $returns = TaxReturn::when($company, fn($q) => $q->where('company', $company))
            ->latest('period_start')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.tax.returns', compact('returns', 'company', 'companies'));
    }

    public function createReturn()
    {
        if (!Auth::user()->canApproveTransactions()) abort(403);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.tax.return-form', compact('companies'));
    }

    public function storeReturn(Request $request)
    {
        if (!Auth::user()->canApproveTransactions()) abort(403);

        $data = $request->validate([
            'company'        => 'nullable|string|max:255',
            'return_type'    => 'required|in:sst-02,sst-03,cp204,cp207,e_filing,other',
            'period_start'   => 'required|date',
            'period_end'     => 'required|date|after_or_equal:period_start',
            'filing_due_date' => 'required|date',
            'notes'          => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($data) {
            $return = TaxReturn::create(array_merge($data, [
                'status'     => 'draft',
                'created_by' => Auth::id(),
            ]));

            // Auto-compute lines from posted journal entries with tax codes in the period
            $salesTax = DB::table('acc_sales_invoice_items as si')
                ->join('acc_sales_invoices as inv', 'inv.id', '=', 'si.invoice_id')
                ->join('acc_tax_codes as tc', 'tc.id', '=', 'si.tax_code_id')
                ->where('inv.company', $data['company'])
                ->whereBetween('inv.date', [$data['period_start'], $data['period_end']])
                ->whereIn('inv.status', ['sent', 'paid', 'partial'])
                ->selectRaw('tc.id as tax_code_id, tc.code, tc.name, SUM(si.line_total) as taxable_amount, SUM(si.tax_amount) as tax_amount')
                ->groupBy('tc.id', 'tc.code', 'tc.name')
                ->get();

            $purchaseTax = DB::table('acc_bill_items as bi')
                ->join('acc_bills as b', 'b.id', '=', 'bi.bill_id')
                ->join('acc_tax_codes as tc', 'tc.id', '=', 'bi.tax_code_id')
                ->where('b.company', $data['company'])
                ->whereBetween('b.date', [$data['period_start'], $data['period_end']])
                ->whereIn('b.status', ['received', 'paid', 'partial'])
                ->selectRaw('tc.id as tax_code_id, tc.code, tc.name, SUM(bi.line_total) as taxable_amount, SUM(bi.tax_amount) as tax_amount')
                ->groupBy('tc.id', 'tc.code', 'tc.name')
                ->get();

            $totalOutput = 0;
            $totalInput  = 0;

            foreach ($salesTax as $line) {
                TaxReturnLine::create([
                    'tax_return_id'  => $return->id,
                    'tax_code_id'    => $line->tax_code_id,
                    'line_label'     => "Output Tax - {$line->code}: {$line->name}",
                    'taxable_amount' => $line->taxable_amount,
                    'tax_amount'     => $line->tax_amount,
                ]);
                $totalOutput += $line->tax_amount;
            }

            foreach ($purchaseTax as $line) {
                TaxReturnLine::create([
                    'tax_return_id'  => $return->id,
                    'tax_code_id'    => $line->tax_code_id,
                    'line_label'     => "Input Tax - {$line->code}: {$line->name}",
                    'taxable_amount' => $line->taxable_amount,
                    'tax_amount'     => -$line->tax_amount,
                ]);
                $totalInput += $line->tax_amount;
            }

            $return->update([
                'total_output_tax' => $totalOutput,
                'total_input_tax'  => $totalInput,
                'net_tax_payable'  => $totalOutput - $totalInput,
            ]);

            AccountingAuditTrail::log('create', $return);
        });

        return redirect()->route('accounting.tax-returns.index')->with('success', 'Tax return generated.');
    }

    public function showReturn(TaxReturn $taxReturn)
    {
        $this->authorizeView();
        $taxReturn->load('lines.taxCode');
        return view('accounting.tax.return-show', compact('taxReturn'));
    }

    public function fileReturn(TaxReturn $taxReturn)
    {
        if (!Auth::user()->canApproveTransactions()) abort(403);
        if ($taxReturn->status !== 'draft') return back()->with('error', 'Only draft returns can be filed.');
        $taxReturn->update(['status' => 'filed', 'filed_date' => now()]);
        return back()->with('success', 'Tax return marked as filed.');
    }
}
