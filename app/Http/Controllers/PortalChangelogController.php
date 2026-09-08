<?php

namespace App\Http\Controllers;

use App\Models\PortalChangelogEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Settings → Portal Changelog (superadmin only). A manually-recorded log of changes made to the
 * portal itself — features added, bugs fixed, config changed — with who logged it, who's
 * credited for it, and when it actually happened. Not an audit trail of employee record edits.
 */
class PortalChangelogController extends Controller
{
    private function authorizeAccess(): void
    {
        if (! Auth::user()->canManageChangelog()) {
            abort(403, 'Only a superadmin may access the Portal Changelog.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeAccess();

        $query = PortalChangelogEntry::query();

        if ($request->filled('type') && array_key_exists($request->type, PortalChangelogEntry::TYPES)) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status') && array_key_exists($request->status, PortalChangelogEntry::STATUSES)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('module_area')) {
            $query->where('module_area', $request->module_area);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('occurred_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('occurred_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        $entries = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return view('superadmin.changelog.index', [
            'entries'     => $entries,
            'moduleAreas' => PortalChangelogEntry::MODULE_AREAS,
            'types'       => PortalChangelogEntry::TYPES,
            'statuses'    => PortalChangelogEntry::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();

        $data = $this->validated($request);
        $user = Auth::user();

        PortalChangelogEntry::create($data + [
            'changed_by_user_id' => $user->id,
            'changed_by_name'    => ($data['changed_by_name'] ?? null) ?: $user->name,
        ]);

        return back()->with('success', 'Changelog entry added.');
    }

    public function update(Request $request, PortalChangelogEntry $entry)
    {
        $this->authorizeAccess();

        $data = $this->validated($request);
        $user = Auth::user();

        // changed_by_user_id (the account that originally logged this) is deliberately never
        // touched here — it's the audit fact. changed_by_name (the credited author) IS editable,
        // same as AARF collector details: "normally us, but not always us".
        $entry->update($data + [
            'changed_by_name'    => ($data['changed_by_name'] ?? null) ?: $entry->changed_by_name,
            'updated_by_user_id' => $user->id,
            'updated_by_name'    => $user->name,
        ]);

        return back()->with('success', 'Changelog entry updated.');
    }

    public function destroy(PortalChangelogEntry $entry)
    {
        $this->authorizeAccess();

        $entry->delete();

        return back()->with('success', 'Changelog entry deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:5000',
            'type'            => ['required', Rule::in(array_keys(PortalChangelogEntry::TYPES))],
            'status'          => ['required', Rule::in(array_keys(PortalChangelogEntry::STATUSES))],
            'module_area'     => 'nullable|string|max:150',
            'occurred_at'     => 'required|date',
            'changed_by_name' => 'nullable|string|max:150',
        ]);
    }
}
