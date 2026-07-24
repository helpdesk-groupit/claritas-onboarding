<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Risk-based 2FA "remember this device" support.
 *
 * The trust anchor is a high-entropy, server-issued token stored in an
 * encrypted httpOnly cookie (named per-account, see cookieName()) and mirrored
 * as a hashed row in `trusted_devices`.
 * On login, if the cookie matches a valid non-expired row for the user AND no
 * risk signal fires (device family change, or — when enabled — country change),
 * the TOTP challenge is skipped. Anything missing or stale falls back to the
 * normal challenge, so the feature can never lock a user out.
 */
class TrustedDeviceService
{
    /** Decide whether the current request may skip the 2FA challenge. */
    public static function trusts(Request $request, User $user): bool
    {
        $raw = $request->cookie(self::cookieName($user->id));
        if (! is_string($raw) || ! str_contains($raw, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $raw, 2);

        $device = TrustedDevice::where('user_id', $user->id)
            ->where('selector', $selector)
            ->first();

        if (! $device) {
            return false;
        }

        if ($device->isExpired()) {
            $device->delete();

            return false;
        }

        // Constant-time comparison against the stored hash.
        if (! hash_equals($device->validator_hash, hash('sha256', $validator))) {
            return false;
        }

        // Risk signal 1: device/browser family changed (ignores version bumps).
        if (! self::deviceMatches($device, $request)) {
            return false;
        }

        // Risk signal 2: country changed (fails open when undeterminable).
        if (! self::countryMatches($device, $request)) {
            return false;
        }

        // Trusted — refresh last-seen metadata.
        $device->forceFill([
            'last_used_at' => now(),
            'last_ip' => $request->ip(),
            'last_country' => self::lookupCountry($request->ip()) ?? $device->last_country,
        ])->save();

        return true;
    }

    /** Mint a new trusted-device row + queue the cookie on the response. */
    public static function issue(User $user, Request $request): TrustedDevice
    {
        $selector = Str::random(24);
        $validator = Str::random(40);

        $device = TrustedDevice::create([
            'user_id' => $user->id,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'device_label' => self::deviceLabel($request->userAgent()),
            'user_agent' => $request->userAgent() ? Str::limit($request->userAgent(), 1000, '') : null,
            'last_ip' => $request->ip(),
            'last_country' => self::lookupCountry($request->ip()),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::days()),
        ]);

        $minutes = self::days() * 24 * 60;
        Cookie::queue(Cookie::make(
            self::cookieName($user->id),
            $selector.':'.$validator,
            $minutes,
            null,   // path  (default)
            null,   // domain (default)
            null,   // secure (null → follows session.secure: false on local http, true on https)
            true,   // httpOnly
            false,  // raw
            'lax'   // sameSite
        ));

        return $device;
    }

    /** Revoke a single device row and clear the cookie if it points to it. */
    public static function revoke(TrustedDevice $device, ?Request $request = null): void
    {
        $selector = $device->selector;
        $userId = $device->user_id;
        $device->delete();

        if ($request) {
            $raw = $request->cookie(self::cookieName($userId));
            if (is_string($raw) && str_starts_with($raw, $selector.':')) {
                Cookie::queue(Cookie::forget(self::cookieName($userId)));
            }
        }
    }

    /** Revoke every trusted device for a user (password reset, 2FA disable/reset). */
    public static function revokeAll(User $user): void
    {
        TrustedDevice::where('user_id', $user->id)->delete();
    }

    // ── Risk signals ─────────────────────────────────────────────────────────

    protected static function deviceMatches(TrustedDevice $device, Request $request): bool
    {
        $stored = self::uaSignature($device->user_agent);
        $current = self::uaSignature($request->userAgent());

        // Fail open if either UA is missing/unparseable — don't punish odd clients.
        if ($stored === null || $current === null) {
            return true;
        }

        return $stored === $current;
    }

    protected static function countryMatches(TrustedDevice $device, Request $request): bool
    {
        if (! config('trusted-device.check_country', true)) {
            return true;
        }

        $current = self::lookupCountry($request->ip());

        // Fail open: unknown current country, or device never recorded one.
        if ($current === null || $device->last_country === null) {
            return true;
        }

        return strtoupper($current) === strtoupper($device->last_country);
    }

    // ── GeoIP (pure-PHP MaxMind reader; NAS-safe; fails open) ─────────────────

    /** Resolve an IP to an ISO country code, or null when undeterminable. */
    public static function lookupCountry(?string $ip): ?string
    {
        if (! $ip || ! class_exists(\GeoIp2\Database\Reader::class)) {
            return null;
        }

        // Private / reserved / loopback addresses have no public geolocation.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $disk = config('trusted-device.geoip_disk', 'local');
        $rel = config('trusted-device.geoip_path', 'geoip/GeoLite2-Country.mmdb');

        try {
            if (! Storage::disk($disk)->exists($rel)) {
                return null;
            }
            $reader = new \GeoIp2\Database\Reader(Storage::disk($disk)->path($rel));
            $country = $reader->country($ip)->country->isoCode;

            return $country ?: null;
        } catch (\Throwable $e) {
            return null; // fail open — never block a login on a GeoIP hiccup
        }
    }

    // ── User-Agent helpers ───────────────────────────────────────────────────

    /** Coarse "Browser/OS" signature, version-insensitive. Null if unknown. */
    protected static function uaSignature(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }

        $browser = self::browserFamily($ua);
        $os = self::osFamily($ua);

        if ($browser === null && $os === null) {
            return null;
        }

        return ($browser ?? '?').'/'.($os ?? '?');
    }

    public static function deviceLabel(?string $ua): string
    {
        if (! $ua) {
            return 'Unknown device';
        }
        $browser = self::browserFamily($ua) ?? 'Unknown browser';
        $os = self::osFamily($ua);

        return $os ? "{$browser} on {$os}" : $browser;
    }

    protected static function browserFamily(string $ua): ?string
    {
        // Order matters: Edge/Opera identify themselves before Chrome.
        return match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome') && ! str_contains($ua, 'Chromium') => 'Chrome',
            str_contains($ua, 'Chromium') => 'Chromium',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => null,
        };
    }

    protected static function osFamily(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };
    }

    // ── Config accessors ─────────────────────────────────────────────────────

    protected static function days(): int
    {
        return max(1, (int) config('trusted-device.days', 30));
    }

    /**
     * Per-user trust cookie name (e.g. "td_token_42").
     *
     * The cookie is scoped to ONE account so that two accounts sharing a single
     * browser (e.g. an IT-Manager login and a Superadmin login) each keep their
     * own trusted-device cookie instead of clobbering a single shared one — the
     * bug where the second account's trust overwrote the first, re-challenging
     * both on every login. The DB row is already keyed by user_id + selector;
     * this aligns the cookie with that same per-account scoping.
     */
    public static function cookieName(int|string $userId): string
    {
        return config('trusted-device.cookie', 'td_token').'_'.$userId;
    }
}
