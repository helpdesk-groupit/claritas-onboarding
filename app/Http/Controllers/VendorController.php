<?php

namespace App\Http\Controllers;

use App\Models\RentalAssetAcknowledgement;
use App\Models\RentalAssetAcknowledgementItem;
use App\Models\Vendor;
use App\Models\VendorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Vendor Management — the company-wide operational vendor master.
 *
 * Holds every vendor the company deals with: suppliers we buy assets from, companies we
 * rent from, service providers, subscriptions. The rental / repair / e-waste type tokens
 * that the decommissioning flows filter on are a SUBSET of this list, not its purpose.
 *
 * Retiring a vendor is an is_active toggle, which keeps historical references intact (assets,
 * decommission batches, contracts and billing documents all point at a vendor). A hard delete
 * exists but only for a row that references NOTHING — see destroy().
 */
class VendorController extends Controller
{
    private function authorizeView(): void
    {
        if (! Auth::user()->canViewVendors()) {
            abort(403);
        }
    }

    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageVendors()) {
            abort(403, 'No permission to manage vendors.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeView();

        // Counted here, not per row: the Assets/Contracts columns need two of these, and
        // deletionBlockers() reads all five to decide whether the row may be deleted at all.
        $query = Vendor::query()
            ->withCount(array_keys(Vendor::DELETE_BLOCKERS))
            ->orderBy('name');

        if ($request->filled('type')) {
            $query->whereJsonContains('vendor_types', $request->type);
        }
        if ($request->filled('industry')) {
            $query->where('industry', $request->industry);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('pic_name', 'like', "%{$s}%")
                ->orWhere('pic_email', 'like', "%{$s}%")
                ->orWhere('company_registration_no', 'like', "%{$s}%")
                ->orWhere('sst_number', 'like', "%{$s}%")
                ->orWhere('tin_number', 'like', "%{$s}%"));
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $vendors = $query->paginate(20)->withQueryString();
        $canManage = Auth::user()->canManageVendors();

        // Master-data health, deliberately NOT filtered — these answer "what does our vendor
        // master look like", not "what did I just search for". `primary` matters most: with
        // no primary e-waste vendor, EwasteSweepService::sweep() cannot RFQ and only bells IT,
        // so the quarterly cycle stalls silently. Surfaced as a banner on the page.
        $stats = [
            'active' => Vendor::active()->count(),
            'inactive' => Vendor::where('is_active', false)->count(),
            'rental' => Vendor::active()->ofAnyType(Vendor::RENTAL_ASSET_TYPES)->count(),
            'ewaste' => Vendor::active()->ofType('ewaste')->count(),
            'contracts' => VendorContract::whereIn('status', ['active', 'draft'])->count(),
            // Contracts running out inside the warning window — the one number on this page
            // that is a call to action rather than a description.
            'expiring' => VendorContract::query()
                ->whereNotIn('status', ['terminated'])
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays(VendorContract::EXPIRY_WARNING_DAYS)->toDateString()])
                ->count(),
            'primary' => Vendor::primaryEwaste(),
        ];

        return view('vendors.index', compact('vendors', 'canManage', 'stats'));
    }

    /**
     * The vendor profile: who they are, what we've agreed with them, what they've billed
     * us, and which assets sit against them.
     */
    public function show(Vendor $vendor)
    {
        $this->authorizeView();

        $vendor->load([
            'contracts.creator',
            // withCount here rather than per row: the billing tab prints how many assets
            // arrived on each document, and the assets tab groups by it.
            'billingDocuments' => fn ($q) => $q->withCount('originAssets'),
            'billingDocuments.creator',
            'billingDocuments.contract',
            'assets' => fn ($q) => $q->orderBy('asset_tag'),
            // The invoice each asset arrived on — the assets tab groups by it, so without
            // this it is a query per asset.
            'assets.originInvoice',
            // resolvedAssigneeName() walks employee → onboarding → personalDetail; eager-load
            // the whole chain or the assets tab fires 3 queries per row.
            'assets.assignedEmployee.onboarding.personalDetail',
            // withCount so the AARF list can print "N assets" without a query per row.
            'rentalAcknowledgements' => fn ($q) => $q->withCount('items'),
            'rentalAcknowledgements.acknowledger',
            // The Ask AI thread. `author` or the who-said-it line fires a query per turn.
            'chatMessages.author',
        ]);

        // One query for the five counts deletionBlockers() reads, instead of five when the
        // header renders the Delete button.
        $vendor->loadCount(array_keys(Vendor::DELETE_BLOCKERS));

        $assets = $vendor->assets;
        $contracts = $vendor->contracts;

        // Rental assets from this vendor that nobody has signed for yet. Queried live
        // rather than derived from $assets, because "pending" is defined by the ABSENCE
        // of an acknowledgement item — a fact that lives in another table.
        $pendingAssets = RentalAssetAcknowledgement::pendingAssetsFor($vendor);
        $acknowledgements = $vendor->rentalAcknowledgements;

        // asset id => direction => the form it sits on in that direction.
        //
        // Keyed by direction, not flat, because one asset now legitimately appears on TWO
        // documents in its life — the receipt when it arrived and the return when it went
        // back. A flat "status of the form" map would let the later one silently overwrite
        // the earlier, and the AARF column would report the wrong document. An asset on an
        // unsigned form is still not acknowledged, which is why the STATUS is carried and
        // not just a boolean.
        $assetFormStatus = RentalAssetAcknowledgementItem::query()
            ->join(
                'rental_asset_acknowledgements as raa',
                'raa.id', '=', 'rental_asset_acknowledgement_items.rental_asset_acknowledgement_id'
            )
            ->whereIn('rental_asset_acknowledgement_items.asset_inventory_id', $assets->pluck('id'))
            ->get([
                'rental_asset_acknowledgement_items.asset_inventory_id as asset_id',
                'rental_asset_acknowledgement_items.direction as direction',
                'raa.id as form_id',
                'raa.reference as reference',
                'raa.status as status',
            ])
            ->groupBy('asset_id')
            ->map(fn ($rows) => $rows->keyBy('direction'));

        $summary = [
            'rented' => $assets->where('ownership_type', 'rental')->count(),
            'purchased' => $assets->where('ownership_type', 'company')->count(),
            // Live rental commitment: what we pay this vendor every month for kit we don't own.
            'monthly_rental' => (float) $assets->where('ownership_type', 'rental')
                ->whereNull('decommissioned_at')
                ->sum('rental_cost_per_month'),
            'contracts_active' => $contracts->filter(fn ($c) => $c->stateBadge()['color'] === 'success')->count(),
            'contracts_expiring' => $contracts->filter(fn ($c) => $c->isExpiringSoon())->count(),
            'quotations' => $vendor->billingDocuments->where('doc_type', 'quotation')->count(),
            'invoices' => $vendor->billingDocuments->where('doc_type', 'invoice')->count(),
            // Documents that carry SST the vendor's own registration says they may not charge.
            'sst_flags' => $vendor->billingDocuments->filter(fn ($d) => $d->sstFlag() !== null)->count(),
        ];

        return view('vendors.show', [
            'vendor' => $vendor,
            'assets' => $assets,
            'summary' => $summary,
            'canManage' => Auth::user()->canManageVendors(),
            'sstVerdict' => $vendor->sstVerdict(),
            'pendingAssets' => $pendingAssets,
            'acknowledgements' => $acknowledgements,
            'assetFormStatus' => $assetFormStatus,
            // The Ask AI tab. `askable` is every filed document — INCLUDING the ones the
            // assistant cannot read, because the panel lists those with their reason; a
            // document dropped from the list silently reads as one the answer covered.
            'askable' => $vendor->askableDocuments(),
            'chatMessages' => $vendor->chatMessages,
            // A per-document "Ask about this" link. Validated in the view against the
            // readable set, so a stale key falls back to the whole vendor.
            'askFocus' => request('focus'),
        ]);
    }

    public function create()
    {
        $this->authorizeManage();

        return view('vendors.form');
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $data = $this->validateVendor($request);
        $data = $this->applyPrimaryRules($request, $data);

        $vendor = Vendor::create($data);

        $this->enforceSinglePrimary($vendor);

        return redirect()->route('vendors.show', $vendor)->with('success', "Vendor \"{$vendor->name}\" created.");
    }

    public function edit(Vendor $vendor)
    {
        $this->authorizeManage();

        return view('vendors.form', compact('vendor'));
    }

    public function update(Request $request, Vendor $vendor)
    {
        $this->authorizeManage();

        $data = $this->validateVendor($request, $vendor);
        $data = $this->applyPrimaryRules($request, $data);

        $vendor->update($data);

        $this->enforceSinglePrimary($vendor);

        return redirect()->route('vendors.show', $vendor)->with('success', "Vendor \"{$vendor->name}\" updated.");
    }

    /**
     * Delete a vendor row that references nothing.
     *
     * Deactivating is still the normal way to retire a vendor — this exists for the row that
     * is not history: the duplicate or typo'd entry somebody just created. A vendor carrying
     * assets, contracts, billing documents, AARFs or e-waste cycles is REFUSED, and the flash
     * names exactly what is attached, because `vendor_contracts`, `vendor_billing_documents`
     * and `rental_asset_acknowledgements` are all `cascadeOnDelete`: without the guard one
     * click would take out every filed invoice and every signed acknowledgement with it, and
     * nothing would say so. The listing and profile disable the button for the same reason,
     * but the check has to live here — a disabled button is not authorization.
     */
    public function destroy(Vendor $vendor)
    {
        $this->authorizeManage();

        if ($blockers = $vendor->deletionBlockers()) {
            return redirect()->back()->with(
                'error',
                "\"{$vendor->name}\" cannot be deleted — it still has ".implode(', ', $blockers)
                .' on record. Deactivate the vendor instead: the reference is kept and it stops appearing in pickers.'
            );
        }

        $name = $vendor->name;
        $vendor->delete();

        return redirect()->route('vendors.index')->with('success', "Vendor \"{$name}\" deleted.");
    }

    public function toggleActive(Vendor $vendor)
    {
        $this->authorizeManage();

        $vendor->is_active = ! $vendor->is_active;
        // A deactivated vendor can't be the primary e-waste RFQ target.
        if (! $vendor->is_active) {
            $vendor->is_primary_ewaste = false;
        }
        $vendor->save();

        return redirect()->back()
            ->with('success', "Vendor \"{$vendor->name}\" ".($vendor->is_active ? 'activated' : 'deactivated').'.');
    }

    // ── Validation + rules ────────────────────────────────────────────────────
    private function validateVendor(Request $request, ?Vendor $vendor = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                // Two rows for the same vendor is the failure mode that makes a master
                // useless — one carries the contracts, the other the assets.
                Rule::unique('vendors', 'name')->ignore($vendor?->id),
            ],
            'vendor_types' => 'required|array|min:1',
            'vendor_types.*' => ['string', Rule::in(array_keys(Vendor::TYPES))],
            'industry' => ['nullable', 'string', Rule::in(array_keys(Vendor::INDUSTRIES))],
            'pic_name' => 'nullable|string|max:255',
            'pic_email' => 'nullable|email|max:255',
            'pic_phone' => 'nullable|string|max:50',
            'technical_person_name' => 'nullable|string|max:255',
            'technical_person_phone' => 'nullable|string|max:50',
            'technical_person_email' => 'nullable|email|max:255',
            'company_registration_no' => 'nullable|string|max:100',
            'sst_number' => 'nullable|string|max:60',
            'sst_category' => ['nullable', 'string', Rule::in(array_keys(Vendor::sstCategories()))],
            'tin_number' => 'nullable|string|max:100',
            // Bank details are free text on purpose — see Vendor::BANK_SUGGESTIONS. The
            // lengths mirror the columns, which mirror acc_vendors where they overlap.
            'bank_name' => 'nullable|string|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_branch' => 'nullable|string|max:255',
            'bank_swift' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:1000',
            'contact_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ], [
            'vendor_types.required' => 'Select at least one type of service.',
            'name.unique' => 'A vendor with this name is already registered.',
        ]);
    }

    /**
     * Primary-e-waste toggle is only meaningful when e-waste is one of the types.
     * Active toggle from the checkbox.
     */
    private function applyPrimaryRules(Request $request, array $data): array
    {
        $isEwaste = in_array('ewaste', $data['vendor_types'] ?? [], true);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_primary_ewaste'] = $isEwaste && $request->boolean('is_primary_ewaste');

        return $data;
    }

    /**
     * Exactly one vendor may hold the primary e-waste flag — the quarterly sweep RFQs it
     * by `first()`, so two primaries would silently pick whichever the DB returned.
     * Every write path must run this, not just store/update.
     */
    private function enforceSinglePrimary(Vendor $vendor): void
    {
        if (! $vendor->is_primary_ewaste) {
            return;
        }

        Vendor::where('id', '!=', $vendor->id)
            ->where('is_primary_ewaste', true)
            ->update(['is_primary_ewaste' => false]);
    }
}
