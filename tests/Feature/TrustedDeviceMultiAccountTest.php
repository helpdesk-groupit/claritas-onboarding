<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TrustedDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

/**
 * Two accounts (e.g. an IT-Manager login and a Superadmin login) used in the
 * SAME browser must each keep their own "remember this device" trust. Before
 * the per-user cookie fix, a single shared `td_token` cookie meant the second
 * account's trust overwrote the first's, so alternating between the two logins
 * re-challenged both for OTP on every login.
 */
class TrustedDeviceMultiAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_accounts_in_same_browser_each_stay_trusted(): void
    {
        $itManager = User::factory()->itManager()->withTwoFactor()->create();
        $superadmin = User::factory()->superadmin()->withTwoFactor()->create();

        // "Remember this device" ticked on the 2FA challenge for each account.
        // Same browser → both cookies are minted (under different names).
        $issue = Request::create('/', 'GET');

        TrustedDeviceService::issue($itManager, $issue);
        $itCookie = Cookie::queued(TrustedDeviceService::cookieName($itManager->id))->getValue();

        TrustedDeviceService::issue($superadmin, $issue);
        $saCookie = Cookie::queued(TrustedDeviceService::cookieName($superadmin->id))->getValue();

        // Each account has its own cookie name — no single shared slot to clobber.
        $this->assertNotSame(
            TrustedDeviceService::cookieName($itManager->id),
            TrustedDeviceService::cookieName($superadmin->id)
        );

        // A later login request from that browser carries BOTH cookies.
        $login = Request::create('/', 'GET', [], [
            TrustedDeviceService::cookieName($itManager->id) => $itCookie,
            TrustedDeviceService::cookieName($superadmin->id) => $saCookie,
        ]);

        // Both accounts are recognised as trusted → neither is re-challenged.
        $this->assertTrue(TrustedDeviceService::trusts($login, $itManager));
        $this->assertTrue(TrustedDeviceService::trusts($login, $superadmin));
    }

    public function test_one_accounts_cookie_never_trusts_another_account(): void
    {
        $itManager = User::factory()->itManager()->withTwoFactor()->create();
        $superadmin = User::factory()->superadmin()->withTwoFactor()->create();

        $issue = Request::create('/', 'GET');
        TrustedDeviceService::issue($itManager, $issue);
        $itCookie = Cookie::queued(TrustedDeviceService::cookieName($itManager->id))->getValue();

        // Browser holds only the IT-Manager cookie; Superadmin was never trusted here.
        $request = Request::create('/', 'GET', [], [
            TrustedDeviceService::cookieName($itManager->id) => $itCookie,
        ]);

        $this->assertTrue(TrustedDeviceService::trusts($request, $itManager));
        $this->assertFalse(TrustedDeviceService::trusts($request, $superadmin));
    }
}
