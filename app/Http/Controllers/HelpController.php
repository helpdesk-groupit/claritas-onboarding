<?php

namespace App\Http\Controllers;

/**
 * Public help / user-manual pages — accessible without login so they can be
 * shared as a link with anyone (employees, prospective hires, external auditors).
 *
 * The same content is also rendered inside in-app modals on the relevant
 * authenticated pages; both surfaces include the same body partial so edits
 * stay in one place. See resources/views/partials/_user-manual-*-body.blade.php.
 *
 * Routes (no auth middleware):
 *   GET /help/my-tickets           → tickets()
 *   GET /help/ticket-management    → manage()
 *   GET /help/department-settings  → departmentSettings()
 */
class HelpController extends Controller
{
    public function tickets()
    {
        return view('help.tickets');
    }

    public function manage()
    {
        return view('help.manage');
    }

    public function departmentSettings()
    {
        return view('help.department-settings');
    }
}
