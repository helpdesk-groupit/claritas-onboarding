<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Capture sweep bounds
    |--------------------------------------------------------------------------
    |
    | How much mail one sweep looks at. `since_days` is the real coverage
    | decision: mail older than this is never captured, so raising it is the
    | only way to reach history (see first_sweep_on_activate in the Known dead
    | controls note in CLAUDE.md — the wizard's "load history" toggle is not
    | wired to anything).
    |
    | `message_limit` is a RUNAWAY GUARD, not a sampling rate. It exists so a
    | mailbox with tens of thousands of messages in the window can't produce an
    | unbounded sweep. It must sit comfortably above real volume inside the
    | window: when a sweep hits it, the oldest mail in the window is skipped —
    | permanently, because the window slides forward. CaptureService logs a
    | warning and says so in the run summary rather than truncating silently.
    |
    | Costs nothing when the mailbox is smaller: the paged fetch stops as soon
    | as a page comes back empty.
    |
    */

    'since_days' => (int) env('EWF_SINCE_DAYS', 30),

    'message_limit' => (int) env('EWF_MESSAGE_LIMIT', 1000),

    /*
    |--------------------------------------------------------------------------
    | IMAP fetch batch
    |--------------------------------------------------------------------------
    |
    | Messages per IMAP round-trip. setFetchBody(true) pulls each message in
    | full — body AND attachment parts — so this bounds peak memory: a sweep
    | holds one batch at a time, not the whole window. Smaller is safer on
    | mailboxes with fat PDFs; larger means fewer round-trips.
    |
    */

    'fetch_batch' => (int) env('EWF_FETCH_BATCH', 10),

    /*
    |--------------------------------------------------------------------------
    | Memory floor
    |--------------------------------------------------------------------------
    |
    | Raised only when the ambient limit is lower, and never below it. PHP's CLI
    | default here is 128M, which is what the SCHEDULER runs under, and a single
    | oversized message exhausts it inside the IMAP read loop. A PHP OOM is
    | fatal and uncatchable, so the run row would be orphaned in `running` with
    | no error to read.
    |
    */

    'memory_floor' => env('EWF_MEMORY_FLOOR', '512M'),

];
