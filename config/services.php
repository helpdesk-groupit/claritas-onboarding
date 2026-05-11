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

    'clamav' => [
        'host' => env('CLAMAV_HOST'),
        'port' => (int) env('CLAMAV_PORT', 3310),
    ],

];
