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
        $this->engine = new DetectionEngine();
    }

    private function invoiceMessage(array $overrides = []): array
    {
        return array_merge([
            'message_id'  => 'msg-1',
            'from'        => 'billing@acme.com',
            'subject'     => 'Invoice for June services',
            'body'        => 'Please find attached. Total RM 1,250.00 due.',
            'date'        => '2026-06-15T08:30:00Z',
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
