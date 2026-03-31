<?php

namespace App\Http\Controllers;

use App\Models\Aarf;
use App\Models\AssetAssignment;
use App\Models\AssetInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AarfController extends Controller
{
    // ── IT: AARF listing — only pending/active onboardings ────────────────
    public function index()
    {
        $u = Auth::user();
        if (!$u->isIt() && !$u->isSuperadmin()) abort(403);

        // Only show AARFs where the onboarding is still in-progress.
        // Once onboarding status = 'offboarded' (person moved to employees table),
        // they no longer appear here — their AARF is accessible via employee profile.
        $aarfs = Aarf::with([
            'onboarding.personalDetail',
            'onboarding.workDetail',
            'onboarding.assetAssignments.asset',
            'itManager',
        ])
        ->whereHas('onboarding', fn($q) => $q->whereIn('status', ['pending', 'active']))
        ->latest()
        ->get();

        return view('it.aarfs', compact('aarfs'));
    }

    // ── IT: AARF detail view ───────────────────────────────────────────────
    public function itShow(Aarf $aarf)
    {
        $u = Auth::user();
        if (!$u->isIt() && !$u->isSuperadmin()) abort(403);

        $aarf->load([
            'onboarding.personalDetail',
            'onboarding.workDetail',
            'onboarding.assetAssignments.asset',
            'itManager',
        ]);

        return view('it.aarf-show', compact('aarf'));
    }

    // ── IT Manager: Edit AARF notes ────────────────────────────────────────
    public function itEdit(Aarf $aarf)
    {
        if (!Auth::user()->isItManager()) abort(403);
        if ($aarf->isLocked()) {
            return redirect()->route('it.aarf.show', $aarf)
                ->with('error', 'This AARF is locked and cannot be edited.');
        }

        $aarf->load([
            'onboarding.personalDetail',
            'onboarding.workDetail',
            'onboarding.assetAssignments.asset',
        ]);

        return view('it.aarf-edit', compact('aarf'));
    }

    // ── IT Manager: Save AARF notes ────────────────────────────────────────
    public function itUpdate(Request $request, Aarf $aarf)
    {
        if (!Auth::user()->isItManager()) abort(403);
        if ($aarf->isLocked()) {
            return redirect()->route('it.aarf.show', $aarf)
                ->with('error', 'This AARF is locked and cannot be edited.');
        }

        $request->validate(['it_notes' => 'nullable|string|max:2000']);
        $aarf->update(['it_notes' => $request->it_notes]);

        return redirect()->route('it.aarf.show', $aarf)
            ->with('success', 'AARF updated successfully.');
    }

    // ── IT Manager: Acknowledge — MUST happen before employee can acknowledge
    public function itAcknowledge(Request $request, Aarf $aarf)
    {
        if (!Auth::user()->isItManager()) abort(403);

        if ($aarf->it_manager_acknowledged) {
            return redirect()->route('it.aarf.show', $aarf)
                ->with('info', 'You have already acknowledged this AARF.');
        }

        $request->validate(['it_manager_remarks' => 'nullable|string|max:1000']);

        $actor = Auth::user();
        $actorName = $actor->name ?? $actor->work_email ?? 'IT Manager';
        $empName = $aarf->onboarding?->personalDetail?->full_name ?? 'New Hire';

        $aarf->update([
            'it_manager_acknowledged'    => true,
            'it_manager_acknowledged_at' => now(),
            'it_manager_user_id'         => Auth::id(),
            'it_manager_remarks'         => $request->it_manager_remarks,
        ]);

        // Log to asset_changes
        $aarf->appendAssetChange("AARF acknowledged by IT Manager ({$actorName}) for {$empName}. Assets confirmed provisioned and ready for handover.");

        // Also log to each assigned asset's remarks
        foreach ($aarf->onboarding?->assetAssignments ?? [] as $assignment) {
            $asset = $assignment->asset;
            if ($asset) {
                $asset->appendRemark("AARF [{$aarf->aarf_reference}] acknowledged by IT Manager ({$actorName}). Asset confirmed provisioned for {$empName}.");
            }
        }

        return redirect()->route('it.aarf.show', $aarf)
            ->with('success', 'AARF acknowledged successfully as IT Manager. The employee can now acknowledge their copy.');
    }

    // ── IT Manager: Edit asset assignments page ────────────────────────────
    public function itEditAssets(Aarf $aarf)
    {
        if (!Auth::user()->isItManager()) abort(403);
        if ($aarf->isLocked()) {
            return redirect()->route('it.aarf.show', $aarf)
                ->with('error', 'This AARF is locked and cannot be edited.');
        }
        $aarf->load(['onboarding.personalDetail', 'onboarding.workDetail', 'onboarding.assetAssignments.asset']);

        // Currently assigned asset IDs for this onboarding
        $assignedIds = $aarf->onboarding?->assetAssignments
            ->where('status', 'assigned')
            ->pluck('asset_inventory_id')
            ->toArray() ?? [];

        // Available assets = available status + any currently assigned to THIS onboarding
        $availableAssets = AssetInventory::where('status', 'available')
            ->orderBy('asset_type')->orderBy('brand')
            ->get();

        return view('it.aarf-assets', compact('aarf', 'availableAssets', 'assignedIds'));
    }

    // ── IT Manager: Add one or more assets to the AARF ───────────────────
    public function itAddAsset(Request $request, Aarf $aarf)
    {
        if (!Auth::user()->isItManager()) abort(403);
        if ($aarf->isLocked()) {
            return back()->with('error', 'This AARF is locked.');
        }

        $request->validate([
            'asset_ids'     => 'required|array|min:1',
            'asset_ids.*'   => 'exists:asset_inventories,id',
            'assigned_date' => 'required|date',
        ]);

        $actor     = Auth::user();
        $actorName = $actor->name ?? $actor->work_email ?? 'IT Manager';
        $empName   = $aarf->onboarding?->personalDetail?->full_name ?? 'New Hire';
        $added     = [];
        $skipped   = [];

        foreach ($request->asset_ids as $assetId) {
            $asset = AssetInventory::find($assetId);
            if (!$asset || $asset->status !== 'available') {
                $skipped[] = $asset?->asset_tag ?? "#$assetId";
                continue;
            }

            AssetAssignment::create([
                'onboarding_id'      => $aarf->onboarding_id,
                'asset_inventory_id' => $asset->id,
                'assigned_date'      => $request->assigned_date,
                'status'             => 'assigned',
            ]);

            $asset->update([
                'status'              => 'assigned',
                'asset_assigned_date' => $request->assigned_date,
            ]);

            $asset->appendRemark("Assigned to {$empName} via AARF [{$aarf->aarf_reference}] by {$actorName}.");
            $aarf->appendAssetChange("[{$asset->asset_tag}] {$asset->asset_name} — Added to AARF for {$empName} by {$actorName}.");

            $added[] = "[{$asset->asset_tag}] {$asset->asset_name}";
        }

        $msg = '';
        if ($added)   $msg .= count($added) . ' asset(s) added: ' . implode(', ', $added) . '. ';
        if ($skipped) $msg .= count($skipped) . ' skipped (no longer available): ' . implode(', ', $skipped) . '.';

        $flashType = $skipped && !$added ? 'error' : 'success';
        return back()->with($flashType, trim($msg) ?: 'Done.');
    }

    // ── IT Manager: Replace one assigned asset with another ───────────────
    public function itReplaceAsset(Request $request, Aarf $aarf, AssetAssignment $assignment)
    {
        if (!Auth::user()->isItManager()) abort(403);
        if ($aarf->isLocked()) {
            return back()->with('error', 'This AARF is locked.');
        }

        $request->validate([
            'new_asset_id' => 'required|exists:asset_inventories,id',
        ]);

        $oldAsset  = AssetInventory::find($assignment->asset_inventory_id);
        $newAsset  = AssetInventory::findOrFail($request->new_asset_id);
        $actor     = Auth::user();
        $actorName = $actor->name ?? $actor->work_email ?? 'IT Manager';
        $empName   = $aarf->onboarding?->personalDetail?->full_name ?? 'New Hire';

        if ($newAsset->status !== 'available') {
            return back()->with('error', "Asset [{$newAsset->asset_tag}] is not available.");
        }

        // Return the old asset
        $assignment->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);
        if ($oldAsset) {
            $oldAsset->update([
                'status'               => 'available',
                'assigned_employee_id' => null,
                'asset_assigned_date'  => null,
            ]);
            $oldAsset->appendRemark("Replaced in AARF [{$aarf->aarf_reference}] by {$actorName}. Swapped out for [{$newAsset->asset_tag}].");
        }

        // Assign the new asset
        AssetAssignment::create([
            'onboarding_id'      => $aarf->onboarding_id,
            'asset_inventory_id' => $newAsset->id,
            'assigned_date'      => $assignment->assigned_date ?? now()->toDateString(),
            'status'             => 'assigned',
        ]);
        $newAsset->update([
            'status'              => 'assigned',
            'asset_assigned_date' => $assignment->assigned_date ?? now()->toDateString(),
        ]);
        $newAsset->appendRemark("Replaced [{$oldAsset?->asset_tag}] in AARF [{$aarf->aarf_reference}] assignment for {$empName} by {$actorName}.");

        // Log to AARF
        $oldTag = $oldAsset ? "[{$oldAsset->asset_tag}] {$oldAsset->asset_name}" : 'previous asset';
        $aarf->appendAssetChange("{$oldTag} — Replaced with [{$newAsset->asset_tag}] {$newAsset->asset_name} for {$empName} by {$actorName}.");

        return back()->with('success', "Asset replaced: [{$oldAsset?->asset_tag}] → [{$newAsset->asset_tag}].");
    }

    // ── IT Manager: Remove a single asset from AARF ───────────────────────
    public function itRemoveAsset(Aarf $aarf, AssetAssignment $assignment)
    {
        if (!Auth::user()->isItManager()) abort(403);
        if ($aarf->isLocked()) {
            return back()->with('error', 'This AARF is locked.');
        }

        $asset     = AssetInventory::find($assignment->asset_inventory_id);
        $actor     = Auth::user();
        $actorName = $actor->name ?? $actor->work_email ?? 'IT Manager';
        $empName   = $aarf->onboarding?->personalDetail?->full_name ?? 'New Hire';

        // Close the assignment
        $assignment->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

        // Free the asset
        if ($asset) {
            $asset->update([
                'status'               => 'available',
                'assigned_employee_id' => null,
                'asset_assigned_date'  => null,
            ]);
            $asset->appendRemark("Removed from AARF [{$aarf->aarf_reference}] assignment for {$empName} by {$actorName}.");
        }

        // Log to AARF
        $tag = $asset ? "[{$asset->asset_tag}] {$asset->asset_name}" : 'Asset';
        $aarf->appendAssetChange("{$tag} — Removed from AARF assignment for {$empName} by {$actorName}.");

        return back()->with('success', "Asset [{$asset?->asset_tag}] removed from AARF.");
    }

    // ── KEPT for backward compat — now unused, redirects to show ──────────
    public function itUpdateAssets(Request $request, Aarf $aarf)
    {
        return redirect()->route('it.aarf.assets', $aarf);
    }

    // ── Public: Employee view via token link ───────────────────────────────
    // This is the link emailed to the new hire. They can only acknowledge
    // AFTER the IT Manager has already acknowledged.
    public function viewAarf(string $token)
    {
        $aarf = Aarf::where('acknowledgement_token', $token)
            ->with([
                'onboarding.personalDetail',
                'onboarding.workDetail',
                'onboarding.assetAssignments.asset',
                'employee',
            ])
            ->firstOrFail();

        // Determine viewer role for display purposes only.
        // HR, superadmin, system_admin → read-only
        // ?readonly=1 (e.g. from profile page) → read-only for everyone
        // Everyone else (employee, guest, IT) → can acknowledge
        $viewerRole = 'employee';
        if (request()->boolean('readonly')) {
            $viewerRole = 'hr';
        } elseif (auth()->check()) {
            $authUser = auth()->user();
            if ($authUser->isHr() || $authUser->isIt() || $authUser->isSuperadmin() || $authUser->isSystemAdmin()) {
                $viewerRole = 'hr';
            }
        }

        return view('aarf.view', compact('aarf', 'viewerRole'));
    }

    // ── Public: Employee acknowledge via token ─────────────────────────────
    public function acknowledge(string $token)
    {
        $aarf = Aarf::where('acknowledgement_token', $token)->firstOrFail();

        if ($aarf->acknowledged) {
            return redirect()->route('aarf.view', $token)
                ->with('info', 'This form has already been acknowledged.');
        }

        $empName = $aarf->onboarding?->personalDetail?->full_name
            ?? $aarf->employee?->full_name
            ?? 'Employee';

        $aarf->update([
            'acknowledged'    => true,
            'acknowledged_at' => now(),
        ]);

        // Log to asset_changes — include the name clearly
        $aarf->appendAssetChange("AARF acknowledged by {$empName}. All assets confirmed received.");

        // Log to each asset's remarks — cover both onboarding and direct employee assignments
        $assignments = collect();
        if ($aarf->onboarding) {
            $assignments = $aarf->onboarding->assetAssignments ?? collect();
        }
        // Also include assets assigned directly via employee_id
        if ($aarf->employee_id || ($aarf->onboarding?->employee?->id)) {
            $empId = $aarf->employee_id ?? $aarf->onboarding->employee?->id;
            if ($empId) {
                $directAssets = \App\Models\AssetInventory::where('assigned_employee_id', $empId)
                    ->whereIn('status', ['assigned', 'unavailable'])->get();
                foreach ($directAssets as $asset) {
                    $asset->appendRemark("Asset receipt confirmed by {$empName} via AARF [{$aarf->aarf_reference}] acknowledgement.");
                }
            }
        }
        foreach ($assignments as $assignment) {
            $asset = $assignment->asset;
            if ($asset) {
                $asset->appendRemark("Asset receipt confirmed by {$empName} via AARF [{$aarf->aarf_reference}] acknowledgement.");
            }
        }

        return redirect()->route('aarf.view', $token)
            ->with('success', 'Thank you! Your Asset Acceptance form has been acknowledged successfully.');
    }

    // ── HR internal view ───────────────────────────────────────────────────
    public function hrView(Aarf $aarf)
    {
        $aarf->load(['onboarding.personalDetail', 'onboarding.workDetail', 'onboarding.assetAssignments.asset']);
        return view('hr.aarf', compact('aarf'));
    }
}