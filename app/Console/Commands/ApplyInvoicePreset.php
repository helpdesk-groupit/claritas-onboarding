<?php

namespace App\Console\Commands;

use App\Models\EmailWorkflow;
use App\Support\Automation\DetectionEngine;
use Illuminate\Console\Command;

/**
 * Merge the supplier-invoice descriptors (EmailWorkflow::SUPPLIER_INVOICE_RULES)
 * into an existing workflow's detection rules.
 *
 * Why a command rather than a migration: detection rules are operator-owned
 * configuration, not schema. A migration would rewrite them on every deploy,
 * silently and with no way to preview the change. This runs when a human asks
 * it to, shows exactly what it will do with --dry-run, and is idempotent.
 *
 * MERGE, never overwrite. Keyword lists are unioned, so anything the operator
 * added by hand survives. The only fields replaced outright are the two the
 * preset exists to change: `filename_mode` (→ regex) and `capture_logic`
 * (→ attachment_or_text).
 *
 * Existing filename keywords are preg_quote()d when the mode flips to regex —
 * "invoice (final)" is a fine substring and a broken pattern, and an
 * uncompilable pattern is silently ignored by the engine, i.e. a rule that
 * stops matching with no visible trace.
 */
class ApplyInvoicePreset extends Command
{
    protected $signature = 'email-workflows:apply-invoice-preset
                            {--workflow= : Workflow id to update}
                            {--all : Update every workflow}
                            {--dry-run : Show the change without saving}';

    protected $description = 'Merge the supplier-invoice detection descriptors into an Email Workflow';

    public function handle(): int
    {
        $targets = $this->targets();

        if ($targets->isEmpty()) {
            $this->error('No workflow selected. Pass --workflow=<id> or --all.');
            $this->line('');
            $this->line('Available workflows:');
            foreach (EmailWorkflow::orderBy('id')->get(['id', 'name', 'status']) as $w) {
                $this->line(sprintf('  %-4s %-40s %s', $w->id, $w->name, $w->status));
            }

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        foreach ($targets as $workflow) {
            $before = (array) ($workflow->rules_json ?? EmailWorkflow::DEFAULT_RULES);
            $after = $this->merge($before);

            $this->line('');
            $this->info("#{$workflow->id} {$workflow->name} ({$workflow->status})");
            $this->report($before, $after);

            // Never store a pattern the engine will silently ignore.
            $bad = array_values(array_filter(
                (array) data_get($after, 'attachment.filename_keywords', []),
                fn ($p) => ! DetectionEngine::isValidPattern((string) $p)
            ));
            if ($bad !== []) {
                $this->error('  Refusing to save — uncompilable filename pattern(s): '.implode(', ', $bad));

                continue;
            }

            if ($before == $after) {
                $this->line('  <fg=gray>Already up to date — nothing to change.</>');

                continue;
            }

            if ($dry) {
                $this->line('  <fg=yellow>Dry run — not saved.</>');

                continue;
            }

            $workflow->rules_json = $after;
            $workflow->save();
            $this->line('  <fg=green>Saved.</>');
        }

        if ($dry) {
            $this->line('');
            $this->line('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,EmailWorkflow> */
    private function targets()
    {
        if ($this->option('all')) {
            return EmailWorkflow::orderBy('id')->get();
        }

        if ($id = $this->option('workflow')) {
            return EmailWorkflow::where('id', (int) $id)->get();
        }

        return EmailWorkflow::whereRaw('1 = 0')->get();
    }

    /**
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    private function merge(array $rules): array
    {
        $preset = EmailWorkflow::SUPPLIER_INVOICE_RULES;

        // Subject: union of keywords, and switch it on — a subject rule that is
        // present but disabled contributes nothing to the OR. The subject MODE
        // is left exactly as the operator had it, so its existing keywords are
        // never reinterpreted and never escaped.
        $subjectMode = (string) data_get($rules, 'subject.mode', 'contains');
        $rules['subject']['enabled'] = true;
        $rules['subject']['mode'] = $subjectMode;
        $rules['subject']['keywords'] = $this->union(
            (array) data_get($rules, 'subject.keywords', []),
            // The preset's subject list is written as plain substrings; if this
            // workflow matches subjects by regex they are still valid patterns
            // (no metacharacters), so the union is safe either way.
            $preset['subject']['keywords'],
            escapeExisting: false
        );

        $rules['combine_subject_body'] = data_get($rules, 'combine_subject_body', 'or');

        // Attachment: the filename list is where the house document codes land,
        // and this is the one field whose mode flips — so anything already there
        // is escaped on the way in.
        $wasRegex = data_get($rules, 'attachment.filename_mode') === 'regex';
        $rules['attachment']['required'] = (bool) data_get($rules, 'attachment.required', true);
        $rules['attachment']['types'] = $this->union(
            (array) data_get($rules, 'attachment.types', []),
            $preset['attachment']['types'],
            escapeExisting: false
        );
        $rules['attachment']['filename_keywords'] = $this->union(
            (array) data_get($rules, 'attachment.filename_keywords', []),
            $preset['attachment']['filename_keywords'],
            escapeExisting: ! $wasRegex
        );
        $rules['attachment']['filename_mode'] = 'regex';

        $rules['capture_logic'] = $preset['capture_logic'];

        // Preserve anything the operator set that the preset has no opinion on.
        $rules['body'] = data_get($rules, 'body', $preset['body']);
        $rules['sender'] = data_get($rules, 'sender', $preset['sender']);

        return $rules;
    }

    /**
     * Union two keyword lists, escaping the existing side when its field is
     * about to be reinterpreted as regex.
     *
     * A plain word survives preg_quote() unchanged, so escaping costs nothing in
     * the common case and saves the uncommon one ("invoice (final)" is a valid
     * substring and an invalid pattern).
     *
     * @param  array<int,mixed>  $existing
     * @param  array<int,string>  $incoming
     * @return array<int,string>
     */
    private function union(array $existing, array $incoming, bool $escapeExisting): array
    {
        $kept = [];
        foreach ($existing as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $kept[] = $escapeExisting ? preg_quote($value, '/') : $value;
        }

        return array_values(array_unique(array_merge($kept, $incoming)));
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    private function report(array $before, array $after): void
    {
        $rows = [
            'capture_logic' => ['before' => data_get($before, 'capture_logic'), 'after' => data_get($after, 'capture_logic')],
            'attachment.filename_mode' => ['before' => data_get($before, 'attachment.filename_mode', 'contains'), 'after' => data_get($after, 'attachment.filename_mode')],
            'subject.enabled' => ['before' => data_get($before, 'subject.enabled') ? 'yes' : 'no', 'after' => data_get($after, 'subject.enabled') ? 'yes' : 'no'],
        ];

        foreach ($rows as $key => $pair) {
            $changed = $pair['before'] !== $pair['after'];
            $this->line(sprintf(
                '  %-26s %s → %s',
                $key,
                (string) $pair['before'],
                $changed ? "<fg=green>{$pair['after']}</>" : (string) $pair['after']
            ));
        }

        foreach ([
            'subject.keywords' => 'subject keywords',
            'attachment.filename_keywords' => 'filename patterns',
        ] as $path => $label) {
            $old = (array) data_get($before, $path, []);
            $new = (array) data_get($after, $path, []);
            $added = array_values(array_diff($new, $old));
            $this->line(sprintf('  %-26s %d → %d', $label, count($old), count($new)));
            foreach ($added as $item) {
                $this->line("      <fg=green>+ {$item}</>");
            }
        }
    }
}
