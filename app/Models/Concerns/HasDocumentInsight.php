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
     * "Which row is this" key, unique across the document tables using this trait.
     *
     * The assistant's scope is chosen from a single list mixing contracts and billing
     * documents, so a bare id would be ambiguous — contract 4 and invoice 4 both exist.
     * Defined once here so the scope chips, the focus deep-link and the controller that
     * resolves a submitted key can never disagree about the format.
     *
     * Matched on the class EXPLICITLY rather than as contract-or-else. A third document
     * type joined the trait (VendorPaymentSlip) and under the old two-way test it would
     * have been keyed `billing:{id}` — the same key as the invoice it pays, silently
     * substituting one document for another the moment payment slips join the ask scope.
     * They are deliberately outside it today; a key that is wrong only in future is still
     * wrong now.
     */
    public function askKey(): string
    {
        $prefix = match (true) {
            $this instanceof \App\Models\VendorContract => 'contract',
            $this instanceof \App\Models\VendorPaymentSlip => 'payment',
            default => 'billing',
        };

        return $prefix.':'.$this->id;
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
            // Names the control as it is ACTUALLY labelled, in the place it actually is —
            // the row's Edit window. It said "press Re-summarise", which is the name of no
            // button in this application, and the staging modal repeats this wording where
            // even the real button cannot exist. Both sent the first operator to try this on
            // live hunting the screen for something that was never there.
            'failed' => 'Could not be read — use "Read the document again" in its Edit window to retry.',
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

    /**
     * The parties the document names, as a plain list.
     *
     * Read through a helper rather than off the column, because the listing shows this on
     * every row and an older record filed before the scan existed has null there — the
     * column is a list or it is nothing, never a string to be exploded at the call site.
     *
     * @return list<string>
     */
    public function companiesInvolvedList(): array
    {
        return array_values(array_filter(
            is_array($this->companies_involved) ? $this->companies_involved : [],
            fn ($name) => is_string($name) && trim($name) !== ''
        ));
    }

    /**
     * Turn the "Companies Involved" input back into a list.
     *
     * The field is one text box holding names separated by commas or newlines, not a
     * multi-select of our own companies: the parties on a vendor document regularly include
     * an entity we carry no row for — the vendor's holding company, a sister company being
     * supplied, a party since renamed — and a picker would silently drop exactly those.
     *
     * Lives here rather than in either controller so the contract and billing sides cannot
     * come to disagree about what separates two company names.
     *
     * @return list<string>
     */
    public static function parseCompaniesInput(?string $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[\r\n,]+/u', $raw) as $name) {
            $clean = trim(preg_replace('/\s+/u', ' ', $name));
            if ($clean === '') {
                continue;
            }
            $clean = mb_substr($clean, 0, 150);
            if (! in_array(mb_strtolower($clean), array_map('mb_strtolower', $out), true)) {
                $out[] = $clean;
            }
            if (count($out) >= 20) {
                break;
            }
        }

        return $out;
    }

    /** Has a person rewritten what the model wrote? */
    public function summaryIsEdited(): bool
    {
        return $this->ai_summary_edited_at !== null;
    }

    /**
     * Who stands behind the summary now on the row, for the panel's footer.
     *
     * The summary is editable, so "Generated by AI from the uploaded document" stops being
     * true the moment somebody corrects it — and this is the column the whole listing is
     * now read from. Printing the wrong provenance over wording a person typed is how a
     * corrected figure gets dismissed as a machine's guess, and how a machine's guess gets
     * trusted as a person's correction.
     */
    public function summaryProvenance(): string
    {
        if ($this->summaryIsEdited()) {
            $who = $this->summaryEditor?->name;

            return 'Edited by '.($who ?: 'a member of staff').' on '.fmt_datetime($this->ai_summary_edited_at).'.';
        }

        return 'Generated by AI from the uploaded document'
            .($this->ai_at ? ' on '.fmt_datetime($this->ai_at) : '').'.';
    }

    /**
     * Record a human's rewrite of the summary.
     *
     * The edit lands in `ai_summary` itself rather than in a second column: the row, the
     * listing and the assistant's context all read that one field, and a parallel
     * "override" column would mean three readers deciding for themselves which of the two
     * is current. The stamp is what keeps the provenance honest instead.
     */
    public function applySummaryEdit(?string $summary, ?int $editorId): void
    {
        $summary = is_string($summary) ? trim($summary) : null;
        $current = (string) $this->ai_summary;

        if ($summary === ($current === '' ? null : $current)) {
            return;
        }

        $this->ai_summary = $summary ?: null;
        $this->ai_summary_edited_at = now();
        $this->ai_summary_edited_by = $editorId;
    }

    public function summaryEditor()
    {
        return $this->belongsTo(\App\Models\User::class, 'ai_summary_edited_by');
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
        // The parties are part of the reading, not of the record: they were read off the
        // file being replaced. Leaving them would name the OLD document's counterparties in
        // the listing's first column, under the new document's name.
        $this->companies_involved = null;
        // A human edit belongs to the summary it was made to. Once that summary is gone the
        // stamp would credit somebody with wording they never saw.
        $this->ai_summary_edited_at = null;
        $this->ai_summary_edited_by = null;
    }
}
