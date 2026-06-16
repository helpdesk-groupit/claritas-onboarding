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
            'motorcycle' => 0.35,
        ],
        // Mileage is claimed under the Petrol account; this GL category offers a
        // "claim by mileage" mode in addition to "by receipt".
        'gl_code' => '919-000',
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
