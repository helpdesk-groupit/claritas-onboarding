<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwasteCompanyApprover;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecureFileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_unauthenticated_user_cannot_access_files(): void
    {
        $response = $this->get('/secure-file/nric_documents/test.pdf');
        $response->assertRedirect(route('login'));
    }

    public function test_hr_manager_can_access_nric_documents(): void
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Storage::disk('local')->put('nric_documents/test.pdf', 'fake-pdf-content');

        $response = $this->actingAs($user)->get('/secure-file/nric_documents/test.pdf');
        $response->assertStatus(200);
    }

    public function test_employee_cannot_access_other_nric_documents(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($user)->create();
        Storage::disk('local')->put('nric_documents/other-person.pdf', 'fake-pdf-content');

        $response = $this->actingAs($user)->get('/secure-file/nric_documents/other-person.pdf');
        $response->assertStatus(403);
    }

    public function test_employee_can_access_own_nric_file(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($user)->create([
            'nric_file_paths' => ['nric_documents/my-nric.pdf'],
        ]);
        Storage::disk('local')->put('nric_documents/my-nric.pdf', 'fake-pdf-content');

        $response = $this->actingAs($user)->get('/secure-file/nric_documents/my-nric.pdf');
        $response->assertStatus(200);
    }

    public function test_it_manager_can_access_aarf_files(): void
    {
        $user = User::factory()->itManager()->withTwoFactor()->create();
        Storage::disk('local')->put('aarfs/form.pdf', 'fake-pdf-content');

        $response = $this->actingAs($user)->get('/secure-file/aarfs/form.pdf');
        $response->assertStatus(200);
    }

    public function test_it_intern_cannot_access_contracts(): void
    {
        $user = User::factory()->itIntern()->create();
        Storage::disk('local')->put('employee_contracts/contract.pdf', 'fake-content');

        $response = $this->actingAs($user)->get('/secure-file/employee_contracts/contract.pdf');
        $response->assertStatus(403);
    }

    public function test_path_traversal_is_blocked(): void
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Storage::disk('local')->put('nric_documents/test.pdf', 'fake-pdf-content');

        $response = $this->actingAs($user)->get('/secure-file/nric_documents/../.env');
        // Path traversal chars are stripped, resulting path doesn't match a valid directory
        $response->assertStatus(404);
    }

    public function test_unknown_directory_is_denied(): void
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Storage::disk('local')->put('secret_stuff/data.txt', 'content');

        $response = $this->actingAs($user)->get('/secure-file/secret_stuff/data.txt');
        $response->assertStatus(403);
    }

    public function test_hr_executive_can_access_education_certificates(): void
    {
        $user = User::factory()->hrExecutive()->withTwoFactor()->create();
        Storage::disk('local')->put('education_certificates/cert.pdf', 'fake-content');

        $response = $this->actingAs($user)->get('/secure-file/education_certificates/cert.pdf');
        $response->assertStatus(200);
    }

    public function test_hr_manager_can_access_claim_receipts(): void
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Storage::disk('local')->put('claim_receipts/receipt.pdf', 'fake-content');

        $response = $this->actingAs($user)->get('/secure-file/claim_receipts/receipt.pdf');
        $response->assertStatus(200);
    }

    /**
     * A named e-waste management approver (ewaste_company_approvers) reads the same quotation/
     * receipt documents the Company Asset Decommissioning review panel embeds inline
     * (decommission._report-preview → _appendix-document, via secure_file_url()). They routinely
     * carry users.role='employee' and hold none of DIRECTORY_PERMISSIONS' listed roles, so
     * without the dynamic canViewDecommissionReports() check the embed 403s for exactly the
     * audience the panel exists to show it to.
     */
    public function test_named_ewaste_management_approver_can_access_quotation_and_receipt_documents(): void
    {
        $approver = User::factory()->create(['role' => 'employee']);
        EwasteCompanyApprover::create(['company' => 'Claritas Asia Sdn Bhd', 'user_id' => $approver->id]);
        Storage::disk('local')->put('ewaste_quotations/quote.pdf', 'fake-content');
        Storage::disk('local')->put('ewaste_receipts/receipt.pdf', 'fake-content');

        $this->actingAs($approver)->get('/secure-file/ewaste_quotations/quote.pdf')->assertStatus(200);
        $this->actingAs($approver)->get('/secure-file/ewaste_receipts/receipt.pdf')->assertStatus(200);
    }

    /** A plain employee with no ewaste_company_approvers row must still be refused. */
    public function test_a_plain_employee_cannot_access_ewaste_quotation_documents(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        Storage::disk('local')->put('ewaste_quotations/quote.pdf', 'fake-content');

        $response = $this->actingAs($user)->get('/secure-file/ewaste_quotations/quote.pdf');
        $response->assertStatus(403);
    }

    /**
     * A non-HR approving manager (e.g. a work-role/IT manager carrying users.role='employee')
     * must be able to view the receipt of a claim routed to them for approval — the bug fixed
     * by SecureFileController::canAccessClaimFile(). HR roles short-circuit before that path,
     * so this case is what proves the claim-level grant works.
     */
    public function test_approving_manager_can_access_claim_receipt(): void
    {
        [, $path, $manager] = $this->makeClaimWithReceipt();

        // The approving manager: NOT an HR role (so canViewAllClaims() is false), linked to the
        // employee record set as the claim's manager_id.
        $managerUser = User::factory()->create(['role' => 'employee']);
        $manager->user_id = $managerUser->id;
        $manager->save();

        $response = $this->actingAs($managerUser)->get('/secure-file/'.$path);
        $response->assertStatus(200);
    }

    public function test_unrelated_employee_cannot_access_claim_receipt(): void
    {
        [, $path] = $this->makeClaimWithReceipt();

        // A random employee with no relationship to the claim (not owner, not approver).
        $stranger = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($stranger)->create();

        $response = $this->actingAs($stranger)->get('/secure-file/'.$path);
        $response->assertStatus(403);
    }

    /** Build an owned claim with one receipt item on disk; returns [claim, receiptPath, managerEmployee]. */
    private function makeClaimWithReceipt(): array
    {
        $owner = Employee::factory()->create();
        $manager = Employee::factory()->create(['work_role' => 'manager']); // the claim's approver

        $category = ExpenseCategory::create([
            'name' => 'Test', 'code' => 'TST-'.uniqid(), 'gl_code' => '900-000', 'rate_type' => 'receipt',
        ]);

        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id,
            'manager_id' => $manager->id,
            'year' => 2026, 'month' => 6,
            'claim_number' => 'EC-2026-06-'.random_int(8000, 8999),
            'title' => 'Test claim', 'status' => 'manager_approved',
        ]);

        $path = 'claim_receipts/'.$owner->id.'/2026-06/receipt-'.uniqid().'.png';
        $claim->items()->create([
            'expense_category_id' => $category->id,
            'expense_date' => '2026-06-04',
            'description' => 'Test item',
            'amount' => 10, 'total_with_gst' => 10,
            'receipt_path' => $path,
        ]);
        Storage::disk('local')->put($path, 'fake-receipt');

        return [$claim->fresh(), $path, $manager];
    }
}
