<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\User;
use App\Services\KolPortalTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * ADM-06 / ADM-07 — the Employee Portal half of the KOL Management Portal SSO
 * handshake.
 *
 * The token shape is a contract with a SEPARATE application, so these tests
 * assert the exact claims its validator requires (iss / sub / name / jti /
 * iat / exp, HS256) rather than just "a token came out".
 */
class KolPortalSsoHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-secret-that-both-apps-hold';

    protected function setUp(): void
    {
        parent::setUp();

        // Same convention as the rest of this suite — the admin roles this route
        // serves are all in TWO_FACTOR_REQUIRED_ROLES, so without this every
        // request lands on /two-factor/setup instead of the route under test.
        $this->withoutMiddleware(EnforceTwoFactor::class);

        Config::set('services.kol_portal.url', 'https://kol.claritasapp.com');
        Config::set('services.kol_portal.shared_secret', self::SECRET);
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: string} header, claims, signature */
    private function decode(string $token): array
    {
        [$h, $p, $s] = explode('.', $token);
        $b64 = fn (string $v) => base64_decode(strtr($v, '-_', '+/').str_repeat('=', (4 - strlen($v) % 4) % 4));

        return [json_decode($b64($h), true), json_decode($b64($p), true), $s];
    }

    public function test_the_token_carries_exactly_the_claims_the_kol_portal_validates(): void
    {
        $user = User::factory()->create([
            'work_email' => 'amos.wafula@claritas.asia',
            'name' => 'Amos Wafula',
            'role' => 'it_manager',
        ]);

        [$header, $claims] = $this->decode(app(KolPortalTokenIssuer::class)->tokenFor($user));

        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $this->assertSame('amos.wafula@claritas.asia', $claims['sub']);
        $this->assertSame('Amos Wafula', $claims['name']);
        $this->assertSame('claritas-employee-portal', $claims['iss']);
        $this->assertNotEmpty($claims['jti']);

        // 60-second life, as the KOL Portal's own mint command uses.
        $this->assertSame(60, $claims['exp'] - $claims['iat']);
        $this->assertGreaterThan(time() - 5, $claims['exp']);
    }

    public function test_the_signature_verifies_against_the_shared_secret(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $token = app(KolPortalTokenIssuer::class)->tokenFor($user);

        [$h, $p, $signature] = explode('.', $token);

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "$h.$p", self::SECRET, true)
        ), '+/', '-_'), '=');

        $this->assertSame($expected, $signature);

        // And a token signed with the WRONG secret must not match — this is
        // what stops anyone who cannot read .env from minting one.
        $wrong = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "$h.$p", 'not-the-secret', true)
        ), '+/', '-_'), '=');

        $this->assertNotSame($wrong, $signature);
    }

    public function test_the_token_never_carries_a_role(): void
    {
        // Part C §4.1 — identity only. The KOL Portal's own staff_users.role
        // is the sole authority there, so a forged/leaked token cannot
        // escalate privilege across the boundary.
        $user = User::factory()->create(['role' => 'superadmin']);

        [, $claims] = $this->decode(app(KolPortalTokenIssuer::class)->tokenFor($user));

        $this->assertArrayNotHasKey('role', $claims);
        $this->assertStringNotContainsString('superadmin', json_encode($claims));
    }

    public function test_every_token_has_a_unique_jti_so_replays_can_be_rejected(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $issuer = app(KolPortalTokenIssuer::class);

        [, $a] = $this->decode($issuer->tokenFor($user));
        [, $b] = $this->decode($issuer->tokenFor($user));

        $this->assertNotSame($a['jti'], $b['jti']);
    }

    public function test_the_route_redirects_an_authorised_user_to_the_kol_portal(): void
    {
        $user = User::factory()->create(['role' => 'it_manager']);

        $response = $this->actingAs($user)->get(route('kol-portal.redirect'));

        $response->assertRedirect();
        $target = $response->headers->get('Location');

        $this->assertStringStartsWith('https://kol.claritasapp.com/sso/callback?token=', $target);
    }

    public function test_an_unauthorised_role_is_refused_even_by_direct_url(): void
    {
        // The sidebar only hides the link; this is what stops someone
        // hand-typing the URL from being handed a valid credential.
        $user = User::factory()->create(['role' => 'employee']);

        $this->actingAs($user)->get(route('kol-portal.redirect'))->assertForbidden();
    }

    public function test_a_guest_cannot_mint_a_token(): void
    {
        $this->get(route('kol-portal.redirect'))->assertRedirect();
    }

    public function test_it_degrades_with_a_clear_message_when_unconfigured(): void
    {
        Config::set('services.kol_portal.url', null);
        Config::set('services.kol_portal.shared_secret', null);

        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)
            ->from(route('profile'))
            ->get(route('kol-portal.redirect'))
            ->assertRedirect()
            ->assertSessionHasErrors('kol_portal');
    }
}
