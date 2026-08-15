<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorDocumentScan;
use App\Models\VendorPaymentSlip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proof of payment for an invoice in the vendor billing register.
 *
 * The through-line: PAID IS EVIDENCE, NOT AN OPINION. The Billing tab's Status column is
 * derived from whether a payment slip exists, so almost everything worth pinning here is a
 * consequence of that one decision — that a posted status changes nothing, that removing a
 * slip un-pays its invoice, that a quotation is never "Pending", and that filing a slip
 * against the wrong bill is caught rather than silently believed.
 */
class VendorPaymentSlipTest extends TestCase
{
    use RefreshDatabase;

    /** Anthropic replies still to be handed out, oldest first. See fakeReading(). */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Storage::fake('local');

        config()->set('vendors.ai.enabled', true);
        config()->set('claims.ocr.enabled', true);
        config()->set('claims.ocr.provider', 'anthropic');
        config()->set('claims.ocr.api_key', 'test-key');
        config()->set('claims.ocr.model', 'claude-haiku-4-5');

        // Registered ONCE, draining a queue, rather than per reading.
        //
        // Http::fake() MERGES stubs instead of replacing them, so a second Http::fake() in
        // the same test leaves the FIRST — by then an exhausted Http::sequence() — still
        // first in line to match. It throws, the service fails open, and the reading comes
        // back empty: a test that files two documents silently gets nothing off the second.
        // A queue behind one stub has no such ordering to get wrong.
        Http::fake(['api.anthropic.com/*' => fn () => $this->nextReply()]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function vendor(array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => 'Acme Rentals Sdn Bhd',
            'vendor_types' => ['rental'],
            'is_active' => true,
        ], $attrs));
    }

    private function invoice(Vendor $vendor, array $attrs = []): VendorBillingDocument
    {
        return VendorBillingDocument::create(array_merge([
            'vendor_id' => $vendor->id,
            'doc_type' => 'invoice',
            'status' => 'received',
            'doc_number' => 'INV-2026-001',
            'total' => 1500.00,
            'currency' => 'MYR',
        ], $attrs));
    }

    /** A real PDF: `valid_file_content` checks magic bytes against the extension. */
    private function pdf(string $name = 'transfer-slip.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>PAYMENT ADVICE</h1>')->output()
        );
    }

    /**
     * Queue the two Anthropic replies one reading consumes: the vision pass that transcribes
     * and summarises, then the text pass that reads the parties and the record fields off
     * that transcript. Call it once per document a test files.
     */
    private function fakeReading(array $summaryPayload, array $detailPayload): void
    {
        $this->replies[] = $summaryPayload;
        $this->replies[] = $detailPayload;
    }

    /**
     * The next queued reply, or a provider failure when nothing is queued.
     *
     * An empty queue answering 5xx is what the fail-open tests rely on, and it is also the
     * honest answer for a call a test never anticipated: the service treats it as an outage,
     * which is exactly what an unstubbed call is.
     *
     * Deliberately UNTYPED: Http::response() hands back a promise, not a Response, and a
     * wrong hint here throws a TypeError that the reader's fail-open catch swallows — every
     * reading silently comes back empty and the failure looks like a broken feature rather
     * than a broken stub.
     */
    private function nextReply()
    {
        $payload = array_shift($this->replies);

        if ($payload === null) {
            return Http::response(['error' => ['message' => 'no reply queued']], 500);
        }

        return Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 800, 'output_tokens' => 200],
        ]);
    }

    /** Upload + read a payment slip, leaving a claimable staging row. */
    private function scan(Vendor $vendor, User $actor, string $token, array $fields = []): void
    {
        $this->fakeReading(
            ['summary' => 'Transfer of RM1,500.00 to Acme Rentals.', 'key_points' => ['RM1,500.00'], 'text' => 'PAYMENT ADVICE …'],
            array_merge([
                'companies_involved' => ['Claritas Sdn Bhd', 'Acme Rentals Sdn Bhd'],
                'paid_amount' => 1500,
                'paid_on' => '2026-08-10',
                'payment_reference' => 'TRX-88213',
                'payment_method' => 'Online transfer',
                'invoice_reference' => 'INV-2026-001',
                'currency' => 'MYR',
            ], $fields)
        );

        $this->actingAs($actor)
            ->post(route('vendors.documents.scan', $vendor), [
                'kind' => 'payment',
                'token' => $token,
                'document' => $this->pdf(),
            ])
            ->assertOk();
    }

    /** Scan and file one, the ordinary way. */
    private function fileSlip(Vendor $vendor, VendorBillingDocument $invoice, User $actor, array $fields = [])
    {
        $token = 'tok-'.str_repeat('p', 12).$invoice->id;
        $this->scan($vendor, $actor, $token, $fields);

        return $this->actingAs($actor)->post(route('vendors.payment-slips.store', $vendor), [
            'vendor_billing_document_id' => $invoice->id,
            'scan_token' => $token,
            'ai_summary' => 'Transfer of RM1,500.00 to Acme Rentals.',
            'companies_involved' => 'Claritas Sdn Bhd, Acme Rentals Sdn Bhd',
        ]);
    }

    // ── Filing one ────────────────────────────────────────────────────────────

    public function test_filing_a_payment_slip_marks_its_invoice_paid(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);

        $this->assertFalse($invoice->isPaid());

        $this->fileSlip($vendor, $invoice, $this->itManager())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('vendors.show', [$vendor, 'tab' => 'billing']));

        $invoice->refresh()->load('paymentSlip');

        $this->assertTrue($invoice->isPaid());
        $this->assertSame('Paid', $invoice->paymentState()['label']);

        // The figures are READ, never typed — none of them was in the request.
        $slip = $invoice->paymentSlip;
        $this->assertSame('1500.00', (string) $slip->paid_amount);
        $this->assertSame('2026-08-10', $slip->paid_on->toDateString());
        $this->assertSame('TRX-88213', $slip->payment_reference);
        $this->assertSame('Online transfer', $slip->payment_method);
        $this->assertSame('INV-2026-001', $slip->invoice_reference);

        Storage::disk('local')->assertExists($slip->file_path);
        $this->assertStringStartsWith('vendor_payment_slips/', $slip->file_path);
    }

    /**
     * The staging row goes and the FILE stays — the record owns it now.
     *
     * That asymmetry is what makes `vendors:prune-document-scans` safe to delete a file with
     * its row: a surviving scan row is by definition an unclaimed upload.
     */
    public function test_saving_consumes_the_staging_row_and_keeps_its_file(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);

        $this->fileSlip($vendor, $invoice, $this->itManager())->assertSessionHasNoErrors();

        $this->assertSame(0, VendorDocumentScan::count());
        Storage::disk('local')->assertExists($invoice->fresh()->paymentSlip->file_path);
    }

    /**
     * The lifecycle status was retired on 2026-08-13 in favour of this evidence. A submit
     * that could still set it would be the one way to assert a bill was settled with no
     * document behind it — on the register whose entire value is provenance.
     */
    public function test_an_invoice_cannot_be_marked_paid_without_a_slip(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);

        $this->actingAs($this->itManager())
            ->put(route('vendors.billing.update', [$vendor, $invoice]), [
                'status' => 'paid',
                'ai_summary' => 'Says here it is paid.',
            ])
            ->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame('received', $invoice->status);
        $this->assertFalse($invoice->isPaid());
        $this->assertSame('Pending', $invoice->paymentState()['label']);

        // And no dropdown to try it with.
        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'billing']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="status"', $html);
    }

    /**
     * A quotation is an offer, not a bill. "Pending" against one would state that we owe
     * money on a document nobody has acted on.
     */
    public function test_a_quotation_carries_no_payment_status_and_cannot_be_paid(): void
    {
        $vendor = $this->vendor();
        $quotation = $this->invoice($vendor, ['doc_type' => 'quotation', 'doc_number' => 'QT-77']);

        $this->assertFalse($quotation->carriesPaymentStatus());
        $this->assertSame('—', $quotation->paymentState()['label']);

        $token = 'tok-'.str_repeat('q', 14);
        $this->scan($vendor, $actor = $this->itManager(), $token);

        $this->actingAs($actor)
            ->post(route('vendors.payment-slips.store', $vendor), [
                'vendor_billing_document_id' => $quotation->id,
                'scan_token' => $token,
            ])
            ->assertSessionHasErrors('vendor_billing_document_id');

        $this->assertSame(0, VendorPaymentSlip::count());
    }

    /**
     * The picker is scoped to this vendor's own invoices, and so is the rule behind it.
     * Without that, a posted id would file our proof of payment against another company's
     * bill — and mark THAT bill paid.
     */
    public function test_a_slip_cannot_be_filed_against_another_vendors_invoice(): void
    {
        $vendor = $this->vendor();
        $other = $this->vendor(['name' => 'Other Vendor Sdn Bhd']);
        $foreignInvoice = $this->invoice($other, ['doc_number' => 'INV-OTHER-1']);

        $token = 'tok-'.str_repeat('x', 14);
        $this->scan($vendor, $actor = $this->itManager(), $token);

        $this->actingAs($actor)
            ->post(route('vendors.payment-slips.store', $vendor), [
                'vendor_billing_document_id' => $foreignInvoice->id,
                'scan_token' => $token,
            ])
            ->assertSessionHasErrors('vendor_billing_document_id');

        $this->assertFalse($foreignInvoice->fresh()->isPaid());
    }

    // ── Replacing one ─────────────────────────────────────────────────────────

    /**
     * One slip per invoice, enforced by a unique index. A second upload REPLACES rather than
     * failing on a constraint the operator never saw — and the old file goes with the old
     * row, or the private disk accumulates documents nothing can reach.
     */
    public function test_uploading_a_second_slip_replaces_the_first_and_deletes_its_file(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $actor = $this->itManager();

        $this->fileSlip($vendor, $invoice, $actor)->assertSessionHasNoErrors();
        $first = $invoice->fresh()->paymentSlip;

        $token = 'tok-'.str_repeat('r', 14);
        $this->scan($vendor, $actor, $token, ['payment_reference' => 'TRX-99999']);

        $this->actingAs($actor)
            ->post(route('vendors.payment-slips.store', $vendor), [
                'vendor_billing_document_id' => $invoice->id,
                'scan_token' => $token,
            ])
            ->assertSessionHasNoErrors();

        $invoice->refresh()->load('paymentSlip');

        $this->assertSame(1, VendorPaymentSlip::count());
        $this->assertSame('TRX-99999', $invoice->paymentSlip->payment_reference);
        $this->assertTrue($invoice->isPaid());

        Storage::disk('local')->assertMissing($first->file_path);
        Storage::disk('local')->assertExists($invoice->paymentSlip->file_path);
    }

    // ── Removing one ──────────────────────────────────────────────────────────

    /**
     * The ONLY way an invoice goes back from Paid to Pending.
     *
     * With the status derived, a slip filed against the wrong bill would otherwise leave
     * that bill reading Paid forever with no control able to say otherwise — which is why
     * `destroy` exists at all rather than being tidying.
     */
    public function test_removing_the_slip_takes_the_invoice_back_to_pending(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $actor = $this->itManager();

        $this->fileSlip($vendor, $invoice, $actor)->assertSessionHasNoErrors();
        $slip = $invoice->fresh()->paymentSlip;

        $this->actingAs($actor)
            ->delete(route('vendors.payment-slips.destroy', [$vendor, $slip]))
            ->assertSessionHasNoErrors();

        $invoice->refresh()->load('paymentSlip');

        $this->assertFalse($invoice->isPaid());
        $this->assertSame('Pending', $invoice->paymentState()['label']);
        Storage::disk('local')->assertMissing($slip->file_path);
    }

    /**
     * Both ids come from the URL. Without the ownership check a slip could be removed
     * through any other vendor's route — silently un-paying an invoice on a profile the
     * operator never opened.
     */
    public function test_a_slip_cannot_be_removed_through_another_vendors_url(): void
    {
        $vendor = $this->vendor();
        $other = $this->vendor(['name' => 'Other Vendor Sdn Bhd']);
        $invoice = $this->invoice($vendor);
        $actor = $this->itManager();

        $this->fileSlip($vendor, $invoice, $actor)->assertSessionHasNoErrors();
        $slip = $invoice->fresh()->paymentSlip;

        $this->actingAs($actor)
            ->delete(route('vendors.payment-slips.destroy', [$other, $slip]))
            ->assertNotFound();

        $this->assertTrue($invoice->fresh()->load('paymentSlip')->isPaid());
    }

    /**
     * The slip ROW cascades with its invoice; its FILE does not, so the controller has to
     * delete it. Otherwise deleting an invoice leaves proof of payment on the private disk
     * that nothing can ever reach or account for.
     */
    public function test_deleting_the_invoice_takes_its_slip_and_the_file_with_it(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $actor = $this->itManager();

        $this->fileSlip($vendor, $invoice, $actor)->assertSessionHasNoErrors();
        $slip = $invoice->fresh()->paymentSlip;

        $this->actingAs($actor)
            ->delete(route('vendors.billing.destroy', [$vendor, $invoice]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, VendorPaymentSlip::count());
        Storage::disk('local')->assertMissing($slip->file_path);
    }

    // ── The cross-check ───────────────────────────────────────────────────────

    /**
     * A slip that pays a different amount, or names a different invoice, is FLAGGED and
     * still filed.
     *
     * Both figures are machine readings of printed documents, so a disagreement is at least
     * as likely to be a misread as a mis-payment — and refusing the upload would leave the
     * operator with no record of the payment at all, which is strictly worse than a warned
     * one.
     */
    public function test_a_slip_that_disagrees_with_its_invoice_is_flagged_not_refused(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor, ['total' => 4500.00, 'doc_number' => 'INV-25268']);

        $this->fileSlip($vendor, $invoice, $this->itManager(), [
            'paid_amount' => 450,
            'invoice_reference' => 'INV-99999',
        ])->assertSessionHasNoErrors();

        $invoice->refresh()->load('paymentSlip');
        $slip = $invoice->paymentSlip;

        // Filed, and the invoice really is marked Paid — the flag never blocks.
        $this->assertNotNull($slip);
        $this->assertTrue($invoice->isPaid());

        $mismatches = $slip->mismatches();
        $this->assertCount(2, $mismatches);
        $this->assertStringContainsString('MYR 450.00', $mismatches[0]);
        $this->assertStringContainsString('MYR 4,500.00', $mismatches[0]);
        $this->assertStringContainsString('INV-99999', $mismatches[1]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'billing']))
            ->assertOk()
            ->assertSee('Does not match this invoice');
    }

    public function test_a_slip_that_agrees_with_its_invoice_is_not_flagged(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor, ['total' => 1500.00]);

        $this->fileSlip($vendor, $invoice, $this->itManager())->assertSessionHasNoErrors();

        $slip = $invoice->fresh()->paymentSlip;

        $this->assertSame([], $slip->mismatches());
        $this->assertNull($slip->mismatchFlag());
    }

    /**
     * The reference check is case- and spacing-insensitive but keeps PUNCTUATION, so
     * "inv-25268" matches while INV-2025-1 and INV-20251 stay two different invoices.
     */
    public function test_the_reference_check_ignores_case_but_not_punctuation(): void
    {
        $vendor = $this->vendor();
        $same = $this->invoice($vendor, ['doc_number' => 'INV-25268', 'total' => 1500.00]);

        $this->fileSlip($vendor, $same, $this->itManager(), ['invoice_reference' => ' inv-25268 '])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $same->fresh()->paymentSlip->mismatches());

        $other = $this->invoice($vendor, ['doc_number' => 'INV-2025-1', 'total' => 1500.00]);

        $this->fileSlip($vendor, $other, $this->itManager(), ['invoice_reference' => 'INV-20251'])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $other->fresh()->paymentSlip->mismatches());
    }

    /**
     * A zero read off a cluttered transfer screenshot would be compared against the invoice
     * total and reported as a short payment — turning one misread number into a warning
     * about the vendor. money() lets 0.00 through, so the payment reader clamps it itself.
     */
    public function test_a_zero_amount_is_treated_as_unread_rather_than_as_a_short_payment(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor, ['total' => 1500.00]);

        $this->fileSlip($vendor, $invoice, $this->itManager(), ['paid_amount' => 0])
            ->assertSessionHasNoErrors();

        $slip = $invoice->fresh()->paymentSlip;

        $this->assertNull($slip->paid_amount);
        $this->assertSame([], $slip->mismatches());
    }

    // ── Failing open ──────────────────────────────────────────────────────────

    /**
     * An unreadable slip must never be an unfileable one — the same rule the contract and
     * billing uploads follow. The document IS the evidence; a provider outage must not stop
     * it being recorded, and the invoice is paid either way.
     */
    public function test_an_unreadable_slip_still_files_and_still_marks_the_invoice_paid(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $actor = $this->itManager();

        // Nothing queued, so the provider answers 500 — see nextReply().
        $token = 'tok-'.str_repeat('f', 14);
        $this->actingAs($actor)
            ->post(route('vendors.documents.scan', $vendor), [
                'kind' => 'payment',
                'token' => $token,
                'document' => $this->pdf(),
            ])
            ->assertOk();

        $this->actingAs($actor)
            ->post(route('vendors.payment-slips.store', $vendor), [
                'vendor_billing_document_id' => $invoice->id,
                'scan_token' => $token,
                'ai_summary' => 'Bank transfer, RM1,500 — typed by hand.',
            ])
            ->assertSessionHasNoErrors();

        $invoice->refresh()->load('paymentSlip');

        $this->assertTrue($invoice->isPaid());
        $this->assertNull($invoice->paymentSlip->paid_amount);
        $this->assertSame('Bank transfer, RM1,500 — typed by hand.', $invoice->paymentSlip->ai_summary);
        // Nothing to disagree with, so nothing is claimed to disagree.
        $this->assertSame([], $invoice->paymentSlip->mismatches());
    }

    /**
     * The token is a lookup key, not a capability: it travels in a URL and is short. Every
     * claim is scoped by vendor, uploader AND kind — a contract or an invoice claimed here
     * would be filed as proof that a bill was paid.
     */
    public function test_a_scan_of_another_kind_cannot_be_filed_as_a_payment_slip(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $actor = $this->itManager();

        $this->fakeReading(
            ['summary' => 'An invoice.', 'key_points' => [], 'text' => 'INVOICE'],
            ['companies_involved' => [], 'doc_type' => 'invoice', 'total' => 1500]
        );

        $token = 'tok-'.str_repeat('k', 14);
        $this->actingAs($actor)
            ->post(route('vendors.documents.scan', $vendor), [
                'kind' => 'billing',
                'token' => $token,
                'document' => $this->pdf('invoice.pdf'),
            ])
            ->assertOk();

        $this->actingAs($actor)
            ->post(route('vendors.payment-slips.store', $vendor), [
                'vendor_billing_document_id' => $invoice->id,
                'scan_token' => $token,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, VendorPaymentSlip::count());
        $this->assertFalse($invoice->fresh()->isPaid());
    }

    // ── The listing ───────────────────────────────────────────────────────────

    /**
     * The five columns, and the two facts the payment column has to carry: what the slip
     * says, and that the invoice is settled.
     */
    public function test_the_billing_listing_shows_the_payment_slip_and_the_derived_status(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $unpaid = $this->invoice($vendor, ['doc_number' => 'INV-2026-002', 'total' => 900.00]);

        $this->fileSlip($vendor, $invoice, $this->itManager())->assertSessionHasNoErrors();

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'billing']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<th>Payment Slip</th>', $html);
        $this->assertStringContainsString('>Paid<', $html);
        $this->assertStringContainsString('>Pending<', $html);
        // What the slip says, on the row.
        $this->assertStringContainsString('TRX-88213', $html);
        $this->assertStringContainsString('Upload Payment Slip', $html);

        // The unpaid one really is unpaid, so the Pending assertion above is not inert.
        $this->assertFalse($unpaid->fresh()->isPaid());
    }

    /**
     * Reading a slip is not a management control — the invoice's own View button is offered
     * to the same viewer on the same row — but correcting and REMOVING one is. A read-only
     * viewer gets the document, not the modal that can un-pay the invoice.
     */
    public function test_a_read_only_viewer_gets_the_slip_but_none_of_its_controls(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);

        $this->fileSlip($vendor, $invoice, $this->itManager())->assertSessionHasNoErrors();
        $slip = $invoice->fresh()->paymentSlip;

        // Built by hand: every role that reaches this page can also manage it, so the
        // canManage=false branch is unreachable through the controller. A view variable
        // added to VendorController::show() must be added here too.
        $html = view('vendors.show', [
            'vendor' => $vendor->fresh()->load(['contracts', 'billingDocuments.paymentSlip', 'assets', 'rentalAcknowledgements', 'chatMessages']),
            'assets' => collect(),
            'summary' => [
                'rented' => 0, 'purchased' => 0, 'monthly_rental' => 0.0,
                'contracts_active' => 0, 'contracts_expiring' => 0,
                'quotations' => 0, 'invoices' => 1, 'sst_flags' => 0,
            ],
            'canManage' => false,
            'pendingAssets' => collect(),
            'acknowledgements' => collect(),
            'ewasteCycles' => collect(),
            'askable' => collect(),
            'chatMessages' => collect(),
            'askFocus' => null,
        ])->render();

        // The slip itself is reachable…
        $this->assertStringContainsString(secure_file_url($slip->file_path), $html);
        // …but nothing that files, corrects or removes one is rendered.
        $this->assertStringNotContainsString('Upload Payment Slip', $html);
        $this->assertStringNotContainsString('Remove payment slip', $html);
        $this->assertStringNotContainsString('id="paymentSlipUpload"', $html);
    }

    /**
     * The private directory has to be registered in BOTH secure_file_url()'s list and
     * SecureFileController::DIRECTORY_PERMISSIONS — the two are independent, and a directory
     * missing from either is a silent 404 or a silent 403 on a document that is on disk.
     */
    public function test_the_slip_is_served_through_the_secure_file_route(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);

        $this->fileSlip($vendor, $invoice, $this->itManager())->assertSessionHasNoErrors();
        $path = $invoice->fresh()->paymentSlip->file_path;

        $this->assertSame(route('secure.file', $path), secure_file_url($path));

        $this->actingAs($this->itManager())->get(secure_file_url($path))->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'employee']))
            ->get(secure_file_url($path))
            ->assertForbidden();
    }

    // ── Correcting the reading ────────────────────────────────────────────────

    /**
     * The summary is editable and the row must say who stands behind it — printing the
     * machine's name over wording a person typed is how a correction gets dismissed as a
     * guess. The FIGURES are not editable: a value no screen offers must not be settable
     * from a request.
     */
    public function test_the_slip_summary_is_editable_but_its_figures_are_not(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $actor = $this->itManager();

        $this->fileSlip($vendor, $invoice, $actor)->assertSessionHasNoErrors();
        $slip = $invoice->fresh()->paymentSlip;

        $this->actingAs($actor)
            ->put(route('vendors.payment-slips.update', [$vendor, $slip]), [
                'ai_summary' => 'Paid from the Enlinea account, not Claritas.',
                'companies_involved' => 'Enlinea Sdn Bhd, Acme Rentals Sdn Bhd',
                // Ignored — no form displays these.
                'paid_amount' => '99999.00',
                'payment_reference' => 'TAMPERED',
                'vendor_billing_document_id' => 999999,
            ])
            ->assertSessionHasNoErrors();

        $slip->refresh();

        $this->assertSame('Paid from the Enlinea account, not Claritas.', $slip->ai_summary);
        $this->assertSame(['Enlinea Sdn Bhd', 'Acme Rentals Sdn Bhd'], $slip->companiesInvolvedList());
        $this->assertTrue($slip->summaryIsEdited());
        $this->assertStringContainsString('Edited by', $slip->summaryProvenance());

        $this->assertSame('1500.00', (string) $slip->paid_amount);
        $this->assertSame('TRX-88213', $slip->payment_reference);
        $this->assertSame($invoice->id, $slip->vendor_billing_document_id);
    }

    // ── Consequences elsewhere ────────────────────────────────────────────────

    /**
     * Overdue is now "past due with nothing paid against it", so filing the proof of payment
     * clears it. Reading the retired status column instead would leave a settled invoice
     * flagged as overdue until somebody changed a dropdown that no longer exists.
     */
    public function test_paying_an_overdue_invoice_clears_the_overdue_badge(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor, [
            'doc_date' => now()->subMonth()->toDateString(),
            'due_date' => now()->subWeek()->toDateString(),
        ]);

        $this->assertTrue($invoice->isOverdue());

        $this->fileSlip($vendor, $invoice, $this->itManager())->assertSessionHasNoErrors();

        $this->assertFalse($invoice->fresh()->load('paymentSlip')->isOverdue());
    }

    /**
     * "Has this invoice been paid?" is the first question anybody asks on this page. Payment
     * slips are deliberately not documents the assistant can be asked about in their own
     * right, so the invoice has to carry its own settlement into the context — with the
     * evidence behind it, and never contradicting the badge on the row.
     */
    public function test_the_assistant_is_told_whether_an_invoice_has_been_paid(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $invoice->forceFill(['ai_status' => 'ok', 'ai_text' => 'INVOICE TEXT', 'ai_at' => now()])->save();

        $unpaidContext = $this->recordedFieldsFor($invoice->fresh());
        $this->assertStringContainsString('Payment status: Pending', $unpaidContext);

        $this->fileSlip($vendor, $invoice, $this->itManager())->assertSessionHasNoErrors();

        $paidContext = $this->recordedFieldsFor($invoice->fresh()->load('paymentSlip'));
        $this->assertStringContainsString('Payment status: Paid', $paidContext);
        $this->assertStringContainsString('TRX-88213', $paidContext);
    }

    /** The protected context builder, reached the only way a test can. */
    private function recordedFieldsFor(VendorBillingDocument $doc): string
    {
        $method = new \ReflectionMethod(\App\Services\VendorDocumentInsightService::class, 'recordedFields');
        $method->setAccessible(true);

        return $method->invoke(null, $doc);
    }
}
