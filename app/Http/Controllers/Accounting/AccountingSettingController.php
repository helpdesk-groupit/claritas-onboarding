<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{AccountingAuditTrail, AccountingSetting, Currency, FiscalYear, FiscalPeriod};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountingSettingController extends Controller
{
    public function index(Request $request)
    {
        if (!Auth::user()->canManageAccounting()) abort(403);
        $company  = $request->get('company');
        $settings = AccountingSetting::where('company', $company)->first();

        $fiscalYears = FiscalYear::when($company, fn($q) => $q->where('company', $company))->orderByDesc('start_date')->get();
        $currencies  = Currency::when($company, fn($q) => $q->where('company', $company))->orderBy('code')->get();
        $companies   = \App\Models\Company::orderBy('name')->pluck('name', 'name');

        return view('accounting.settings', compact('settings', 'fiscalYears', 'currencies', 'company', 'companies'));
    }

    public function update(Request $request)
    {
        if (!Auth::user()->canManageAccounting()) abort(403);

        $data = $request->validate([
            'company'                  => 'nullable|string|max:255',
            'base_currency'            => 'required|string|size:3',
            'tax_id_label'             => 'nullable|string|max:50',
            'invoice_prefix'           => 'nullable|string|max:10',
            'invoice_next_number'      => 'nullable|integer|min:1',
            'bill_prefix'              => 'nullable|string|max:10',
            'bill_next_number'         => 'nullable|integer|min:1',
            'payment_prefix'           => 'nullable|string|max:10',
            'payment_next_number'      => 'nullable|integer|min:1',
            'journal_prefix'           => 'nullable|string|max:10',
            'journal_next_number'      => 'nullable|integer|min:1',
            'default_payment_terms'    => 'nullable|integer|min:0|max:365',
            'ai_provider'              => 'nullable|in:openai,anthropic,local',
            'ai_api_key'               => ['nullable', 'string', 'max:255', 'regex:/^sk-[a-zA-Z0-9_\-]+$/'],
            'ai_model'                 => 'nullable|string|max:100',
        ]);

        $settings = AccountingSetting::updateOrCreate(
            ['company' => $data['company']],
            $data
        );

        AccountingAuditTrail::log('update', $settings);

        return back()->with('success', 'Accounting settings saved.');
    }

    // ── Fiscal Years ─────────────────────────────────────────────
    public function storeFiscalYear(Request $request)
    {
        if (!Auth::user()->canManageAccounting()) abort(403);
        $data = $request->validate([
            'company'    => 'nullable|string|max:255',
            'name'       => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after:start_date',
        ]);

        DB::transaction(function () use ($data) {
            $fy = FiscalYear::create(array_merge($data, ['status' => 'open']));

            // Auto-generate 12 monthly periods
            $start = \Carbon\Carbon::parse($data['start_date']);
            $end   = \Carbon\Carbon::parse($data['end_date']);

            $period = 1;
            while ($start->lt($end)) {
                $periodEnd = $start->copy()->endOfMonth();
                if ($periodEnd->gt($end)) $periodEnd = $end->copy();

                FiscalPeriod::create([
                    'fiscal_year_id' => $fy->id,
                    'period_number'  => $period,
                    'start_date'     => $start->toDateString(),
                    'end_date'       => $periodEnd->toDateString(),
                    'status'         => 'open',
                ]);

                $start = $periodEnd->copy()->addDay();
                $period++;
            }
        });

        return back()->with('success', 'Fiscal year created with monthly periods.');
    }

    // ── Currencies ───────────────────────────────────────────────
    public function storeCurrency(Request $request)
    {
        if (!Auth::user()->canManageAccounting()) abort(403);
        $data = $request->validate([
            'company'       => 'nullable|string|max:255',
            'code'          => 'required|string|size:3',
            'name'          => 'required|string|max:100',
            'symbol'        => 'required|string|max:5',
            'exchange_rate' => 'required|numeric|min:0.000001',
        ]);
        Currency::create($data);
        return back()->with('success', 'Currency added.');
    }
}
