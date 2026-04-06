<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{AccountingAuditTrail, AssetDepreciationEntry, ChartOfAccount, FixedAsset, FixedAssetCategory, JournalEntry};
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FixedAssetController extends Controller
{
    private function authorizeView(): void  { if (!Auth::user()->canViewAccounting()) abort(403); }
    private function authorizeManage(): void { if (!Auth::user()->canManageAccounting()) abort(403); }

    // ── Categories ───────────────────────────────────────────────
    public function categories(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $categories = FixedAssetCategory::when($company, fn($q) => $q->where('company', $company))
            ->withCount('assets')
            ->orderBy('name')
            ->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.fixed-assets.categories', compact('categories', 'company', 'companies'));
    }

    public function storeCategory(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company'            => 'nullable|string|max:255',
            'name'               => 'required|string|max:255',
            'useful_life_months' => 'required|integer|min:1|max:600',
            'depreciation_method' => 'required|in:straight_line,declining_balance,sum_of_years',
            'asset_account_id'   => 'nullable|exists:acc_chart_of_accounts,id',
            'depreciation_account_id' => 'nullable|exists:acc_chart_of_accounts,id',
            'accumulated_depreciation_account_id' => 'nullable|exists:acc_chart_of_accounts,id',
        ]);
        $cat = FixedAssetCategory::create($data);
        AccountingAuditTrail::log('create', $cat);

        return redirect()->route('accounting.asset-categories.index', ['company' => $data['company']])
            ->with('success', "Category {$cat->name} created.");
    }

    // ── Fixed Assets ─────────────────────────────────────────────
    public function index(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $status  = $request->get('status');

        $assets = FixedAsset::when($company, fn($q) => $q->where('company', $company))
            ->when($status, fn($q) => $q->where('status', $status))
            ->with('category')
            ->orderBy('asset_code')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.fixed-assets.index', compact('assets', 'company', 'status', 'companies'));
    }

    public function create()
    {
        $this->authorizeManage();
        $categories = FixedAssetCategory::orderBy('name')->get();
        $companies  = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.fixed-assets.form', compact('categories', 'companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company'        => 'nullable|string|max:255',
            'category_id'    => 'required|exists:acc_fixed_asset_categories,id',
            'asset_code'     => 'required|string|max:50',
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string|max:500',
            'purchase_date'  => 'required|date',
            'purchase_cost'  => 'required|numeric|min:0',
            'residual_value' => 'required|numeric|min:0',
            'useful_life_months' => 'required|integer|min:1|max:600',
            'serial_number'  => 'nullable|string|max:100',
            'location'       => 'nullable|string|max:255',
        ]);
        $data['status'] = 'active';
        $data['current_value'] = $data['purchase_cost'];

        $asset = FixedAsset::create($data);
        AccountingAuditTrail::log('create', $asset);

        return redirect()->route('accounting.fixed-assets.index', ['company' => $data['company']])
            ->with('success', "Asset {$asset->name} registered.");
    }

    public function edit(FixedAsset $fixedAsset)
    {
        $this->authorizeManage();
        $categories = FixedAssetCategory::orderBy('name')->get();
        $companies  = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.fixed-assets.form', ['asset' => $fixedAsset, 'categories' => $categories, 'companies' => $companies]);
    }

    public function update(Request $request, FixedAsset $fixedAsset)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string|max:500',
            'residual_value' => 'required|numeric|min:0',
            'serial_number'  => 'nullable|string|max:100',
            'location'       => 'nullable|string|max:255',
            'status'         => 'required|in:active,disposed,fully_depreciated',
        ]);
        $fixedAsset->update($data);
        return redirect()->route('accounting.fixed-assets.index')->with('success', 'Asset updated.');
    }

    // ── Depreciation Run ─────────────────────────────────────────
    public function runDepreciation(Request $request, AccountingService $svc)
    {
        if (!Auth::user()->canApproveTransactions()) abort(403);

        $data = $request->validate([
            'company'   => 'nullable|string|max:255',
            'run_month' => 'required|date_format:Y-m',
        ]);

        $runDate = \Carbon\Carbon::createFromFormat('Y-m', $data['run_month'])->endOfMonth();

        $assets = FixedAsset::where('status', 'active')
            ->when($data['company'], fn($q) => $q->where('company', $data['company']))
            ->with('category')
            ->get();

        $count = 0;

        DB::transaction(function () use ($assets, $runDate, $svc, &$count) {
            foreach ($assets as $asset) {
                $existing = AssetDepreciationEntry::where('fixed_asset_id', $asset->id)
                    ->where('period_date', $runDate->toDateString())
                    ->exists();
                if ($existing) continue;

                $depAmount = $asset->monthly_depreciation;
                if ($depAmount <= 0) continue;

                $totalDepreciated = AssetDepreciationEntry::where('fixed_asset_id', $asset->id)->sum('amount');
                $maxDepreciation  = $asset->purchase_cost - $asset->residual_value;
                $remaining        = $maxDepreciation - $totalDepreciated;
                $depAmount        = min($depAmount, $remaining);

                if ($depAmount <= 0) {
                    $asset->update(['status' => 'fully_depreciated']);
                    continue;
                }

                AssetDepreciationEntry::create([
                    'fixed_asset_id' => $asset->id,
                    'period_date'    => $runDate->toDateString(),
                    'amount'         => round($depAmount, 2),
                ]);

                $asset->update([
                    'current_value' => max(0, $asset->current_value - $depAmount),
                ]);

                $count++;
            }
        });

        return back()->with('success', "Depreciation run completed for {$count} asset(s).");
    }

    public function depreciationSchedule(FixedAsset $fixedAsset)
    {
        $this->authorizeView();
        $entries = AssetDepreciationEntry::where('fixed_asset_id', $fixedAsset->id)
            ->orderBy('period_date')
            ->get();
        return view('accounting.fixed-assets.depreciation', compact('fixedAsset', 'entries'));
    }
}
