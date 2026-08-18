<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A mileage/Petrol screenshot has been misread when the uploaded image is actually a
 * collage of two unrelated routes (e.g. two separate Google Maps directions panels
 * pasted into one screenshot) — the model folded both distances into one route_stops
 * reading, silently overstating one trip and dropping the other. The map prompt now
 * asks the model to flag that case (map_multi_routes) instead of merging it, and the
 * controller refuses to auto-fill when it does, asking for one screenshot per trip.
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
            $p = new \ReflectionProperty(\App\Services\ClaimReceiptOcrService::class, $prop);
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

    public function test_a_collage_of_two_routes_is_refused_with_a_split_message(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => [
                'map_multi_routes' => true,
                // The model is told to null these out on a flagged collage — simulate it
                // still populating them anyway, to prove the server forces them null too.
                'distance_km' => 17.7,
                'route_from' => 'HRDF', 'route_to' => 'Jaya One',
                'route_stops' => ['HRDF', 'Jaya One'],
            ],
            'items' => [], 'account_holder' => null, 'issuer' => null,
            'is_single_receipt' => false, 'receipt_total' => null,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('routes.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', false);
        $this->assertStringContainsString('more than one route', (string) $res->json('message'));
        // Never surface a combined/guessed distance for a flagged collage.
        $this->assertNull($res->json('distance_km'));
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
