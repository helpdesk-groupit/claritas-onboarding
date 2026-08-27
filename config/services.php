<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third-party services such
    | as ClamAV anti-malware. Each service is read by the MalwareScanner /
    | ScanUploadsForMalware middleware. Leaving CLAMAV_HOST unset disables
    | the ClamAV layer; the MalwareScanner heuristic layer continues to run.
    |
    */

    /*
    | ADM-06 / Part C 4.1 — SSO handoff to the KOL Management Portal.
    | The shared secret MUST be byte-identical to SSO_SHARED_SECRET in that
    | application's .env; a mismatch fails every handoff with a deliberately
    | generic error on its side, so it will not tell you that is the cause.
    | Leaving either value unset hides the sidebar link and disables the route.
    */
    'kol_portal' => [
        'url' => env('KOL_PORTAL_URL'),
        'shared_secret' => env('KOL_PORTAL_SHARED_SECRET'),
    ],

    'clamav' => [
        'host' => env('CLAMAV_HOST'),
        'port' => (int) env('CLAMAV_PORT', 3310),
    ],

];
