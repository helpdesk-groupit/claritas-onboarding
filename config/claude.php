<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude model pricing — USD per MILLION tokens
    |--------------------------------------------------------------------------
    |
    | The single source of truth for what an Anthropic call costs, used by
    | ClaudeModelRate to price each usage-log row at the moment it is written.
    | Because the cost is materialised onto the row, editing a rate here only
    | affects FUTURE calls — past months keep the cost they were recorded at.
    |
    | Anthropic publishes NO machine-readable pricing endpoint (the Models API
    | returns capabilities — context window, vision, thinking — but not price),
    | so these cannot be fetched automatically without scraping a human web page,
    | which would silently misprice everything the day the page's layout changes.
    | Prices change a few times a year; update them here and deploy. Current
    | rates: https://platform.claude.com/docs/en/pricing
    |
    | Sonnet 5 is at its introductory rate ($2/$10) through 2026-08-31; move it to
    | $3/$10 (its standard rate) when the intro period ends.
    |
    */

    'model_rates' => [
        'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'input' => 1.00, 'output' => 5.00],
        'claude-sonnet-5' => ['label' => 'Claude Sonnet 5 (intro rate to 31-08-2026)', 'input' => 2.00, 'output' => 10.00],
        'claude-opus-4-8' => ['label' => 'Claude Opus 4.8', 'input' => 5.00, 'output' => 25.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | USD → MYR conversion
    |--------------------------------------------------------------------------
    |
    | Used only for the approximate MYR figures on the usage report. USD is the
    | currency Anthropic actually bills in; this is a convenience conversion.
    | Exchange rates drift — update as needed.
    |
    */

    'usd_myr_rate' => (float) env('CLAUDE_USD_MYR_RATE', 4.70),

];
