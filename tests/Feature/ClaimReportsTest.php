<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimReportsTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        return User::factory()->financeManager()->withTwoFactor()->create();
    }

    private function category(string $gl, string $name): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => $name, 'code' => 'C-'.uniqid(), 'gl_code' => $gl,
            'rate_type' => 'receipt', 'is_active' => true,
        ]);
    }

    private function claimWithItem(string $status, string $company, string $name, ExpenseCategory $cat, string $desc, float $amount, int $month = 6): ExpenseClaim
    {
        $owner = Employee::factory()->create(['company' => $company, 'full_name' => $name]);
        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id, 'year' => 2026, 'month' => $month,
            'claim_number' => 'EC-2026-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'-'.random_int(1000, 9999),
            'title' => 'x', 'status' => $status,
        ]);
        $claim->items()->create([
            'expense_category_id' => $cat->id, 'expense_date' => '2026-06-10',
            'description' => $desc, 'amount' => $amount, 'total_with_gst' => $amount,
        ]);

        return $claim;
    }

    public function test_finance_hr_and_superadmin_can_view_others_cannot(): void
    {
        // Allowed: finance, HR manager/executive, superadmin.
        foreach ([
            $this->financeUser(),
            User::factory()->hrManager()->withTwoFactor()->create(),
            User::factory()->hrExecutive()->withTwoFactor()->create(),
            User::factory()->superadmin()->withTwoFactor()->create(),
        ] as $user) {
            Employee::factory()->withUser($user)->create();
            $this->actingAs($user)->get(route('finance.claim-reports'))->assertStatus(200);
        }

        // Not allowed: a regular employee.
        $emp = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($emp)->create();
        $this->actingAs($emp)->get(route('finance.claim-reports'))->assertStatus(403);
    }

    public function test_shows_only_claims_approved_by_manager_and_hr(): void
    {
        $cat = $this->category('932-000', 'Medical Fees');
        $this->claimWithItem('hr_approved', 'Acme', 'Alice Approved', $cat, 'APPROVED LINE', 50);
        $this->claimWithItem('paid', 'Acme', 'Percy Paid', $cat, 'PAID LINE', 30);
        $this->claimWithItem('manager_approved', 'Acme', 'Bob Pending', $cat, 'PENDING LINE', 20);
        $this->claimWithItem('submitted', 'Acme', 'Sam Submitted', $cat, 'SUBMITTED LINE', 10);

        $res = $this->actingAs($this->financeUser())->get(route('finance.claim-reports', ['year' => 2026]));
        $res->assertStatus(200);
        $res->assertSee('APPROVED LINE');
        $res->assertSee('PAID LINE');
        $res->assertSee('Alice Approved');
        $res->assertSee('932-000');
        // Not yet fully approved — must not appear.
        $res->assertDontSee('PENDING LINE');
        $res->assertDontSee('SUBMITTED LINE');
        $res->assertDontSee('Bob Pending');
    }

    public function test_category_filter_limits_rows(): void
    {
        $medical = $this->category('932-000', 'Medical Fees');
        $transport = $this->category('914(b)-000', 'Transportation');
        $this->claimWithItem('hr_approved', 'Acme', 'Alice', $medical, 'MEDICAL LINE', 50);
        $this->claimWithItem('hr_approved', 'Acme', 'Alice', $transport, 'TRANSPORT LINE', 25);

        $res = $this->actingAs($this->financeUser())->get(route('finance.claim-reports', ['year' => 2026, 'category' => $medical->id]));
        $res->assertStatus(200);
        $res->assertSee('MEDICAL LINE');
        $res->assertDontSee('TRANSPORT LINE');
    }
}
