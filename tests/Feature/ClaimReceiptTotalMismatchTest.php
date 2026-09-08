<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * When is_single_receipt collapses several product-row items into one, the merged amount is
 * the model's OWN separate read of the printed grand total (receipt_total) rather than the
 * summed rows — see collapseSingleReceiptItems(). That preference is right on a discounted
 * receipt (the rows are the unreliable side), but it also means a straight vision misread of
 * the total line sails through with nothing to catch it: a real AEON Big receipt (2026-09-08)
 * had two items summing to RM19.66 while the model's receipt_total came back RM13.03 — nothing
 * on the page explains a RM6.63 gap. This pins the cross-check added for that: a gap of more
 * than a couple of cents between receipt_total and the summed rows sets the document's "issue",
 * which routes the claimant to the confirm/review table instead of an instant, unverified
 * auto-fill (see resources/views/user/claims/index.blade.php's `d.issue || !d.amount || !okDate`
 * gate). It's a review prompt, not a rejection — the merged amount still stays receipt_total.
 */
class ClaimReceiptTotalMismatchTest extends TestCase
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

    /** The exact shape of the real AEON Big receipt: two items summing to RM19.66, total misread as RM13.03. */
    private function aeonItems(): array
    {
        return [
            ['amount' => 7.76, 'date' => '2026-08-19', 'vendor' => 'AEON BIG', 'item_description' => 'C-MANGO-DRAGON', 'category' => null, 'tax_amount' => 0],
            ['amount' => 11.90, 'date' => '2026-08-19', 'vendor' => 'AEON BIG', 'item_description' => 'CH STRAWBERRY 250G', 'category' => null, 'tax_amount' => 0],
        ];
    }

    public function test_a_receipt_total_that_does_not_match_the_summed_items_is_flagged_for_review(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => null, 'map_multi_routes' => false,
            'items' => $this->aeonItems(),
            'account_holder' => null, 'issuer' => null, 'issue' => null,
            'is_single_receipt' => true, 'receipt_total' => 13.03,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('aeon.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true)->assertJsonPath('multi', false);
        // The merge still trusts receipt_total for the amount itself — the fix is the flag, not a
        // silent correction to whichever number the code guesses is more likely to be right.
        $this->assertEquals(13.03, $res->json('amount'));
        $issue = (string) $res->json('issue');
        $this->assertStringContainsString('13.03', $issue);
        $this->assertStringContainsString('19.66', $issue);
    }

    public function test_a_receipt_total_that_matches_the_summed_items_is_not_flagged(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => null, 'map_multi_routes' => false,
            'items' => $this->aeonItems(),
            'account_holder' => null, 'issuer' => null, 'issue' => null,
            'is_single_receipt' => true, 'receipt_total' => 19.66,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('aeon.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertEquals(19.66, $res->json('amount'));
        $this->assertNull($res->json('issue'));
    }

    /** A one-cent rounding adjustment (subtotal 19.66 → total 19.65) is ordinary and must not trip the flag. */
    public function test_a_one_cent_rounding_gap_is_not_flagged(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => null, 'map_multi_routes' => false,
            'items' => $this->aeonItems(),
            'account_holder' => null, 'issuer' => null, 'issue' => null,
            'is_single_receipt' => true, 'receipt_total' => 19.65,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('aeon.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertEquals(19.65, $res->json('amount'));
        $this->assertNull($res->json('issue'));
    }

    /** The model's own reason (e.g. "blurry") must win — the mismatch note never overwrites it. */
    public function test_the_models_own_issue_is_not_overwritten_by_the_mismatch_note(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => null, 'map_multi_routes' => false,
            'items' => $this->aeonItems(),
            'account_holder' => null, 'issuer' => null,
            'issue' => 'The photo is too blurry to read some of the lines clearly.',
            'is_single_receipt' => true, 'receipt_total' => 13.03,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('aeon.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertSame('The photo is too blurry to read some of the lines clearly.', $res->json('issue'));
    }

    /** No receipt_total at all (model couldn't find a total line) falls back to the sum — no comparison possible, no flag. */
    public function test_a_null_receipt_total_is_not_flagged_and_falls_back_to_the_sum(): void
    {
        $user = $this->actingEmployee();
        $this->fakeVision([
            'map' => null, 'map_multi_routes' => false,
            'items' => $this->aeonItems(),
            'account_holder' => null, 'issuer' => null, 'issue' => null,
            'is_single_receipt' => true, 'receipt_total' => null,
        ]);

        $res = $this->actingAs($user)->postJson(route('user.claims.scan-receipt'), [
            'receipt' => UploadedFile::fake()->image('aeon.jpg'),
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertEquals(19.66, $res->json('amount'));
        $this->assertNull($res->json('issue'));
    }
}
