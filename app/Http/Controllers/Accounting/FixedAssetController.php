<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\AssetDecommissionController;
use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingAuditTrail;
use App\Models\Accounting\AssetDepreciationEntry;
use App\Models\Accounting\FixedAsset;
use App\Models\Accounting\FixedAssetCategory;
use App\Models\AssetDecommissionBatch;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FixedAssetController extends Controller
{
    private function authorizeView(): void
    {
        if (! Auth::user()->canViewAccounting()) {
            abort(403);
        }
    }

    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageAccounting()) {
            abort(403);
        }
    }

    // ── Categories ───────────────────────────────────────────────
    public function categories(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $categories = FixedAssetCategory::when($company, fn ($q) => $q->where('company', $company))
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
            'company' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'useful_life_months' => 'required|integer|min:1|max:600',
            'depreciation_method' => 'required|in:straight_line,declining_balance,sum_of_years',
            'asset_account_id' => 'nullable|exists:acc_chart_of_accounts,id',
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
        $status = $request->get('status');

        $assets = FixedAsset::when($company, fn ($q) => $q->where('company', $company))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('category')
            ->orderBy('asset_code')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');

        // Status = Disposed is the SINGLE home for everything e-waste: the quotations awaiting
        // a Finance decision (approved/rejected inline, right here) and the finished cycles'
        // reports. There is no separate screen and no extra sub-tab anywhere — asset work
        // belongs on the Assets tab.
        //
        // Deliberate scope:
        //   • E-WASTE ONLY — a rental return goes back to its owner rather than being
        //     disposed of, and since 2026-08-10 is not an AssetDecommissionBatch at all
        //     (it is a RentalAssetAcknowledgement filed on the vendor's profile).
        //   • REPORT-LEVEL, not asset-level — one row per cycle with its PDF, not the
        //     individual laptops inside it.
        //   • EVERY non-cancelled cycle, in-flight ones included, each showing its
        //     ewasteStageBadge(). This list used to be FINALIZED ONLY on the assumption that
        //     "in-flight cycles show in the pending-quotation list above it instead" — but
        //     that list is finance_status = 'pending', so the moment Finance APPROVED a
        //     quotation the cycle dropped out of it without ever entering this one and went
        //     invisible on Finance's only surface. That gap is the entire collection window
        //     (approved → vendor collects → pays → receipt uploaded), which is days or weeks
        //     and is precisely when someone asks "where is that disposal up to?".
        //     finance_rejected (awaiting a revised quote) and awaiting_quotation had the same
        //     hole. Only `cancelled` is excluded — it is not a record of anything.
        //
        // These are asset_inventories/dispose_assets rows, NOT acc_fixed_assets: the register
        // below carries cost and depreciation the IT inventory never captures, and nothing is
        // posted between the two ledgers.
        $decommissionBatches = null;
        $pendingQuotations = null;
        if ($status === 'disposed' && Auth::user()->canViewDecommissionReports()) {
            $decommissionBatches = AssetDecommissionBatch::where('type', AssetDecommissionBatch::TYPE_EWASTE)
                ->where('status', '!=', 'cancelled')
                ->with('vendor')
                ->withCount('items')
                ->latest()
                ->get();

            // Only the roles that may actually act on a quotation are shown the action list —
            // same gate AssetDecommissionController::authorizeFinance() enforces on submit.
            if (Auth::user()->canApproveEwasteQuotation()) {
                $pendingQuotations = AssetDecommissionController::pendingQuotationsQuery()->get();
            }
        }

        return view('accounting.fixed-assets.index', compact('assets', 'company', 'status', 'companies', 'decommissionBatches', 'pendingQuotations'));
    }

    public function create()
    {
        $this->authorizeManage();
        $categories = FixedAssetCategory::orderBy('name')->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');

        return view('accounting.fixed-assets.form', compact('categories', 'companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company' => 'nullable|string|max:255',
            'category_id' => 'required|exists:acc_fixed_asset_categories,id',
            'asset_code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'purchase_date' => 'required|date',
            'purchase_cost' => 'required|numeric|min:0',
            'residual_value' => 'required|numeric|min:0',
            'useful_life_months' => 'required|integer|min:1|max:600',
            'serial_number' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
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
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');

        return view('accounting.fixed-assets.form', ['asset' => $fixedAsset, 'categories' => $categories, 'companies' => $companies]);
    }

    public function update(Request $request, FixedAsset $fixedAsset)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'residual_value' => 'required|numeric|min:0',
            'serial_number' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'status' => 'required|in:active,disposed,fully_depreciated',
        ]);
        $fixedAsset->update($data);

        return redirect()->route('accounting.fixed-assets.index')->with('success', 'Asset updated.');
    }

    // ── Depreciation Run ─────────────────────────────────────────
    public function runDepreciation(Request $request, AccountingService $svc)
    {
        if (! Auth::user()->canApproveTransactions()) {
            abort(403);
        }

        $data = $request->validate([
            'company' => 'nullable|string|max:255',
            'run_month' => 'required|date_format:Y-m',
        ]);

        $runDate = \Carbon\Carbon::createFromFormat('Y-m', $data['run_month'])->endOfMonth();

        $assets = FixedAsset::where('status', 'active')
            ->when($data['company'], fn ($q) => $q->where('company', $data['company']))
            ->with('category')
            ->get();

        $count = 0;

        DB::transaction(function () use ($assets, $runDate, &$count) {
            foreach ($assets as $asset) {
                $existing = AssetDepreciationEntry::where('fixed_asset_id', $asset->id)
                    ->where('period_date', $runDate->toDateString())
                    ->exists();
                if ($existing) {
                    continue;
                }

                $depAmount = $asset->monthly_depreciation;
                if ($depAmount <= 0) {
                    continue;
                }

                $totalDepreciated = AssetDepreciationEntry::where('fixed_asset_id', $asset->id)->sum('amount');
                $maxDepreciation = $asset->purchase_cost - $asset->residual_value;
                $remaining = $maxDepreciation - $totalDepreciated;
                $depAmount = min($depAmount, $remaining);

                if ($depAmount <= 0) {
                    $asset->update(['status' => 'fully_depreciated']);

                    continue;
                }

                AssetDepreciationEntry::create([
                    'fixed_asset_id' => $asset->id,
                    'period_date' => $runDate->toDateString(),
                    'amount' => round($depAmount, 2),
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
