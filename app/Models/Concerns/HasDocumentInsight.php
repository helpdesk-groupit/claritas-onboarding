<?php

namespace App\Models\Concerns;

/**
 * The `ai_*` half of a vendor document — the readable summary shown on its row and the
 * transcription the vendor Q&A assistant answers from.
 *
 * Shared by VendorContract and VendorBillingDocument. The two tables carry identical
 * columns and the wording has to match on both, because the same status means the same
 * thing whichever tab it is read on — a trait rather than copy-paste, so the two can't
 * drift apart the next time a status is added.
 *
 * The models supply aiLabel() and aiKind(); everything else is common.
 */
trait HasDocumentInsight
{
    /** Statuses whose transcription can actually be used to answer a question. */
    public const AI_USABLE_STATUSES = ['ok', 'partial'];

    /**
     * "Which row is this" key, unique across the two document tables.
     *
     * The assistant's scope is chosen from a single list mixing contracts and billing
     * documents, so a bare id would be ambiguous — contract 4 and invoice 4 both exist.
     * Defined once here so the scope chips, the focus deep-link and the controller that
     * resolves a submitted key can never disagree about the format.
     */
    public function askKey(): string
    {
        return ($this instanceof \App\Models\VendorContract ? 'contract:' : 'billing:').$this->id;
    }

    /** Human wording for what the summariser did, or null when nothing has been tried. */
    public function aiNote(): ?string
    {
        return static::aiNoteFor($this->ai_status);
    }

    /**
     * The same wording keyed by a bare status, so the row, the poll response and the
     * assistant's scope list can never describe one document's state three ways.
     */
    public static function aiNoteFor(?string $status): ?string
    {
        return match ($status) {
            'pending' => 'Reading the document — this runs in the background and appears here shortly.',
            'ok' => 'Summarised from the uploaded document.',
            // Announced, never smoothed over: a transcript cut short would otherwise let the
            // assistant report a clause as absent when it never received the page it was on.
            'partial' => 'Only part of this document could be transcribed. The rest was not read, '
                .'and the assistant says so whenever it relies on this document.',
            'empty' => 'The document was read but nothing could be summarised from it.',
            'skipped' => 'Not read: the configured AI provider cannot read PDFs.',
            'disabled' => 'Not read: document AI is not configured.',
            'failed' => 'Could not be read — press Re-summarise to try again.',
            default => null,
        };
    }

    /** Is there a transcription good enough to answer questions from? */
    public function hasAiText(): bool
    {
        return in_array($this->ai_status, self::AI_USABLE_STATUSES, true)
            && filled($this->ai_text);
    }

    /**
     * Why this document cannot be asked about, or null when it can.
     *
     * Phrased as a reason rather than a boolean because the page LISTS the documents it
     * cannot use, greyed with this text beside them. A document you can see on the page
     * but cannot ask about has to say why — silently omitting it reads as though it were
     * covered by the answer.
     */
    public function aiUnavailableReason(): ?string
    {
        if (blank($this->file_path)) {
            return 'no document has been uploaded against this record';
        }

        if ($this->hasAiText()) {
            return null;
        }

        return match ($this->ai_status) {
            // Stated without an instruction: this wording is also read inside an answer's
            // "not read for this answer" line, where telling somebody to press a button is
            // beside the point — and the assistant panel now offers that button itself,
            // beside this very reason.
            null => 'it has not been read yet',
            'pending' => 'it is still being read',
            'skipped' => 'it is a PDF and the configured AI provider cannot read PDFs',
            'disabled' => 'document AI was switched off when it was filed',
            'failed' => 'it could not be read',
            'empty' => 'it was read but no text could be extracted from it',
            // ok/partial with no stored text: the status says it was read, the column says
            // otherwise. Say what is actually true rather than trusting the status alone.
            default => 'no text was stored for it',
        };
    }

    /** Has a summary worth rendering on the row? */
    public function hasAiSummary(): bool
    {
        return filled($this->ai_summary) || ! empty($this->ai_key_points);
    }

    /** The summary as sanitised HTML. Rendered server-side so no markdown parser ships. */
    public function aiSummaryHtml(): string
    {
        if (blank($this->ai_summary)) {
            return '';
        }

        return \Illuminate\Support\Str::markdown($this->ai_summary, [
            // The summary is model output. Strip any HTML it emits rather than trusting a
            // prompt rule to have held, and never let it render a javascript: link.
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Clear the stored reading of a document that is about to be replaced.
     *
     * Load-bearing: the summary and transcription describe the OLD file. Leaving them in
     * place shows the previous PDF's summary under the new one's name and — far worse —
     * has the assistant answer questions about the new document from the old one's text.
     * Called in the same write that sets the new file_path.
     */
    public function resetDocumentInsight(string $status = 'pending'): void
    {
        $this->ai_status = $status;
        $this->ai_summary = null;
        $this->ai_key_points = null;
        $this->ai_text = null;
        $this->ai_at = null;
    }
}
