<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Entities (claim forms are entity-specific)
    |--------------------------------------------------------------------------
    | Used to scope category sets per company. Employee.company should match
    | one of these. Categories with a null company apply to all entities.
    */
    'entities' => ['Claritas', 'Enlinea', 'Nuren SG'],

    /*
    |--------------------------------------------------------------------------
    | Year-end grace day
    |--------------------------------------------------------------------------
    | A receipt must be claimed under a report for its own month, and within the
    | same calendar year — except for a grace window into January: up to and
    | including this day of January, the PREVIOUS year's months may still be
    | filed (catch-up for December, etc.). After it, the previous year is closed.
    */
    'year_end_grace_day' => 20,

    /*
    |--------------------------------------------------------------------------
    | Mileage
    |--------------------------------------------------------------------------
    | Per-km rates by vehicle. Distance is measured from `origin` (default
    | Jaya One) to the destination. Google Maps auto-calc is used when a key is
    | configured (see google_maps below); otherwise the employee enters km.
    */
    'mileage' => [
        'origin' => env('CLAIMS_MILEAGE_ORIGIN', 'Jaya One, Petaling Jaya, Selangor, Malaysia'),
        // Optional "lat,lon" to pin the fixed origin and skip geocoding it each lookup.
        'origin_coords' => env('CLAIMS_MILEAGE_ORIGIN_COORDS'),
        'rates' => [
            'car' => 0.70,
            'motorcycle' => 0.25,
        ],
        // Mileage is claimed under the Petrol account; this GL category offers a
        // "claim by mileage" mode in addition to "by receipt".
        'gl_code' => '919-000',
        // Categories on the mileage GL (919-000) that are NOT distance-based — they take an
        // actual receipt AMOUNT instead (e.g. the per-person Support Allowance, which is
        // petrol/transport receipts up to a monthly cap, not km × rate). Everything else on
        // the mileage GL is treated as mileage/distance as usual.
        'receipt_categories' => ['CLARITAS_SUPPORT_ALLOWANCE'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Overtime / extra-hours bands (hours => RM payout)
    |--------------------------------------------------------------------------
    | The highest band whose hours threshold is met wins (8h => 100, 4h => 50).
    */
    'ot_bands' => [
        4 => 50.00,
        8 => 100.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Event / programme day rate (RM per full day)
    |--------------------------------------------------------------------------
    | Community/ClubMama full-day events and Parentcraft/Superkid programmes.
    */
    'event_day_rate' => 150.00,

    /*
    |--------------------------------------------------------------------------
    | Role-based caps
    |--------------------------------------------------------------------------
    | Interns & probationary staff have a medical cap. Probation is detected via
    | employment_type, employment_status, or an unreached confirmation_date.
    */
    'intern_medical_cap' => 100.00,
    'intern_employment_types' => ['intern', 'internship', 'intern (paid)', 'intern (unpaid)'],
    // GL account that carries medical claims (intern/probationer cap applies here).
    'medical_gl_code' => '932-000',

    /*
    |--------------------------------------------------------------------------
    | Google Maps Distance Matrix (mileage auto-calc)
    |--------------------------------------------------------------------------
    | When no key is set, mileage falls back to manual km entry (the feature is
    | inert, not broken).
    */
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mileage auto-distance provider
    |--------------------------------------------------------------------------
    | 'ors'    => OpenRouteService (free, no credit card; geocode + driving route)
    | 'google' => Google Distance Matrix (needs a billing-enabled Maps key)
    | With no key for the selected provider, the destination box stays hidden and
    | the form falls back to manual km entry — the feature is inert, not broken.
    */
    'distance' => [
        'provider' => env('CLAIMS_DISTANCE_PROVIDER', 'google'),
        'ors_key' => env('CLAIMS_DISTANCE_ORS_KEY'),
        // Max km from the origin a destination geocode may resolve to. Caps the ORS
        // geocoder to a circle around Jaya One so an ambiguous short name (e.g.
        // "Suria KLCC") can't match a same-named place in another state. Raise it for
        // a company that legitimately claims long-distance trips.
        'max_radius_km' => env('CLAIMS_DISTANCE_MAX_RADIUS_KM', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Receipt OCR (reuses AiAccountingService)
    |--------------------------------------------------------------------------
    | Enabled only when an AI provider is configured for the accounting module.
    */
    'ocr' => [
        'enabled' => (bool) env('CLAIMS_OCR_ENABLED', false),
        // Provider for claim OCR, independent of the accounting module:
        // gemini | openai | anthropic | ollama. Null = reuse the accounting setting.
        'provider' => env('CLAIMS_OCR_PROVIDER'),
        // Falls back to the accounting AI key, then services.openai.api_key.
        'api_key' => env('CLAIMS_OCR_API_KEY'),
        'model' => env('CLAIMS_OCR_MODEL'),
        // Ollama host (when provider=ollama). Null = reuse the accounting
        // ollama_base_url, then default http://localhost:11434.
        'ollama_url' => env('CLAIMS_OCR_OLLAMA_URL'),
        // Multi-receipt scan: max transactions read from ONE image (a long bank/toll
        // statement is split into this many rows at most). The review table flags a
        // "truncated" result so nothing is silently dropped. Raise for very long
        // statements at the cost of accuracy + tokens; split/crop is the alternative.
        'max_items' => (int) env('CLAIMS_OCR_MAX_ITEMS', 40),
        // Output token budget for the multi-item scan; must be generous enough to fit
        // max_items objects of JSON. ~100 tokens/row is a safe rule of thumb.
        'max_tokens' => (int) env('CLAIMS_OCR_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Manager-approval buffer (working days)
    |--------------------------------------------------------------------------
    | The policy deadline day (e.g. 20th) is the HR cutoff — a claim must be
    | MANAGER-APPROVED by then to be processed this cycle. The employee submission
    | deadline = HR cutoff minus this many working days. Set to 0 so employees see
    | the same date as the HR cutoff (the 20th); raise it to give managers a lead
    | time in which employees must submit earlier than the cutoff.
    */
    'manager_buffer_days' => (int) env('CLAIMS_MANAGER_BUFFER_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | Approved-claim PDF filename prefix (per company)
    |--------------------------------------------------------------------------
    | Generated PDFs are named like the originals, e.g.
    |   ENSB-SE-20260430-<Name>_<Event>_Claim_<Mon>_<YY>.pdf
    | Keyed by a case-insensitive substring of the employee's company; if none
    | matches, the prefix is derived from the company's initials + "-SE".
    */
    'file_prefixes' => [
        'Enlinea' => 'ENSB-SE',
    ],

    /*
    |--------------------------------------------------------------------------
    | Approved-claim ZIP export (HR bulk download)
    |--------------------------------------------------------------------------
    | Rendering a batch of claim PDFs is one of the heaviest things this app
    | does: each claim embeds its receipt images, and dompdf decodes every
    | image through GD, which costs w*h*4 bytes of transient RAM (a
    | 5-megapixel receipt photo = ~22 MB) on top of the PDF itself.
    |
    | This used to render INLINE in the web request, bounded by a wall-clock
    | budget so it never outran nginx's `proxy_read_timeout 60s` on this
    | site's own vhost (two 504s on 2026-08-18 are what forced that design).
    | The batch was ordered newest-processed-first, so once a cycle's approved
    | claims exceeded the old 60-claim cap, whatever got silently cut from the
    | tail skewed toward the claims approved EARLIEST in the cycle — which
    | read to HR as "only what I approved today shows up" (confirmed against
    | production data 2026-09-03: the August cycle had 79 HR-approved claims
    | against the 60-claim cap).
    |
    | The export now renders in a background job (BuildClaimZipExport, on the
    | same `database` queue that drains Email Workflow sweeps and Social
    | Strategist generation) — see ExpenseClaimZipExport. Off the request
    | lifetime, there is no 60s wall to protect against, so every matching
    | claim renders regardless of how many there are.
    |
    | 'max_claims' is now only a SANITY CEILING against a genuinely
    | pathological filter (not normal cycle volume) — sized well above any
    | real cycle's count. 'job_timeout' is a safety net on the job itself
    | (well under the queue worker's own 3600s timeout) so a stuck render is
    | killed cleanly rather than hanging the worker. Neither truncates
    | silently: the ZIP carries an _EXPORT-NOTES.txt naming exactly what (if
    | anything) was left out, and the export's status also reports it.
    */
    'zip_export' => [
        'max_claims' => (int) env('CLAIMS_ZIP_MAX_CLAIMS', 2000),
        'job_timeout' => (int) env('CLAIMS_ZIP_JOB_TIMEOUT', 1800),
        // How long a finished export (ready or failed) and its stored archive are kept
        // before claims:prune-zip-exports discards them.
        'retention_hours' => (int) env('CLAIMS_ZIP_RETENTION_HOURS', 48),
        // BuildClaimZipExport runs inside the queue worker, which on the live NAS is launched
        // by the Synology Task Scheduler as `root` — a different OS user than the PHP-FPM
        // pool (`http`) that serves the download back to the browser. A directory the job
        // creates for the first time is therefore owned by root, mode 0700, and invisible to
        // the web server. Set this to the web server's OS group (e.g. `http`) so the job can
        // chgrp+chmod its own output to be group-readable — deliberately NOT world-readable,
        // and deliberately scoped to only this one export directory, never the disk's other
        // (sensitive) private directories. Left null/unset on every other environment (e.g.
        // local dev) so this is a no-op there.
        'storage_group' => env('CLAIMS_ZIP_STORAGE_GROUP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Claim PDF memory floor
    |--------------------------------------------------------------------------
    | Applies to EVERY claim PDF (single download, HR view, batch ZIP), because
    | the expensive part is per-IMAGE, not per-batch: dompdf decodes each
    | embedded receipt through GD at w*h*4 bytes, so one 5-megapixel receipt
    | photo costs ~22 MB of transient buffer, and a claim may carry several.
    |
    | Applied as a FLOOR — it never lowers a pool that was deliberately given
    | more room, and never caps an unlimited (-1) limit. Set to null to leave
    | the pool's own memory_limit alone entirely.
    */
    'pdf_memory_limit' => env('CLAIMS_PDF_MEMORY_LIMIT', '512M'),

    /*
    |--------------------------------------------------------------------------
    | PDF receipt previews (inline pictures for PDF attachments)
    |--------------------------------------------------------------------------
    | A PDF receipt can never be embedded inline by dompdf, so in the claim form
    | it printed a sentence where every other line showed a picture — which read
    | to approvers as the receipt having been dropped, even though the pages are
    | appended in full after the form (verified on a real export: EC-2026-08-0061
    | downloads as 20 pages, 18 of them receipt).
    |
    | The pages are rasterised in the BROWSER by pdf.js and posted back, because
    | this host has no Imagick, Ghostscript or Poppler — the same limitation that
    | makes ClaimReceiptOcrService send Anthropic a native `document` block.
    |
    | 'max_pages' bounds what goes INTO A CLAIM ROW, not what is kept: a long
    | statement would otherwise push the form off its own page. Anything beyond
    | it is still reproduced in full in the appended pages.
    | 'max_upload_kb' caps one posted page image. These are downscaled JPEGs of a
    | single page, so this is generous; it exists to stop the endpoint being used
    | as general file storage.
    */
    'pdf_preview' => [
        'enabled' => (bool) env('CLAIMS_PDF_PREVIEW_ENABLED', true),
        'max_pages' => (int) env('CLAIMS_PDF_PREVIEW_MAX_PAGES', 3),

        /*
         * The cap on pages STORED, which is a different question from how many a row shows.
         * When FPDI cannot open a receipt (a compressed cross-reference stream — 4 of the 119
         * PDF receipts on production, all %PDF-1.6), these rasterised pages are the only way
         * that receipt reaches the downloaded report, and a truncated one would be a partial
         * copy of an approved claim's evidence. So storage runs to the whole document while
         * `max_pages` keeps bounding the picture inside the item row.
         */
        'store_max_pages' => (int) env('CLAIMS_PDF_PREVIEW_STORE_MAX_PAGES', 20),
        'max_upload_kb' => (int) env('CLAIMS_PDF_PREVIEW_MAX_UPLOAD_KB', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public holidays (deadline roll-back)
    |--------------------------------------------------------------------------
    | The 20th submission deadline rolls back to the preceding working day when
    | it lands on a weekend or one of these dates. Only fixed-date national
    | holidays are listed — MAINTAIN this yearly: add state holidays and the
    | shifting lunar dates (Chinese New Year, Hari Raya, Deepavali, Wesak,
    | Agong's birthday, etc.). Weekends are always skipped automatically.
    */
    'public_holidays' => [
        '2026-01-01', // New Year's Day
        '2026-05-01', // Labour Day
        '2026-08-31', // Merdeka
        '2026-09-16', // Malaysia Day
        '2026-12-25', // Christmas
    ],
];
