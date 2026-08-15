<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\EwasteCompanyApprover;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Settings → E-Waste Approvers. Who, in management, may authorise a disposal for each company.
 *
 * Superadmin only: this screen grants the authority to sign off writing company assets off the
 * books, so being able to edit it is a strictly bigger permission than being able to use it.
 */
class EwasteApproverController extends Controller
{
    private function authorizeAccess(): void
    {
        if (! Auth::user()->canManageEwasteApprovers()) {
            abort(403, 'Only a superadmin may configure e-waste approvers.');
        }
    }

    public function index()
    {
        $this->authorizeAccess();

        return view('superadmin.ewaste-approvers', [
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            // Any active user may be named: the approvers are the CEO/CTO of each entity, whose
            // app role is frequently just `employee`. Narrowing this to management-sounding
            // roles would hide exactly the people it exists to name.
            'candidates' => User::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'work_email', 'role']),
            'assigned' => EwasteCompanyApprover::map(),
        ]);
    }

    /**
     * Wipe-and-rebuild, like DepartmentSettingsController. The table is small and
     * admin-edited, and a diff would have to reason about rows the form did not mention.
     */
    public function update(Request $request)
    {
        $this->authorizeAccess();

        $data = $request->validate([
            'approvers' => 'nullable|array',
            'approvers.*' => 'nullable|array',
            'approvers.*.*' => 'integer|exists:users,id',
        ]);

        $companies = Company::orderBy('name')->pluck('name')->all();
        $submitted = $data['approvers'] ?? [];

        DB::transaction(function () use ($submitted, $companies) {
            EwasteCompanyApprover::query()->delete();

            $rows = [];
            foreach ($submitted as $company => $userIds) {
                // Only registered companies: a stray key would create a mapping no cycle can
                // ever match, since a batch's company always comes from the registered list.
                if (! in_array($company, $companies, true)) {
                    continue;
                }

                foreach (array_unique(array_map('intval', $userIds ?: [])) as $userId) {
                    $rows[] = [
                        'company' => $company,
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($rows) {
                EwasteCompanyApprover::insert($rows);
            }
        });

        $unset = collect($companies)
            ->reject(fn ($c) => ! empty($submitted[$c]))
            ->values();

        return redirect()->route('superadmin.ewaste-approvers')->with(
            $unset->isEmpty() ? 'success' : 'warning',
            $unset->isEmpty()
                ? 'E-waste approvers updated.'
                : 'E-waste approvers updated. No approver is named for: '.$unset->implode(', ')
                    // Both consequences, because this list has two readers: disposal approval
                    // and the signed-AARF copy. Naming only the first would let somebody read
                    // "approval falls back" as "nothing else changed".
                    .' — disposals for those companies fall back to superadmin approval, and'
                    .' their signed AARFs are copied to superadmins instead of named management.'
        );
    }
}
