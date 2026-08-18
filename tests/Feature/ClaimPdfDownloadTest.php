<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The per-claim "Download PDF" button (route user.claims.pdf), reachable from HR's claim page,
 * a reviewer's claim page, and the employee's own Print / PDF form page.
 *
 * The batch ZIP export has had its own suite since production ran out of memory inside it on
 * 2026-08-18; the SINGLE download never did, even though it renders through the same dompdf
 * path and pays the same per-image cost. One 5-megapixel receipt is ~22 MB of GD buffer, a
 * claim may carry several, and the live pool is a stock 128 MB — so the failure the batch hit
 * is reachable from one claim, and would surface as a bare 500 on a button HR press daily.
 */
class ClaimPdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function hrManager(): User
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Medical Fees', 'code' => 'C-'.uniqid(),
            'gl_code' => '932-000', 'rate_type' => 'receipt', 'is_active' => true,
        ]);
    }

    /**
     * An hr_approved claim owned by a fresh employee, optionally carrying receipts.
     *
     * @param  array<int, string>  $receipts
     */
    private function approvedClaim(array $receipts = [], ?Employee $owner = null, ?User $manager = null): ExpenseClaim
    {
        $owner ??= Employee::factory()->create([
            'company' => 'Enlinea Sdn. Bhd.', 'full_name' => 'Alice Approved',
        ]);

        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id, 'year' => 2026, 'month' => 6,
            'claim_number' => 'EC-2026-06-'.random_int(1000, 9999),
            'title' => 'x', 'event' => 'AMD Editorial', 'status' => 'hr_approved',
            'company' => 'Enlinea Sdn. Bhd.',
            'manager_id' => $manager?->employee?->id,
            'submitted_at' => '2026-06-05 09:00:00',
            'manager_approved_at' => '2026-06-10 09:00:00',
            'hr_approved_at' => '2026-06-20 09:00:00',
            'processed_at' => '2026-06-25 09:00:00',
        ]);

        $cat = $this->category();
        foreach ($receipts ?: [null] as $i => $receipt) {
            $claim->items()->create([
                'expense_category_id' => $cat->id,
                'expense_date' => '2026-06-0'.($i + 1),
                'description' => 'Consultation '.($i + 1),
                'amount' => 50, 'total_with_gst' => 50,
                'receipt_path' => $receipt,
                'approver_id' => $manager?->employee?->id,
            ]);
        }

        return $claim;
    }

    /**
     * A real photo-sized receipt on the private disk.
     *
     * Two different costs are being reproduced here, and they are driven by different things:
     * the DECODE cost dompdf pays is w*h*4 bytes of GD buffer regardless of how well the file
     * compresses, while the size a receipt adds to the finished PDF depends on the bytes. A
     * blocky pattern deflates to almost nothing, so $noisy fills every pixel independently
     * when the test is measuring what the embed contributes rather than what it costs to decode.
     */
    private function receiptImage(string $key, int $w = 900, int $h = 1200, bool $noisy = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        if ($noisy) {
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    imagesetpixel($im, $x, $y, (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255));
                }
            }
        } else {
            for ($y = 0; $y < $h; $y += 4) {
                for ($x = 0; $x < $w; $x += 4) {
                    imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, imagecolorallocate($im, ($x + $y) % 256, ($x * 7) % 256, ($y * 13) % 256));
                }
            }
        }
        ob_start();
        imagepng($im, null, 1);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        $path = 'claim_receipts/test/'.$key.'.png';
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /**
     * dompdf's download() returns the whole document as the response body — a plain Response,
     * not a streamed or file one, so the bytes are simply the content.
     */
    private function pdfBytes($response): string
    {
        return (string) $response->getContent();
    }

    public function test_the_owner_can_download_their_approved_claim(): void
    {
        $owner = Employee::factory()->create(['company' => 'Enlinea Sdn. Bhd.', 'full_name' => 'Alice Approved']);
        $user = User::factory()->create(['role' => 'employee']);
        $owner->update(['user_id' => $user->id]);

        $claim = $this->approvedClaim([], $owner->fresh());

        $response = $this->actingAs($user)->get(route('user.claims.pdf', $claim));

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $this->pdfBytes($response), 'The download is not a PDF document.');
    }

    public function test_hr_can_download_any_approved_claim(): void
    {
        $claim = $this->approvedClaim();

        $response = $this->actingAs($this->hrManager())->get(route('user.claims.pdf', $claim));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $bytes = $this->pdfBytes($response);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(10000, strlen($bytes), 'The rendered claim form is implausibly small.');
    }

    /** The download is offered on a page reviewers reach, so the reviewer path must work too. */
    public function test_the_approving_manager_can_download_the_claim(): void
    {
        $manager = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($manager)->create(['work_role' => 'manager']);

        $claim = $this->approvedClaim([], null, $manager->fresh());

        $this->actingAs($manager)->get(route('user.claims.pdf', $claim))->assertStatus(200);
    }

    public function test_an_unrelated_employee_cannot_download_someone_elses_claim(): void
    {
        $claim = $this->approvedClaim();

        $stranger = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($stranger)->create();

        $this->actingAs($stranger)->get(route('user.claims.pdf', $claim))->assertStatus(403);
    }

    public function test_a_guest_is_sent_to_login_rather_than_handed_the_document(): void
    {
        $claim = $this->approvedClaim();

        $this->get(route('user.claims.pdf', $claim))->assertRedirect(route('login'));
    }

    /** The file must arrive named like the original form, or it is unfilable once saved. */
    public function test_the_download_carries_the_form_filename(): void
    {
        $claim = $this->approvedClaim();

        $response = $this->actingAs($this->hrManager())->get(route('user.claims.pdf', $claim));

        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', (string) $disposition);
        $this->assertStringContainsString($claim->pdfFilename(), (string) $disposition);
        $this->assertStringContainsString('AMD Editorial', (string) $disposition);
    }

    /** The receipts are the evidence — a form that renders without them is not the report. */
    public function test_receipts_are_embedded_in_the_downloaded_document(): void
    {
        $withoutReceipt = $this->approvedClaim();
        $withReceipt = $this->approvedClaim([$this->receiptImage('embedded', 500, 650, true)]);

        $hr = $this->hrManager();
        $bareResponse = $this->actingAs($hr)->get(route('user.claims.pdf', $withoutReceipt));
        $fullResponse = $this->actingAs($hr)->get(route('user.claims.pdf', $withReceipt));

        $bare = strlen($this->pdfBytes($bareResponse));
        $full = strlen($this->pdfBytes($fullResponse));

        $this->assertGreaterThan(
            $bare + 200000,
            $full,
            'The claim with a receipt is no bigger than the one without — the image was not embedded.'
        );
    }

    /**
     * A receipt dompdf cannot embed must SAY so on the form. Silently omitting it would produce
     * a claim report that looks complete while the evidence for the line is simply missing.
     */
    public function test_a_non_image_receipt_is_named_rather_than_dropped(): void
    {
        Storage::disk('local')->put('claim_receipts/test/scan.pdf', '%PDF-1.4 not an image');
        $claim = $this->approvedClaim(['claim_receipts/test/scan.pdf']);

        $response = $this->actingAs($this->hrManager())->get(route('user.claims.pdf', $claim));
        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $this->pdfBytes($response));

        $claim->loadMissing('items.category', 'employee');
        $html = view('user.claims.report-pdf', [
            'claim' => $claim,
            'company' => \App\Models\Company::forName($claim->resolvedCompany()),
            'items' => $claim->items,
        ])->render();

        $this->assertStringContainsString('not embeddable in this PDF', $html);
    }

    /**
     * The failure that took the batch export down, reached from a SINGLE claim.
     *
     * The live pool is a stock 128 MB and each embedded receipt costs w*h*4 bytes of transient
     * GD buffer inside dompdf. A claim with a handful of photographed receipts therefore has to
     * survive a stingy pool, or HR gets a bare 500 on a button they press every day.
     */
    public function test_a_claim_with_several_photo_receipts_survives_a_stingy_memory_pool(): void
    {
        $receipts = [];
        for ($i = 0; $i < 4; $i++) {
            $receipts[] = $this->receiptImage('big'.$i, 1600, 2000);
        }
        $claim = $this->approvedClaim($receipts);
        $hr = $this->hrManager();

        $original = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '128M');
            $response = $this->actingAs($hr)->get(route('user.claims.pdf', $claim));
            $response->assertStatus(200);
            $this->assertStringStartsWith('%PDF', $this->pdfBytes($response));
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }

    /**
     * The ceiling is raised for the render, but a flat ini_set is a ceiling as often as it is a
     * floor. Same rule the ZIP export and the Email Workflow sweep already follow.
     */
    public function test_the_memory_ceiling_is_raised_as_a_floor_never_lowered(): void
    {
        $claim = $this->approvedClaim();
        $hr = $this->hrManager();
        $original = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '128M');
            $this->actingAs($hr)->get(route('user.claims.pdf', $claim))->assertStatus(200);
            $this->assertSame('512M', ini_get('memory_limit'), 'A stingy pool should have been raised for the render.');

            ini_set('memory_limit', '1G');
            $this->actingAs($hr)->get(route('user.claims.pdf', $claim))->assertStatus(200);
            $this->assertSame('1G', ini_get('memory_limit'), 'A generous limit must not be lowered.');

            ini_set('memory_limit', '-1');
            $this->actingAs($hr)->get(route('user.claims.pdf', $claim))->assertStatus(200);
            $this->assertSame('-1', ini_get('memory_limit'), 'An unlimited limit must not be capped.');
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }
}
