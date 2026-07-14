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
