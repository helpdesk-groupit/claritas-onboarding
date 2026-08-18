<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Receipt files are SHARED, not owned.
 *
 * makeCorrection() copies receipt_path / receipt_paths straight into the correction's items,
 * so a correction and the frozen original it corrects point at the same bytes on disk. Every
 * delete path used to assume sole ownership, which meant fixing a rejected claim could destroy
 * the evidence attached to the claim that is deliberately kept as history. Production carries
 * two live correction chains sharing 10 files this way, one side of each being an hr_approved
 * (paid) report — so this is about an audit trail, not tidiness.
 */
class ClaimReceiptSharingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'employee']);
        $this->employee = Employee::factory()->withUser($this->user)->create(['company' => 'Enlinea Sdn. Bhd.']);
        $this->category = ExpenseCategory::create([
            'name' => 'Medical Fees', 'code' => 'C-'.uniqid(), 'gl_code' => '932-000',
            'rate_type' => 'receipt', 'is_active' => true,
        ]);
    }

    private function receipt(string $name = 'r.png'): string
    {
        $path = 'claim_receipts/'.$this->employee->id.'/'.now()->format('Y-m').'/'.$name;
        Storage::disk('local')->put($path, 'PNGDATA-'.$name);

        return $path;
    }

    private function claim(string $status, ?int $correctionOf = null): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $this->employee->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'claim_number' => 'EC-'.now()->format('Y-m').'-'.random_int(1000, 9999),
            'title' => 'x', 'event' => 'Test event', 'status' => $status,
            'correction_of_id' => $correctionOf,
        ]);
    }

    private function item(ExpenseClaim $claim, ?string $receipt, array $extra = []): ExpenseClaimItem
    {
        return $claim->items()->create(array_merge([
            'expense_category_id' => $this->category->id, 'expense_date' => now()->startOfMonth()->toDateString(),
            'description' => 'Consultation', 'amount' => 50, 'total_with_gst' => 50,
            'receipt_path' => $receipt, 'manager_status' => 'pending',
        ], $extra));
    }

    /** The core guarantee: a shared file survives deleting one of the claims that cite it. */
    public function test_deleting_a_correction_draft_keeps_the_original_claims_receipt(): void
    {
        $shared = $this->receipt('shared.png');

        $original = $this->claim('manager_rejected');
        $this->item($original, $shared);

        $correction = $this->claim('draft', $original->id);
        $this->item($correction, $shared);   // exactly what makeCorrection() does

        $this->actingAs($this->user)
            ->delete(route('user.claims.discard', $correction))
            ->assertRedirect();

        $this->assertDatabaseMissing('expense_claims', ['id' => $correction->id]);
        $this->assertTrue(
            Storage::disk('local')->exists($shared),
            'Deleting the correction destroyed the frozen original claim’s receipt.'
        );
    }

    /** ...and the file IS removed once the last claim citing it is gone. */
    public function test_deleting_the_only_claim_that_cites_a_receipt_does_remove_the_file(): void
    {
        $solo = $this->receipt('solo.png');
        $draft = $this->claim('draft');
        $this->item($draft, $solo);

        $this->actingAs($this->user)
            ->delete(route('user.claims.discard', $draft))
            ->assertRedirect();

        $this->assertFalse(
            Storage::disk('local')->exists($solo),
            'An unshared receipt should be cleaned up with its claim.'
        );
    }

    /** Replacing a receipt on a correction must not strip the original of its evidence. */
    public function test_replacing_a_receipt_on_a_correction_keeps_the_originals_copy(): void
    {
        Storage::fake('local');
        $shared = 'claim_receipts/'.$this->employee->id.'/'.now()->format('Y-m').'/shared.png';
        Storage::disk('local')->put($shared, 'ORIGINAL-BYTES');

        $original = $this->claim('manager_rejected');
        $this->item($original, $shared);

        $correction = $this->claim('draft', $original->id);
        $item = $this->item($correction, $shared, ['is_locked' => false]);

        $resp = $this->actingAs($this->user)->put(route('user.claims.update-item', $item), [
            'expense_category_id' => $this->category->id,
            'expense_date' => now()->startOfMonth()->toDateString(),
            'description' => 'Consultation (corrected)',
            // Required for anyone outside the Sales team (updateItem's $projectRequired), so
            // without it the request bounces on validation and never reaches the delete path
            // this test exists to exercise.
            'project_client' => 'Acme Holdings',
            'amount' => 50,
            'gst_amount' => 0,
            'total_with_gst' => 50,
            'receipt' => UploadedFile::fake()->image('better.png', 40, 40),
        ]);
        $resp->assertSessionHasNoErrors();
        $this->assertNull($resp->getSession()->get('error'), 'updateItem bounced: '.(string) $resp->getSession()->get('error'));

        $this->assertTrue(
            Storage::disk('local')->exists($shared),
            'Replacing the correction’s receipt deleted the frozen original’s copy.'
        );
        $this->assertSame('ORIGINAL-BYTES', Storage::disk('local')->get($shared));

        // And the correction genuinely moved to its own new file.
        $item->refresh();
        $this->assertNotSame($shared, $item->receipt_path, 'The correction should point at the new upload.');
    }

    /** The guard must look inside the receipt_paths / supporting_paths JSON arrays too. */
    public function test_a_file_cited_only_inside_a_json_array_still_counts_as_referenced(): void
    {
        $shared = $this->receipt('multi.png');

        $keeper = $this->claim('hr_approved');
        // Cited ONLY via the extras array, never as the primary receipt_path.
        $this->item($keeper, null, ['receipt_paths' => [$shared]]);

        $draft = $this->claim('draft');
        $this->item($draft, $shared);

        $this->actingAs($this->user)
            ->delete(route('user.claims.discard', $draft))
            ->assertRedirect();

        $this->assertTrue(
            Storage::disk('local')->exists($shared),
            'A file referenced only through receipt_paths was treated as unreferenced.'
        );
    }
}
