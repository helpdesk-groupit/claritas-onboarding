<?php

namespace Tests\Unit;

use App\Console\Commands\ApplyInvoicePreset;
use App\Models\EmailWorkflow;
use App\Support\Automation\DetectionEngine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The merge step of `email-workflows:apply-invoice-preset` is pure array work,
 * and it is the code path that rewrites a LIVE workflow's detection rules — so
 * it is tested directly rather than only through the command.
 *
 * The contract it must hold: union, never clobber; idempotent; and never leave
 * behind a pattern the engine will silently ignore.
 */
class ApplyInvoicePresetMergeTest extends TestCase
{
    /** @param array<string,mixed> $rules */
    private function merge(array $rules): array
    {
        $method = new ReflectionMethod(ApplyInvoicePreset::class, 'merge');
        $method->setAccessible(true);

        return $method->invoke(new ApplyInvoicePreset, $rules);
    }

    public function test_merge_switches_the_two_fields_the_preset_exists_to_change(): void
    {
        $merged = $this->merge(EmailWorkflow::DEFAULT_RULES);

        $this->assertSame('regex', $merged['attachment']['filename_mode']);
        $this->assertSame('attachment_or_text', $merged['capture_logic']);
        $this->assertTrue($merged['subject']['enabled']);
    }

    public function test_merge_keeps_every_preset_pattern(): void
    {
        $merged = $this->merge(EmailWorkflow::DEFAULT_RULES);

        foreach (EmailWorkflow::SUPPLIER_INVOICE_RULES['attachment']['filename_keywords'] as $pattern) {
            $this->assertContains($pattern, $merged['attachment']['filename_keywords']);
        }
    }

    public function test_merge_preserves_operator_additions(): void
    {
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['subject']['keywords'][] = 'penyata';
        $rules['attachment']['filename_keywords'][] = 'lampiran';
        $rules['sender']['denylist'] = ['@spam.example'];

        $merged = $this->merge($rules);

        $this->assertContains('penyata', $merged['subject']['keywords']);
        $this->assertContains('lampiran', $merged['attachment']['filename_keywords']);
        $this->assertSame(['@spam.example'], $merged['sender']['denylist']);
    }

    public function test_merge_escapes_existing_filename_keywords_when_the_mode_flips(): void
    {
        // A fine substring and a broken pattern. Carried over unescaped it would
        // stop compiling, and the engine ignores what won't compile — a rule
        // that quietly stops matching.
        $rules = EmailWorkflow::DEFAULT_RULES;
        $rules['attachment']['filename_mode'] = 'contains';
        $rules['attachment']['filename_keywords'] = ['statement (final)'];

        $merged = $this->merge($rules);

        foreach ($merged['attachment']['filename_keywords'] as $pattern) {
            $this->assertTrue(
                DetectionEngine::isValidPattern($pattern),
                "Merge produced an uncompilable pattern: {$pattern}"
            );
        }

        // …and it still matches the file it was written for.
        $engine = new DetectionEngine;
        $result = $engine->evaluate([
            'message_id' => 'm', 'from' => 'ap@vendor.example',
            'subject' => 'Documents', 'body' => '', 'date' => '2026-08-05T00:00:00Z',
            'attachments' => [['id' => 'a', 'name' => 'statement (final).pdf', 'mime' => 'application/pdf', 'size' => 1]],
        ], $merged);

        $this->assertTrue($result['matched']);
    }

    public function test_merge_is_idempotent(): void
    {
        $once = $this->merge(EmailWorkflow::DEFAULT_RULES);
        $twice = $this->merge($once);

        $this->assertSame($once, $twice);
    }

    /**
     * End-to-end proof of the whole point: a workflow sitting on the generic
     * defaults today, after the merge, matches the supplier documents it has
     * been missing.
     *
     * @dataProvider realSupplierDocuments
     */
    public function test_merged_rules_match_the_documents_the_defaults_missed(string $filename, string $subject): void
    {
        $engine = new DetectionEngine;
        $message = [
            'message_id' => 'm', 'from' => 'ap@vendor.example',
            'subject' => $subject, 'body' => 'Please find the attached document.',
            'date' => '2026-08-05T00:00:00Z',
            'attachments' => [['id' => 'a', 'name' => $filename, 'mime' => 'application/pdf', 'size' => 2048]],
        ];

        $merged = $this->merge(EmailWorkflow::DEFAULT_RULES);
        $result = $engine->evaluate($message, $merged);

        $this->assertTrue($result['matched'], "Merged rules failed to match: {$filename}");
        $this->assertCount(1, $result['attachments'], "Matched but captured nothing: {$filename}");
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function realSupplierDocuments(): array
    {
        return DetectionEngineTest::realSupplierDocuments();
    }
}
