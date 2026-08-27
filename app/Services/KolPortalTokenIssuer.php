<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mints the short-lived, single-use JWT that hands an authenticated Employee
 * Portal user to the KOL Management Portal (its ADM-06 / Part C §4.1).
 *
 * NO JWT LIBRARY, deliberately — same reasoning as this codebase's "NO
 * SPREADSHEET LIBRARY" decision. We only ever SIGN here; we never parse or
 * verify a token, and signing HS256 is a hash_hmac plus base64url. The
 * security-critical half — verification, expiry, replay rejection — happens
 * on the KOL Portal side, which does use a vetted library (firebase/php-jwt).
 * Adding a composer dependency to this app buys nothing and has to be
 * installed by hand on the NAS.
 *
 * The token carries IDENTITY ONLY (work email + display name). It deliberately
 * carries no role: the KOL Portal's own staff_users.role is the sole authority
 * on what someone may do there, so a compromise here cannot escalate privilege
 * over there.
 */
class KolPortalTokenIssuer
{
    /** Matches the KOL Portal's own dev mint command. Long enough to survive a redirect, short enough to be useless if leaked. */
    private const TTL_SECONDS = 60;

    private const ISSUER = 'claritas-employee-portal';

    public function isConfigured(): bool
    {
        return filled(config('services.kol_portal.url'))
            && filled(config('services.kol_portal.shared_secret'));
    }

    /**
     * The full URL to send the browser to, including the signed token.
     */
    public function redirectUrlFor(User $user): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('KOL Portal SSO is not configured (KOL_PORTAL_URL / KOL_PORTAL_SHARED_SECRET).');
        }

        $base = rtrim((string) config('services.kol_portal.url'), '/');

        return $base.'/sso/callback?token='.urlencode($this->tokenFor($user));
    }

    public function tokenFor(User $user): string
    {
        $now = time();

        return $this->encode([
            'iss' => self::ISSUER,
            // The KOL Portal matches staff_users.work_email on this exactly.
            'sub' => $user->work_email,
            'name' => $user->name ?: $user->work_email,
            // Single-use: the KOL Portal records the jti and rejects a replay.
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function encode(array $claims): string
    {
        $secret = (string) config('services.kol_portal.shared_secret');

        $segments = [
            $this->base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256'])),
            $this->base64UrlEncode((string) json_encode($claims)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
