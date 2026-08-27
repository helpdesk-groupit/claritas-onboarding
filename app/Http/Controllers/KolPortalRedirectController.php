<?php

namespace App\Http\Controllers;

use App\Services\KolPortalTokenIssuer;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * ADM-06 — "The Employee Portal's navigation menu includes a link/tab (e.g.
 * 'KOL Management') that opens the KOL Management Portal in a new browser
 * tab", authenticated by SSO (ADM-07) so the user is not asked to log in twice.
 *
 * This is the minting half of that handshake. The KOL Portal owns the
 * verifying half (/sso/callback).
 */
class KolPortalRedirectController extends Controller
{
    public function __invoke(Request $request, KolPortalTokenIssuer $issuer)
    {
        $user = $request->user();

        // Gate here as well as in the sidebar. The nav only hides the link;
        // this is what actually stops someone hand-typing the URL from being
        // handed a valid token for a system they have no business in.
        abort_unless($user->canAccessKolPortal(), 403);

        if (! $issuer->isConfigured()) {
            return back()->withErrors([
                'kol_portal' => 'The KOL Management Portal link is not configured yet. Ask IT to set KOL_PORTAL_URL and KOL_PORTAL_SHARED_SECRET.',
            ]);
        }

        try {
            $url = $issuer->redirectUrlFor($user);
        } catch (RuntimeException $e) {
            report($e);

            return back()->withErrors(['kol_portal' => 'Could not open the KOL Management Portal. IT has been notified.']);
        }

        // away() — the target is a different application on a different
        // hostname, so this must not be routed through this app's URL
        // generator. The token rides in the query string because the KOL
        // Portal's callback is a GET the browser follows directly.
        return redirect()->away($url);
    }
}
