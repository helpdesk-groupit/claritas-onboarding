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
    | `message_limit` is **0 = unlimited** by default: a sweep reads every
    | message in the window, bounded only by `since_days`. Set a positive number
    | only to deliberately cap a sweep — and know that hitting the cap skips the
    | oldest mail in the window permanently, because the window slides forward.
    | CaptureService logs a warning and says so in the run summary rather than
    | truncating silently.
    |
    | Unlimited is safe here because every adapter paginates and releases each
    | page (see EmailSourceAdapter::search) — peak memory tracks one page, not
    | the sweep. It costs nothing on a small mailbox: paging stops as soon as a
    | page comes back empty. What it does cost is TIME (~2 min per 100 IMAP
    | messages), which matters for the synchronous "Run now" button: the browser
    | will give up long before a large sweep finishes, though the run itself
    | completes server-side and lands in the run history. The scheduled sweep has
    | no such limit — PHP CLI max_execution_time is 0.
    |
    */

    'since_days' => (int) env('EWF_SINCE_DAYS', 30),

    'message_limit' => (int) env('EWF_MESSAGE_LIMIT', 0),

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
