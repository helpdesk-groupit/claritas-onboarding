<?php

namespace App\Http\Controllers;

use App\Models\AssetDecommissionBatch;
use App\Models\RentalAssetAcknowledgement;
use App\Models\Vendor;
use App\Models\VendorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        // master look like", not "what did I just search for". `rfq_recipients` matters most:
        // the quarterly sweep asks EVERY active e-waste vendor that has a PIC email, so with
        // none it cannot RFQ at all and only bells IT, and the cycle stalls quietly. Surfaced
        // as a banner on the page that fixes it.
        //
        // It counts through Vendor::ewasteRfqRecipients(), the same query the sweep sends
        // through, rather than a lookalike written here — the banner it replaced was left
        // asserting the sweep "cannot send an RFQ" long after the sweep had stopped depending
        // on the flag it was describing.
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
            'rfq_recipients' => Vendor::ewasteRfqRecipients()->count(),
            // An e-waste vendor with no PIC email can't be asked to quote. Naming how many
            // are in that state turns "nobody can be RFQ'd" into a fixable row on this page.
            'ewaste_no_pic' => Vendor::active()->ofType('ewaste')
                ->where(fn ($q) => $q->whereNull('pic_email')->orWhere('pic_email', ''))
                ->count(),
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
            // Who last rewrote each summary. Loaded here because summaryProvenance() prints
            // their name under every row that has been edited — without it that is a query
            // per edited document on a page whose first job is to show those summaries.
            'contracts.summaryEditor',
            // A contract filed from a disposal cycle derives its Status badge from that cycle
            // live (VendorContract::stateBadge), and "superseded" is answered by comparing it
            // against its own vendor's other revisions. Without the nested load that is three
            // queries per filed quotation on the tab that lists them.
            'contracts.assetDecommissionQuotation.batch.quotations',
            // No withCount('originAssets') any more: the billing row that printed it and
            // linked to the Assets tab was removed on 2026-08-13, and counting for nobody is
            // a subquery per page load. The relation itself stays — it is how an invoice
            // names its assets, and the Assets tab still groups by the same FK.
            'billingDocuments',
            'billingDocuments.creator',
            'billingDocuments.summaryEditor',
            'billingDocuments.contract',
            // The proof each invoice was paid. Not optional: the Status column, the Payment
            // Slip column, the Overdue badge and the row's own action button all consult it,
            // so without this it is four lazy loads per invoice on the tab built to show
            // them. `uploader` prints under the slip in its modal.
            'billingDocuments.paymentSlip',
            'billingDocuments.paymentSlip.uploader',
            'billingDocuments.paymentSlip.summaryEditor',
            'assets' => fn ($q) => $q->orderBy('asset_tag'),
            // The invoice each asset arrived on — the assets tab groups by it, so without
            // this it is a query per asset.
            'assets.originInvoice',
            // No assignee chain: the Assigned To column came off both asset tables on
            // 2026-08-13, and this eager-load existed only to keep resolvedAssigneeName()
            // from firing 3 queries per row. Who holds the asset is on the asset record.
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

        // E-waste cycles this vendor was AWARDED (Phase 6). Matched on the selected quotation
        // first, falling back to the batch's own vendor_id for cycles closed before offers
        // were compared per vendor. A vendor who quoted and lost is deliberately absent: they
        // collected nothing, and listing the cycle here would read as though they had. Their
        // losing offer still appears in that cycle's own report, as evidence of the comparison.
        $ewasteCycles = AssetDecommissionBatch::where('type', AssetDecommissionBatch::TYPE_EWASTE)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($vendor) {
                $q->whereHas('selectedQuotation', fn ($s) => $s->where('vendor_id', $vendor->id))
                    ->orWhere(fn ($b) => $b->whereNull('selected_quotation_id')->where('vendor_id', $vendor->id));
            })
            ->withCount('items')
            ->latest('id')
            ->get();

        // The per-asset AARF map (asset id => direction => the form it sits on) was built
        // here until 2026-08-13. The Assets tab was cut to six columns and the cell that read
        // it went with them, so the query fed nothing. The form-level register on the Report
        // tab is unaffected: it reads the forms themselves, not this map. If a per-asset
        // acknowledgement state is ever wanted back, note it has to be keyed by DIRECTION and
        // not flat — one asset legitimately sits on two forms (the receipt when it arrived
        // and the return when it went back), and a flat map lets the later silently overwrite
        // the earlier, reporting the wrong document.

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
            'pendingAssets' => $pendingAssets,
            'acknowledgements' => $acknowledgements,
            'ewasteCycles' => $ewasteCycles,
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
        $data['is_active'] = $request->boolean('is_active', true);

        $vendor = Vendor::create($data);

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
        $data['is_active'] = $request->boolean('is_active', true);

        $vendor->update($data);

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

        // Deactivating is enough on its own: Vendor::ewasteRfqRecipients() is scoped to
        // active vendors, so a retired e-waste vendor drops out of the quarterly RFQ without
        // any second flag needing to be cleared alongside it.
        $vendor->is_active = ! $vendor->is_active;
        $vendor->save();

        return redirect()->back()
            ->with('success', "Vendor \"{$vendor->name}\" ".($vendor->is_active ? 'activated' : 'deactivated').'.');
    }

    // ── Validation + rules ────────────────────────────────────────────────────
    private function validateVendor(Request $request, ?Vendor $vendor = null): array
    {
        $data = $request->validate([
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
            // A vendor may be registered under several taxable-service groups. Retired keys
            // are accepted alongside the offered ones because the form renders a stored one
            // ticked: refusing it here would bounce an unrelated edit with an error about a
            // field the form filled in by itself, which is the trap the invoice picker
            // already documents.
            'sst_categories' => 'nullable|array',
            'sst_categories.*' => [
                'string',
                Rule::in(array_keys(Vendor::sstCategories() + Vendor::LEGACY_SST_CATEGORIES)),
            ],
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
            'sst_categories.*.in' => 'That is not a recognised SST category.',
        ]);

        $categories = array_values(array_unique($data['sst_categories'] ?? []));

        // "Not SST-registered" is the ABSENCE of a registration, so it cannot sit beside a
        // group — a row asserting both would make sstVerdict() answer on whichever branch
        // it happened to test first, and that answer decides whether an invoice's SST line
        // gets flagged. `sales_tax` is deliberately not exclusive: a manufacturer can also
        // be registered for a taxable service.
        if (in_array('not_registered', $categories, true) && count($categories) > 1) {
            throw ValidationException::withMessages([
                'sst_categories' => '“Not SST-registered” cannot be combined with an SST category — untick one or the other.',
            ]);
        }

        // Empty means "not recorded", which is a different answer from "not registered" and
        // must stay null rather than becoming an empty list nothing distinguishes.
        $data['sst_categories'] = $categories ?: null;

        return $data;
    }
}
