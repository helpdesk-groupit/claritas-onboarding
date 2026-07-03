<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimReceiptMonthGuardTest extends TestCase
{
    use RefreshDatabase;

    private function draftClaim(User $user, Employee $owner): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $owner->id,
            'year' => 2026, 'month' => 6,
            'claim_number' => 'EC-2026-06-'.random_int(8000, 8999),
            'title' => 'June claim', 'event' => 'June claim', 'status' => 'draft',
            'project_client' => 'Internal',
        ]);
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Test', 'code' => 'TST-'.uniqid(), 'gl_code' => '900-000',
            'rate_type' => 'receipt', 'requires_receipt' => true, 'is_active' => true,
        ]);
    }

    public function test_receipt_from_another_month_is_rejected_with_guidance(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        // An April receipt added to a June claim → rejected, polite message names both months.
        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'April taxi',
            'expense_date' => '2026-04-10',
            'amount' => 25,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('June 2026', $res->json('message'));
        $this->assertStringContainsString('April 2026', $res->json('message'));
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'April taxi']);
    }

    public function test_receipt_from_the_claim_month_is_accepted(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'June taxi',
            'expense_date' => '2026-06-10',
            'amount' => 25,
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'June taxi']);
    }
}
