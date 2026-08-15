<?php

namespace App\Jobs;

use App\Models\VendorBillingDocument;
use App\Models\VendorContract;
use App\Services\VendorDocumentInsightService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads an uploaded vendor contract / quotation / invoice into its summary and its
 * transcription, off the request cycle.
 *
 * It runs out of band for a documented reason: transcribing a multi-page PDF on top of
 * the field scan the same upload already ran would push the save past Cloudflare's ~100s
 * edge timeout on live — which is exactly why Email Workflow's "Run now" stopped being
 * synchronous. Dispatched to the `database` queue and drained by the scheduler-supervised
 * `queue:work database --stop-when-empty` already in routes/console.php: no new worker.
 *
 * ShouldBeUnique keyed on the document, so a double Save or an impatient Re-summarise
 * can't run two readings of the same file at once and bill for both.
 *
 * FAILS OPEN. Anything thrown is recorded as ai_status = 'failed' and logged; the
 * document, its file and every field the OCR extracted are untouched. A summary is a
 * convenience laid over the record — it is never allowed to endanger it.
 */
class SummariseVendorDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** The two document tables this serves, keyed by the token the callers pass. */
    public const TYPES = [
        'contract' => VendorContract::class,
        'billing' => VendorBillingDocument::class,
    ];

    /** One shot: a killed reading is re-dispatched by Re-summarise, never auto-retried. */
    public int $tries = 1;

    /** A long contract is several minutes of transcription. */
    public int $timeout = 600;

    /** Release the uniqueness lock if the job dies without clearing it. */
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $documentType,
        public readonly int $documentId,
    ) {
        // Pin to the `database` queue. Set via onConnection() rather than a $connection
        // property so it doesn't clash with Queueable's own declaration.
        $this->onConnection('database');
    }

    public function uniqueId(): string
    {
        return 'vdoc-summary-'.$this->documentType.'-'.$this->documentId;
    }

    /** Queue a reading for either kind of document, or do nothing if it has no file. */
    public static function dispatchFor(VendorContract|VendorBillingDocument $document): void
    {
        if (blank($document->file_path)) {
            return;
        }

        static::dispatch(
            $document instanceof VendorContract ? 'contract' : 'billing',
            $document->id
        );
    }

    public function handle(): void
    {
        $class = self::TYPES[$this->documentType] ?? null;
        if (! $class) {
            return;
        }

        /** @var VendorContract|VendorBillingDocument|null $document */
        $document = $class::find($this->documentId);

        // Deleted between dispatch and execution. Not an error — nothing to summarise.
        if (! $document || blank($document->file_path)) {
            return;
        }

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists($document->file_path)) {
                $this->record($document, ['status' => 'failed', 'summary' => null, 'key_points' => [], 'text' => null]);

                Log::warning('Vendor document summary: stored file is missing', [
                    'type' => $this->documentType,
                    'id' => $this->documentId,
                    'path' => $document->file_path,
                ]);

                return;
            }

            // readDetails(), not read(): a re-reading has to refresh the PARTIES as well,
            // because they are shown in the listing's first column and would otherwise keep
            // naming the counterparties of a document that has since been replaced. The
            // dates and figures are NOT touched — those are the record, owned by whoever
            // filed it, and a summariser must never overwrite a field a human owns.
            $this->record($document, VendorDocumentInsightService::readDetails(
                $disk->path($document->file_path),
                (string) $disk->mimeType($document->file_path),
                $document->aiKind(),
                $document->vendor?->name
            ));
        } catch (\Throwable $e) {
            // The document is already safely stored; losing it — or its extracted fields —
            // over a failed summary would be absurd.
            $this->record($document, ['status' => 'failed', 'summary' => null, 'key_points' => [], 'text' => null]);

            Log::warning('Vendor document summary failed', [
                'type' => $this->documentType,
                'id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write the reading. Uses the model rather than a raw update so the `ai_key_points`
     * cast applies, but touches ONLY the ai_* columns — a summariser must never write a
     * field a human owns.
     */
    private function record(VendorContract|VendorBillingDocument $document, array $result): void
    {
        $document->forceFill([
            'ai_status' => $result['status'],
            'ai_summary' => $result['summary'],
            'ai_key_points' => $result['key_points'] ?: null,
            'ai_text' => $result['text'],
            'ai_at' => now(),
            'companies_involved' => ($result['companies'] ?? []) ?: null,
            // This summary is the model's again. Any edit stamp on the row belonged to the
            // wording just replaced, and leaving it would print a person's name under text
            // they never wrote.
            'ai_summary_edited_at' => null,
            'ai_summary_edited_by' => null,
        ])->save();
    }
}
