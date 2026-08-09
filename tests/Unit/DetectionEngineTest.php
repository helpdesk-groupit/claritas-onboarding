<?php

namespace Tests\Unit;

use App\Models\EmailWorkflow;
use App\Support\Automation\DetectionEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the Email Workflow detection engine — no DB, no network.
 * Covers subject/body/attachment matching, combine operators, sender filters,
 * currency-aware amount extraction, idempotency keys, and monthly partitions.
 */
class DetectionEngineTest extends TestCase
{
    private DetectionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DetectionEngine;
    }

    private function invoiceMessage(array $overrides = []): array
    {
        return array_merge([
            'message_id' => 'msg-1',
            'from' => 'billing@acme.com',
            'subject' => 'Invoice for June services',
            'body' => 'Please find attached. Total RM 1,250.00 due.',
            'date' => '2026-06-15T08:30:00Z',
            'attachments' => [
                ['id' => 'a1', 'name' => 'invoice-202606.pdf', 'mime' => 'application/pdf', 'size' => 1024],
            ],
        ], $overrides);
    }

    public function test_default_rules_match_a_typical_invoice(): void
    {
        $result = $this->engine->evaluate($this->invoiceMessage(), EmailWorkflow::DEFAULT_RULES);

        $this->assertTrue($result['matched']);
        $this->assertCount(1, $result['attachments']);
    }

    public function test_subject_contains_is_case_insensitive(): void
    {
        $msg = $this->invoiceMessage(['subject' => 'INVOICE FOR JUNE']);
        $result = $this->engine->evaluate($msg, EmailWorkflow::DEFAULT_RULES);

        $this->assertTrue($result['matched']);
    }

    public function test_no_attachment_fails_when_attachment_required(): void
    {
        $msg = $this->invoiceMessage(['attachments' => []]);
        $result = $this->engine->evaluate($msg, EmailWorkflow::DEFAULT_RULES);

        $this->assertFalse($result['matched']);
        $this->assertSame([], $result['attachments']);
    }

    public function test_wrong_attachment_type_is_filtered_out(): void
    {
        $msg = $this->invoiceMessage([
            'attachments' => [
                ['id' => 'a1', 'name' => 'invoice.txt', 'mime' => 'text/plain', 'size' => 10],
            ],
        ]);
        $result = $this->engine->evaluate($msg, EmailWorkflow::DEFAULT_RULES);

        $this->assertFalse($result['matched']);
    }

    public function test_combine_and_requires_both_subject_and_body(): void
    {
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['body'] = ['enabled' => true, 'mode' => 'contains', 'keywords' => ['paid']];
        $rules['combine_subject_body'] = 'and';

        // Subject matches "invoice" but body has no "paid" → AND fails.
        $msg = $this->invoiceMessage(['body' => 'Total RM 1,250.00 due.']);
        $this->assertFalse($this->engine->evaluate($msg, $rules)['matched']);

        // Body now contains "paid" → AND passes.
        $msg2 = $this->invoiceMessage(['body' => 'Marked paid. Total RM 1,250.00.']);
        $this->assertTrue($this->engine->evaluate($msg2, $rules)['matched']);
    }

    public function test_regex_subject_mode(): void
    {
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['subject'] = ['enabled' => true, 'mode' => 'regex', 'keywords' => ['^invoice for .+']];

        $this->assertTrue($this->engine->evaluate($this->invoiceMessage(), $rules)['matched']);

        $msg = $this->invoiceMessage(['subject' => 'random newsletter']);
        $this->assertFalse($this->engine->evaluate($msg, $rules)['matched']);
    }

    public function test_denylist_blocks_sender(): void
    {
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['sender']['denylist'] = ['acme.com'];

        $this->assertFalse($this->engine->evaluate($this->invoiceMessage(), $rules)['matched']);
    }

    public function test_allowlist_only_permits_listed_senders(): void
    {
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['sender']['allowlist'] = ['trusted.com'];

        $this->assertFalse($this->engine->evaluate($this->invoiceMessage(), $rules)['matched']);

        $msg = $this->invoiceMessage(['from' => 'ap@trusted.com']);
        $this->assertTrue($this->engine->evaluate($msg, $rules)['matched']);
    }

    public function test_extracts_myr_amount(): void
    {
        $fields = $this->engine->extractFields($this->invoiceMessage());

        $this->assertSame(1250.00, $fields['amount']);
        $this->assertSame('MYR', $fields['currency']);
        $this->assertFalse($fields['needs_review']);
    }

    public function test_extracts_usd_and_european_formats(): void
    {
        $usd = $this->engine->extractFields($this->invoiceMessage(['body' => 'Amount: $1,234.56']));
        $this->assertSame(1234.56, $usd['amount']);
        $this->assertSame('USD', $usd['currency']);

        $eur = $this->engine->extractFields($this->invoiceMessage(['body' => 'Betrag: €1.234,56']));
        $this->assertSame(1234.56, $eur['amount']);
        $this->assertSame('EUR', $eur['currency']);
    }

    public function test_amount_not_found_flags_needs_review(): void
    {
        $fields = $this->engine->extractFields($this->invoiceMessage([
            'subject' => 'Invoice', 'body' => 'No money figure here.',
        ]));

        $this->assertNull($fields['amount']);
        $this->assertTrue($fields['needs_review']);
    }

    // ── Filename matching ────────────────────────────────────────────────

    public function test_filename_keywords_default_to_substring_matching(): void
    {
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['attachment']['filename_keywords'] = ['invoice'];

        $this->assertTrue($this->engine->evaluate($this->invoiceMessage(), $rules)['matched']);

        $msg = $this->invoiceMessage([
            'attachments' => [['id' => 'a1', 'name' => 'statement.pdf', 'mime' => 'application/pdf', 'size' => 1]],
        ]);
        $this->assertFalse($this->engine->evaluate($msg, $rules)['matched']);
    }

    public function test_filename_regex_mode_is_precise_where_substring_is_not(): void
    {
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['attachment']['filename_mode'] = 'regex';
        $rules['attachment']['filename_keywords'] = ['(?<![a-z0-9])I-\d{6}(?!\d)'];

        $hit = $this->invoiceMessage([
            'attachments' => [['id' => 'a1', 'name' => 'I-001068 Care Digital Sdn Bhd.pdf', 'mime' => 'application/pdf', 'size' => 1]],
        ]);
        $this->assertTrue($this->engine->evaluate($hit, $rules)['matched']);

        // A substring rule of "I-0" would have matched this; the pattern must not.
        $miss = $this->invoiceMessage([
            'attachments' => [['id' => 'a1', 'name' => 'API-0 spec invoice.pdf', 'mime' => 'application/pdf', 'size' => 1]],
        ]);
        $this->assertFalse($this->engine->evaluate($miss, $rules)['matched']);
    }

    public function test_no_filename_keywords_accepts_every_allowed_type(): void
    {
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['attachment']['filename_keywords'] = [];

        $msg = $this->invoiceMessage([
            'attachments' => [['id' => 'a1', 'name' => 'anything.pdf', 'mime' => 'application/pdf', 'size' => 1]],
        ]);

        $this->assertTrue($this->engine->evaluate($msg, $rules)['matched']);
    }

    // ── attachment_or_text capture logic ─────────────────────────────────

    public function test_or_logic_captures_on_filename_evidence_alone(): void
    {
        $rules = EmailWorkflow::SUPPLIER_INVOICE_RULES;

        // Subject says nothing useful; the house code in the filename is the
        // only evidence. Under attachment_and_text this was a silent miss.
        $msg = $this->invoiceMessage([
            'subject' => 'Documents attached',
            'body' => 'As discussed.',
            'attachments' => [['id' => 'a1', 'name' => 'ENSB-IO-02452- Bio-Oil.pdf', 'mime' => 'application/pdf', 'size' => 1]],
        ]);

        $result = $this->engine->evaluate($msg, $rules);
        $this->assertTrue($result['matched']);
        $this->assertCount(1, $result['attachments']);
    }

    public function test_or_logic_on_text_evidence_still_returns_attachments_to_capture(): void
    {
        $rules = EmailWorkflow::SUPPLIER_INVOICE_RULES;

        // Subject is the evidence; the filename matches no pattern. CaptureService
        // skips a matched verdict with an empty attachment list *silently*, so an
        // empty list here would be a success that stores nothing.
        $msg = $this->invoiceMessage([
            'subject' => 'Rental Invoice (Aug 2026)',
            'body' => 'Kindly settle by month end.',
            'attachments' => [['id' => 'a1', 'name' => 'monthly-schedule.pdf', 'mime' => 'application/pdf', 'size' => 1]],
        ]);

        $result = $this->engine->evaluate($msg, $rules);
        $this->assertTrue($result['matched']);
        $this->assertCount(1, $result['attachments'], 'A text-only match must still nominate attachments to store.');
    }

    public function test_or_logic_text_fallback_respects_the_type_filter(): void
    {
        $rules = EmailWorkflow::SUPPLIER_INVOICE_RULES;

        $msg = $this->invoiceMessage([
            'subject' => 'Rental Invoice (Aug 2026)',
            'attachments' => [
                ['id' => 'a1', 'name' => 'signature.png', 'mime' => 'image/png', 'size' => 1],
                ['id' => 'a2', 'name' => 'schedule.pdf', 'mime' => 'application/pdf', 'size' => 1],
            ],
        ]);

        $result = $this->engine->evaluate($msg, $rules);
        $this->assertSame(['schedule.pdf'], array_column($result['attachments'], 'name'));
    }

    public function test_or_logic_still_skips_unrelated_mail(): void
    {
        $rules = EmailWorkflow::SUPPLIER_INVOICE_RULES;

        $msg = $this->invoiceMessage([
            'subject' => 'Your weekly digest',
            'body' => 'Top stories this week.',
            'attachments' => [['id' => 'a1', 'name' => 'newsletter.pdf', 'mime' => 'application/pdf', 'size' => 1]],
        ]);

        $this->assertFalse($this->engine->evaluate($msg, $rules)['matched']);
    }

    // ── The preset, against the documents it was built from ──────────────

    /**
     * Every filename here is a real supplier document the generic defaults let
     * through. If one of these ever goes red, an invoice is being lost.
     *
     * @dataProvider realSupplierDocuments
     */
    public function test_supplier_invoice_preset_matches_real_documents(string $filename, string $subject): void
    {
        $msg = $this->invoiceMessage([
            'subject' => $subject,
            'body' => 'Please find the attached document.',
            'attachments' => [['id' => 'a1', 'name' => $filename, 'mime' => 'application/pdf', 'size' => 2048]],
        ]);

        $result = $this->engine->evaluate($msg, EmailWorkflow::SUPPLIER_INVOICE_RULES);

        $this->assertTrue($result['matched'], "Preset failed to match: {$filename}");
        $this->assertCount(1, $result['attachments'], "Preset matched but captured nothing: {$filename}");
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function realSupplierDocuments(): array
    {
        // Subjects are '' where the real subject is unknown — the point of the
        // filename patterns is that they must carry the match on their own.
        return [
            'Care Digital invoice · Telecontinent' => ['CDSB-IV-2608-002 (Telecontinent).pdf', 'Subscription Billing | August-2026'],
            'Care Digital invoice · Nandos' => ['CDSB-IV-2608-003 (Nandos).pdf', ''],
            'Care Digital invoice · MyRodeo' => ['CDSB-IV-2608-004 (MyRodeo).pdf', ''],
            'Care Digital statement of account' => ['CDSB- SOA-20260731-Telecontinent.pdf', 'Statement of Account'],
            'Supplier invoice to Care Digital' => ['I-001068 Care Digital Sdn Bhd.pdf', ''],
            'Supplier invoice to Enlinea 038276' => ['I-038276 Enlinea Sdn Bhd.pdf', ''],
            'Supplier invoice to Enlinea 038363' => ['I-038363 Enlinea Sdn Bhd.pdf', ''],
            'Supplier invoice to Enlinea 038565' => ['I-038565 Enlinea Sdn Bhd.pdf', ''],
            'Enlinea IO · Bio-Oil' => ['ENSB-IO-02452- Bio-Oil.pdf', ''],
            'Enlinea IO · OZ Marketing' => ['ENSB-IO-02459-OZ Marketing.pdf', ''],
            'Nuren ASX invoice' => ['NUREN GROUP LIMITED CHS26051383 2026-08-03.pdf', 'ASX Invoice'],
            'Claritas statement of account' => ['SOA - CLARITAS (20.07.2026).pdf', ''],
            'Care Digital rental invoice' => ['Care Digital - 082026.pdf', 'Rental Invoice (Aug 2026)'],

            // ── Collections: SOAs and payment chasers (July 2026 audit) ──
            // Real threads from finance@claritas.asia that every run dropped.
            // A payment chaser rarely says "invoice" anywhere: the subject
            // chases, and the attachment is a statement or a transfer slip.
            'Payment chaser · SOA attached' => [
                'SOA - CLARITAS (20.07.2026).pdf',
                '[6th Follow Up] ACCORDIA - CLARITAS : PAYMENT FOLLOW UP (20/07/2026)',
            ],
            'Payment chaser · Maybank2u slip' => [
                'NCSB-M2U-20260720-accordia.pdf',
                'Re: [5th Follow Up] ACCORDIA - CLARITAS : PAYMENT FOLLOW UP',
            ],
            'Outstanding debt · transfer slip' => [
                'NCSB-M2U-20260720-cloudspace.pdf',
                'Re: OUTSTANDING DEBT – RM 59,826.31 - Claritas Consulting (Asia) SB',
            ],
            // The subject alone must carry it — a chaser can attach a plainly
            // named scan, and dropping it is the whole bug.
            'Payment chaser · anonymous scan' => [
                'scan0042.pdf',
                'PAYMENT FOLLOW UP — August statement',
            ],
        ];
    }

    /**
     * @dataProvider innocentLookalikes
     */
    public function test_the_collections_vocabulary_does_not_swallow_unrelated_mail(string $filename, string $subject): void
    {
        // Widening the rules is only safe if the widening is precise. `contains`
        // is a plain substring match, so a bare "soa" keyword would fire on
        // "soap" and a bare "follow up" on every thread in the mailbox — and
        // each false positive files a stranger's PDF into the client's Drive.
        $msg = $this->invoiceMessage([
            'subject' => $subject,
            'body' => 'Nothing financial here at all.',
            'attachments' => [['id' => 'a1', 'name' => $filename, 'mime' => 'application/pdf', 'size' => 2048]],
        ]);

        $this->assertFalse(
            $this->engine->evaluate($msg, EmailWorkflow::SUPPLIER_INVOICE_RULES)['matched'],
            "Preset wrongly matched: {$subject} / {$filename}"
        );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function innocentLookalikes(): array
    {
        return [
            'soap is not an SOA' => ['product-list.pdf', 'Soap dispenser restock for the pantry'],
            'soaring is not an SOA' => ['deck.pdf', 'Soaring engagement on our July campaign'],
            'a plain follow-up is not a payment chaser' => ['notes.pdf', 'Follow up on yesterday’s design review'],
            'a meeting reminder is not a payment reminder' => ['agenda.pdf', 'Reminder: townhall at 3pm'],
            'lunch is still lunch' => ['menu.pdf', 'Team lunch on Friday'],
        ];
    }

    public function test_preset_filename_patterns_all_compile(): void
    {
        foreach (EmailWorkflow::SUPPLIER_INVOICE_RULES['attachment']['filename_keywords'] as $pattern) {
            $this->assertTrue(
                DetectionEngine::isValidPattern($pattern),
                "Preset pattern does not compile: {$pattern}"
            );
        }
    }

    /**
     * The step-2 form re-parses its fields on every save, so the preset has to
     * survive a round-trip through the splitter unchanged. A plain comma split
     * would turn `\d{6,}` into `\d{6` + `}` — two dead patterns, silently.
     */
    public function test_preset_survives_the_form_round_trip(): void
    {
        $patterns = EmailWorkflow::SUPPLIER_INVOICE_RULES['attachment']['filename_keywords'];

        $this->assertSame($patterns, DetectionEngine::splitKeywords(implode(', ', $patterns)));
    }

    public function test_split_keywords_is_brace_aware(): void
    {
        $this->assertSame(
            ['invoice', '\d{3,}', 'receipt'],
            DetectionEngine::splitKeywords('invoice, \d{3,}, receipt')
        );

        $this->assertSame(
            ['invoice', 'receipt', 'credit note'],
            DetectionEngine::splitKeywords("invoice, receipt\ncredit note")
        );

        $this->assertSame([], DetectionEngine::splitKeywords('  ,  '));
    }

    public function test_invalid_pattern_is_detected_not_thrown(): void
    {
        $this->assertFalse(DetectionEngine::isValidPattern('invoice(('));
        $this->assertTrue(DetectionEngine::isValidPattern('CDSB-\s*IV-\d'));
    }

    public function test_idempotency_key_format(): void
    {
        $this->assertSame(
            'msg-1|invoice.pdf',
            $this->engine->idempotencyKey('msg-1', 'invoice.pdf')
        );
    }

    public function test_monthly_partition_name(): void
    {
        $this->assertSame('2026-06', $this->engine->monthlyPartition('2026-06-15T08:30:00Z'));
    }
}
