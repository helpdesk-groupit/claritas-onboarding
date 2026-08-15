<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Our own SST (service tax) category
    |--------------------------------------------------------------------------
    |
    | The taxable-service category OUR company is registered under. When it matches
    | a vendor's category, the B2B exemption applies and that vendor may not charge
    | us service tax — Vendor Management says so on the vendor's profile and flags
    | any invoice that carries an SST line anyway.
    |
    | Left NULL on purpose. Where our SST identity should ultimately live — per legal
    | entity on `companies`, or once for the whole group — is still an open decision,
    | and a wrong default here would quietly assert "SST is chargeable" on every
    | vendor. While it is null the verdict honestly reads "not determined"; set it
    | (here or via VENDOR_OWN_SST_CATEGORY) and every vendor page goes live at once.
    |
    | Must be one of the keys in `sst_categories` below. May also be an ARRAY of keys
    | (edit this file rather than the env var) — we can be registered under more than
    | one group for the same reason a vendor can, and the exemption then applies to
    | whichever group is shared.
    |
    */
    'own_sst_category' => env('VENDOR_OWN_SST_CATEGORY'),

    /*
    |--------------------------------------------------------------------------
    | SST categories
    |--------------------------------------------------------------------------
    |
    | Leave empty to use Vendor::DEFAULT_SST_CATEGORIES — Groups A to L of the Service
    | Tax First Schedule, plus the two answers that are not groups (Sales Tax registrant
    | and Not SST-registered). Override the whole list when Finance wants different
    | wording. The letters live in the LABELS only: they have been re-assigned by more
    | than one amendment, so a re-gazette is a label edit here rather than a data
    | migration. Keys are what gets stored; changing a KEY orphans existing vendor rows
    | (Vendor::LEGACY_SST_CATEGORIES exists so the last such change stayed readable).
    |
    */
    'sst_categories' => [],

    /*
    |--------------------------------------------------------------------------
    | AARF reference prefixes
    |--------------------------------------------------------------------------
    |
    | Rental asset acknowledgement forms are numbered {prefix}-{year}-{0001}. Keyed by
    | direction: a receipt and a return are the same document with the type flipped, but
    | they are numbered apart so a reference alone says which way the assets moved.
    |
    | Distinct from config('decommission.batch_prefixes') on purpose — EWA-… numbers e-waste
    | cycles, which archive assets out of inventory as WASTE. An AARF hands them back to
    | their owner. (There was a RET-… prefix for vendor-return batches until 2026-08-10,
    | when returns moved onto this form and are numbered RTA-… here instead.)
    |
    */
    'aarf_prefixes' => [
        'receipt' => 'RRA',
        'return' => 'RTA',
    ],

    /*
    |--------------------------------------------------------------------------
    | AARF start-tracking date
    |--------------------------------------------------------------------------
    |
    | The day the acknowledgement process began. A rental asset registered BEFORE this
    | date was already with us when the process started, so it is never asked for — only
    | assets registered on or after it appear as awaiting acknowledgement.
    |
    | Without it, switching the feature on makes every rental asset the company has ever
    | held come up as pending at once, which buries the handful that genuinely just
    | arrived. Defaults to the date the feature was introduced; set AARF_TRACK_FROM to
    | the day you switch it on in each environment.
    |
    | Leaving it EMPTY tracks every rental asset regardless of age. That is the safe
    | direction to fail — an unreadable date must never silently stop new arrivals from
    | ever being acknowledged, which is the failure nobody would notice.
    |
    */
    'aarf_track_from' => env('AARF_TRACK_FROM', '2026-08-07'),

    /*
    |--------------------------------------------------------------------------
    | Bulk import
    |--------------------------------------------------------------------------
    |
    | Registering vendors from a spreadsheet the company already keeps. The uploaded file
    | is parked on the private disk between the preview and the confirmation, so that the
    | operator can correct the column mapping without re-uploading and so that the import
    | is always derived from the document rather than from a payload posted back.
    |
    | `retention_hours` is how long an UNCONFIRMED upload waits before the nightly sweep
    | (`vendors:prune-import-batches`) discards it with its file. Long enough to survive an
    | operator being interrupted mid-review; short enough that abandoned lists — which carry
    | real vendor contact and banking details — do not accumulate.
    |
    | Confirming or cancelling an import discards its file immediately either way, so this
    | window only ever applies to a browser tab that was closed.
    |
    */
    'import' => [
        'retention_hours' => env('VENDOR_IMPORT_RETENTION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document summaries + the vendor Q&A assistant
    |--------------------------------------------------------------------------
    |
    | The ONE machine reading of an uploaded contract/quotation/invoice: a readable
    | summary shown on its row plus a faithful transcription the assistant answers
    | questions from. Runs on the same Anthropic key and provider resolution as the
    | eClaim receipt scan (Superadmin → Settings → Claude API, else CLAIMS_OCR_*).
    |
    | Set `enabled` to false to switch it off without touching the key — uploads and
    | every existing screen keep working; the rows simply carry no summary.
    |
    | A `vendors.ocr` block used to sit above this one, configuring a per-field scan
    | that pre-filled the contract and billing forms. That feature was removed on
    | 2026-08-11 (the fields are typed by hand) and its config went with it — an env
    | var nothing reads is worse than none, because it reads as a working switch.
    |
    | NOTE: only Anthropic can read a PDF. On any other provider a PDF is recorded as
    | "not scanned" and is therefore NOT available to the assistant — which the page
    | states per document rather than hiding.
    |
    */
    'ai' => [
        'enabled' => env('VENDOR_AI_ENABLED', true),

        // Caps the summary + transcription reply. A transcript longer than this comes
        // back truncated, which is recorded as ai_status = 'partial' and announced —
        // never quietly presented as the whole document.
        'summary_max_tokens' => env('VENDOR_AI_SUMMARY_MAX_TOKENS', 8000),

        // How long to wait for that reply. Deliberately far above the OCR transport's own
        // 45s default, which was sized for a photo of a receipt: this call transcribes a
        // whole multi-page PDF, the request is not streamed, and the client therefore sits
        // at zero bytes until the entire generation finishes. At 45s the first real contract
        // filed on live — a signed multi-page agreement — died twice on the timeout and was
        // reported to the operator as unreadable, which it was not.
        //
        // Safe to make this longer than the edge will hold a request open: the scan stores
        // its file and its staging row BEFORE calling the model, and the browser polls for
        // the result under a token it generated, so a read that outlives the request is
        // collected rather than paid for and thrown away.
        'read_timeout' => env('VENDOR_AI_READ_TIMEOUT', 180),

        // Caps the second, text-only pass that reads the parties and the record fields off
        // the transcript. Small on purpose: it returns a handful of values, and a ceiling
        // sized for prose would only buy room for the model to pad.
        'detail_max_tokens' => env('VENDOR_AI_DETAIL_MAX_TOKENS', 1500),

        // How long an uploaded-but-unfiled document waits in `vendor_document_scans`
        // before the nightly sweep discards it and its file. Long enough to survive an
        // operator being interrupted mid-upload, short enough that abandoned files do not
        // accumulate on the private disk.
        'scan_retention_hours' => env('VENDOR_AI_SCAN_RETENTION_HOURS', 24),

        'chat_max_tokens' => env('VENDOR_AI_CHAT_MAX_TOKENS', 2000),

        // How many prior messages of the thread go into the next question, before the
        // most recent "Start new topic" divider is reached.
        'chat_history_turns' => env('VENDOR_AI_CHAT_HISTORY', 10),

        // Hard ceiling on how much document text one question may carry. A vendor with
        // forty filed documents would otherwise blow the context window and the bill.
        // Exceeding it DROPS documents (newest kept) and the answer names the dropped
        // ones — a silent truncation would read as "that clause isn't in our documents".
        'chat_context_chars' => env('VENDOR_AI_CHAT_CONTEXT_CHARS', 400000),
    ],

];
