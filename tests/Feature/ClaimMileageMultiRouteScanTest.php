<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\ClaimReceiptOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * How many TRIPS is this image? The two ways of getting that wrong are opposite, and both
 * cost the employee money, so they are pinned together here.
 *
 * TOO FEW: the uploaded image is really a collage of two unrelated routes (two separate Google
 * Maps directions panels pasted into one screenshot) and the model folds both distances into
 * one route_stops reading — silently overstating one trip and dropping the other. The map
 * prompt asks the model to flag that case (map_multi_routes) instead of merging it, and the
 * controller refuses to auto-fill when it does.
 *
 * TOO MANY: one genuine journey through several stops gets read, or described to the employee,
 * as though it were several trips. Reported 2026-09-10 — a single route office → errand → home
 * came back ending at the middle stop, and the form's own note told the employee to submit the
 * rest separately. A trip is a journey, not a pair of addresses: it runs from the first field
 * to the last however many stops sit between them, and it is ONE claim item on the ONE total
 * the map prints for it.
 */
class ClaimMileageMultiRouteScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetClaudeMemo();
        config(['claims.ocr.enabled' => true, 'claims.ocr.provider' => 'anthropic', 'claims.ocr.api_key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        $this->forgetClaudeMemo();
        parent::tearDown();
    }

    /** Same reason as EwasteAmountOcrTest: the ClaudeApiSetting lookup is memoised in a static. */
    private function forgetClaudeMemo(): void
    {
        foreach (['claudeMemo' => null, 'claudeMemoLoaded' => false] as $prop => $value) {
            $p = new \ReflectionProperty(ClaimReceiptOcrService::class, $prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }

    private function fakeVision(array $json): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);
    }

    private function actingEmployee(): User
    {
        $user = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    /**
     * The shape a REAL Anthropic reply actually used, logged live 2026-08-18: "map_multi_routes"
     * came back as a TOP-LEVEL key, a sibling of "map", not nested inside it — even though the
     * original prompt wording asked for it inside the "map object" sentence. normalizeMap() used
     * to only ever look for it inside $json['map'], so this exact reply's true flag was silently
     * never seen, and the scan fell through to the generic "couldn't read it" message instead of
     * the multi-route one built for exactly this case. This is the real bug, not a hypothetical.
     */
    public function test_a_collage_of_two_routes_is_refused_with_a_split_message(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => [
                // The model is told to null these out on a flagged collage — simulate it
                // still populating them anyway, to prove the server forces them null too.
                'distance_km' => 17.7,
                'route_from' => 'HRDF', 'route_to' => 'Jaya One',
                'route_stops' => ['HRDF', 'Jaya One'],
            ],
            'map_multi_routes' => true, // TOP-LEVEL, a sibling of "map" — the real observed shape.
            'items' => [], 'account_holder' => null, 'issuer' => null, 'issue' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('routes.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', false);
        $this->assertStringContainsString('two separate journeys', (string) $res->json('message'));
        // Never surface a combined/guessed distance for a flagged collage.
        $this->assertNull($res->json('distance_km'));
    }

    /** Belt-and-braces: a reply that DOES nest the flag inside "map" must still be honoured. */
    public function test_the_flag_is_also_honoured_when_nested_inside_map(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => [
                'map_multi_routes' => true,
                'distance_km' => null, 'route_from' => null, 'route_to' => null, 'route_stops' => null,
            ],
            'items' => [], 'account_holder' => null, 'issuer' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('routes-nested.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', false);
        $this->assertStringContainsString('two separate journeys', (string) $res->json('message'));
    }

    public function test_an_unreadable_receipt_surfaces_the_models_own_reason(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => null,
            'items' => [], 'account_holder' => null, 'issuer' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
            'issue' => 'The photo is too blurry to read the amount or date clearly.',
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('blurry.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertSame('The photo is too blurry to read the amount or date clearly.', $res->json('issue'));
    }

    /**
     * The refusal is the ONE thing on screen when the collage flag fires, so it has to be able to
     * tell a wrongly-flagged multi-stop trip what to do. The old wording ("more than one route")
     * was read by an employee whose single journey merely passed through a stop as a verdict on
     * their perfectly ordinary screenshot.
     */
    public function test_the_refusal_says_a_multi_stop_route_is_not_this_case(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => ['map_multi_routes' => true],
            'items' => [], 'account_holder' => null, 'issuer' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('collage.jpg'),
        ]);

        $msg = (string) $res->json('message');
        $this->assertStringContainsString('one screenshot per trip', $msg);
        $this->assertStringContainsString('several stops is one trip', $msg);
    }

    /**
     * THE REPORTED BUG (2026-09-10). One Google Maps panel, one search box, one route line, one
     * 44.0 km total, three address fields: Enlinea → Menara Tan & Tan → home at USJ 6. The reply
     * listed all three stops in order and then named the MIDDLE one as route_to, so the trip read
     * as ending where the employee had only stopped off — the leg home silently dropped off a
     * claim whose distance covers it, and the employee was told to submit the rest separately.
     *
     * route_from/route_to and route_stops are three separately-answered fields; the ordered list
     * is the one that had to look at every field, so it decides the endpoints. Mutation check:
     * delete the derivation in normalizeMap() and this fails on route_to.
     */
    public function test_a_three_stop_trip_ends_at_its_final_destination(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => [
                'map_multi_routes' => false,
                'distance_km' => 44.0,
                'route_from' => 'Enlinea Sdn Bhd',
                'route_to' => 'Menara Tan & Tan', // the middle stop — the misread being corrected
                'route_stops' => ['Enlinea Sdn Bhd', 'Menara Tan & Tan', 'USJ 6'],
            ],
            'items' => [], 'account_holder' => null, 'issuer' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('one-trip-three-stops.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertSame('Enlinea Sdn Bhd', $res->json('route_from'));
        $this->assertSame('USJ 6', $res->json('route_to'));
        $this->assertSame(['Enlinea Sdn Bhd', 'Menara Tan & Tan', 'USJ 6'], $res->json('route_stops'));
        // One trip, one distance: the map's own total already covers every leg, so it is passed
        // through whole — never split per leg, never re-derived, never refused for having stops.
        $this->assertEquals(44.0, $res->json('distance_km'));
    }

    /**
     * One address is not a route. A single-entry list must not be dressed up as one — deriving
     * endpoints from it would report a trip that starts and ends in the same place, which is a
     * real journey shape (there-and-back) and so would not look wrong to anybody.
     */
    public function test_a_single_address_is_not_treated_as_a_stop_list(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => [
                'map_multi_routes' => false,
                'distance_km' => 12.3,
                'route_from' => 'Jaya One', 'route_to' => 'Suria KLCC',
                'route_stops' => ['Suria KLCC'],
            ],
            'items' => [], 'account_holder' => null, 'issuer' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('two-point.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertNull($res->json('route_stops'));
        $this->assertSame('Jaya One', $res->json('route_from'));
        $this->assertSame('Suria KLCC', $res->json('route_to'));
    }

    /**
     * The prompt is where a SHORT read gets fixed — normalizeMap() can only stop a reply
     * contradicting itself, not put back a field the model never looked at. Assert against the
     * request actually sent, so a rule that stops reaching the model fails here rather than
     * quietly costing employees the last leg of their journeys.
     */
    public function test_the_map_prompt_asks_for_every_stop_and_one_total(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => null, 'items' => [], 'account_holder' => null, 'issuer' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
        ]);

        $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('anything.jpg'),
        ]);

        Http::assertSent(function ($request) {
            $sent = (string) $request->body();
            // Read every field, and the LAST one is where the trip ended.
            $this->assertStringContainsString('READ EVERY ONE', $sent);
            $this->assertStringContainsString('NEVER an intermediate stop', $sent);
            $this->assertStringContainsString('do NOT drop the last leg', $sent);
            // One trip, one total — never a per-leg figure and never a split.
            $this->assertStringContainsString('ALREADY covers every leg', $sent);
            $this->assertStringContainsString('multi-stop route as more than one trip', $sent);
            // …and having several stops is never by itself grounds to call it two trips.
            $this->assertStringContainsString('is ONE trip: answer false', $sent);

            return true;
        });
    }

    public function test_a_genuine_multi_stop_trip_still_auto_fills(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => [
                'map_multi_routes' => false,
                'distance_km' => 24.5,
                'route_from' => 'Home', 'route_to' => 'Home',
                'route_stops' => ['Home', 'Office', 'Client A', 'Home'],
            ],
            'items' => [], 'account_holder' => null, 'issuer' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('trip.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertEquals(24.5, $res->json('distance_km'));
        $this->assertEquals(['Home', 'Office', 'Client A', 'Home'], $res->json('route_stops'));
    }
}
