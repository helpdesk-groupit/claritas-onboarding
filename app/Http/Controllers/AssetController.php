<?php

namespace App\Http\Controllers;

use App\Models\AssetInventory;
use App\Models\AssetAssignment;
use App\Models\Aarf;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\Onboarding;
use App\Models\DisposedAsset;
use Illuminate\Support\Facades\Mail;
use App\Mail\AarfAcknowledgementMail;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeItAccess();

        // Exclude 'not_good' assets — they are shown in the Damaged Assets page
        $query = AssetInventory::with('assignedEmployee.onboarding.personalDetail')
            ->where('asset_condition', '!=', 'not_good');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('asset_tag', 'like', "%{$s}%")
                                    ->orWhere('brand', 'like', "%{$s}%")
                  ->orWhere('model', 'like', "%{$s}%")
                  ->orWhere('serial_number', 'like', "%{$s}%");
            });
        }
        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('type'))      $query->where('asset_type', $request->type);
        if ($request->filled('ownership')) $query->where('ownership_type', $request->ownership);
        if ($request->filled('vendor'))    $query->where('rental_vendor', $request->vendor);

        $assets = $query->latest()->paginate(15)->withQueryString();

        $stats = [
            'total_assets'    => AssetInventory::where('asset_condition', '!=', 'not_good')->count(),
            'available'       => AssetInventory::where('status', 'available')->where('asset_condition', '!=', 'not_good')->count(),
            'assigned'        => AssetInventory::where('status', 'assigned')->count(),
            'unavailable'     => AssetInventory::where('status', 'unavailable')->where('asset_condition', '!=', 'not_good')->count(),
        ];

        $employees = Employee::with('onboarding.personalDetail')->whereNull('active_until')->get();

        // Decommissioning tab with its own filters
        $disposedQuery = DisposedAsset::with('asset')->latest('disposed_at');
        if ($request->filled('d_search')) {
            $ds = $request->d_search;
            $disposedQuery->where(fn($q) =>
                $q->where('asset_tag', 'like', "%{$ds}%")
                  ->orWhere('brand',   'like', "%{$ds}%")
                  ->orWhere('model',   'like', "%{$ds}%")
            );
        }
        if ($request->filled('d_type'))      $disposedQuery->where('asset_type', $request->d_type);
        if ($request->filled('d_ownership')) $disposedQuery->whereHas('asset', fn($q) => $q->where('ownership_type', $request->d_ownership));
        if ($request->filled('d_vendor'))    $disposedQuery->whereHas('asset', fn($q) => $q->where('rental_vendor',   $request->d_vendor));

        $disposed = $disposedQuery->paginate(15, ['*'], 'disposed_page')->withQueryString();

        // Distinct rental vendors for filter dropdowns
        $rentalVendors = AssetInventory::where('ownership_type', 'rental')
            ->whereNotNull('rental_vendor')
            ->distinct()->orderBy('rental_vendor')
            ->pluck('rental_vendor');

        // Registered companies for Add Asset company_name dropdown
        $registeredCompanies = \App\Models\Company::orderBy('name')->get(['name']);

        return view('it.assets.page', compact('assets', 'stats', 'employees', 'disposed', 'rentalVendors',
            'registeredCompanies'
        ));
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
        $data      = $this->buildAssetData($request, $validated);

        if ($request->hasFile('invoice_document')) {
            $data['invoice_document'] = $request->file('invoice_document')->store('invoices', 'public');
        }

        $asset = AssetInventory::create($data);

        // ── Dispose if condition = not_good ────────────────────────────────
        if (($data['asset_condition'] ?? null) === 'not_good') {
            $actor     = Auth::user();
            $actorName = $actor->name ?? $actor->work_email ?? 'IT Team';
            $decommissionReason = $request->input('decommission_reason');
            DisposedAsset::firstOrCreate(
                ['asset_inventory_id' => $asset->id],
                [
                    'asset_tag'       => $asset->asset_tag,
                    'asset_type'      => $asset->asset_type,
                    'brand'           => $asset->brand,
                    'model'           => $asset->model,
                    'serial_number'   => $asset->serial_number,
                    'asset_condition' => 'not_good',
                    'disposed_by'     => $actorName,
                    'disposed_at'     => now(),
                    'remarks'         => $asset->remarks,
                ]
            );
            if ($decommissionReason) {
                DisposedAsset::where('asset_inventory_id', $asset->id)
                    ->update(['reason' => $decommissionReason]);
            }
            $reasonNote = $decommissionReason ? " Reason: {$decommissionReason}." : '';
            $asset->appendRemark("Asset flagged as Not Good — moved to Decommissioning Assets by {$actorName}.{$reasonNote}");
        }

        // Save uploaded photos into asset_photos/{asset_tag}/ folder
        if ($request->hasFile('asset_photos')) {
            $folder = 'asset_photos/' . \Illuminate\Support\Str::slug($asset->asset_tag);
            $paths  = [];
            foreach ($request->file('asset_photos') as $photo) {
                $paths[] = $photo->store($folder, 'public');
            }
            $asset->update(['asset_photos' => $paths]);
        }

        if ($asset->assigned_employee_id) {
            $employee  = Employee::find($asset->assigned_employee_id);
            $actor     = Auth::user();
            $actorName = $actor->name ?? $actor->work_email ?? 'IT Team';
            $empName   = $employee?->full_name ?? "Employee #{$asset->assigned_employee_id}";

            $this->createAssignmentForEmployee($employee, $asset->id, $asset->asset_assigned_date ?? now()->toDateString());

            $aarf = $this->ensureAarfForEmployee($employee);

            // Specific remarks on asset and AARF
            $asset->appendRemark(
                "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                "assigned to {$empName} by {$actorName} during asset creation."
            );
            $aarf?->appendAssetChange(
                "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                "assigned to {$empName} by {$actorName} during asset creation."
            );

            // Reset acknowledgement and send email
            if ($aarf) {
                $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                $this->sendAarfEmail($aarf, $employee, $empName, 'assigned');
            }
        }

        $tab = ($data['asset_condition'] ?? null) === 'not_good' ? 'damaged' : null;
        return redirect()->route('assets.index', array_filter(['tab' => $tab]))
            ->with('success', 'Asset added successfully.');
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
        $registeredCompanies = \App\Models\Company::orderBy('name')->get(['name']);
        return view('it.assets.edit', compact('asset', 'employees', 'registeredCompanies'));
    }

    public function update(Request $request, AssetInventory $asset)
    {
        $this->authorizeCanEdit();
        $user      = Auth::user();
        $actorName = $user->name ?? $user->work_email ?? 'IT Team';

        $validated = $this->validateAsset($request, isUpdate: true, user: $user);
        $data      = $this->buildAssetData($request, $validated, $user);

        if ($request->hasFile('invoice_document')) {
            $data['invoice_document'] = $request->file('invoice_document')->store('invoices', 'public');
        }

        // Handle multi-photo upload — append to existing photos
        if ($request->hasFile('asset_photos')) {
            $folder    = 'asset_photos/' . \Illuminate\Support\Str::slug($asset->asset_tag);
            $existing  = $asset->asset_photos ?? [];
            foreach ($request->file('asset_photos') as $photo) {
                $existing[] = $photo->store($folder, 'public');
            }
            $data['asset_photos'] = $existing;
        }

        // Capture BEFORE saving
        $oldEmployeeId   = $asset->assigned_employee_id ? (int)$asset->assigned_employee_id : null;
        $newEmployeeId   = isset($data['assigned_employee_id']) && $data['assigned_employee_id'] !== '' && $data['assigned_employee_id'] !== null
                           ? (int)$data['assigned_employee_id'] : null;
        $oldAssignedDate = $asset->asset_assigned_date?->toDateString();

        // Capture old Section A/B values before saving (for change detection)
        $sectionABKeys = ['asset_tag','asset_type','brand','model','serial_number',
                          'processor','ram_size','storage','operating_system','screen_size','spec_others'];
        $oldSectionAB = [];
        foreach ($sectionABKeys as $k) {
            $oldSectionAB[$k] = (string)($asset->$k ?? '');
        }

        unset($data['notes']); // never overwrite remarks log

        $asset->update($data);

        // ── Dispose if condition = not_good ────────────────────────────────
        if (($data['asset_condition'] ?? null) === 'not_good') {
            $decommissionReason = $request->input('decommission_reason');
            DisposedAsset::firstOrCreate(
                ['asset_inventory_id' => $asset->id],
                [
                    'asset_tag'       => $asset->asset_tag,
                    'asset_type'      => $asset->asset_type,
                    'brand'           => $asset->brand,
                    'model'           => $asset->model,
                    'serial_number'   => $asset->serial_number,
                    'asset_condition' => 'not_good',
                    'disposed_by'     => $actorName,
                    'disposed_at'     => now(),
                    'remarks'         => $asset->remarks,
                ]
            );
            if ($decommissionReason) {
                DisposedAsset::where('asset_inventory_id', $asset->id)
                    ->update(['reason' => $decommissionReason]);
            }
            $reasonNote = $decommissionReason ? " Reason: {$decommissionReason}." : '';
            $asset->appendRemark("Asset flagged as Not Good — moved to Decommissioning Assets by {$actorName}.{$reasonNote}");
        } elseif (in_array($data['asset_condition'] ?? null, ['good', 'under_maintenance'])) {
            if (DisposedAsset::where('asset_inventory_id', $asset->id)->exists()) {
                DisposedAsset::where('asset_inventory_id', $asset->id)->delete();
                $asset->appendRemark("Asset condition restored to " . ucfirst($data['asset_condition']) . " — removed from Decommissioning Assets by {$actorName}.");
            }
        }

        // ── Assignment change handling ─────────────────────────────────────

        if (!$oldEmployeeId && $newEmployeeId) {
            // Newly assigned
            $emp  = Employee::find($newEmployeeId);
            $name = $emp?->full_name ?? "Employee #{$newEmployeeId}";

            AssetAssignment::where('asset_inventory_id', $asset->id)
                ->where('status', 'assigned')
                ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

            if ($emp) {
                $this->createAssignmentForEmployee($emp, $asset->id, $data['asset_assigned_date'] ?? now()->toDateString());
                $aarf = $this->ensureAarfForEmployee($emp);
                $aarf?->appendAssetChange(
                    "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                    "assigned to {$name} by {$actorName}."
                );
                if ($aarf) {
                    $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                    $this->sendAarfEmail($aarf, $emp, $name, 'assigned');
                }
            }
            $asset->appendRemark(
                "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                "assigned to {$name} by {$actorName}."
            );

        } elseif ($oldEmployeeId && !$newEmployeeId) {
            // Unassigned
            $emp  = Employee::find($oldEmployeeId);
            $name = $emp?->full_name ?? "Employee #{$oldEmployeeId}";

            AssetAssignment::where('asset_inventory_id', $asset->id)
                ->where('status', 'assigned')
                ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

            if ($emp) {
                $aarf = $emp->resolveAarf();
                $aarf?->appendAssetChange(
                    "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                    "unassigned from {$name} by {$actorName}."
                );
                if ($aarf) {
                    $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                    $this->sendAarfEmail($aarf, $emp, $name, 'returned');
                }
            }
            $asset->appendRemark(
                "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                "unassigned from {$name} by {$actorName}."
            );

        } elseif ($oldEmployeeId && $newEmployeeId && $oldEmployeeId !== $newEmployeeId) {
            // Reassigned to different employee
            $oldEmp  = Employee::find($oldEmployeeId);
            $newEmp  = Employee::find($newEmployeeId);
            $oldName = $oldEmp?->full_name ?? "Employee #{$oldEmployeeId}";
            $newName = $newEmp?->full_name ?? "Employee #{$newEmployeeId}";

            AssetAssignment::where('asset_inventory_id', $asset->id)
                ->where('status', 'assigned')
                ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

            if ($newEmp) {
                $this->createAssignmentForEmployee($newEmp, $asset->id, $data['asset_assigned_date'] ?? now()->toDateString());
            }

            if ($oldEmp) {
                $oldAarf = $oldEmp->resolveAarf();
                $oldAarf?->appendAssetChange(
                    "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                    "returned — reassigned from {$oldName} to {$newName} by {$actorName}."
                );
                if ($oldAarf) {
                    $oldAarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                    $this->sendAarfEmail($oldAarf, $oldEmp, $oldName, 'returned');
                }
            }
            if ($newEmp) {
                $newAarf = $this->ensureAarfForEmployee($newEmp);
                $newAarf?->appendAssetChange(
                    "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                    "assigned — reassigned to {$newName} from {$oldName} by {$actorName}."
                );
                if ($newAarf) {
                    $newAarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                    $this->sendAarfEmail($newAarf, $newEmp, $newName, 'assigned');
                }
            }
            $asset->appendRemark(
                "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                "reassigned from {$oldName} to {$newName} by {$actorName}."
            );

        } elseif ($oldEmployeeId && $newEmployeeId && $oldEmployeeId === $newEmployeeId) {
            // Same employee — check if Section A or B fields changed
            $emp  = Employee::find($newEmployeeId);
            $name = $emp?->full_name ?? "Employee #{$newEmployeeId}";

            $sectionABFields = ['asset_tag','asset_type','brand','model','serial_number',
                                'processor','ram_size','storage','operating_system','screen_size','spec_others'];

            $changedFields = [];
            foreach ($sectionABFields as $field) {
                $oldVal = $oldSectionAB[$field] ?? '';
                $newVal = (string)($data[$field] ?? '');
                if ($oldVal !== $newVal) {
                    $changedFields[] = ucfirst(str_replace('_', ' ', $field));
                }
            }

            if (!empty($changedFields)) {
                $changeList = implode(', ', $changedFields);
                $remarkText = "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                              "details updated ({$changeList}) by {$actorName}.";
                $asset->appendRemark($remarkText);

                if ($emp) {
                    $aarf = $emp->resolveAarf();
                    $aarf?->appendAssetChange($remarkText);
                    if ($aarf) {
                        $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                        $this->sendAarfEmail($aarf, $emp, $name, 'assigned');
                    }
                }
            } elseif (($data['asset_assigned_date'] ?? null) && ($data['asset_assigned_date'] ?? null) !== $oldAssignedDate) {
                // Only date changed — log remark only, no email needed
                $asset->appendRemark(
                    "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                    "assignment date updated for {$name} by {$actorName}."
                );
            }
        }

        // ── Auto-assigned (via onboarding) — detect Section A/B changes ──────
        if (!$oldEmployeeId && !$newEmployeeId) {
            $autoAssignment = AssetAssignment::with('onboarding.personalDetail')
                ->where('asset_inventory_id', $asset->id)
                ->where('status', 'assigned')
                ->whereNotNull('onboarding_id')
                ->first();

            if ($autoAssignment) {
                $changedAutoFields = [];
                foreach ($sectionABKeys as $field) {
                    if (($oldSectionAB[$field] ?? '') !== (string)($data[$field] ?? '')) {
                        $changedAutoFields[] = ucfirst(str_replace('_', ' ', $field));
                    }
                }

                if (!empty($changedAutoFields)) {
                    $changeList   = implode(', ', $changedAutoFields);
                    $assigneeName = $autoAssignment->onboarding?->personalDetail?->full_name ?? 'New Hire';
                    $remarkText   = "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                                    "details updated ({$changeList}) by {$actorName}.";
                    $asset->appendRemark($remarkText);

                    // Find employee if already activated
                    $emp = Employee::where('onboarding_id', $autoAssignment->onboarding_id)->first();
                    if ($emp) {
                        $aarf = $emp->resolveAarf();
                        $aarf?->appendAssetChange($remarkText);
                        if ($aarf) {
                            $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                            $this->sendAarfEmail($aarf, $emp, $emp->full_name ?? $assigneeName, 'assigned');
                        }
                    } else {
                        // Employee not activated yet — log to onboarding AARF only
                        $aarf = \App\Models\Aarf::where('onboarding_id', $autoAssignment->onboarding_id)->first();
                        $aarf?->appendAssetChange($remarkText);
                    }
                }
            }
        }

        // Manual note
        $userNote = trim($request->input('remarks', ''));
        if ($userNote) {
            $asset->appendRemark("Note: {$userNote}");
        }

        if ($asset->asset_condition === 'not_good') {
            return redirect()->route('assets.disposed.show', $asset)->with('success', 'Asset updated successfully.');
        }

        return redirect()->route('assets.show', $asset)->with('success', 'Asset updated successfully.');
    }

    // ── Damaged Assets page (view-only) ────────────────────────────────────
    public function disposed(Request $request)
    {
        $this->authorizeItAccess();
        $disposed = DisposedAsset::latest('disposed_at')->paginate(20)->withQueryString();
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

        $actor       = Auth::user();
        $actorName   = $actor->name ?? $actor->work_email ?? 'IT Team';

        // Resolve employee — direct OR via onboarding (auto-assigned)
        $oldEmployee = $asset->assigned_employee_id
            ? Employee::find($asset->assigned_employee_id)
            : null;

        if (!$oldEmployee) {
            $assignment = AssetAssignment::where('asset_inventory_id', $asset->id)
                ->where('status', 'assigned')
                ->whereNotNull('onboarding_id')
                ->first();
            if ($assignment?->onboarding_id) {
                $oldEmployee = Employee::where('onboarding_id', $assignment->onboarding_id)->first();
            }
        }

        $oldName    = $oldEmployee?->full_name ?? 'previous assignee';
        $assetLabel = trim("{$asset->brand} {$asset->model}") ?: $asset->asset_tag;
        $today      = now()->format('d M Y');

        AssetAssignment::where('asset_inventory_id', $asset->id)
            ->where('status', 'assigned')
            ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

        $asset->update([
            'status'               => 'available',
            'assigned_employee_id' => null,
            'asset_assigned_date'  => null,
            'expected_return_date' => null,
        ]);

        $remarkText = "{$assetLabel} returned by {$oldName} on {$today}, processed by {$actorName}.";
        $asset->appendRemark($remarkText);

        if ($oldEmployee) {
            $aarf = $oldEmployee->resolveAarf();
            $aarf?->appendAssetChange($remarkText);

            if ($aarf) {
                $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                $this->sendAarfEmail($aarf, $oldEmployee, $oldName, 'returned');
            }
        }

        return redirect()->route('assets.index')
            ->with('success', "Asset [{$asset->asset_tag}] released from {$oldName}.");
    }

    // ── Download CSV export ─────────────────────────────────────────────────
    public function export(Request $request)
    {
        $this->authorizeItAccess();
        $query = AssetInventory::with('assignedEmployee.onboarding.personalDetail');
        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('type'))      $query->where('asset_type', $request->type);
        if ($request->filled('ownership')) $query->where('ownership_type', $request->ownership);
        if ($request->filled('vendor'))    $query->where('rental_vendor', $request->vendor);

        $assets  = $query->latest()->get();
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="assets_export_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($assets) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Asset Tag', 'Type', 'Brand', 'Model', 'Serial Number',
                'Status', 'Condition', 'Processor', 'RAM', 'Storage', 'OS',
                'Ownership Type', 'Company Name', 'Purchase Date', 'Vendor', 'Cost (RM)', 'Warranty Expiry',
                'Rental Vendor', 'Rental Vendor Contact', 'Rental Cost/Month', 'Rental Start', 'Rental End', 'Contract Ref',
                'Assigned To', 'Assigned Date', 'Expected Return',
                'Maintenance Status', 'Last Maintenance', 'Remarks',
            ]);
            foreach ($assets as $a) {
                fputcsv($file, [
                    $a->asset_tag, $a->asset_type, $a->brand, $a->model, $a->serial_number,
                    $a->status, $a->asset_condition, $a->processor, $a->ram_size, $a->storage, $a->operating_system,
                    $a->ownership_type, $a->company_name,
                    $a->purchase_date, $a->purchase_vendor, $a->purchase_cost, $a->warranty_expiry_date,
                    $a->rental_vendor, $a->rental_vendor_contact, $a->rental_cost_per_month,
                    $a->rental_start_date, $a->rental_end_date, $a->rental_contract_reference,
                    $a->resolvedAssigneeName(), $a->asset_assigned_date, $a->expected_return_date,
                    $a->maintenance_status, $a->last_maintenance_date, $a->remarks,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function reassign(Request $request, AssetInventory $asset)
    {
        $this->authorizeCanEdit();
        $request->validate(['new_employee_id' => 'required|exists:employees,id']);

        $actor       = Auth::user();
        $actorName   = $actor->name ?? $actor->work_email ?? 'IT Team';
        $newEmployee = Employee::findOrFail($request->new_employee_id);
        $newName     = $newEmployee->full_name ?? "Employee #{$newEmployee->id}";
        $oldEmployee = $asset->assigned_employee_id ? Employee::find($asset->assigned_employee_id) : null;
        $oldName     = $oldEmployee?->full_name ?? 'previous assignee';

        AssetAssignment::where('asset_inventory_id', $asset->id)
            ->where('status', 'assigned')
            ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

        $this->createAssignmentForEmployee($newEmployee, $asset->id, now()->toDateString());

        $asset->update([
            'assigned_employee_id' => $newEmployee->id,
            'asset_assigned_date'  => now()->toDateString(),
            'status'               => 'assigned',
        ]);

        $asset->appendRemark(
            "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
            "reassigned from {$oldName} to {$newName} by {$actorName}."
        );

        if ($oldEmployee) {
            $oldAarf = $oldEmployee->resolveAarf();
            $oldAarf?->appendAssetChange(
                "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                "returned — reassigned from {$oldName} to {$newName} by {$actorName}."
            );
            if ($oldAarf) {
                $oldAarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                $this->sendAarfEmail($oldAarf, $oldEmployee, $oldName, 'returned');
            }
        }
        $newAarf = $this->ensureAarfForEmployee($newEmployee);
        $newAarf?->appendAssetChange(
            "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
            "assigned — reassigned to {$newName} from {$oldName} by {$actorName}."
        );
        if ($newAarf) {
            $newAarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
            $this->sendAarfEmail($newAarf, $newEmployee, $newName, 'assigned');
        }

        return redirect()->route('assets.show', $asset)
            ->with('success', "Asset successfully reassigned from {$oldName} to {$newName}.");
    }

    public function returnAsset(AssetInventory $asset)
    {
        $this->authorizeCanEdit();

        $actor       = Auth::user();
        $actorName   = $actor->name ?? $actor->work_email ?? 'IT Team';
        $oldEmployee = $asset->assigned_employee_id ? Employee::find($asset->assigned_employee_id) : null;
        $oldName     = $oldEmployee?->full_name ?? 'previous assignee';

        AssetAssignment::where('asset_inventory_id', $asset->id)
            ->where('status', 'assigned')
            ->update(['status' => 'returned', 'returned_date' => now()->toDateString()]);

        $asset->update([
            'status'               => 'available',
            'assigned_employee_id' => null,
            'asset_assigned_date'  => null,
            'expected_return_date' => null,
        ]);

        $asset->appendRemark(
            "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
            "returned by {$oldName} to IT department. Processed by {$actorName}."
        );

        if ($oldEmployee) {
            $aarf = $oldEmployee->resolveAarf();
            $aarf?->appendAssetChange(
                "Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) " .
                "returned by {$oldName} to IT department. Processed by {$actorName}."
            );
            if ($aarf) {
                $aarf->update(['acknowledged' => false, 'acknowledged_at' => null]);
                $this->sendAarfEmail($aarf, $oldEmployee, $oldName, 'returned');
            }
        }

        return redirect()->route('assets.show', $asset)
            ->with('success', "Asset marked as returned and is now available.");
    }

    // ── Download CSV import template ───────────────────────────────────────
    // Download CSV import template
    public function importTemplate()
    {
        $this->authorizeCanAdd();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="asset_import_template.csv"',
        ];

        $callback = function () {
            $handle = fopen('php://output', 'w');

            // Column headers — mirrors the Add Asset form fields exactly
            // Columns marked * are REQUIRED. All others are optional.
            fputcsv($handle, [
                'asset_type',           // * REQUIRED: laptop / monitor / converter / phone / sim_card / access_card / other
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
                'purchase_vendor',      // optional
                'purchase_cost',        // optional: numeric e.g. 4500.00
                'purchase_date',        // optional: DD-MM-YYYY
                'warranty_expiry_date', // optional: DD-MM-YYYY
                'rental_vendor',        // optional: required if ownership_type = rental
                'rental_vendor_contact',// optional
                'rental_cost_per_month',// optional
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
                'Laptop',                                           // asset_type      *
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
                'Laptop',
                'CLR-LPT-001',
                'HP EliteBook 840 G9 i5-1235U 8GB 256GB SSD Win 11 Pro',
                'New stock received 01-01-2025',
                'HP', 'EliteBook 840 G9', 'SN-HP-001',
                '', '', '', '', '', '',
                'company', 'Claritas Asia Sdn. Bhd.',
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

        $handle    = fopen($request->file('csv_file')->getRealPath(), 'r');
        $headers   = null;
        $imported  = 0;
        $skipped   = 0;
        $errors    = [];
        $rowNumber = 1;

        // Sanitise: strip non-breaking spaces (\xA0) and control chars
        $sanitise = function (?string $val): ?string {
            if ($val === null || $val === '') return null;
            $val = str_replace("\xc2\xa0", ' ', $val);
            $val = str_replace("\xa0", ' ', $val);
            $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $val);
            $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
            $val = preg_replace('/ {2,}/', ' ', $val);
            return trim($val) ?: null;
        };

        // Date parser: DD-MM-YYYY, DD/MM/YYYY, YYYY-MM-DD
        $parseDate = function ($val) {
            if (empty(trim($val ?? ''))) return null;
            $val = trim($val);
            if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $val)) return $val;
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $val, $m))
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
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
                if (str_contains($lower, $key)) return $type;
            }
            return 'other';
        };

        // Specs auto-parser
        $parseSpecs = function (string $raw): array {
            $r = ['brand' => null, 'model' => null, 'processor' => null,
                  'ram_size' => null, 'storage' => null, 'operating_system' => null, 'screen_size' => null];
            if (empty(trim($raw))) return $r;
            $s = trim($raw);
            $brands = ['Dell','HP','Lenovo','Apple','Asus','Acer','Microsoft','Samsung','LG','MSI','Toshiba','Incite'];

            // MODEL: line
            if (preg_match('/(?:MODEL|LAPTOP)[\:\s]+([^\n\r]+)/i', $s, $m)) {
                $ml = trim($m[1]);
                foreach ($brands as $b) { if (stripos($ml, $b) !== false) { $r['brand'] = $b; break; } }
                if (!$r['brand'] && strlen(explode(' ', $ml)[0] ?? '') >= 2) $r['brand'] = explode(' ', $ml)[0];
                $r['model'] = $ml;
            } else {
                foreach ($brands as $b) { if (stripos(explode(' ', $s)[0] ?? '', $b) !== false) { $r['brand'] = $b; break; } }
                if (preg_match('/^([\w\s\-\.]+?)(?:\s+\d{1,3}GB|\s+[Ii][3579]|\s+Ryzen|\s+Win|,|$)/i', $s, $m))
                    $r['model'] = trim($m[1]);
            }
            // CPU
            if (preg_match('/(?:CPU|PROCESSOR)[\:\s]+([^\n\r]+)/i', $s, $m)) $r['processor'] = trim($m[1]);
            elseif (preg_match('/\b((?:Intel\s+)?(?:Core\s+)?[Ii][3579][\-\s]?\d{3,5}[A-Z0-9]*|Ryzen\s+\d[\s\w]+?(?=\s+\d|,|$)|i[3579][\-]\d+[A-Z]*)/i', $s, $m)) $r['processor'] = trim($m[0]);
            // RAM
            if (preg_match('/(?:RAM)[\:\s]+(\d{1,3}\s*GB)/i', $s, $m)) $r['ram_size'] = trim($m[1]);
            elseif (preg_match('/\b(\d{1,3}\s*GB)\s*(?:DDR\d?|RAM|LPDDR\d?)?/i', $s, $m)) $r['ram_size'] = trim($m[0]);
            // Storage
            if (preg_match('/(?:STORAGE|HDD|SSD)[\:\s]+(\d{1,4}\s*(?:GB|TB)[^\n\r]*)/i', $s, $m)) $r['storage'] = trim($m[1]);
            elseif (preg_match_all('/\b(\d{1,4}\s*(?:GB|TB))\s*(?:M\.?2|NVMe|SSD|HDD|eMMC)?/i', $s, $all)) {
                foreach ($all[0] as $c) { if (trim($c) !== $r['ram_size']) { $r['storage'] = trim($c); break; } }
            }
            // OS
            if (preg_match('/(?:OS|WINDOWS|WIN)[\:\s]+([^\n\r]+)/i', $s, $m)) $r['operating_system'] = trim($m[1]);
            elseif (preg_match('/\b(Windows\s*\d+(?:\s+(?:Pro|Home|Enterprise))?|Win\s*\d+(?:\s+(?:Pro|Home))?|macOS(?:\s+[\w]+)?|Ventura|Monterey|Sequoia|Big Sur|Sonoma|Ubuntu(?:\s+\d+\.\d+)?)/i', $s, $m)) $r['operating_system'] = trim($m[0]);
            // Screen size
            if (preg_match('/\b(\d{1,2}(?:\.\d)?\s*(?:inch|in\b|"))/i', $s, $m)) $r['screen_size'] = trim($m[0]);
            elseif (preg_match("/\\b(\\d{1,2}(?:\\.\\d)?)(?:''|'|\"|\\x22)/", $s, $m)) $r['screen_size'] = $m[1] . '"';

            return $r;
        };

        // Employee matcher
        $findEmployee = function (string $name): ?Employee {
            if (empty(trim($name))) return null;
            $name = trim($name);
            $emp = Employee::whereNull('active_until')->where('full_name', $name)->first();
            if ($emp) return $emp;
            $stripped = trim(preg_replace('/\s*\(.*?\)/', '', $name));
            if ($stripped !== $name) {
                $emp = Employee::whereNull('active_until')->where('full_name', $stripped)->first();
                if ($emp) return $emp;
            }
            if (preg_match('/\(([^)]+)\)/', $name, $m)) {
                $emp = Employee::whereNull('active_until')->where('preferred_name', trim($m[1]))->first();
                if ($emp) return $emp;
            }
            return Employee::whereNull('active_until')->where('full_name', 'like', "%" . $stripped . "%")->first();
        };

        // Ensure AARF exists (works for both onboarded and imported employees)
        $ensureAarf = fn(Employee $emp) => $this->ensureAarfForEmployee($emp);

        // Main import loop
        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $row);
                $rowNumber++;
                continue;
            }
            $rowNumber++;
            if (empty(array_filter($row))) continue;

            $d = array_combine($headers, array_pad($row, count($headers), ''));
            $v = fn(string $k) => $sanitise(trim($d[$k] ?? ''));

            // ── Only 4 fields are required ────────────────────────────────
            $missing = [];
            if (empty($v('asset_type'))) $missing[] = 'asset_type';
            if (empty($v('asset_tag')))  $missing[] = 'asset_tag';
            if (empty($v('specs')) && empty($v('brand')) && empty($v('model'))) $missing[] = 'specs (or brand+model)';
            if (empty($v('remarks')))    $missing[] = 'remarks';

            if ($missing) {
                $errors[] = "Row {$rowNumber}: Missing required field(s): " . implode(', ', $missing) . " — skipped.";
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
            $parsed   = $parseSpecs($specsRaw);

            $brand     = $v('brand')  ?: ($parsed['brand']  ?? null) ?: 'Unknown';
            $model     = $v('model')  ?: ($parsed['model']  ?? null) ?: $assetTag;
            $assetType = $normaliseType($v('asset_type') ?? '');

            // ── Optional field defaults ───────────────────────────────────
            $rawCond       = strtolower($v('asset_condition') ?? '');
            $condition     = in_array($rawCond, ['new','good','fair','damaged']) ? $rawCond : 'good';
            $rawMaint      = strtolower($v('maintenance_status') ?? '');
            $maintStatus   = in_array($rawMaint, ['none','under_maintenance','repair_required']) ? $rawMaint : 'none';
            $ownershipType = in_array(strtolower($v('ownership_type') ?? ''), ['company','rental'])
                           ? strtolower($v('ownership_type') ?? '') : 'company';

            // ── Employee assignment ───────────────────────────────────────
            $assignedToName = $sanitise(trim($d['assigned_to'] ?? '')) ?? '';
            $employee       = $findEmployee($assignedToName);

            if (!empty($assignedToName) && !$employee) {
                $errors[] = "Row {$rowNumber}: Employee '{$assignedToName}' not found — asset imported as unassigned.";
            }

            // Status: use CSV value if valid, else derive from assignment
            $rawStatus = strtolower($v('status') ?? '');
            if (in_array($rawStatus, ['available','assigned','under_maintenance','retired'])) {
                $status = $rawStatus;
            } else {
                $status = $employee ? 'assigned' : 'available';
            }
            if ($employee) $status = 'assigned'; // assignment always wins

            // ── Remarks ───────────────────────────────────────────────────
            $remarksInput  = $sanitise(trim($d['remarks'] ?? ''));
            $initialRemark = trim(($remarksInput ? $remarksInput . "\n" : '') . 'Imported via CSV.');

            // ── Create asset record ───────────────────────────────────────
            $asset = AssetInventory::create([
                'asset_tag'            => $assetTag,
                'asset_type'           => $assetType,
                'brand'                => $brand,
                'model'                => $model,
                'serial_number'        => $v('serial_number') ?: null,
                'processor'            => $v('processor')         ?: ($parsed['processor']        ?? null),
                'ram_size'             => $v('ram_size')          ?: ($parsed['ram_size']         ?? null),
                'storage'              => $v('storage')           ?: ($parsed['storage']          ?? null),
                'operating_system'     => $v('operating_system')  ?: ($parsed['operating_system'] ?? null),
                'screen_size'          => $v('screen_size')       ?: ($parsed['screen_size']      ?? null),
                'spec_others'          => $v('spec_others') ?: (!empty($specsRaw) ? 'Original: ' . $specsRaw : null),
                'ownership_type'       => $ownershipType,
                'company_name'         => $v('company_name') ?: null,
                'purchase_vendor'      => $v('purchase_vendor') ?: null,
                'purchase_cost'        => is_numeric($d['purchase_cost'] ?? '') ? $d['purchase_cost'] : null,
                'purchase_date'        => $parseDate($d['purchase_date'] ?? ''),
                'warranty_expiry_date' => $parseDate($d['warranty_expiry_date'] ?? ''),
                'rental_vendor'              => $ownershipType === 'rental' ? ($v('rental_vendor') ?: null) : null,
                'rental_vendor_contact'      => $ownershipType === 'rental' ? ($v('rental_vendor_contact') ?: null) : null,
                'rental_cost_per_month'      => $ownershipType === 'rental' && is_numeric($d['rental_cost_per_month'] ?? '') ? $d['rental_cost_per_month'] : null,
                'rental_start_date'          => $ownershipType === 'rental' ? $parseDate($d['rental_start_date'] ?? '') : null,
                'rental_end_date'            => $ownershipType === 'rental' ? $parseDate($d['rental_end_date'] ?? '') : null,
                'rental_contract_reference'  => $ownershipType === 'rental' ? ($v('rental_contract_reference') ?: null) : null,
                'status'               => $status,
                'asset_condition'      => $condition,
                'maintenance_status'   => $maintStatus,
                'assigned_employee_id' => $employee?->id,
                'asset_assigned_date'  => $employee ? ($parseDate($d['asset_assigned_date'] ?? '') ?? now()->toDateString()) : null,
                'expected_return_date' => $parseDate($d['expected_return_date'] ?? ''),
                'asset_location'       => $v('asset_location') ?: null,
                'remarks'              => $initialRemark,
            ]);

            // ── Link to employee + ensure AARF ────────────────────────────
            if ($employee) {
                $this->createAssignmentForEmployee($employee, $asset->id, $asset->asset_assigned_date ?? now()->toDateString());
                $aarf = $this->ensureAarfForEmployee($employee);
                $aarf?->appendAssetChange("[{$asset->asset_tag}] assigned to {$employee->full_name} (imported from CSV).");
            }

            $imported++;
        }

        fclose($handle);
        $message = "{$imported} asset(s) imported successfully.";
        if ($skipped) $message .= " {$skipped} row(s) skipped.";
        return back()->with('success', $message)->with('import_errors', $errors);
    }

  
    /**
     * Send AARF acknowledgement email to the employee.
     * Resolves recipient email from employee record, falls back to onboarding data.
     */
    private function sendAarfEmail(Aarf $aarf, Employee $employee, string $empName, string $action): void
    {
        if (!$aarf->acknowledgement_token) {
            \Log::info("AARF email skipped — no acknowledgement_token. AARF #{$aarf->id}");
            return;
        }

        $recipientEmail = $employee->company_email
                       ?? $employee->personal_email
                       ?? $employee->onboarding?->workDetail?->company_email
                       ?? $employee->onboarding?->personalDetail?->personal_email
                       ?? null;

        if (!$recipientEmail) {
            \Log::info("AARF email skipped — no recipient email found for employee #{$employee->id} ({$empName})");
            return;
        }

        \Log::info("AARF email queued via terminating() — to: {$recipientEmail}, action: {$action}, AARF #{$aarf->id}");

        $aarfId = $aarf->id;
        $to     = $recipientEmail;
        $name   = $empName;
        $act    = $action;

        app()->terminating(function () use ($aarfId, $to, $name, $act) {
            \Illuminate\Support\Facades\Log::info("AARF terminating callback firing — to: {$to}, action: {$act}");
            try {
                $freshAarf = \App\Models\Aarf::find($aarfId);
                if (!$freshAarf) {
                    \Illuminate\Support\Facades\Log::warning("AARF email aborted — AARF #{$aarfId} not found in DB");
                    return;
                }

                \Illuminate\Support\Facades\Mail::to($to)
                    ->send(new \App\Mail\AarfAcknowledgementMail($freshAarf, $name, $act));

                \Illuminate\Support\Facades\Log::info("AARF email sent successfully to {$to}");

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("AARF email FAILED to {$to}: " . $e->getMessage());
            }
        });
    }

    private function ensureAarfForEmployee(Employee $emp): ?Aarf
    {
        // Try direct employee_id link (imported employees)
        $aarf = Aarf::where('employee_id', $emp->id)->first();
        if ($aarf) return $aarf;

        // Try onboarding_id link (onboarded employees)
        if ($emp->onboarding_id) {
            $aarf = Aarf::where('onboarding_id', $emp->onboarding_id)->first();
            if ($aarf) return $aarf;
        }

        // Create new AARF — use employee_id if no onboarding, onboarding_id if available
        return Aarf::create([
            'onboarding_id'         => $emp->onboarding_id ?? null,
            'employee_id'           => $emp->onboarding_id ? null : $emp->id,
            'aarf_reference'        => Onboarding::generateAarfReference(),
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
            'onboarding_id'      => $emp->onboarding_id ?? null,
            'employee_id'        => $emp->onboarding_id ? null : $emp->id,
            'asset_inventory_id' => $assetId,
            'assigned_date'      => $date,
            'status'             => 'assigned',
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function buildAssetData(Request $request, array $validated, $user = null): array
    {
        $canEditAll = !$user || $user->canEditAllAssetSections();

        $data = [
            'asset_tag'        => $validated['asset_tag'],
            'asset_type'       => $validated['asset_type'],
            'brand'            => $validated['brand'],
            'model'            => $validated['model'],
            'serial_number'    => $validated['serial_number'],
            'processor'        => $validated['processor'] ?? null,
            'ram_size'         => $validated['ram_size'] ?? null,
            'storage'          => $validated['storage'] ?? null,
            'operating_system' => $validated['operating_system'] ?? null,
            'screen_size'      => $validated['screen_size'] ?? null,
            'spec_others'      => $validated['spec_others'] ?? null,
        ];

        if ($canEditAll) {
            $data['purchase_date']        = $validated['purchase_date'] ?? null;
            $data['purchase_vendor']      = $validated['purchase_vendor'] ?? null;
            $data['purchase_cost']        = $validated['purchase_cost'] ?? null;
            $data['warranty_expiry_date'] = $validated['warranty_expiry_date'] ?? null;

            $data['ownership_type'] = $validated['ownership_type'] ?? 'company';
            if ($data['ownership_type'] === 'rental') {
                $data['company_name']              = null;
                $data['rental_vendor']             = $validated['rental_vendor'] ?? null;
                $data['rental_vendor_contact']     = $validated['rental_vendor_contact'] ?? null;
                $data['rental_cost_per_month']     = $validated['rental_cost_per_month'] ?? null;
                $data['rental_start_date']         = $validated['rental_start_date'] ?? null;
                $data['rental_end_date']           = $validated['rental_end_date'] ?? null;
                $data['rental_contract_reference'] = $validated['rental_contract_reference'] ?? null;
            } else {
                $data['company_name']              = $validated['company_name'] ?? null;
                $data['rental_vendor']             = null;
                $data['rental_vendor_contact']     = null;
                $data['rental_cost_per_month']     = null;
                $data['rental_start_date']         = null;
                $data['rental_end_date']           = null;
                $data['rental_contract_reference'] = null;
            }

            // Section E condition drives availability:
            //   good             → available (unless assigned to an employee)
            //   under_maintenance→ unavailable
            //   not_good         → unavailable + flagged for disposal on save
            $condition = $validated['asset_condition'];
            $data['asset_condition']    = $condition;
            $data['maintenance_status'] = ($condition === 'under_maintenance')
                ? ($validated['maintenance_status'] ?? 'pending')
                : null;
            $data['last_maintenance_date'] = $validated['last_maintenance_date'] ?? null;
            $data['notes']                 = $validated['remarks'] ?? null;

            // Section D — assignment
            $newEmployeeId = $validated['assigned_employee_id'] ?? null;
            if ($newEmployeeId) {
                $data['status']               = 'assigned';
                $data['assigned_employee_id'] = $newEmployeeId;
                $data['asset_assigned_date']  = $validated['asset_assigned_date'] ?? now()->toDateString();
                $data['expected_return_date'] = $validated['expected_return_date'] ?? null;
            } else {
                // Status set by condition; only 'available' assets can be assigned
                $data['status']               = ($condition === 'good') ? 'available' : 'unavailable';
                $data['assigned_employee_id'] = null;
                $data['asset_assigned_date']  = null;
                $data['expected_return_date'] = null;
            }
        }

        return $data;
    }

    private function validateAsset(Request $request, bool $isUpdate = false, $user = null): array
    {
        $rules = [
            'asset_tag'        => 'required|string|max:50' . ($isUpdate ? '' : '|unique:asset_inventories,asset_tag'),
            'asset_type'       => 'required|in:laptop,monitor,converter,phone,sim_card,access_card,other',
            'brand'            => 'required|string|max:100',
            'model'            => 'required|string|max:100',
            'serial_number'    => 'required|string|max:100',
            'processor'        => 'nullable|string|max:255',
            'ram_size'         => 'nullable|string|max:100',
            'storage'          => 'nullable|string|max:100',
            'operating_system' => 'nullable|string|max:100',
            'screen_size'      => 'nullable|string|max:50',
            'spec_others'      => 'nullable|string',
        ];

        $canEditAll = !$user || $user->canEditAllAssetSections();
        if ($canEditAll) {
            $rules['purchase_date']             = 'nullable|date';
            $rules['purchase_vendor']           = 'nullable|string|max:255';
            $rules['purchase_cost']             = 'nullable|numeric|min:0';
            $rules['warranty_expiry_date']      = 'nullable|date';
            $rules['invoice_document']          = 'nullable|file|mimes:pdf|max:5120';
            $rules['ownership_type']            = 'required|in:company,rental';
            $rules['company_name']              = 'nullable|string|max:255';
            $rules['rental_vendor']             = 'nullable|string|max:255';
            $rules['rental_vendor_contact']     = 'nullable|string|max:255';
            $rules['rental_cost_per_month']     = 'nullable|numeric|min:0';
            $rules['rental_start_date']         = 'nullable|date';
            $rules['rental_end_date']           = 'nullable|date|after_or_equal:rental_start_date';
            $rules['rental_contract_reference'] = 'nullable|string|max:255';
            $rules['status']                    = 'required|in:available,unavailable,assigned';
            $rules['assigned_employee_id']      = 'nullable|exists:employees,id';
            $rules['asset_assigned_date']       = 'nullable|date';
            $rules['expected_return_date']      = 'nullable|date';
            $rules['asset_condition']           = 'required|in:good,not_good,under_maintenance';
            $rules['maintenance_status']        = 'nullable|in:pending,in_progress,done';
            $rules['last_maintenance_date']     = 'nullable|date';
            $rules['remarks']                   = 'nullable|string';
            $rules['asset_photos']              = 'nullable|array|min:1|max:15';
            $rules['asset_photos.*']            = 'image|max:5120';
            $rules['decommission_reason']       = 'nullable|string|max:500';
        }

        return $request->validate($rules);
    }

    private function authorizeItAccess(): void
    {
        if (!Auth::user()->isIt() && !Auth::user()->isSuperadmin()) abort(403, 'IT access only.');
    }

    private function authorizeCanAdd(): void
    {
        if (!Auth::user()->canAddAsset()) abort(403, 'No permission to add assets.');
    }

    private function authorizeCanEdit(): void
    {
        if (!Auth::user()->canEditAsset()) abort(403, 'No permission to edit assets.');
    }
}