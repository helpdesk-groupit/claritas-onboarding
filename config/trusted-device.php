<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trust window (days)
    |--------------------------------------------------------------------------
    | How long a remembered device skips the 2FA challenge before the user is
    | prompted again. Google/OWASP default is 30 days.
    */
    'days' => (int) env('TRUSTED_DEVICE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Location (country) gating
    |--------------------------------------------------------------------------
    | When true, a trusted device is re-challenged if the login country differs
    | from the country recorded when the device was trusted. Fails OPEN: if the
    | country cannot be determined (no GeoIP DB, private/local IP, lookup error)
    | the device is still trusted. This keeps local development and intranet use
    | working without the GeoIP file present.
    */
    'check_country' => (bool) env('TRUSTED_DEVICE_CHECK_COUNTRY', true),

    /*
    |--------------------------------------------------------------------------
    | GeoLite2-Country database
    |--------------------------------------------------------------------------
    | Stored on the "local" disk (NAS-safe via the Storage facade — no hardcoded
    | absolute path). Refreshed monthly by the `geoip:update` command from the
    | CDN mirror below (a ~2 MB file; no MaxMind API key required). Swap the URL
    | for a MaxMind-licensed permalink if you prefer the official source.
    */
    'geoip_disk' => 'local',
    'geoip_path' => 'geoip/GeoLite2-Country.mmdb',
    'geoip_url'  => env(
        'GEOIP_COUNTRY_URL',
        'https://cdn.jsdelivr.net/gh/wp-statistics/GeoLite2-Country@master/GeoLite2-Country.mmdb.gz'
    ),

    /*
    |--------------------------------------------------------------------------
    | Trusted-device cookie name
    |--------------------------------------------------------------------------
    | Encrypted + httpOnly by Laravel's cookie stack. Holds "selector:validator".
    */
    'cookie' => 'td_token',
];
