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
    | `message_limit` caps ONE SWEEP (0 = unlimited). 500 is chosen so each
    | sweep re-reads the newest 500, the captures table skips what it already
    | has, and the window slides forward with the mailbox. While a day's volume
    | stays well under the cap, every arriving message lands in at least one
    | sweep before sliding past it — so nothing NEW is missed, and the cap costs
    | only the old backlog, which is a deliberate choice. At the observed ~35
    | messages/day that is roughly a fortnight of catch-up slack, so a few missed
    | sweeps are harmless.
    |
    | It only goes wrong if daily volume ever approaches the cap. CaptureService
    | detects exactly that (comparing how far back a sweep reached against the
    | previous sweep) and records a coverage_warning on the run. It does NOT warn
    | merely for filling the cap — that is the design working, and warning every
    | run would be noise nobody reads.
    |
    | Why not unlimited: measured on the real mailbox, an unbounded sweep ran 25+
    | minutes and accumulated 180MB+ before failing — the engine holds every
    | normalized message for the run, so memory and time grow with the mailbox,
    | not with the page. Unlimited would need a streaming redesign of
    | EmailSourceAdapter::search (yield per message instead of returning an
    | array). 500 keeps a sweep near ~10 minutes.
    |
    */

    'since_days' => (int) env('EWF_SINCE_DAYS', 30),

    'message_limit' => (int) env('EWF_MESSAGE_LIMIT', 500),

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

    /*
    |--------------------------------------------------------------------------
    | API request timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | For the HTTPS providers (Gmail, Microsoft Graph). Laravel's HTTP client
    | defaults to 30s, which is an INTERACTIVE timeout — and a sweep is not
    | interactive. It asks for up to `fetch_batch`-sized pages of messages WITH
    | their bodies, filtered and sorted server-side, and a mail API can take a
    | while to answer the first page against a real mailbox.
    |
    | The default bit on 2026-07-17: every Graph sweep died on
    | `cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes
    | received for https://graph.microsoft.com/v1.0/me/messages`. Nothing was
    | unreachable — earlier calls to the same host answered fine — Graph simply
    | had not finished composing the response within 30s.
    |
    | 120s is generous but bounded. It costs nothing on a healthy call (the
    | timeout is a ceiling, not a wait) and the scheduled sweep has no wall-clock
    | limit of its own to blow. The synchronous "Run now" browser request may
    | still give up on a very large mailbox — that is already true and by design;
    | the run completes server-side and lands in the run history either way.
    |
    */

    'request_timeout' => (int) env('EWF_REQUEST_TIMEOUT', 120),

];
