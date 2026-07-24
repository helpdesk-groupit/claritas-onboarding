<?php

namespace App\Http\Controllers;

/**
 * Help / user-manual pages — shareable URLs, but viewing requires login.
 * An unauthenticated visitor is redirected to the login page by the auth
 * middleware on the route group.
 *
 * The same content is also rendered inside in-app modals on the relevant
 * authenticated pages; both surfaces include the same body partial so edits
 * stay in one place. See resources/views/partials/_user-manual-*-body.blade.php.
 *
 * Routes (auth-gated):
 *   GET /help/my-tickets           → tickets()
 *   GET /help/ticket-management    → manage()
 *   GET /help/department-settings  → departmentSettings()
 *   GET /help/my-claims            → claims()
 *   GET /help/team-claims          → teamClaims()
 *   GET /help/hr-claims            → hrClaims()
 *   GET /help/claim-reports        → claimReports()
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

    public function claims()
    {
        return view('help.claims');
    }

    public function teamClaims()
    {
        return view('help.team-claims');
    }

    public function hrClaims()
    {
        return view('help.hr-claims');
    }

    public function claimReports()
    {
        return view('help.claim-reports');
    }
}
