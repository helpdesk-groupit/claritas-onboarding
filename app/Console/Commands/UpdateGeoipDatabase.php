<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads / refreshes the GeoLite2-Country database used by the trusted-device
 * location check. Uses a ~2 MB CDN mirror by default (no MaxMind API key). The
 * file is stored on the "local" disk via the Storage facade so it lives on the
 * NAS RAID volume and rides along with the existing backups.
 *
 * Fails safe: a download error leaves the previous file untouched, and the app's
 * country check fails open when the file is missing — logins are never blocked.
 */
class UpdateGeoipDatabase extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Download/refresh the GeoLite2-Country database for trusted-device location checks';

    public function handle(): int
    {
        $url  = config('trusted-device.geoip_url');
        $disk = config('trusted-device.geoip_disk', 'local');
        $rel  = config('trusted-device.geoip_path', 'geoip/GeoLite2-Country.mmdb');

        if (!$url) {
            $this->error('No GEOIP_COUNTRY_URL / trusted-device.geoip_url configured.');
            return self::FAILURE;
        }

        $this->info("Fetching GeoLite2-Country from {$url} ...");

        try {
            $response = Http::timeout(120)->retry(2, 2000)->get($url);
        } catch (\Throwable $e) {
            $this->error('Download error: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!$response->successful()) {
            $this->error('Download failed: HTTP ' . $response->status());
            return self::FAILURE;
        }

        $body = $response->body();

        // The CDN mirror serves a .gz; decode if gzipped, else use as-is.
        $decoded = @gzdecode($body);
        $data    = $decoded !== false ? $decoded : $body;

        if (strlen($data) < 100_000) {
            $this->error('Downloaded file looks too small (' . strlen($data) . ' bytes) — aborting to avoid clobbering a good DB.');
            return self::FAILURE;
        }

        Storage::disk($disk)->put($rel, $data);

        $this->info('GeoLite2-Country updated: ' . number_format(strlen($data)) . ' bytes at ' . $disk . ':' . $rel);
        return self::SUCCESS;
    }
}
