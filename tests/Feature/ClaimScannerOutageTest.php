<?php

namespace Tests\Feature;

use App\Models\ClaudeApiSetting;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AiScannerUnavailableNotification;
use App\Services\ClaimReceiptOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A scan that never ran is not a receipt that couldn't be read.
 *
 * The two used to be the same thing on screen. ClaimReceiptOcrService fails OPEN by design
 * — every failure returns null and the claimant types the details in — but a null told the
 * caller nothing about WHOSE fault it was, so scanReceipt() answered a bare `ok:false` and
 * the form rendered it as "Couldn't read it — enter details manually", in red, next to the
 * upload box.
 *
 * That wording is a judgement on the photo, and it was wrong for three days. From
 * 2026-09-09 09:23 the Anthropic balance was exhausted and production logged 36 consecutive
 * 400s reading "Your credit balance is too low to access the Anthropic API" — nine of them
 * inside five seconds, one employee re-clicking Scan on a receipt that was perfectly legible.
 * It was reported as "the OCR can't read clear receipts", which is precisely what the screen
 * had been saying.
 *
 * What these tests pin:
 *  - a provider refusal reads as OUR outage, and never as a problem with the receipt;
 *  - the model's own "this photo is too blurry" verdict still comes back as a SUCCESSFUL
 *    read carrying an `issue`, so the confirm-what-was-read flow is untouched;
 *  - the claimant is never shown the company's billing state;
 *  - a refusal stops the app making the same doomed call, and a transient blip does not;
 *  - somebody who can fix it is told, once.
 */
class ClaimScannerOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetClaudeMemo();
        ClaimReceiptOcrService::forgetLastFailure();
        ClaimReceiptOcrService::clearOutage();
        Cache::flush();
        config(['claims.ocr.enabled' => true, 'claims.ocr.provider' => 'anthropic', 'claims.ocr.api_key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        $this->forgetClaudeMemo();
        ClaimReceiptOcrService::forgetLastFailure();
        parent::tearDown();
    }

    /** Same reason as ClaimReceiptTotalMismatchTest: the ClaudeApiSetting lookup is memoised in a static. */
    private function forgetClaudeMemo(): void
    {
        foreach (['claudeMemo' => null, 'claudeMemoLoaded' => false] as $prop => $value) {
            $p = new \ReflectionProperty(ClaimReceiptOcrService::class, $prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }

    private function actingEmployee(): User
    {
        $user = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    /** The exact body Anthropic returns for an exhausted balance (copied from production's log). */
    private function fakeCreditExhausted(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.',
            ],
        ], 400)]);
    }

    private function fakeVision(array $json): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);
    }

    /**
     * One stub whose answer can be switched mid-test, for the two tests that need the account
     * to start refusing and then start working.
     *
     * A second Http::fake() would NOT do it: fakes MERGE rather than replace, so the original
     * 400 stub stays first in line to match and the "after top-up" call gets refused again —
     * the trap VendorPaymentSlipTest documents, and it cost two failures here first time round.
     * The closure is deliberately un-type-hinted: Http::response() returns a promise, and a
     * `Response` hint throws a TypeError that the service's fail-open catch swallows whole.
     *
     * @return object{ok:bool} flip ->ok to make the provider start accepting calls
     */
    private function fakeSwitchableAnthropic(array $visionJson): object
    {
        $state = (object) ['ok' => false];

        Http::fake(['api.anthropic.com/*' => function () use ($state, $visionJson) {
            if (! $state->ok) {
                return Http::response([
                    'type' => 'error',
                    'error' => [
                        'type' => 'invalid_request_error',
                        'message' => 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.',
                    ],
                ], 400);
            }

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($visionJson)]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200);
        }]);

        return $state;
    }

    private function scan(User $user, string $name = 'receipt.jpg')
    {
        return $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image($name),
        ]);
    }

    // ── The outage must not read as a bad receipt ────────────────────────────────────

    public function test_a_provider_refusal_is_reported_as_our_outage_not_as_an_unreadable_receipt(): void
    {
        $this->fakeCreditExhausted();
        $res = $this->scan($this->actingEmployee());

        $res->assertStatus(200)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('unavailable', true);

        $message = (string) $res->json('message');
        $this->assertNotSame('', $message, 'A failed scan must say why, not come back with a bare ok:false.');
        // The whole defect in one assertion: the old response carried no message at all, so the
        // form fell back to "Couldn't read it", which is a claim about the document.
        $this->assertStringNotContainsStringIgnoringCase('couldn’t read it', $message);
        $this->assertStringNotContainsStringIgnoringCase("couldn't read it", $message);
        $this->assertStringContainsStringIgnoringCase('not with your receipt', $message);
    }

    public function test_the_claimant_is_never_shown_the_companys_billing_state(): void
    {
        $this->fakeCreditExhausted();
        $res = $this->scan($this->actingEmployee());

        $message = (string) $res->json('message');
        // An employee filing a lunch receipt has no business reading the account's balance,
        // and the raw provider text is the easiest thing in the world to pass straight through.
        foreach (['credit balance', 'Plans & Billing', 'purchase credits', 'API'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $message);
        }
        // It still reaches the people who can act — just not through this response.
        $this->assertStringContainsString(
            'credit balance is too low',
            (string) (ClaimReceiptOcrService::currentOutage()['detail'] ?? '')
        );
    }

    /**
     * The behaviour the operator described, pinned so this fix can't quietly change it: a
     * receipt the MODEL judged unreadable is still a successful scan. It comes back ok:true
     * carrying the model's own reason, which is what routes the form to the review table to
     * show what was read and ask the employee to confirm it.
     */
    public function test_a_receipt_the_model_judged_unreadable_still_returns_what_it_read_for_confirmation(): void
    {
        $this->fakeVision([
            'map' => null, 'map_multi_routes' => false,
            'items' => [['amount' => 42.50, 'date' => null, 'vendor' => 'STARBUCKS', 'item_description' => 'Coffee', 'category' => null, 'tax_amount' => 0]],
            'account_holder' => null, 'issuer' => null,
            'issue' => 'The photo is too blurry to read the date clearly.',
            'is_single_receipt' => true, 'receipt_total' => 42.50,
        ]);

        $res = $this->scan($this->actingEmployee());

        $res->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('unavailable', null);
        // What it managed to read is handed back, so the form can show it and ask for a check.
        $this->assertEquals(42.50, $res->json('amount'));
        $this->assertSame('STARBUCKS', $res->json('vendor'));
        $this->assertStringContainsString('blurry', (string) $res->json('issue'));
    }

    // ── The breaker: stop making the same doomed call, but only when it IS doomed ─────

    public function test_a_refusal_stops_the_app_making_the_same_doomed_call_again(): void
    {
        $this->fakeCreditExhausted();
        $user = $this->actingEmployee();

        $this->scan($user)->assertJsonPath('unavailable', true);
        Http::assertSentCount(1);

        // Second scan: the answer is already known, so no round trip is spent rediscovering it.
        // This is what nine scans in five seconds cost before — nine identical refused requests.
        $this->scan($user, 'again.jpg')->assertJsonPath('unavailable', true);
        Http::assertSentCount(1);
    }

    public function test_a_transient_server_error_does_not_disable_the_scanner_for_everyone(): void
    {
        // A 5xx is a blip. Tripping the breaker on one would take the scanner down for every
        // employee over a wobble that clears by itself — a worse bug than the one being fixed.
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);
        $user = $this->actingEmployee();

        $this->scan($user)->assertJsonPath('unavailable', true);
        $this->assertNull(ClaimReceiptOcrService::currentOutage(), 'A 5xx must not trip the outage breaker.');

        // ...and the next scan really does try again.
        $sentBefore = count(Http::recorded());
        $this->scan($user, 'again.jpg');
        $this->assertGreaterThan($sentBefore, count(Http::recorded()));
    }

    /**
     * The breaker disables scanning for EVERY employee, so only an account-level refusal may
     * trip it. Providers also answer 400 for a problem with one request — an image they could
     * not decode being the obvious one — and under a status-only rule any employee could take
     * the company's scanner down for five minutes, and raise a false billing alert, by
     * uploading a single malformed photo through a box built to accept photos.
     */
    public function test_one_employees_odd_image_cannot_disable_the_scanner_for_the_company(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'Could not process image'],
        ], 400)]);

        $res = $this->scan($this->actingEmployee());

        // This scan still fails open, and says so without blaming the receipt...
        $res->assertJsonPath('ok', false)->assertJsonPath('unavailable', true);
        $this->assertStringNotContainsStringIgnoringCase('couldn’t read it', (string) $res->json('message'));
        // ...but nobody else's scanning is affected, and no false billing alarm is raised.
        $this->assertNull(ClaimReceiptOcrService::currentOutage(), 'A per-request 400 must not trip the company-wide breaker.');
        Notification::assertNotSentTo($admin, AiScannerUnavailableNotification::class);
    }

    /** A rejected key IS account-level, however it is phrased — that one must still trip. */
    public function test_a_rejected_key_still_trips_the_breaker(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
        ], 401)]);

        $this->scan($this->actingEmployee());

        $this->assertNotNull(ClaimReceiptOcrService::currentOutage());
    }

    /**
     * The provider's message is persisted (cache + the notifications table) and rendered on
     * the settings page. OpenAI-compatible providers answer a bad key with "Incorrect API key
     * provided: sk-abc…", and a masked secret fragment is still a secret fragment in a row.
     */
    public function test_a_key_echoed_back_by_the_provider_is_not_written_down(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'Incorrect API key provided: sk-EXAMPLE-not-a-real-key. Check your key.'],
        ], 401)]);

        $this->scan($this->actingEmployee());

        $detail = (string) (ClaimReceiptOcrService::currentOutage()['detail'] ?? '');
        $this->assertStringNotContainsString('sk-EXAMPLE-not-a-real-key', $detail);
        $this->assertStringContainsString('redacted', $detail);
    }

    public function test_a_rate_limit_is_treated_as_transient_rather_than_an_incident(): void
    {
        // 429 clears on its own. Escalating ordinary busy-ness to a bell-notified outage is how
        // an alert becomes noise an admin learns to dismiss.
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);
        Notification::fake();

        $this->scan($this->actingEmployee())->assertJsonPath('unavailable', true);

        $this->assertNull(ClaimReceiptOcrService::currentOutage());
        Notification::assertNothingSent();
    }

    public function test_the_outage_clears_itself_without_anyone_remembering_a_flag(): void
    {
        config(['claims.ocr.outage_ttl_minutes' => 5]);
        $state = $this->fakeSwitchableAnthropic([
            'map' => null, 'map_multi_routes' => false,
            'items' => [['amount' => 12.00, 'date' => '2026-09-10', 'vendor' => 'KOPITIAM', 'item_description' => 'Lunch', 'category' => null, 'tax_amount' => 0]],
            'account_holder' => null, 'issuer' => null, 'issue' => null,
            'is_single_receipt' => true, 'receipt_total' => 12.00,
        ]);
        $user = $this->actingEmployee();

        $this->scan($user);
        $this->assertNotNull(ClaimReceiptOcrService::currentOutage());

        // Credit topped up; nobody touched the app. Six minutes later it tries again by itself.
        $state->ok = true;
        $this->travel(6)->minutes();
        $this->assertNull(ClaimReceiptOcrService::currentOutage(), 'The breaker must expire on its own — a topped-up account should heal without an admin clearing anything.');

        $this->scan($user, 'after.jpg')->assertJsonPath('ok', true);
    }

    public function test_a_successful_key_test_clears_the_outage_at_once(): void
    {
        $state = $this->fakeSwitchableAnthropic(['items' => []]);
        $this->scan($this->actingEmployee());
        $this->assertNotNull(ClaimReceiptOcrService::currentOutage());

        // The admin tops up and presses Test — the natural "I've fixed it" action, and the
        // only way back before the TTL runs out.
        $state->ok = true;

        $admin = User::factory()->withTwoFactor()->create(['role' => 'superadmin']);
        $this->actingAs($admin)
            ->postJson(route('superadmin.claude-api.test'), ['api_key' => 'sk-ant-test', 'model' => array_key_first(ClaudeApiSetting::MODELS)])
            ->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertNull(ClaimReceiptOcrService::currentOutage());
    }

    // ── Somebody who can fix it gets told ────────────────────────────────────────────

    public function test_admins_are_told_once_per_outage_and_not_once_per_failed_scan(): void
    {
        Notification::fake();
        $this->fakeCreditExhausted();

        $admin = User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
        $user = $this->actingEmployee();

        $this->scan($user);
        $this->scan($user, 'b.jpg');
        $this->scan($user, 'c.jpg');

        // One bell, not three. Repeats of a known outage are noise, and noise gets dismissed.
        Notification::assertSentToTimes($admin, AiScannerUnavailableNotification::class, 1);
    }

    public function test_the_admin_notification_carries_the_providers_own_words(): void
    {
        Notification::fake();
        $this->fakeCreditExhausted();
        $admin = User::factory()->create(['role' => 'superadmin', 'is_active' => true]);

        $this->scan($this->actingEmployee());

        Notification::assertSentTo($admin, AiScannerUnavailableNotification::class, function ($notification) use ($admin) {
            $payload = $notification->toDatabase($admin);
            // Paraphrasing "credit balance is too low" into "an API error" is how an admin ends
            // up checking the key when the problem is the invoice.
            $this->assertStringContainsString('credit balance is too low', $payload['message']);
            $this->assertSame('ai_scanner_unavailable', $payload['event']);
            // The bell JS reads only icon/color/message/url — all four must be present.
            foreach (['icon', 'color', 'message', 'url'] as $key) {
                $this->assertNotEmpty($payload[$key], "Bell payload is missing {$key}.");
            }

            return true;
        });
    }

    public function test_a_deactivated_admin_is_not_notified(): void
    {
        Notification::fake();
        $this->fakeCreditExhausted();
        $former = User::factory()->create(['role' => 'superadmin', 'is_active' => false]);

        $this->scan($this->actingEmployee());

        Notification::assertNotSentTo($former, AiScannerUnavailableNotification::class);
    }

    // ── The admin page must stop claiming everything is fine ─────────────────────────

    public function test_the_settings_page_shows_the_outage_instead_of_a_green_all_clear(): void
    {
        $this->fakeCreditExhausted();
        $this->scan($this->actingEmployee());

        $admin = User::factory()->withTwoFactor()->create(['role' => 'superadmin']);
        $res = $this->actingAs($admin)->get(route('superadmin.claude-api.index'));

        $res->assertStatus(200)
            ->assertSee('AI scanning is failing right now')
            // The provider's reason belongs here — this is the page where it can be acted on.
            ->assertSee('credit balance is too low', false)
            // "OCR is active" was the old claim, and it was green through 36 refused calls.
            ->assertDontSee('OCR is active');
    }

    // ── The multi-file path had the same silence ─────────────────────────────────────

    public function test_a_multi_file_batch_reports_the_outage_rather_than_a_bare_failure(): void
    {
        $this->fakeCreditExhausted();

        $res = $this->actingAs($this->actingEmployee())->postJson(route('user.claims.scan-receipt'), [
            'receipt_files' => [
                UploadedFile::fake()->image('p1.jpg'),
                UploadedFile::fake()->image('p2.jpg'),
            ],
        ]);

        // Before, this came back ok:false with no reason, and the form told the employee to
        // "try adding them one at a time" — advice that cannot possibly help during an outage.
        $res->assertStatus(200)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('unavailable', true);
        $this->assertStringContainsStringIgnoringCase('not with your receipt', (string) $res->json('message'));
    }
}
