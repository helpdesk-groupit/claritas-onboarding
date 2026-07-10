<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The live (inline) add path guards against claiming the same expense twice via a
 * DIFFERENT image (the SHA-256 receipt-hash dedup only catches the identical file):
 * a line with the same date + description + amount on an active claim is rejected —
 * except for batch (multi-receipt statement) adds, where distinct rows may share them.
 */
class ClaimDuplicateItemTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id,
            'year' => 2026, 'month' => 6,
            'claim_number' => 'EC-2026-06-'.random_int(8000, 8999),
            'title' => 'June', 'event' => 'June', 'status' => 'draft', 'project_client' => 'Internal',
        ]);
        $category = ExpenseCategory::create([
            'name' => 'Test', 'code' => 'TST-'.uniqid(), 'gl_code' => '900-000',
            'rate_type' => 'receipt', 'requires_receipt' => true, 'is_active' => true,
        ]);

        return [$user, $claim, $category];
    }

    private function payload(ExpenseCategory $category, array $over = []): array
    {
        return array_merge([
            'expense_category_id' => $category->id,
            'description' => 'Team lunch',
            'expense_date' => '2026-06-10',
            'amount' => 25,
        ], $over);
    }

    public function test_identical_item_same_date_desc_amount_is_rejected(): void
    {
        [$user, $claim, $category] = $this->fixtures();

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), $this->payload($category))
            ->assertStatus(200)->assertJsonPath('ok', true);

        // Same date + description + amount again (e.g. a different photo of the same receipt) → blocked.
        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), $this->payload($category));
        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('already exists', (string) $res->json('errors.description'));
    }

    public function test_batch_add_bypasses_the_duplicate_guard(): void
    {
        [$user, $claim, $category] = $this->fixtures();

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), $this->payload($category))
            ->assertStatus(200);

        // Batch (statement) add: distinct rows may legitimately share date/description/amount → allowed.
        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), $this->payload($category, ['batch' => 1]))
            ->assertStatus(200)->assertJsonPath('ok', true);
    }

    private function mileageCategory(): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Petrol', 'code' => 'PET-'.uniqid(), 'gl_code' => config('claims.mileage.gl_code'),
            'rate_type' => 'per_km', 'requires_receipt' => false, 'is_active' => true,
        ]);
    }

    public function test_mileage_duplicate_asks_for_confirmation_before_adding(): void
    {
        [$user, $claim] = $this->fixtures();
        $mileage = $this->mileageCategory();
        $trip = [
            'expense_category_id' => $mileage->id,
            'description' => 'Office → Client A',
            'expense_date' => '2026-06-10',
            'c_km' => 10, 'c_vehicle' => 'car',
        ];

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), $trip)
            ->assertStatus(200)->assertJsonPath('ok', true);

        // Same route + date + distance again → NOT added; the server asks to confirm first.
        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), $trip);
        $res->assertStatus(200)->assertJsonPath('ok', false)->assertJsonPath('needs_confirm', true);
        $this->assertNotEmpty($res->json('warning'));
        $this->assertEquals(1, $claim->fresh()->items()->count()); // nothing added yet

        // Re-submit confirmed → the repeat trip is added.
        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), array_merge($trip, ['confirm_duplicate' => 1]))
            ->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertEquals(2, $claim->fresh()->items()->count());
    }

    public function test_deleting_one_mileage_trip_keeps_the_sibling_sharing_the_map(): void
    {
        [$user, $claim] = $this->fixtures();
        $mileage = $this->mileageCategory();
        // Two trips backed by the SAME map screenshot (same receipt_hash), both mileage (unit=km).
        $base = [
            'expense_claim_id' => $claim->id, 'expense_category_id' => $mileage->id,
            'expense_date' => '2026-06-10', 'amount' => 7.28, 'gst_amount' => 0, 'total_with_gst' => 7.28,
            'unit' => 'km', 'receipt_hash' => 'SAME-MAP-HASH-123',
        ];
        $a = ExpenseClaimItem::create(array_merge($base, ['description' => 'Travelling']));
        $b = ExpenseClaimItem::create(array_merge($base, ['description' => 'Travel back']));

        // Deleting one mileage trip must NOT take its sibling with it (unlike a statement scan).
        $this->actingAs($user)->deleteJson(route('user.claims.inline-remove-item', $a))->assertStatus(200);

        $this->assertDatabaseMissing('expense_claim_items', ['id' => $a->id]);
        $this->assertDatabaseHas('expense_claim_items', ['id' => $b->id]);
    }
}
