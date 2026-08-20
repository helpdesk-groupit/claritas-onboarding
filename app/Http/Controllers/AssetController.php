<?php

namespace App\Http\Controllers;

use App\Models\Aarf;
use App\Models\AssetAssignment;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\RentalAssetAcknowledgement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        // Asset Listing proper is IT/HR-only (canViewAssets()). Finance and named e-waste
        // management approvers hold neither, but the "Company Asset Decommissioning" review —
        // stats, cycles-in-review decide panel, archive — moved here, and they are the only
        // two audiences that page ever served. Every tab-pane on the full page renders into
        // the DOM regardless of which is active (a documented CSP/JS trap in this codebase),
        // so a decom-only viewer gets a separate, minimal view rather than the full inventory
        // page with tabs hidden by CSS — hiding it client-side would still ship the whole
        // asset table in the response. That minimal view still carries its OWN Company Asset
        // Decommissioning / Reports tab bar (it.assets.decommission-review), styled identically
        // to the full page's, so this audience lands on the same tabbed experience — just
        // without the two inventory tabs (Asset Listing, Decommissioning Assets) they have no
        // legitimate reason to see.
        $user = Auth::user();
        $fullAccess = $user->canViewAssets();
        abort_unless($fullAccess || $user->canViewDecommissionReports(), 403);

        if (! $fullAccess) {
            $decomReview = $this->buildDecommissionReview($user, $request->integer('year') ?: null);
            $reports = $this->ewasteCycleReportsFor($user->reachableDecommissionCompanies());

            return view('it.assets.decommission-review', array_merge($decomReview, [
                'activeTab' => $request->query('tab') === 'reports' ? 'reports' : 'company-decom',
                'reportGroups' => $reports['groups'],
                'reportsCount' => $reports['count'],
            ]));
        }

        // Exclude decommissioning conditions (not_good / returned) and soft-archived assets —
        // the former show in the Decommissioning tab, the latter have left every inventory view.
        $query = AssetInventory::with(['assignedEmployee.onboarding.personalDetail', 'vendor'])
            ->whereNull('decommissioned_at')
            ->whereNotIn('asset_condition', AssetInventory::DECOMMISSION_CONDITIONS);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('asset_tag', 'like', "%{$s}%")
                    ->orWhere('brand', 'like', "%{$s}%")
                    ->orWhere('model', 'like', "%{$s}%")
                    ->orWhere('serial_number', 'like', "%{$s}%")
                    ->orWhere('notes', 'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('asset_category', $request->category);
        }
        if ($request->filled('type')) {
            $query->where('asset_type', $request->type);
        }
        if ($request->filled('ownership')) {
            $query->where('ownership_type', $request->ownership);
        }
        if ($request->filled('vendor')) {
            self::applyVendorFilter($query, $request->vendor);
        }
        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }
        if ($request->filled('company_name')) {
            $coNorm = strtolower(str_replace(['.', ','], '', preg_replace('/\s+/', ' ', trim($request->company_name))));
            $query->where(function ($q) use ($request, $coNorm) {
                $q->where('company_name', $request->company_name)
                    ->orWhere('company_supplied_to', $request->company_name)
                    ->orWhereRaw("LOWER(REPLACE(REPLACE(TRIM(company_name), '.', ''), ',', '')) LIKE ?", ["%{$coNorm}%"])
                    ->orWhereRaw("LOWER(REPLACE(REPLACE(TRIM(company_supplied_to), '.', ''), ',', '')) LIKE ?", ["%{$coNorm}%"]);
            });
        }

        $assets = $query->latest()->paginate(15)->withQueryString();

        $activeInv = fn () => AssetInventory::whereNull('decommissioned_at')
            ->whereNotIn('asset_condition', AssetInventory::DECOMMISSION_CONDITIONS);
        $stats = [
            'total_assets' => $activeInv()->count(),
            'available' => $activeInv()->where('status', 'available')->count(),
            'assigned' => $activeInv()->where('status', 'assigned')->count(),
            'unavailable' => $activeInv()->where('status', 'unavailable')->count(),
        ];

        $employees = Employee::with('onboarding.personalDetail')->whereNull('active_until')->get();
        // New hires who can be handed an asset before day one (see pendingOnboardingOptions).
        $pendingOnboardings = self::pendingOnboardingOptions();

        // Decommissioning tab — staged assets not yet soft-archived (a finalized e-waste
        // cycle or a signed return form sets decommissioned_at, dropping them from this tab
        // too). `asset.vendor` is eager-loaded because the queue now shows which vendor each
        // returned asset goes back to — resolved off the FK, never the `rental_vendor` free
        // text, which holds the PIC's NAME since 2026-08-06.
        // `inspector` is read by the Inspection column on every e-waste row.
        $disposedQuery = DisposedAsset::with(['asset.vendor', 'batch', 'inspector'])
            ->whereHas('asset', fn ($q) => $q->whereNull('decommissioned_at'))
            ->latest('disposed_at');
        if ($request->filled('d_search')) {
            $ds = $request->d_search;
            $disposedQuery->where(fn ($q) => $q->where('asset_tag', 'like', "%{$ds}%")
                ->orWhere('brand', 'like', "%{$ds}%")
                ->orWhere('model', 'like', "%{$ds}%")
            );
        }
        if ($request->filled('d_type')) {
            $disposedQuery->where('asset_type', $request->d_type);
        }
        if ($request->filled('d_decotype')) {
            $disposedQuery->where('decommission_type', $request->d_decotype);
        }
        if ($request->filled('d_ownership')) {
            $disposedQuery->whereHas('asset', fn ($q) => $q->where('ownership_type', $request->d_ownership));
        }
        if ($request->filled('d_vendor')) {
            $disposedQuery->whereHas('asset', fn ($q) => self::applyVendorFilter($q, $request->d_vendor));
        }

        $disposed = $disposedQuery->paginate(15, ['*'], 'disposed_page')->withQueryString();

        // asset id => the return AARF it already sits on. One query for the page, so each
        // row can link to its form instead of offering to raise a second one for the same
        // asset — which the unique index would reject anyway, but only after the click.
        $returnForms = RentalAssetAcknowledgement::returnFormsByAsset(
            $disposed->pluck('asset_inventory_id')
        );
        // Registered vendors for the Add-Asset modal's vendor picker (both ownership types).
        // Split by what they're engaged for so the picker only offers vendors that make
        // sense for the chosen ownership: rented FROM a rental vendor, bought FROM a supplier.
        $vendorOptions = self::vendorPickerOptions();
        // How many vendors the quarterly sweep can actually ask to quote. There is no
        // "primary" one — it RFQs the whole active e-waste market so the offers can be
        // compared — so the number, not a name, is what tells IT the sweep can send.
        $ewasteRfqVendorCount = \App\Models\Vendor::ewasteRfqRecipients()->count();
        // Open (in-progress) e-waste cycles, newest first, for the status panel.
        //
        // "Open" is `finalized_at IS NULL`, NOT a status list — a cycle stranded at
        // `collected` because its auto-finalize threw still has a null finalized_at and
        // deliberately STAYS here, because it needs the manual finalize fallback and is
        // therefore genuinely open work.
        $openBatches = \App\Models\AssetDecommissionBatch::with('vendor')
            ->whereNull('finalized_at')
            ->where('status', '!=', 'cancelled')
            ->latest()->get();
        // Unsigned return forms are open work in exactly the same sense: assets are
        // committed to a document nobody has signed yet, and until it is signed they are
        // still on the books. Listed alongside the cycles so one panel answers "what is
        // outstanding in decommissioning?" rather than half of it.
        $openReturnForms = RentalAssetAcknowledgement::with('vendor')
            ->withCount('items')
            ->where('type', RentalAssetAcknowledgement::TYPE_RETURN)
            ->where('status', RentalAssetAcknowledgement::STATUS_DRAFT)
            ->latest()->get();
        $canDecommission = Auth::user()->canManageDecommission();

        // Distinct values for filter dropdowns. Vendor COMPANY names, not the PIC now
        // held in rental_vendor — see rentalVendorFilterOptions()/applyVendorFilter().
        $rentalVendors = self::rentalVendorFilterOptions();
        $filterBrands = AssetInventory::where('asset_condition', '!=', 'not_good')
            ->whereNotNull('brand')->where('brand', '!=', '')
            ->distinct()->orderBy('brand')
            ->pluck('brand');
        // Registered companies — used for both Add Asset dropdown and filter dropdown
        $registeredCompanies = \App\Models\Company::orderBy('name')->get(['name']);

        // Asset overview widget data
        $normaliseCo = fn (string $s) => strtolower(str_replace(['.', ','], '', preg_replace('/\s+/', ' ', trim($s))));
        $canonMap = [];
        foreach ($registeredCompanies as $co) {
            $canonMap[$normaliseCo($co->name)] = $co->name;
        }
        $resolveCo = fn (?string $raw) => $canonMap[$normaliseCo(trim($raw ?? ''))] ?? (trim($raw ?? '') ?: 'Unspecified');

        // Card 1 & 2: asset_type counts grouped by company (for filter)
        // Structure: [ 'CompanyName' => [ 'type' => count, ... ], ... ]
        $buildCompanyTypeMap = function ($rows, string $companyField = 'company') use ($resolveCo) {
            $map = [];
            foreach ($rows as $row) {
                $co = $resolveCo($row->$companyField);
                $type = $row->asset_type;
                $map[$co][$type] = ($map[$co][$type] ?? 0) + $row->total;
            }
            foreach ($map as &$types) {
                arsort($types);
            }
            uksort($map, 'strcasecmp');

            return $map;
        };

        // Card 1: All assets by company → type
        $allRows = AssetInventory::selectRaw('
                COALESCE(
                    NULLIF(TRIM(CASE WHEN ownership_type = "rental" THEN company_supplied_to ELSE company_name END), ""),
                    "Unspecified"
                ) as company, asset_type, count(*) as total
            ')->groupBy('company', 'asset_type')->get();
        $overviewAllByCompany = $buildCompanyTypeMap($allRows);
        $overviewAllTotal = AssetInventory::count();
        // Flat totals by type (for default "All Companies" view)
        $overviewAllByType = [];
        foreach ($overviewAllByCompany as $types) {
            foreach ($types as $t => $c) {
                $overviewAllByType[$t] = ($overviewAllByType[$t] ?? 0) + $c;
            }
        }
        arsort($overviewAllByType);

        // Card 2: Company-owned assets by company → type
        $coRows = AssetInventory::where('ownership_type', 'company')
            ->selectRaw('COALESCE(NULLIF(TRIM(company_name),""), "Unspecified") as company, asset_type, count(*) as total')
            ->groupBy('company', 'asset_type')->get();
        $overviewCompanyByCompany = $buildCompanyTypeMap($coRows);
        $overviewCompanyTotal = AssetInventory::where('ownership_type', 'company')->count();
        $overviewCompanyByType = [];
        foreach ($overviewCompanyByCompany as $types) {
            foreach ($types as $t => $c) {
                $overviewCompanyByType[$t] = ($overviewCompanyByType[$t] ?? 0) + $c;
            }
        }
        arsort($overviewCompanyByType);

        // Card 3: Rental/leased assets by company → type → brand counts
        // Structure: [ 'CompanyName' => [ 'total' => N, 'types' => [ 'type' => [ 'total' => N, 'brands' => [ 'brand' => N ] ] ] ] ]
        $rentalBrandRows = AssetInventory::where('ownership_type', 'rental')
            ->selectRaw('COALESCE(NULLIF(TRIM(company_supplied_to),""), "Unspecified") as company, asset_type, COALESCE(NULLIF(TRIM(brand),""), "Unknown") as brand_name, count(*) as total')
            ->groupBy('company', 'asset_type', 'brand_name')->get();
        $overviewRentalByCompany = [];
        foreach ($rentalBrandRows as $row) {
            $co = $resolveCo($row->company);
            $type = $row->asset_type;
            $brand = $row->brand_name;
            if (! isset($overviewRentalByCompany[$co])) {
                $overviewRentalByCompany[$co] = ['total' => 0, 'types' => []];
            }
            if (! isset($overviewRentalByCompany[$co]['types'][$type])) {
                $overviewRentalByCompany[$co]['types'][$type] = ['total' => 0, 'brands' => []];
            }
            $overviewRentalByCompany[$co]['total'] += $row->total;
            $overviewRentalByCompany[$co]['types'][$type]['total'] += $row->total;
            $overviewRentalByCompany[$co]['types'][$type]['brands'][$brand] = ($overviewRentalByCompany[$co]['types'][$type]['brands'][$brand] ?? 0) + $row->total;
        }
        uasort($overviewRentalByCompany, fn ($a, $b) => $b['total'] <=> $a['total']);
        foreach ($overviewRentalByCompany as &$coData) {
            uasort($coData['types'], fn ($a, $b) => $b['total'] <=> $a['total']);
            foreach ($coData['types'] as &$typeData) {
                arsort($typeData['brands']);
            }
        }
        $overviewRentalTotal = AssetInventory::where('ownership_type', 'rental')->count();

        // How many queued e-waste assets are still unfinished (uninspected, or inspected but
        // with no confirmed owner). Counted over the WHOLE queue, not the rendered page — the
        // gate is absolute, so a row on page 2 postpones the quarter just the same.
        $awaitingInspection = DisposedAsset::awaitingInspection()
            ->whereHas('asset', fn ($q) => $q->whereNull('decommissioned_at'))
            ->count();

        // ── Company Asset Decommissioning tab ──
        // Assets that have COMPLETED inspection (Complete or Incomplete) and are not yet
        // gathered into a cycle — the "become available under Company Asset Decommissioning"
        // step in the required workflow. The complement of awaitingInspection()'s own scope,
        // so a row can never appear in both this list and the still-uninspected queue.
        $readyForSweep = DisposedAsset::where('decommission_type', 'e_waste')
            ->whereNull('decommission_batch_id')
            ->whereNotNull('inspected_at')
            ->whereNotNull('company')
            ->whereHas('asset', fn ($q) => $q->whereNull('decommissioned_at'))
            ->with('asset')
            ->orderBy('inspected_at')
            ->get();

        // "Company Asset Decommissioning" + "Reports" tabs — same data + query shape as the
        // reduced Finance/management-only page above, so the two access points can never
        // disagree on what counts as a pending decision.
        $decomReview = $this->buildDecommissionReview($user, $request->integer('year') ?: null);
        $reports = $this->ewasteCycleReportsFor($user->reachableDecommissionCompanies());
        $reportGroups = $reports['groups'];
        $reportsCount = $reports['count'];
        ['activeByCompany' => $activeByCompany, 'year' => $year, 'decomStats' => $decomStats,
            'awaiting' => $awaiting, 'canFinance' => $canFinance, 'ewasteVendors' => $ewasteVendors] = $decomReview;

        return view('it.assets.page', compact('assets', 'stats', 'employees', 'pendingOnboardings', 'disposed', 'rentalVendors',
            'registeredCompanies', 'filterBrands', 'awaitingInspection', 'readyForSweep',
            'overviewAllTotal', 'overviewAllByType', 'overviewAllByCompany',
            'overviewCompanyTotal', 'overviewCompanyByType', 'overviewCompanyByCompany',
            'overviewRentalTotal', 'overviewRentalByCompany',
            'ewasteRfqVendorCount', 'openBatches', 'openReturnForms', 'returnForms', 'canDecommission',
            'vendorOptions', 'activeByCompany', 'year', 'decomStats', 'awaiting', 'canFinance',
            'ewasteVendors', 'reportGroups', 'reportsCount'
        ));
    }

    /**
     * Everything the "Company Asset Decommissioning" review needs — stats, the by-company
     * working queue, and the cycles awaiting THIS user's own decision. Shared by both shapes
     * of index() (the full page's tab and the reduced Finance/management-only page), so the
     * two access points can never disagree on what counts as a pending decision.
     */
    private function buildDecommissionReview(User $user, ?int $year): array
    {
        // Null = every company. A user who reaches this only as a named approver is scoped to
        // their own entities — another company's disposal is not theirs to read.
        $companies = $user->reachableDecommissionCompanies();

        $scoped = fn ($q) => $companies === null ? $q : $q->whereIn('company', $companies->all());

        // Every non-cancelled cycle, in-flight ones included: this is the review surface now,
        // so a cycle awaiting a decision has to appear on it. `cancelled` stays out — it is
        // not a record of anything. Each row is labelled by ewasteStageBadge().
        $query = $scoped(
            AssetDecommissionBatch::with('vendor')->withCount('items')
                ->where('type', AssetDecommissionBatch::TYPE_EWASTE)
                ->where('status', '!=', 'cancelled')
        );
        if ($year) {
            $query->whereYear('created_at', $year);
        }

        // ── Headline figures: FINISHED CYCLES ONLY, and that is load-bearing ──
        // Computed over the whole filtered set, but ALSO restricted to cycles that actually
        // completed. Admitting in-flight ones to `recovered` — COALESCE(receipt, quotation, 0)
        // — would book an approved-but-uncollected offer as money received.
        $finished = (clone $query)->where(fn ($q) => $q->whereNotNull('finalized_at')->orWhere('status', 'completed'));
        // Named decomStats, not stats: the full Asset Listing page already has its own $stats
        // (asset total/available/assigned/unavailable counts) — merging this under the same
        // key would silently clobber it.
        $decomStats = [
            'batches' => (clone $finished)->count(),
            'assets' => DisposedAsset::whereIn('decommission_batch_id', (clone $finished)->select('asset_decommission_batches.id'))->count(),
            'recovered' => (float) (clone $finished)->sum(DB::raw('COALESCE(receipt_amount, quotation_amount, 0)')),
        ];

        // ── Every cycle NOT YET completed, nested Year → Month → Company ──
        // Completed cycles are the Reports tab's job now (ewasteCycleReportsFor(), completed
        // only) — this is the working queue: whatever stage a cycle is at before that
        // (gathering quotes, awaiting a decision, awaiting collection & payment, rejected and
        // awaiting a re-quote), labelled by its own ewasteStageBadge(). Grouped by the SAME
        // Year → Month → Company shape ewasteCycleReportsFor() builds for the Reports tab —
        // keyed off created_at rather than finalized_at, since an in-flight cycle has no
        // finalized_at yet.
        $activeByCompany = (clone $query)
            ->whereNull('finalized_at')
            ->where('status', '!=', 'completed')
            ->latest()
            ->get()
            ->groupBy(fn ($b) => $b->created_at->format('Y'))
            ->sortKeysDesc()
            ->map(fn ($yearBatches) => $yearBatches
                ->groupBy(fn ($b) => $b->created_at->format('Y-m'))
                ->sortKeysDesc()
                ->map(fn ($monthBatches) => $monthBatches
                    ->groupBy(fn ($b) => $b->company ?: 'Unspecified company')
                    ->sortKeys()));

        // ── The cycles awaiting THIS user's decision ──
        // Loaded separately: an approver must reach a pending cycle wherever it sits in the
        // by-company list, and the full comparison is only rendered for the handful that need
        // one rather than for every row.
        $awaiting = $scoped(
            AssetDecommissionBatch::where('type', AssetDecommissionBatch::TYPE_EWASTE)
                ->where('status', 'pending_approval')
                ->with([
                    'vendor', 'items', 'creator', 'financeReviewer', 'managementReviewer',
                    'quotations.uploader', 'quotations.financeReviewer', 'quotations.vendor',
                    'recommendedQuotation.vendor', 'selectedQuotation.vendor',
                ])
                ->withCount('items')
        )->latest()->get();

        // Per cycle, because management authority is per company: a group CFO may sign nothing
        // while a named CEO signs only their own entity's. Finance's gate is role-wide, but it
        // is still evaluated per row so both flags read the same way in the view. A cycle
        // belongs in "awaiting your decision" only while THIS party's own verdict is still
        // outstanding.
        $canFinance = $user->canApproveEwasteQuotation();
        $awaiting = $awaiting->filter(
            fn ($b) => ($canFinance && $b->finance_status === 'pending')
                || ($b->management_status === 'pending' && $user->canApproveEwasteAsManagement($b->company))
        )->values();

        return [
            'activeByCompany' => $activeByCompany,
            'year' => $year,
            'decomStats' => $decomStats,
            'awaiting' => $awaiting,
            'canFinance' => $canFinance,
            // Who may be named as the sender of a filed quotation — unused here (IT does not
            // upload from this surface), but the shared comparison partial expects the variable.
            'ewasteVendors' => collect(),
        ];
    }

    /**
     * Every non-cancelled e-waste cycle for the "Reports" tab, nested Year → Month → Company —
     * the SAME data + query shape wherever it's shown (the full Asset Listing page, and the
     * reduced Finance/management-only page), so the two access points can never disagree.
     * $companies = null means every company (canViewAssets() holders); a named approver
     * reaching this only via canViewDecommissionReports() is scoped to their own entities, same
     * as buildDecommissionReview()'s $awaiting/$activeByCompany — another company's disposal
     * record is not theirs to read.
     *
     * COMPLETED cycles only — a cycle still in flight belongs in buildDecommissionReview()'s
     * $activeByCompany on the "Company Asset Decommissioning" tab instead, with its own
     * ewasteStageBadge().
     *
     * @return array{groups: \Illuminate\Support\Collection, count: int}
     */
    private function ewasteCycleReportsFor(?\Illuminate\Support\Collection $companies): array
    {
        $query = AssetDecommissionBatch::where('type', AssetDecommissionBatch::TYPE_EWASTE)
            ->where(fn ($q) => $q->whereNotNull('finalized_at')->orWhere('status', 'completed'))
            ->with('vendor')->withCount('items');

        if ($companies !== null) {
            $query->whereIn('company', $companies->all());
        }

        $batches = $query->orderByDesc('finalized_at')->orderByDesc('created_at')->get();

        // finalized_at falls back to created_at for the rare legacy row completed without one.
        $groups = $batches
            ->groupBy(fn ($b) => ($b->finalized_at ?? $b->created_at)->format('Y'))
            ->sortKeysDesc()
            ->map(fn ($yearBatches) => $yearBatches
                ->groupBy(fn ($b) => ($b->finalized_at ?? $b->created_at)->format('Y-m'))
                ->sortKeysDesc()
                ->map(fn ($monthBatches) => $monthBatches
                    ->groupBy(fn ($b) => $b->company ?: 'Unspecified company')
                    ->sortKeys()));

        return ['groups' => $groups, 'count' => $batches->count()];
    }

    /**
     * Active vendors for the asset forms' vendor picker, split by ownership.
     *
     * Rental assets may only be linked to a vendor we actually rent from; purchases to a
     * supplier. Both lists carry the vendor's contact details as data-* attributes so the
     * form can auto-fill them without a round trip — one query, no AJAX endpoint, and no
     * chance of the page showing details for a vendor that no longer exists.
     *
     * @return array{rental:\Illuminate\Support\Collection,purchase:\Illuminate\Support\Collection}
     */
    private static function vendorPickerOptions(?AssetInventory $asset = null): array
    {
        $active = \App\Models\Vendor::query()->where('is_active', true)->orderBy('name')->get();

        $options = [
            // Rented or leased — both are ownership_type 'rental' on the asset.
            'rental' => $active->filter(fn ($v) => $v->isRental())->values(),
            // A supplier may be registered under any of the "we buy things from them"
            // types; a vendor with no type at all still can't be picked.
            'purchase' => $active->filter(fn ($v) => $v->isAssetSupplier())->values(),
        ];

        // An asset already linked to a vendor must ALWAYS see that vendor in its own picker,
        // even if the vendor has since been deactivated or had its types changed. Without
        // this the linked vendor has no <option>, the select falls back to "" on the next
        // save, and an edit to something unrelated (RAM, a note) silently NULLs the link —
        // destroying exactly the historical reference that deactivating a vendor instead of
        // deleting it exists to preserve.
        if ($asset?->vendor_id) {
            $key = $asset->ownership_type === 'rental' ? 'rental' : 'purchase';
            if (! $options[$key]->contains('id', $asset->vendor_id)) {
                if ($linked = \App\Models\Vendor::find($asset->vendor_id)) {
                    $options[$key] = $options[$key]->push($linked)->sortBy('name')->values();
                }
            }
        }

        return $options;
    }

    /**
     * Vendor filter — resolves the vendor COMPANY through the FK, falling back to the
     * free-text column only for assets that were never linked to a registered vendor.
     *
     * `rental_vendor` used to be force-synced to the vendor's company name, so filtering
     * on that string alone worked. It now holds the vendor's PIC (a person), so the old
     * `where('rental_vendor', $vendor)` would quietly have become a filter by contact
     * name — matching nothing, and hiding every rental asset saved since. The FK is the
     * authoritative link; the free text is only meaningful when there is no FK.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  scoped to AssetInventory
     */
    private static function applyVendorFilter($query, string $vendor): void
    {
        $query->where(function ($q) use ($vendor) {
            $q->whereHas('vendor', fn ($v) => $v->where('name', $vendor))
                ->orWhere(fn ($q2) => $q2->whereNull('vendor_id')->where('rental_vendor', $vendor));
        });
    }

    /**
     * Options for the two vendor filter dropdowns — vendor COMPANY names, matching what
     * applyVendorFilter() searches on: registered vendors that rental assets actually
     * link to, plus the free-text names of rental assets with no registered vendor.
     */
    private static function rentalVendorFilterOptions(): \Illuminate\Support\Collection
    {
        $linkedIds = AssetInventory::where('ownership_type', 'rental')
            ->whereNotNull('vendor_id')->distinct()->pluck('vendor_id');

        return \App\Models\Vendor::whereIn('id', $linkedIds)->pluck('name')
            ->merge(
                AssetInventory::where('ownership_type', 'rental')
                    ->whereNull('vendor_id')
                    ->whereNotNull('rental_vendor')->where('rental_vendor', '!=', '')
                    ->distinct()->pluck('rental_vendor')
            )
            ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    public function create()
    {
        $this->authorizeCanAdd();

        return redirect()->route('assets.index');
    }

    public function store(Request $request)
    {
        $this->authorizeCanAdd();
        $validated = $this->validateAsset($request);
        $data = $this->buildAssetData($request, $validated);

        if ($request->hasFile('invoice_document')) {
            $data['invoice_document'] = $request->file('invoice_document')->store('invoices', 'local');
        }
        if ($request->hasFile('invoice_documents')) {
            $paths = [];
            foreach ($request->file('invoice_documents') as $file) {
                $paths[] = $file->store('invoices', 'local');
            }
            $data['invoice_documents'] = $paths;
        }
        if ($request->hasFile('rental_contract_documents')) {
            $paths = [];
            foreach ($request->file('rental_contract_documents') as $file) {
                $paths[] = $file->store('rental_contracts', 'local');
            }
            $data['rental_contract_documents'] = $paths;
        }

        $asset = AssetInventory::create($data);

        // ── Stage for decommissioning: Not Good → e-waste, Returned → vendor return ──
        $actor = Auth::user();
        $actorName = $actor->name ?? $actor->work_email ?? 'IT Team';
        $decommissionReason = $request->input('decommission_reason');
        if (($data['asset_condition'] ?? null) === 'not_good') {
            $this->stageForDecommission($asset, 'e_waste', $actorName, $decommissionReason);
        } elseif (($data['asset_condition'] ?? null) === 'returned') {
            $this->stageForDecommission($asset, 'vendor_return', $actorName, $decommissionReason);
        }

        // Save uploaded photos into asset_photos/{asset_tag}/ folder
        if ($request->hasFile('asset_photos')) {
            $folder = 'asset_photos/'.\Illuminate\Support\Str::slug($asset->asset_tag);
            $paths = [];
            foreach ($request->file('asset_photos') as $photo) {
                $paths[] = $photo->store($folder, 'public');
            }
            $asset->update(['asset_photos' => $paths]);
        }

        // The assignee may be an employee OR a new hire who has no employees row yet, so
        // this reads the request rather than the asset's assigned_employee_id FK — which is
        // null for an onboarding assignment (it is carried by asset_assignments alone).
        $assignNote = '';
        if ($assignee = $this->requestedAssignee($request)) {
            $actorName = Auth::user()->name ?? Auth::user()->work_email ?? 'IT Team';
            $line = "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) ".
                    "assigned to {$assignee['name']} by {$actorName} during asset creation.";

            $this->createAssignmentForAssignee($assignee, $asset->id, $asset->asset_assigned_date?->toDateString() ?? now()->toDateString());
            $asset->appendRemark($line);
            $assignNote = trim($this->notifyAssigneeOfAarf($assignee, $asset, 'assigned', $line)
                .' '.$this->sameTypeAlreadyHeldNote($assignee, $asset));
        }

        $tab = in_array($data['asset_condition'] ?? null, ['not_good', 'returned']) ? 'damaged' : null;

        return redirect()->route('assets.index', array_filter(['tab' => $tab]))
            ->with('success', trim('Asset added successfully. '.$assignNote));
    }

    public function show(AssetInventory $asset)
    {
        $this->authorizeItAccess();
        $asset->load('assignedEmployee.onboarding.personalDetail');
        $employees = Employee::with('onboarding.personalDetail')
            ->whereNull('active_until')
            ->where('id', '!=', $asset->assigned_employee_id ?? 0)
            ->get();

        return view('it.assets.show', compact('asset', 'employees'));
    }

    public function edit(AssetInventory $asset)
    {
        $this->authorizeCanEdit();
        $employees = Employee::with('onboarding.personalDetail')->whereNull('active_until')->get();
        $pendingOnboardings = self::pendingOnboardingOptions();
        $registeredCompanies = \App\Models\Company::orderBy('name')->get(['name']);
        // Registered vendors for the Section C vendor <select> (Vendor Management), split
        // by ownership. NOTE the listing page's $rentalVendors is a pluck() of distinct
        // free-text names for its filter dropdown — a different variable of a different
        // type. Don't reuse either name across the two views.
        $vendorOptions = self::vendorPickerOptions($asset);

        return view('it.assets.edit', compact('asset', 'employees', 'pendingOnboardings', 'registeredCompanies', 'vendorOptions'));
    }

    public function update(Request $request, AssetInventory $asset)
    {
        $this->authorizeCanEdit();
        $user = Auth::user();
        $actorName = $user->name ?? $user->work_email ?? 'IT Team';

        $validated = $this->validateAsset($request, isUpdate: true, user: $user);
        $data = $this->buildAssetData($request, $validated, $user);

        // An asset committed to an UNSIGNED return form must not have its condition changed
        // out from under the document.
        //
        // Until 2026-08-10 a vendor return created a batch, which stamped
        // `dispose_assets.decommission_batch_id`, and the two branches further down guard on
        // exactly that column: one refuses to delete a staging row, the other refuses to
        // re-route it to e-waste. A return AARF stamps nothing, so both guards became
        // no-ops for returns and two silent corruptions opened up — restore the asset to
        // Good and its queue row is deleted while it stays an item on the form, so signing
        // that form later stamps `decommissioned_at` on an in-service, assigned laptop and
        // states in a signed PDF that it went back to the vendor; or flip it to Not Good and
        // the same asset is swept into an e-waste cycle while still being returned.
        //
        // Refused rather than silently tolerated, because the operator's remedy is one
        // click (discard the draft form) and any other outcome leaves two documents
        // disagreeing about where one asset went. Checked BEFORE any upload is stored so a
        // bounce leaves nothing behind.
        if (array_key_exists('asset_condition', $data)
            && $data['asset_condition'] !== $asset->asset_condition
            && ($openReturn = RentalAssetAcknowledgement::openReturnFormFor($asset->id))) {
            return back()->withInput()->with('error',
                "{$asset->asset_tag} is on unsigned return form {$openReturn->reference}, so its condition cannot be changed. "
                .'Discard that form first if the asset is no longer going back to the vendor.');
        }

        if ($request->hasFile('invoice_document')) {
            $data['invoice_document'] = $request->file('invoice_document')->store('invoices', 'local');
        }

        // Invoices: honor per-file keep/remove, then append new uploads
        $invoicePaths = $asset->invoice_documents ?? [];
        if ($request->has('invoice_keep_submitted')) {
            $keep = (array) $request->input('invoice_keep_paths', []);
            $removed = array_diff($invoicePaths, $keep);
            foreach ($removed as $r) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($r);
            }
            $invoicePaths = array_values(array_intersect($invoicePaths, $keep));
        }
        if ($request->hasFile('invoice_documents')) {
            foreach ($request->file('invoice_documents') as $file) {
                $invoicePaths[] = $file->store('invoices', 'local');
            }
        }
        if ($request->has('invoice_keep_submitted') || $request->hasFile('invoice_documents')) {
            $data['invoice_documents'] = ! empty($invoicePaths) ? $invoicePaths : null;
        }

        // Contract docs: honor per-file keep/remove, then append new uploads
        $contractPaths = $asset->rental_contract_documents ?? [];
        if ($request->has('contract_keep_submitted')) {
            $keep = (array) $request->input('contract_keep_paths', []);
            $removed = array_diff($contractPaths, $keep);
            foreach ($removed as $r) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($r);
            }
            $contractPaths = array_values(array_intersect($contractPaths, $keep));
        }
        if ($request->hasFile('rental_contract_documents')) {
            foreach ($request->file('rental_contract_documents') as $file) {
                $contractPaths[] = $file->store('rental_contracts', 'local');
            }
        }
        if ($request->has('contract_keep_submitted') || $request->hasFile('rental_contract_documents')) {
            $data['rental_contract_documents'] = ! empty($contractPaths) ? $contractPaths : null;
        }

        // Handle photo keep/remove + new uploads
        $existing = $asset->asset_photos ?? [];
        if ($request->has('photo_keep_submitted')) {
            $keep = (array) $request->input('photo_keep_paths', []);
            $existing = array_values(array_intersect($existing, $keep));
        }
        if ($request->hasFile('asset_photos')) {
            $folder = 'asset_photos/'.\Illuminate\Support\Str::slug($asset->asset_tag);
            foreach ($request->file('asset_photos') as $photo) {
                $existing[] = $photo->store($folder, 'public');
            }
        }
        if ($request->has('photo_keep_submitted')) {
            $data['asset_photos'] = ! empty($existing) ? $existing : null;
        } elseif (! empty($existing)) {
            $data['asset_photos'] = $existing;
        }

        // Capture BEFORE saving. Section D is only submitted by roles that may edit it
        // (canEditAllAssetSections) — for anyone else the form carries no assignment fields
        // at all, so touching the assignment here would read "not assigned" from an absent
        // field and silently release the asset from whoever holds it.
        $canEditAssignment = $user->canEditAllAssetSections();
        $oldAssignee = $canEditAssignment ? $this->currentAssignee($asset) : null;
        $newAssignee = $canEditAssignment ? $this->requestedAssignee($request) : null;
        $oldAssignedDate = $asset->asset_assigned_date?->toDateString();

        // Capture old Section A/B values before saving (for change detection)
        $sectionABKeys = ['asset_tag', 'asset_type', 'brand', 'model', 'serial_number',
            'processor', 'ram_size', 'storage', 'operating_system', 'screen_size', 'spec_others'];
        $oldSectionAB = [];
        foreach ($sectionABKeys as $k) {
            $oldSectionAB[$k] = (string) ($asset->$k ?? '');
        }

        $asset->update($data);

        // ── Stage for decommissioning: Not Good → e-waste, Returned → vendor return ──
        $decommissionReason = $request->input('decommission_reason');
        if (($data['asset_condition'] ?? null) === 'not_good') {
            $this->stageForDecommission($asset, 'e_waste', $actorName, $decommissionReason);
        } elseif (($data['asset_condition'] ?? null) === 'returned') {
            $this->stageForDecommission($asset, 'vendor_return', $actorName, $decommissionReason);
        } elseif (in_array($data['asset_condition'] ?? null, ['good', 'under_maintenance'])) {
            // Restore — remove from the Decommissioning queue, but never yank an asset
            // already collected into an e-waste cycle or already soft-archived.
            //
            // An asset committed to an unsigned RETURN form never reaches here: the
            // pre-flight guard at the top of update() bounces the condition change, because
            // `decommission_batch_id` (the only thing this line tests) is not written by the
            // return flow and cannot speak for it.
            $staging = DisposedAsset::where('asset_inventory_id', $asset->id)->first();
            if ($staging && ! $staging->decommission_batch_id && ! $asset->decommissioned_at) {
                $staging->delete();
                $asset->appendRemark('Asset condition restored to '.ucfirst(str_replace('_', ' ', (string) $data['asset_condition'])).' — removed from Decommissioning by '.$actorName.'.');
            }
        }

        // ── Assignment change handling ─────────────────────────────────────
        // Compared by assignee KEY ("employee:12" / "onboarding:34"), not employee id, so a
        // new hire with no employees row is a first-class assignee: handing them an asset,
        // swapping it, and taking it back all reach their AARF and their inbox.
        $assignNote = '';

        if ($canEditAssignment && ($oldAssignee['key'] ?? null) !== ($newAssignee['key'] ?? null)) {
            AssetAssignment::where('asset_inventory_id', $asset->id)
                ->where('status', 'assigned')
                ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

            if ($newAssignee) {
                $this->createAssignmentForAssignee($newAssignee, $asset->id, $data['asset_assigned_date'] ?? now()->toDateString());
            }

            $label = "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model})";

            if (! $oldAssignee) {
                $line = "{$label} assigned to {$newAssignee['name']} by {$actorName}.";
                $asset->appendRemark($line);
                $assignNote = trim($this->notifyAssigneeOfAarf($newAssignee, $asset, 'assigned', $line)
                    .' '.$this->sameTypeAlreadyHeldNote($newAssignee, $asset));
            } elseif (! $newAssignee) {
                $line = "{$label} unassigned from {$oldAssignee['name']} by {$actorName}.";
                $asset->appendRemark($line);
                $this->notifyAssigneeOfAarf($oldAssignee, $asset, 'returned', $line);
            } else {
                $oldName = $oldAssignee['name'];
                $newName = $newAssignee['name'];
                $asset->appendRemark("{$label} reassigned from {$oldName} to {$newName} by {$actorName}.");
                $this->notifyAssigneeOfAarf($oldAssignee, $asset, 'returned',
                    "{$label} returned — reassigned from {$oldName} to {$newName} by {$actorName}.");
                $assignNote = trim($this->notifyAssigneeOfAarf($newAssignee, $asset, 'assigned',
                    "{$label} assigned — reassigned to {$newName} from {$oldName} by {$actorName}.")
                    .' '.$this->sameTypeAlreadyHeldNote($newAssignee, $asset));
            }

        } elseif ($canEditAssignment && $newAssignee) {
            // Same assignee — the asset they hold may itself have changed. Covers a new hire
            // as well as an employee: the spec on the form they are asked to sign must match
            // the machine, so a Section A/B edit re-opens the acknowledgement either way.
            $changedFields = [];
            foreach ($sectionABKeys as $field) {
                if (($oldSectionAB[$field] ?? '') !== (string) ($data[$field] ?? '')) {
                    $changedFields[] = ucfirst(str_replace('_', ' ', $field));
                }
            }

            if (! empty($changedFields)) {
                $changeList = implode(', ', $changedFields);
                $remarkText = "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) ".
                              "details updated ({$changeList}) by {$actorName}.";
                $asset->appendRemark($remarkText);
                $assignNote = $this->notifyAssigneeOfAarf($newAssignee, $asset, 'assigned', $remarkText, resetAcknowledgement: true, pending: false);
            } elseif (($data['asset_assigned_date'] ?? null) && ($data['asset_assigned_date'] ?? null) !== $oldAssignedDate) {
                // Sync new date to asset_assignments so AARF reflects the change
                AssetAssignment::where('asset_inventory_id', $asset->id)
                    ->where('status', 'assigned')
                    ->update(['assigned_date' => $data['asset_assigned_date']]);

                $asset->appendRemark(
                    "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) ".
                    "assignment date updated for {$newAssignee['name']} by {$actorName}."
                );
            }
        }

        // Preserve listing filters (passed through the form action query string) so the
        // detail page's Back button returns to the same filtered/paginated list.
        $filters = $request->query();

        if ($asset->asset_condition === 'not_good') {
            return redirect()->route('assets.disposed.show', array_merge($filters, ['asset' => $asset->id]))->with('success', 'Asset updated successfully.');
        }

        if ($asset->asset_condition === 'returned') {
            return redirect()->route('assets.index', array_merge($filters, ['tab' => 'damaged']))->with('success', 'Asset marked as Returned and moved to Decommissioning.');
        }

        return redirect()->route('assets.show', array_merge($filters, ['asset' => $asset->id]))
            ->with('success', trim('Asset updated successfully. '.$assignNote));
    }

    // ── Damaged Assets page (view-only) ────────────────────────────────────
    public function disposed(Request $request)
    {
        $this->authorizeItAccess();
        // Hide assets already collected + soft-archived by a completed decommission batch.
        $disposed = DisposedAsset::where(fn ($q) => $q->whereNull('asset_inventory_id')
            ->orWhereHas('asset', fn ($qq) => $qq->whereNull('decommissioned_at')))
            ->latest('disposed_at')->paginate(20)->withQueryString();

        return view('it.assets.disposed', compact('disposed'));
    }

    // ── View-only detail for a disposed asset ───────────────────────────────
    public function disposedShow(AssetInventory $asset)
    {
        $this->authorizeItAccess();

        return view('it.assets.disposed-show', compact('asset'));
    }

    // ── Release: unassign asset from employee ───────────────────────────────
    public function releaseAsset(AssetInventory $asset)
    {
        $this->authorizeCanEdit();

        $actor = Auth::user();
        $actorName = $actor->name ?? $actor->work_email ?? 'IT Team';

        // Resolve the holder — an employee, or a new hire who has no employees row yet.
        $oldAssignee = $this->currentAssignee($asset);
        $oldName = $oldAssignee['name'] ?? 'previous assignee';
        $assetLabel = trim("{$asset->brand} {$asset->model}") ?: $asset->asset_tag;
        $today = now()->format('d/m/Y');

        AssetAssignment::where('asset_inventory_id', $asset->id)
            ->where('status', 'assigned')
            ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

        $asset->update([
            'status' => 'available',
            'assigned_employee_id' => null,
            'asset_assigned_date' => null,
            'expected_return_date' => null,
        ]);

        $remarkText = "{$assetLabel} returned by {$oldName} on {$today}, processed by {$actorName}.";
        $asset->appendRemark($remarkText);

        if ($oldAssignee) {
            $this->notifyAssigneeOfAarf($oldAssignee, $asset, 'returned', $remarkText);
        }

        return redirect()->route('assets.index')
            ->with('success', "Asset [{$asset->asset_tag}] released from {$oldName}.");
    }

    // ── Download CSV export ─────────────────────────────────────────────────
    public function export(Request $request)
    {
        $u = Auth::user();
        if (! $u->isSuperadmin() && ! $u->isHrManager() && ! $u->isHrExecutive() && ! $u->isItManager() && ! $u->isItExecutive()) {
            abort(403);
        }
        $query = AssetInventory::with(['assignedEmployee.onboarding.personalDetail', 'vendor']);
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('asset_category', $request->category);
        }
        if ($request->filled('type')) {
            $query->where('asset_type', $request->type);
        }
        if ($request->filled('ownership')) {
            $query->where('ownership_type', $request->ownership);
        }
        if ($request->filled('vendor')) {
            self::applyVendorFilter($query, $request->vendor);
        }
        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }
        if ($request->filled('company_name')) {
            $coNorm = strtolower(str_replace(['.', ','], '', preg_replace('/\s+/', ' ', trim($request->company_name))));
            $query->where(function ($q) use ($request, $coNorm) {
                $q->where('company_name', $request->company_name)
                    ->orWhere('company_supplied_to', $request->company_name)
                    ->orWhereRaw("LOWER(REPLACE(REPLACE(TRIM(company_name), '.', ''), ',', '')) LIKE ?", ["%{$coNorm}%"])
                    ->orWhereRaw("LOWER(REPLACE(REPLACE(TRIM(company_supplied_to), '.', ''), ',', '')) LIKE ?", ["%{$coNorm}%"]);
            });
        }

        $assets = $query->latest()->get();
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="assets_export_'.date('Y-m-d').'.csv"',
        ];

        $callback = function () use ($assets) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Asset Tag', 'Category', 'Type', 'Brand', 'Model', 'Serial Number',
                'Status', 'Condition',
                'Processor', 'RAM', 'Storage', 'OS', 'Screen Size', 'Other Specs',
                'Ownership Type', 'Company Name', 'Supplied To',
                'Purchase Date', 'Vendor', 'Cost (RM)', 'Warranty Expiry',
                'Rental Vendor', 'Rental Vendor PIC', 'Rental Vendor Contact', 'Rental Cost/Month', 'Rental Start', 'Rental End', 'Contract Ref',
                'Assigned To', 'Assigned Date', 'Expected Return',
                'Maintenance Status', 'Last Maintenance', 'Notes', 'Remarks',
            ]);
            foreach ($assets as $a) {
                fputcsv($file, [
                    $a->asset_tag, $a->asset_category, $a->asset_type, $a->brand, $a->model, $a->serial_number,
                    $a->status, $a->asset_condition,
                    $a->processor, $a->ram_size, $a->storage, $a->operating_system, $a->screen_size, $a->spec_others,
                    $a->ownership_type, $a->company_name, $a->company_supplied_to,
                    $a->purchase_date?->format('d/m/Y'), $a->purchase_vendor, $a->purchase_cost, $a->warranty_expiry_date?->format('d/m/Y'),
                    // The vendor COMPANY comes off the FK; rental_vendor is the PIC, so the
                    // two are separate columns (it fell back to the free text only for
                    // assets never linked to a registered vendor).
                    $a->vendor_id ? $a->vendor?->name : $a->rental_vendor,
                    $a->vendor_id ? $a->rental_vendor : null,
                    $a->rental_vendor_contact, $a->rental_cost_per_month,
                    $a->rental_start_date?->format('d/m/Y'), $a->rental_end_date?->format('d/m/Y'), $a->rental_contract_reference,
                    $a->resolvedAssigneeName(), $a->asset_assigned_date?->format('d/m/Y'), $a->expected_return_date?->format('d/m/Y'),
                    $a->maintenance_status, $a->last_maintenance_date?->format('d/m/Y'), $a->notes, $a->remarks,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function reassign(Request $request, AssetInventory $asset)
    {
        $this->authorizeCanEdit();
        // Either target is accepted, exactly one required: a new hire has no employees row
        // until their start date, so demanding new_employee_id would exclude them.
        $request->validate([
            'new_employee_id' => 'required_without:new_onboarding_id|nullable|exists:employees,id',
            'new_onboarding_id' => ['required_without:new_employee_id', 'nullable', self::assignableOnboardingRule()],
        ]);

        $actor = Auth::user();
        $actorName = $actor->name ?? $actor->work_email ?? 'IT Team';

        $newAssignee = $this->requestedAssignee($request, 'new_employee_id', 'new_onboarding_id');
        if (! $newAssignee) {
            return back()->with('error', 'That person could no longer be resolved — reassignment cancelled.');
        }
        $newName = $newAssignee['name'];
        $oldAssignee = $this->currentAssignee($asset);
        $oldName = $oldAssignee['name'] ?? 'previous assignee';

        AssetAssignment::where('asset_inventory_id', $asset->id)
            ->where('status', 'assigned')
            ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

        $this->createAssignmentForAssignee($newAssignee, $asset->id, now()->toDateString());

        $asset->update([
            // Null for a pre-start hire — the assignment is carried by asset_assignments.
            'assigned_employee_id' => $newAssignee['type'] === 'employee' ? $newAssignee['id'] : null,
            'asset_assigned_date' => now()->toDateString(),
            'status' => 'assigned',
        ]);

        $label = "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model})";
        $asset->appendRemark("{$label} reassigned from {$oldName} to {$newName} by {$actorName}.");

        if ($oldAssignee) {
            $this->notifyAssigneeOfAarf($oldAssignee, $asset, 'returned',
                "{$label} returned — reassigned from {$oldName} to {$newName} by {$actorName}.");
        }
        $note = trim($this->notifyAssigneeOfAarf($newAssignee, $asset, 'assigned',
            "{$label} assigned — reassigned to {$newName} from {$oldName} by {$actorName}.")
            .' '.$this->sameTypeAlreadyHeldNote($newAssignee, $asset));

        return redirect()->route('assets.show', $asset)
            ->with('success', trim("Asset successfully reassigned from {$oldName} to {$newName}. ".$note));
    }

    public function returnAsset(AssetInventory $asset)
    {
        $this->authorizeCanEdit();

        $actor = Auth::user();
        $actorName = $actor->name ?? $actor->work_email ?? 'IT Team';
        $oldAssignee = $this->currentAssignee($asset);
        $oldName = $oldAssignee['name'] ?? 'previous assignee';

        AssetAssignment::where('asset_inventory_id', $asset->id)
            ->where('status', 'assigned')
            ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

        $asset->update([
            'status' => 'available',
            'assigned_employee_id' => null,
            'asset_assigned_date' => null,
            'expected_return_date' => null,
        ]);

        $line = "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) ".
                "returned by {$oldName} to IT department. Processed by {$actorName}.";
        $asset->appendRemark($line);

        if ($oldAssignee) {
            $this->notifyAssigneeOfAarf($oldAssignee, $asset, 'returned', $line);
        }

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Asset marked as returned and is now available.');
    }

    // ── Download CSV import template ───────────────────────────────────────
    // Download CSV import template
    public function importTemplate()
    {
        $this->authorizeCanAdd();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="asset_import_template.csv"',
        ];

        $callback = function () {
            $handle = fopen('php://output', 'w');

            // Column headers — mirrors the Add Asset form fields exactly
            // Columns marked * are REQUIRED. All others are optional.
            fputcsv($handle, [
                'asset_category',       // * REQUIRED: office_furniture / it_equipment / office_equipment / software / office_supplies / leasehold / others
                'asset_type',           // * REQUIRED: laptop / monitor / converter / phone / sim_card / access_card / desk / chair / etc.
                'asset_tag',            // * REQUIRED: unique label / asset ID (e.g. FIX13872)
                'specs',                // * REQUIRED: free-text specs — auto-parsed into brand/model/processor/ram/storage/os/screen
                'remarks',              // * REQUIRED: existing remarks / audit history
                // ── Section A: Asset Identity (auto-filled from specs if blank) ──
                'brand',                // optional override: Dell / HP / Lenovo / Apple / Asus / Acer / Samsung / LG / MSI / Other
                'model',                // optional override: e.g. E7490, EliteBook 840 G9
                'serial_number',        // optional
                // ── Section B: Specs (auto-filled from specs column if blank) ─────
                'processor',            // optional override
                'ram_size',             // optional override
                'storage',              // optional override
                'operating_system',     // optional override
                'screen_size',          // optional override
                'spec_others',          // optional: any other spec notes
                // ── Section C: Ownership ──────────────────────────────────────────
                'ownership_type',       // optional: company (default) / rental
                'company_name',         // optional: owning company name
                'company_supplied_to',  // optional: company the vendor supplied to
                'purchase_vendor',      // optional
                'purchase_cost',        // optional: numeric e.g. 4500.00
                'purchase_date',        // optional: DD-MM-YYYY
                'warranty_expiry_date', // optional: DD-MM-YYYY
                'rental_vendor',        // optional: required if ownership_type = rental
                'rental_vendor_contact', // optional
                'rental_cost_per_month', // optional
                'rental_start_date',    // optional: DD-MM-YYYY
                'rental_end_date',      // optional: DD-MM-YYYY
                'rental_contract_reference', // optional
                // ── Section D: Assignment ─────────────────────────────────────────
                'assigned_to',          // optional: employee full name — matched against employee listing
                'asset_assigned_date',  // optional: DD-MM-YYYY
                'expected_return_date', // optional: DD-MM-YYYY
                'asset_location',       // optional: physical location e.g. PDH, HQ KL
                // ── Section E: Condition ─────────────────────────────────────────
                'asset_condition',      // optional: new (default) / good / fair / damaged
                'maintenance_status',   // optional: none (default) / under_maintenance / repair_required
                'status',               // optional: available (default) / assigned / under_maintenance / retired
            ]);

            // Example row 1 — laptop with assignment history
            fputcsv($handle, [
                'it_equipment',                                    // asset_category   *
                'laptop',                                          // asset_type       *
                'FIX13872',                                        // asset_tag        *
                'DELL E7490 I7-8 16GB 512GB M.2 , win 11',        // specs            *
                'Delivery to Group IT on 16/7/2024',              // remarks          *
                '',                                                // brand            (auto: Dell)
                '',                                                // model            (auto: E7490)
                '',                                                // serial_number
                '',                                                // processor        (auto: I7-8)
                '',                                                // ram_size         (auto: 16GB)
                '',                                                // storage          (auto: 512GB M.2)
                '',                                                // operating_system (auto: Win 11)
                '',                                                // screen_size
                '',                                                // spec_others
                'company',                                         // ownership_type
                'Incite Innovation',                               // company_name
                '',                                                // company_supplied_to
                '',                                                // purchase_vendor
                '',                                                // purchase_cost
                '',                                                // purchase_date
                '',                                                // warranty_expiry_date
                '', '', '', '', '', '',                            // rental fields
                'Wong Zhen Hoong',                                 // assigned_to
                '',                                                // asset_assigned_date
                '',                                                // expected_return_date
                'PDH',                                             // asset_location
                'good',                                            // asset_condition
                'none',                                            // maintenance_status
                'assigned',                                        // status
            ]);

            // Example row 2 — available laptop, no assignment
            fputcsv($handle, [
                'it_equipment',
                'laptop',
                'CLR-LPT-001',
                'HP EliteBook 840 G9 i5-1235U 8GB 256GB SSD Win 11 Pro',
                'New stock received 01-01-2025',
                'HP', 'EliteBook 840 G9', 'SN-HP-001',
                '', '', '', '', '', '',
                'company', 'Claritas Asia Sdn. Bhd.', '',
                'Dell Malaysia', '4500.00', '17-01-2024', '15-01-2027',
                '', '', '', '', '', '',
                '', '', '', 'HQ KL',
                'new', 'none', 'available',
            ]);

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // Import assets from CSV
    public function importCsv(Request $request)
    {
        $this->authorizeCanAdd();

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $handle = fopen($request->file('csv_file')->getRealPath(), 'r');
        $headers = null;
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        // Sanitise: strip non-breaking spaces (\xA0) and control chars
        $sanitise = function (?string $val): ?string {
            if ($val === null || $val === '') {
                return null;
            }
            $val = str_replace("\xc2\xa0", ' ', $val);
            $val = str_replace("\xa0", ' ', $val);
            $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $val);
            $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
            $val = preg_replace('/ {2,}/', ' ', $val);

            return trim($val) ?: null;
        };

        // Date parser: DD-MM-YYYY, DD/MM/YYYY, YYYY-MM-DD
        $parseDate = function ($val) {
            if (empty(trim($val ?? ''))) {
                return null;
            }
            $val = trim($val);
            if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $val)) {
                return $val;
            }
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $val, $m)) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }

            return null;
        };

        // Asset type normaliser
        $normaliseType = function (string $raw): string {
            $map = [
                'laptop' => 'laptop', 'notebook' => 'laptop', 'computer' => 'laptop',
                'monitor' => 'monitor', 'display' => 'monitor', 'screen' => 'monitor',
                'converter' => 'converter', 'hub' => 'converter', 'docking' => 'converter',
                'phone' => 'phone', 'mobile' => 'phone', 'handphone' => 'phone',
                'sim' => 'sim_card', 'sim_card' => 'sim_card',
                'access_card' => 'access_card', 'access card' => 'access_card',
            ];
            $lower = strtolower(trim($raw));
            foreach ($map as $key => $type) {
                if (str_contains($lower, $key)) {
                    return $type;
                }
            }

            return 'other';
        };

        // Specs auto-parser
        $parseSpecs = function (string $raw): array {
            $r = ['brand' => null, 'model' => null, 'processor' => null,
                'ram_size' => null, 'storage' => null, 'operating_system' => null, 'screen_size' => null];
            if (empty(trim($raw))) {
                return $r;
            }
            $s = trim($raw);
            $brands = ['Dell', 'HP', 'Lenovo', 'Apple', 'Asus', 'Acer', 'Microsoft', 'Samsung', 'LG', 'MSI', 'Toshiba', 'Incite'];

            // MODEL: line
            if (preg_match('/(?:MODEL|LAPTOP)[\:\s]+([^\n\r]+)/i', $s, $m)) {
                $ml = trim($m[1]);
                foreach ($brands as $b) {
                    if (stripos($ml, $b) !== false) {
                        $r['brand'] = $b;
                        break;
                    }
                }
                if (! $r['brand'] && strlen(explode(' ', $ml)[0] ?? '') >= 2) {
                    $r['brand'] = explode(' ', $ml)[0];
                }
                $r['model'] = $ml;
            } else {
                foreach ($brands as $b) {
                    if (stripos(explode(' ', $s)[0] ?? '', $b) !== false) {
                        $r['brand'] = $b;
                        break;
                    }
                }
                if (preg_match('/^([\w\s\-\.]+?)(?:\s+\d{1,3}GB|\s+[Ii][3579]|\s+Ryzen|\s+Win|,|$)/i', $s, $m)) {
                    $r['model'] = trim($m[1]);
                }
            }
            // CPU
            if (preg_match('/(?:CPU|PROCESSOR)[\:\s]+([^\n\r]+)/i', $s, $m)) {
                $r['processor'] = trim($m[1]);
            } elseif (preg_match('/\b((?:Intel\s+)?(?:Core\s+)?[Ii][3579][\-\s]?\d{3,5}[A-Z0-9]*|Ryzen\s+\d[\s\w]+?(?=\s+\d|,|$)|i[3579][\-]\d+[A-Z]*)/i', $s, $m)) {
                $r['processor'] = trim($m[0]);
            }
            // RAM
            if (preg_match('/(?:RAM)[\:\s]+(\d{1,3}\s*GB)/i', $s, $m)) {
                $r['ram_size'] = trim($m[1]);
            } elseif (preg_match('/\b(\d{1,3}\s*GB)\s*(?:DDR\d?|RAM|LPDDR\d?)?/i', $s, $m)) {
                $r['ram_size'] = trim($m[0]);
            }
            // Storage
            if (preg_match('/(?:STORAGE|HDD|SSD)[\:\s]+(\d{1,4}\s*(?:GB|TB)[^\n\r]*)/i', $s, $m)) {
                $r['storage'] = trim($m[1]);
            } elseif (preg_match_all('/\b(\d{1,4}\s*(?:GB|TB))\s*(?:M\.?2|NVMe|SSD|HDD|eMMC)?/i', $s, $all)) {
                foreach ($all[0] as $c) {
                    if (trim($c) !== $r['ram_size']) {
                        $r['storage'] = trim($c);
                        break;
                    }
                }
            }
            // OS
            if (preg_match('/(?:OS|WINDOWS|WIN)[\:\s]+([^\n\r]+)/i', $s, $m)) {
                $r['operating_system'] = trim($m[1]);
            } elseif (preg_match('/\b(Windows\s*\d+(?:\s+(?:Pro|Home|Enterprise))?|Win\s*\d+(?:\s+(?:Pro|Home))?|macOS(?:\s+[\w]+)?|Ventura|Monterey|Sequoia|Big Sur|Sonoma|Ubuntu(?:\s+\d+\.\d+)?)/i', $s, $m)) {
                $r['operating_system'] = trim($m[0]);
            }
            // Screen size
            if (preg_match('/\b(\d{1,2}(?:\.\d)?\s*(?:inch|in\b|"))/i', $s, $m)) {
                $r['screen_size'] = trim($m[0]);
            } elseif (preg_match("/\\b(\\d{1,2}(?:\\.\\d)?)(?:''|'|\"|\\x22)/", $s, $m)) {
                $r['screen_size'] = $m[1].'"';
            }

            return $r;
        };

        // Employee matcher
        $findEmployee = function (string $name): ?Employee {
            if (empty(trim($name))) {
                return null;
            }
            $name = trim($name);
            $emp = Employee::whereNull('active_until')->where('full_name', $name)->first();
            if ($emp) {
                return $emp;
            }
            $stripped = trim(preg_replace('/\s*\(.*?\)/', '', $name));
            if ($stripped !== $name) {
                $emp = Employee::whereNull('active_until')->where('full_name', $stripped)->first();
                if ($emp) {
                    return $emp;
                }
            }
            if (preg_match('/\(([^)]+)\)/', $name, $m)) {
                $emp = Employee::whereNull('active_until')->where('preferred_name', trim($m[1]))->first();
                if ($emp) {
                    return $emp;
                }
            }

            return Employee::whereNull('active_until')->where('full_name', 'like', '%'.$stripped.'%')->first();
        };

        // Ensure AARF exists (works for both onboarded and imported employees)
        $ensureAarf = fn (Employee $emp) => $this->ensureAarfForEmployee($emp);

        // Main import loop
        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $row);
                $rowNumber++;

                continue;
            }
            $rowNumber++;
            if (empty(array_filter($row))) {
                continue;
            }

            $d = array_combine($headers, array_pad($row, count($headers), ''));
            $v = fn (string $k) => $sanitise(trim($d[$k] ?? ''));

            // ── Required fields ───────────────────────────────────────────
            $missing = [];
            if (empty($v('asset_category'))) {
                $missing[] = 'asset_category';
            }
            if (empty($v('asset_type'))) {
                $missing[] = 'asset_type';
            }
            if (empty($v('asset_tag'))) {
                $missing[] = 'asset_tag';
            }
            if (empty($v('specs')) && empty($v('brand')) && empty($v('model'))) {
                $missing[] = 'specs (or brand+model)';
            }
            if (empty($v('remarks'))) {
                $missing[] = 'remarks';
            }

            if ($missing) {
                $errors[] = "Row {$rowNumber}: Missing required field(s): ".implode(', ', $missing).' — skipped.';
                $skipped++;

                continue;
            }

            // ── Duplicate check — skip if asset_tag already exists ────────
            $assetTag = $v('asset_tag');
            if (AssetInventory::where('asset_tag', $assetTag)->exists()) {
                $errors[] = "Row {$rowNumber}: Asset tag '{$assetTag}' already exists — skipped (duplicate).";
                $skipped++;

                continue;
            }

            // ── Parse specs + apply column overrides ──────────────────────
            $specsRaw = $sanitise(trim($d['specs'] ?? '')) ?? '';
            $parsed = $parseSpecs($specsRaw);

            $brand = $v('brand') ?: ($parsed['brand'] ?? null) ?: 'Unknown';
            $model = $v('model') ?: ($parsed['model'] ?? null) ?: $assetTag;
            $assetType = $normaliseType($v('asset_type') ?? '');

            // ── Optional field defaults ───────────────────────────────────
            $rawCond = strtolower($v('asset_condition') ?? '');
            $condition = in_array($rawCond, ['new', 'good', 'fair', 'damaged']) ? $rawCond : 'good';
            $rawMaint = strtolower($v('maintenance_status') ?? '');
            $maintStatus = in_array($rawMaint, ['none', 'under_maintenance', 'repair_required']) ? $rawMaint : 'none';
            $ownershipType = in_array(strtolower($v('ownership_type') ?? ''), ['company', 'rental'])
                           ? strtolower($v('ownership_type') ?? '') : 'company';

            // ── Employee assignment ───────────────────────────────────────
            $assignedToName = $sanitise(trim($d['assigned_to'] ?? '')) ?? '';
            $employee = $findEmployee($assignedToName);

            if (! empty($assignedToName) && ! $employee) {
                $errors[] = "Row {$rowNumber}: Employee '{$assignedToName}' not found — asset imported as unassigned.";
            }

            // Status: use CSV value if valid, else derive from assignment
            $rawStatus = strtolower($v('status') ?? '');
            if (in_array($rawStatus, ['available', 'assigned', 'under_maintenance', 'retired'])) {
                $status = $rawStatus;
            } else {
                $status = $employee ? 'assigned' : 'available';
            }
            if ($employee) {
                $status = 'assigned';
            } // assignment always wins

            // ── Remarks ───────────────────────────────────────────────────
            $remarksInput = $sanitise(trim($d['remarks'] ?? ''));
            $initialRemark = trim(($remarksInput ? $remarksInput."\n" : '').'Imported via CSV.');

            // ── Create asset record ───────────────────────────────────────
            $asset = AssetInventory::create([
                'asset_tag' => $assetTag,
                'asset_category' => $v('asset_category') ?: 'others',
                'asset_type' => $assetType,
                'brand' => $brand,
                'model' => $model,
                'serial_number' => $v('serial_number') ?: null,
                'processor' => $v('processor') ?: ($parsed['processor'] ?? null),
                'ram_size' => $v('ram_size') ?: ($parsed['ram_size'] ?? null),
                'storage' => $v('storage') ?: ($parsed['storage'] ?? null),
                'operating_system' => $v('operating_system') ?: ($parsed['operating_system'] ?? null),
                'screen_size' => $v('screen_size') ?: ($parsed['screen_size'] ?? null),
                'spec_others' => $v('spec_others') ?: (! empty($specsRaw) ? 'Original: '.$specsRaw : null),
                'ownership_type' => $ownershipType,
                'company_name' => $v('company_name') ?: null,
                'company_supplied_to' => $v('company_supplied_to') ?: null,
                'purchase_vendor' => $v('purchase_vendor') ?: null,
                'purchase_cost' => is_numeric($d['purchase_cost'] ?? '') ? $d['purchase_cost'] : null,
                'purchase_date' => $parseDate($d['purchase_date'] ?? ''),
                'warranty_expiry_date' => $parseDate($d['warranty_expiry_date'] ?? ''),
                'rental_vendor' => $ownershipType === 'rental' ? ($v('rental_vendor') ?: null) : null,
                'rental_vendor_contact' => $ownershipType === 'rental' ? ($v('rental_vendor_contact') ?: null) : null,
                'rental_cost_per_month' => $ownershipType === 'rental' && is_numeric($d['rental_cost_per_month'] ?? '') ? $d['rental_cost_per_month'] : null,
                'rental_start_date' => $ownershipType === 'rental' ? $parseDate($d['rental_start_date'] ?? '') : null,
                'rental_end_date' => $ownershipType === 'rental' ? $parseDate($d['rental_end_date'] ?? '') : null,
                'rental_contract_reference' => $ownershipType === 'rental' ? ($v('rental_contract_reference') ?: null) : null,
                'status' => $status,
                'asset_condition' => $condition,
                'maintenance_status' => $maintStatus,
                'assigned_employee_id' => $employee?->id,
                'asset_assigned_date' => $employee ? ($parseDate($d['asset_assigned_date'] ?? '') ?? now()->toDateString()) : null,
                'expected_return_date' => $parseDate($d['expected_return_date'] ?? ''),
                'asset_location' => $v('asset_location') ?: null,
                'remarks' => $initialRemark,
            ]);

            // ── Link to employee + ensure AARF ────────────────────────────
            if ($employee) {
                $this->createAssignmentForEmployee($employee, $asset->id, $asset->asset_assigned_date ?? now()->toDateString());
                $aarf = $this->ensureAarfForEmployee($employee);
                $aarf?->appendAssetChange("[{$asset->asset_tag}] assigned to {$employee->full_name} (imported from CSV).");
                $aarf?->addPendingAsset($asset->id);
            }

            $imported++;
        }

        fclose($handle);
        $message = "{$imported} asset(s) imported successfully.";
        if ($skipped) {
            $message .= " {$skipped} row(s) skipped.";
        }

        return back()->with('success', $message)->with('import_errors', $errors);
    }

    // ── Assignee resolution ────────────────────────────────────────────────
    //
    // An asset is not always held by an `employees` row. A new hire has no employee record
    // until `employees:activate` creates it on their start date, so an asset handed over
    // before day one can only be carried by asset_assignments.onboarding_id — the state the
    // onboarding auto-assign has always produced, and now the state IT creates deliberately
    // when it picks the machine a new hire will get.
    //
    // These helpers are the ONE place that difference is resolved, so every assign / swap /
    // release path reaches the right AARF and the right inbox. Shape:
    //   ['type' => 'employee'|'onboarding', 'id' => int, 'name' => string, 'key' => 'employee:12']
    // The key exists so callers compare assignees without caring which kind they are.

    private function employeeAssignee(Employee $emp): array
    {
        return [
            'type' => 'employee',
            'id' => $emp->id,
            'name' => $emp->full_name
                ?: ($emp->onboarding?->personalDetail?->full_name ?: "Employee #{$emp->id}"),
            'key' => "employee:{$emp->id}",
        ];
    }

    private function onboardingAssignee(Onboarding $ob): array
    {
        return [
            'type' => 'onboarding',
            'id' => $ob->id,
            'name' => $ob->personalDetail?->full_name ?: "New hire #{$ob->id}",
            'key' => "onboarding:{$ob->id}",
        ];
    }

    /**
     * Whoever holds the asset right now: the employee FK if there is one, else the live
     * asset_assignments row. An onboarding assignment whose hire has since been activated
     * resolves to the employee — that row is the truthful identity once it exists.
     */
    private function currentAssignee(AssetInventory $asset): ?array
    {
        if ($asset->assigned_employee_id) {
            $emp = Employee::find($asset->assigned_employee_id);

            return $emp ? $this->employeeAssignee($emp) : null;
        }

        $assignment = AssetAssignment::where('asset_inventory_id', $asset->id)
            ->where('status', 'assigned')
            ->latest('id')
            ->first();

        if ($assignment?->employee_id) {
            $emp = Employee::find($assignment->employee_id);

            return $emp ? $this->employeeAssignee($emp) : null;
        }

        if ($assignment?->onboarding_id) {
            $emp = Employee::where('onboarding_id', $assignment->onboarding_id)->first();
            if ($emp) {
                return $this->employeeAssignee($emp);
            }
            $ob = Onboarding::with('personalDetail')->find($assignment->onboarding_id);

            return $ob ? $this->onboardingAssignee($ob) : null;
        }

        return null;
    }

    /** The assignee the submitted form asks for, or null for "not assigned". */
    private function requestedAssignee(
        Request $request,
        string $employeeField = 'assigned_employee_id',
        string $onboardingField = 'assigned_onboarding_id',
    ): ?array {
        if ($empId = $request->input($employeeField)) {
            $emp = Employee::find($empId);

            return $emp ? $this->employeeAssignee($emp) : null;
        }

        if ($obId = $request->input($onboardingField)) {
            // Activated between page load and submit → file it under the employee row, so the
            // asset is not left pointing at an identity that has been superseded.
            $emp = Employee::where('onboarding_id', $obId)->first();
            if ($emp) {
                return $this->employeeAssignee($emp);
            }
            $ob = Onboarding::with('personalDetail')->find($obId);

            return $ob ? $this->onboardingAssignee($ob) : null;
        }

        return null;
    }

    /** The AARF this assignee acknowledges on, created if they have none yet. */
    private function assigneeAarf(array $assignee): ?Aarf
    {
        if ($assignee['type'] === 'employee') {
            $emp = Employee::find($assignee['id']);

            return $emp ? $this->ensureAarfForEmployee($emp) : null;
        }

        if (! Onboarding::whereKey($assignee['id'])->exists()) {
            return null;
        }

        // Onboarding records are created with their AARF, but one from before that existed —
        // or one whose AARF creation was skipped — must still be able to receive assets.
        $aarf = Aarf::firstOrCreate(
            ['onboarding_id' => $assignee['id']],
            [
                'aarf_reference' => Onboarding::generateAarfReference(),
                'acknowledgement_token' => Str::random(64),
            ]
        );

        // Without a token the emailed link cannot exist, so the form would be unreachable.
        if (! $aarf->acknowledgement_token) {
            $aarf->update(['acknowledgement_token' => Str::random(64)]);
        }

        return $aarf;
    }

    private function createAssignmentForAssignee(array $assignee, int $assetId, string $date): void
    {
        if ($assignee['type'] === 'employee') {
            $emp = Employee::find($assignee['id']);
            if ($emp) {
                $this->createAssignmentForEmployee($emp, $assetId, $date);
            }

            return;
        }

        AssetAssignment::create([
            'onboarding_id' => $assignee['id'],
            'asset_inventory_id' => $assetId,
            'assigned_date' => $date,
            'status' => 'assigned',
        ]);
    }

    /**
     * Where an AARF notification goes. Work email first — it is the address the new hire is
     * told to watch and the one the form is meant to arrive at — then the personal one, which
     * for a pre-start hire may be all that is on file (work email is nullable on onboarding).
     */
    private function assigneeAarfEmail(array $assignee): ?string
    {
        if ($assignee['type'] === 'employee') {
            $emp = Employee::with(['onboarding.workDetail', 'onboarding.personalDetail'])->find($assignee['id']);

            return $emp?->company_email
                ?: ($emp?->personal_email
                ?: ($emp?->onboarding?->workDetail?->company_email
                ?: $emp?->onboarding?->personalDetail?->personal_email));
        }

        $ob = Onboarding::with(['workDetail', 'personalDetail'])->find($assignee['id']);

        return $ob?->workDetail?->company_email ?: $ob?->personalDetail?->personal_email;
    }

    /**
     * Log the change on the assignee's AARF and email them the acknowledgement link.
     *
     * Returns a sentence for the operator's flash message: whether the AARF was emailed and
     * to which address, or why it was not. A new hire's work email is nullable, so "no email
     * was sent" is a real outcome IT has to be told about on screen — a log line they never
     * read is how an unacknowledged handover goes unnoticed.
     */
    private function notifyAssigneeOfAarf(
        array $assignee,
        AssetInventory $asset,
        string $action,
        string $logLine,
        bool $resetAcknowledgement = true,
        bool $pending = true,
    ): string {
        $aarf = $this->assigneeAarf($assignee);
        if (! $aarf) {
            return '';
        }

        $aarf->appendAssetChange($logLine);
        if ($resetAcknowledgement) {
            $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
        }
        if ($pending) {
            $action === 'returned'
                ? $aarf->removePendingAsset($asset->id)
                : $aarf->addPendingAsset($asset->id);
        }

        $to = $this->assigneeAarfEmail($assignee);
        if (! $to) {
            \Log::info("AARF email skipped — no address on file for {$assignee['key']} ({$assignee['name']}), AARF #{$aarf->id}");

            return "No work or personal email is on file for {$assignee['name']} — the AARF was NOT emailed.";
        }
        if (! $aarf->acknowledgement_token) {
            \Log::info("AARF email skipped — no acknowledgement_token. AARF #{$aarf->id}");

            return '';
        }

        $this->queueAarfEmail($aarf->id, $to, $assignee['name'], $action);

        return $action === 'returned'
            ? "Asset-return notice emailed to {$to}."
            : "AARF emailed to {$to} for acknowledgement.";
    }

    /**
     * Warn when this person already holds another asset of the same type.
     *
     * The onboarding auto-assign reserves whichever machine of a requested type happens to be
     * free, so a new hire is often already holding one by the time IT hands over the laptop it
     * actually chose. Nothing is released automatically — which of the two they keep is IT's
     * call, and guessing would take an asset back from someone who has it in their hands — but
     * they are told, because otherwise a double handover shows up only at an audit.
     */
    private function sameTypeAlreadyHeldNote(array $assignee, AssetInventory $asset): string
    {
        if (! $asset->asset_type) {
            return '';
        }

        // An employee's assignments may be keyed by onboarding_id (see
        // createAssignmentForEmployee), so both keys have to be searched for one person.
        $onboardingIds = [];
        $employeeIds = [];
        if ($assignee['type'] === 'onboarding') {
            $onboardingIds[] = $assignee['id'];
        } else {
            $employeeIds[] = $assignee['id'];
            if ($obId = Employee::whereKey($assignee['id'])->value('onboarding_id')) {
                $onboardingIds[] = $obId;
            }
        }

        $tags = AssetAssignment::query()
            ->with('asset:id,asset_tag')
            ->where('status', 'assigned')
            ->where('asset_inventory_id', '!=', $asset->id)
            ->where(function ($q) use ($onboardingIds, $employeeIds) {
                if ($onboardingIds) {
                    $q->orWhereIn('onboarding_id', $onboardingIds);
                }
                if ($employeeIds) {
                    $q->orWhereIn('employee_id', $employeeIds);
                }
            })
            ->whereHas('asset', fn ($q) => $q->where('asset_type', $asset->asset_type)->whereNull('decommissioned_at'))
            ->get()
            ->pluck('asset.asset_tag')
            ->filter()->unique()->values();

        if ($tags->isEmpty()) {
            return '';
        }

        $type = str_replace('_', ' ', (string) $asset->asset_type);

        return "Note: {$assignee['name']} already holds another {$type} (".$tags->implode(', ').
               ') — release whichever they are not keeping.';
    }

    /**
     * Deferred to terminating() so a slow mail server never holds up the save. The AARF row
     * is already committed at this point, so a send failure is logged, not surfaced as a
     * failed assignment — and the link stays valid for a resend.
     */
    private function queueAarfEmail(int $aarfId, string $to, string $name, string $action): void
    {
        \Log::info("AARF email queued via terminating() — to: {$to}, action: {$action}, AARF #{$aarfId}");

        app()->terminating(function () use ($aarfId, $to, $name, $action) {
            \Illuminate\Support\Facades\Log::info("AARF terminating callback firing — to: {$to}, action: {$action}");
            try {
                $freshAarf = \App\Models\Aarf::find($aarfId);
                if (! $freshAarf) {
                    \Illuminate\Support\Facades\Log::warning("AARF email aborted — AARF #{$aarfId} not found in DB");

                    return;
                }

                \Illuminate\Support\Facades\Mail::to($to)
                    ->send(new \App\Mail\AarfAcknowledgementMail($freshAarf, $name, $action));

                \Illuminate\Support\Facades\Log::info("AARF email sent successfully to {$to}");

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("AARF email FAILED to {$to}: ".$e->getMessage());
            }
        });
    }

    /**
     * New hires who can hold an asset but have no employees row yet.
     *
     * Anyone already activated is excluded on purpose — they are in the employee list, and
     * offering both would let one person be assigned an asset under two identities.
     */
    private static function pendingOnboardingOptions()
    {
        return Onboarding::with(['personalDetail', 'workDetail'])
            ->whereIn('status', self::ASSIGNABLE_ONBOARDING_STATUSES)
            ->whereDoesntHave('employee')
            ->whereHas('personalDetail')
            ->get()
            ->sortBy(fn ($ob) => strtolower((string) $ob->personalDetail?->full_name))
            ->values();
    }

    /**
     * Statuses an onboarding can be assigned an asset under. 'active' persists after the hire
     * is activated (nothing moves it on), which is why the employee fallback in
     * requestedAssignee() matters; 'offboarded'/'completed' records are past handing kit over.
     */
    private const ASSIGNABLE_ONBOARDING_STATUSES = ['pending', 'active'];

    private static function assignableOnboardingRule(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('onboardings', 'id')->whereIn('status', self::ASSIGNABLE_ONBOARDING_STATUSES);
    }

    private function ensureAarfForEmployee(Employee $emp): ?Aarf
    {
        // Try direct employee_id link (imported employees)
        $aarf = Aarf::where('employee_id', $emp->id)->first();
        if ($aarf) {
            return $aarf;
        }

        // Try onboarding_id link (onboarded employees)
        if ($emp->onboarding_id) {
            $aarf = Aarf::where('onboarding_id', $emp->onboarding_id)->first();
            if ($aarf) {
                return $aarf;
            }
        }

        // Create new AARF — use employee_id if no onboarding, onboarding_id if available
        return Aarf::create([
            'onboarding_id' => $emp->onboarding_id ?? null,
            'employee_id' => $emp->onboarding_id ? null : $emp->id,
            'aarf_reference' => Onboarding::generateAarfReference(),
            'acknowledgement_token' => Str::random(64),
        ]);
    }

    /**
     * Create an asset_assignment record for an employee.
     * Uses employee_id when no onboarding_id exists.
     */
    private function createAssignmentForEmployee(Employee $emp, int $assetId, string $date): void
    {
        AssetAssignment::create([
            'onboarding_id' => $emp->onboarding_id ?? null,
            'employee_id' => $emp->onboarding_id ? null : $emp->id,
            'asset_inventory_id' => $assetId,
            'assigned_date' => $date,
            'status' => 'assigned',
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Stage an asset into the Decommissioning queue (dispose_assets).
     *
     * @param  string  $type  'e_waste' (Not Good) | 'vendor_return' (Returned)
     */
    private function stageForDecommission(AssetInventory $asset, string $type, string $actorName, ?string $reason): void
    {
        // Completeness is NOT set here any more (2026-08-13). It is decided by an inspection,
        // which is a separate act performed later from the Decommissioning queue — see
        // AssetDecommissionController::inspect(). It used to default to 'complete' on this
        // path, which made an asset nobody had looked at indistinguishable from one inspected
        // and found intact, and left the quarterly gate with nothing to test.
        DisposedAsset::firstOrCreate(
            ['asset_inventory_id' => $asset->id],
            [
                'asset_tag' => $asset->asset_tag,
                'asset_type' => $asset->asset_type,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'serial_number' => $asset->serial_number,
                'asset_condition' => $asset->asset_condition,
                'decommission_type' => $type,
                'disposed_by' => $actorName,
                'disposed_at' => now(),
                'remarks' => $asset->remarks,
            ]
        );

        // Keep the routing type + reason correct even if the staging row pre-existed (e.g.
        // condition switched not_good ⇄ returned). Never touch a row already gathered into an
        // e-waste cycle — and note this column says nothing about a RETURN form, which stamps
        // nothing here; that case is refused earlier, by update()'s pre-flight guard.
        $update = [
            'decommission_type' => $type,
            'asset_condition' => $asset->asset_condition,
        ];
        // Completeness, the parts list and the inspection that produced them are e-waste
        // concepts. Switching the row to a vendor return clears all three — a returned asset
        // carrying "Inspected — Incomplete" would assert a disposal inspection that no longer
        // applies to it. Re-saving an asset that is STILL e-waste deliberately leaves them
        // alone, so an ordinary edit (remarks, photos) cannot wipe a completed inspection.
        if ($type !== 'e_waste') {
            $update['ewaste_completeness'] = null;
            $update['ewaste_parts_removed'] = null;
            $update['inspected_at'] = null;
            $update['inspected_by'] = null;
        }
        if ($reason) {
            $update['reason'] = $reason;
        }
        DisposedAsset::where('asset_inventory_id', $asset->id)
            ->whereNull('decommission_batch_id')
            ->update($update);

        // The completeness verdict is no longer known at this point — the inspection logs its
        // own remark when it happens.
        $label = $type === 'vendor_return' ? 'Returned (to vendor)' : 'Not Good';
        $reasonNote = $reason ? " Reason: {$reason}." : '';
        $asset->appendRemark("Asset flagged as {$label} — staged for decommissioning by {$actorName}.{$reasonNote}");
    }

    private function buildAssetData(Request $request, array $validated, $user = null): array
    {
        $canEditAll = ! $user || $user->canEditAllAssetSections();

        $data = [
            'asset_tag' => $validated['asset_tag'],
            'asset_category' => $validated['asset_category'],
            'asset_type' => $validated['asset_type'],
            'brand' => $validated['brand'],
            'model' => $validated['model'],
            'asset_name' => $validated['asset_name'] ?? null,
            'serial_number' => $validated['serial_number'],
            'notes' => $validated['notes'] ?? null,
            'processor' => $validated['processor'] ?? null,
            'ram_size' => $validated['ram_size'] ?? null,
            'storage' => $validated['storage'] ?? null,
            'operating_system' => $validated['operating_system'] ?? null,
            'screen_size' => $validated['screen_size'] ?? null,
            'spec_others' => $validated['spec_others'] ?? null,
        ];

        if ($canEditAll) {
            $data['purchase_date'] = $validated['purchase_date'] ?? null;
            $data['purchase_cost'] = $validated['purchase_cost'] ?? null;
            $data['warranty_expiry_date'] = $validated['warranty_expiry_date'] ?? null;

            $data['ownership_type'] = $validated['ownership_type'] ?? 'company';
            // The registered-vendor link survives BOTH branches: an asset bought from a
            // supplier keeps its vendor exactly as a rented one does.
            $vendorId = $validated['vendor_id'] ?? null;
            $data['vendor_id'] = $vendorId;
            $vendorName = $vendorId ? (\App\Models\Vendor::find($vendorId)?->name) : null;

            if ($data['ownership_type'] === 'rental') {
                $data['company_name'] = null;
                $data['company_supplied_to'] = $validated['company_supplied_to'] ?? null;
                // `rental_vendor` holds the PERSON we deal with, not the vendor company:
                // picking a registered vendor auto-fills it with that vendor's PIC name
                // (and rental_vendor_contact with their number). It is deliberately NOT
                // synced to the vendor's name any more — the company is on vendor_id, and
                // both vendor filters now resolve through that FK (applyVendorFilter), so
                // an asset linked only by FK is still found. For an UNREGISTERED vendor
                // there is no FK, so the free text is all there is and the filter falls
                // back to it — which is why applyVendorFilter keeps both arms.
                $data['rental_vendor'] = $validated['rental_vendor'] ?? null;
                $data['rental_vendor_contact'] = $validated['rental_vendor_contact'] ?? null;
                $data['rental_cost_per_month'] = $validated['rental_cost_per_month'] ?? null;
                $data['rental_start_date'] = $validated['rental_start_date'] ?? null;
                $data['rental_end_date'] = $validated['rental_end_date'] ?? null;
                $data['rental_contract_reference'] = $validated['rental_contract_reference'] ?? null;
                // A rental has no purchase vendor; keep the two stories from bleeding.
                $data['purchase_vendor'] = $validated['purchase_vendor'] ?? null;
            } else {
                $data['company_name'] = $validated['company_name'] ?? null;
                $data['company_supplied_to'] = null;
                $data['rental_vendor'] = null;
                $data['rental_vendor_contact'] = null;
                $data['rental_cost_per_month'] = null;
                $data['rental_start_date'] = null;
                $data['rental_end_date'] = null;
                $data['rental_contract_reference'] = null;
                // Same sync on the purchase side: purchase_vendor is the free-text name
                // shown across the existing asset views and exports.
                $data['purchase_vendor'] = $vendorName ?: ($validated['purchase_vendor'] ?? null);
            }

            // Section E condition drives availability:
            //   good             → available (unless assigned to an employee)
            //   under_maintenance→ unavailable
            //   not_good         → unavailable + flagged for disposal on save
            $condition = $validated['asset_condition'];
            $data['asset_condition'] = $condition;
            $data['maintenance_status'] = ($condition === 'under_maintenance')
                ? ($validated['maintenance_status'] ?? 'pending')
                : null;
            $data['last_maintenance_date'] = $validated['last_maintenance_date'] ?? null;

            // Section D — assignment. EITHER id means the asset is held by someone; only the
            // FK differs. A pre-start new hire has no employees row, so their assignment is
            // carried by asset_assignments alone and assigned_employee_id stays null — the
            // same shape the onboarding auto-assign has always written.
            $newEmployeeId = $validated['assigned_employee_id'] ?? null;
            $newOnboardingId = $validated['assigned_onboarding_id'] ?? null;

            if (! $newEmployeeId && $newOnboardingId) {
                // Activated between page load and submit → the employees row exists now, so
                // stamp the FK rather than leaving the asset keyed to a superseded identity.
                $newEmployeeId = Employee::where('onboarding_id', $newOnboardingId)->value('id');
            }

            if ($newEmployeeId || $newOnboardingId) {
                // Assigned → 'assigned' internally (AARF logic depends on this value)
                $data['status'] = 'assigned';
                $data['assigned_employee_id'] = $newEmployeeId ?: null;
                $data['asset_assigned_date'] = $validated['asset_assigned_date'] ?? now()->toDateString();
                $data['expected_return_date'] = $validated['expected_return_date'] ?? null;
            } else {
                // Nobody holds it → status driven by condition
                $data['status'] = ($condition === 'good') ? 'available' : 'unavailable';
                $data['assigned_employee_id'] = null;
                $data['asset_assigned_date'] = null;
                $data['expected_return_date'] = null;
            }
        }

        return $data;
    }

    private function validateAsset(Request $request, bool $isUpdate = false, $user = null): array
    {
        $rules = [
            'asset_tag' => 'required|string|max:50'.($isUpdate ? '' : '|unique:asset_inventories,asset_tag'),
            'asset_category' => 'required|string|max:100',
            'asset_type' => 'required|string|max:100',
            'brand' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'asset_name' => 'nullable|string|max:255',
            'serial_number' => 'required|string|max:100',
            'notes' => 'nullable|string|max:1500',
            'processor' => 'nullable|string|max:255',
            'ram_size' => 'nullable|string|max:100',
            'storage' => 'nullable|string|max:100',
            'operating_system' => 'nullable|string|max:100',
            'screen_size' => 'nullable|string|max:50',
            'spec_others' => 'nullable|string',
        ];

        $canEditAll = ! $user || $user->canEditAllAssetSections();
        if ($canEditAll) {
            $rules['purchase_date'] = 'nullable|date';
            $rules['purchase_vendor'] = 'nullable|string|max:255';
            $rules['purchase_cost'] = 'nullable|numeric|min:0';
            $rules['warranty_expiry_date'] = 'nullable|date';
            $rules['invoice_document'] = 'nullable|file|mimes:pdf|max:5120|valid_file_content';
            $rules['invoice_documents'] = 'nullable|array|max:10';
            $rules['invoice_documents.*'] = 'file|mimes:pdf,jpg,jpeg,png|max:5120|valid_file_content';
            $rules['ownership_type'] = 'required|in:company,rental';
            $rules['company_name'] = 'nullable|string|max:255';
            $rules['company_supplied_to'] = 'nullable|string|max:255';
            $rules['rental_vendor'] = 'nullable|string|max:255';
            // One FK for both ownership types — rented-from when ownership_type = rental,
            // purchased-from when = company. (Was rental_vendor_id until 2026-08-06.)
            $rules['vendor_id'] = 'nullable|exists:vendors,id';
            $rules['rental_vendor_contact'] = 'nullable|string|max:255';
            $rules['rental_cost_per_month'] = 'nullable|numeric|min:0';
            $rules['rental_start_date'] = 'nullable|date';
            $rules['rental_end_date'] = 'nullable|date|after_or_equal:rental_start_date';
            $rules['rental_contract_reference'] = 'nullable|string|max:255';
            $rules['rental_contract_documents'] = 'nullable|array|max:10';
            $rules['rental_contract_documents.*'] = 'file|mimes:pdf,jpg,jpeg,png|max:5120|valid_file_content';
            $rules['status'] = 'required|in:available,unavailable,assigned';
            $rules['assigned_employee_id'] = 'nullable|exists:employees,id';
            // A new hire can hold an asset before their start date, when no employees row
            // exists yet — the picker offers them alongside employees and posts this instead.
            $rules['assigned_onboarding_id'] = ['nullable', self::assignableOnboardingRule()];
            $rules['asset_assigned_date'] = 'nullable|date';
            $rules['expected_return_date'] = 'nullable|date';
            $rules['asset_condition'] = 'required|in:'.implode(',', array_keys(AssetInventory::CONDITIONS));
            $rules['maintenance_status'] = 'nullable|in:pending,in_progress,done';
            $rules['last_maintenance_date'] = 'required_if:asset_condition,under_maintenance|nullable|date';
            $rules['remarks'] = 'nullable|string';
            $rules['asset_photos'] = 'nullable|array|min:1|max:15';
            $rules['asset_photos.*'] = 'image|max:5120|valid_file_content';
            // Mandatory for an e-waste marking: the reason is what the vendor RFQ, the Finance
            // approval and the final decommissioning report all state as WHY the asset was
            // written off, and none of them can invent it later. Both asset forms have always
            // rendered it with an asterisk and toggled `required` in JS — the server rule was
            // the half that never enforced it, so a direct POST filed an unexplained write-off.
            // Deliberately NOT required for a vendor return: there the usual reason is "contract
            // end" and the return AARF carries the narrative with the collector's signature.
            $rules['decommission_reason'] = ['nullable', 'string', 'max:500', Rule::requiredIf(
                fn () => $request->input('asset_condition') === 'not_good'
            )];
            // ewaste_completeness / ewaste_parts_removed are deliberately NOT validated here and
            // no longer appear on either asset form. They are set by an inspection alone, so a
            // posted value must be ignored rather than accepted — the same rule the vendor
            // document forms follow: a value no screen displays must not be settable from the
            // request. Accepting one would let the completeness a vendor is quoting against be
            // set by somebody who never looked at the machine.
        }

        return $request->validate($rules, [
            // "The decommission reason field is required" does not say why it matters here.
            'decommission_reason.required' => 'State why this asset is being written off — the reason is sent to the e-waste vendor and printed on the final decommissioning report.',
        ]);
    }

    private function authorizeItAccess(): void
    {
        if (! Auth::user()->canViewAssets()) {
            abort(403);
        }
    }

    private function authorizeCanAdd(): void
    {
        if (! Auth::user()->canAddAsset()) {
            abort(403, 'No permission to add assets.');
        }
    }

    private function authorizeCanEdit(): void
    {
        if (! Auth::user()->canEditAsset()) {
            abort(403, 'No permission to edit assets.');
        }
    }
}
