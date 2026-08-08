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
    | `message_limit` caps ONE PASS of a sweep (0 = unlimited). It is a MEMORY
    | bound, not a coverage bound — that distinction is the whole fix below.
    |
    | It used to be the coverage bound: a sweep read the newest 500 and stopped,
    | which is sound only while a day's volume stays under 500. On
    | admin@claritas.asia it did not, and the arithmetic is unforgiving — a
    | 19:30 daily sweep that reaches back only to 03:56 leaves 15 hours of mail
    | read by no run, ever, which then ages out of the `since_days` window. That
    | happened on 27, 29, 30 Jul and 3, 4, 7 Aug 2026. The run rows warned about
    | it every time; a warning nobody acts on is not a safeguard.
    |
    | A sweep now keeps requesting older slices (offset paging, newest-first)
    | until it reaches back past what the previous run covered. Each pass is
    | processed and released before the next is fetched, so peak memory tracks
    | ONE pass however many it takes — which is why 500 can stay 500: it is the
    | measured-safe slice size (~40MB, ~60s on Graph), not a statement about how
    | much mail a sweep may read.
    |
    | Why not simply unlimited: measured on the real mailbox, an unbounded sweep
    | ran 25+ minutes and accumulated 180MB+ before failing, because the engine
    | held every normalized message for the whole run. Paged passes give the same
    | coverage with bounded memory.
    |
    */

    'since_days' => (int) env('EWF_SINCE_DAYS', 30),

    'message_limit' => (int) env('EWF_MESSAGE_LIMIT', 500),

    /*
    |--------------------------------------------------------------------------
    | Catch-up bounds
    |--------------------------------------------------------------------------
    |
    | What stops a sweep that is chasing coverage it cannot reach. Both are
    | backstops against a pathological mailbox, NOT a normal stopping point: in
    | the steady state a sweep needs one or two passes, and a run that regularly
    | needs many is telling you its capture cron should fire more often.
    |
    | `max_passes` bounds the work; `max_sweep_seconds` bounds the wall clock and
    | is the one that actually binds. It must stay comfortably under BOTH
    | CaptureService::STALE_RUN_MINUTES (or a healthy long run gets reaped as
    | dead mid-flight) and RunEmailWorkflowCapture::$timeout (or the worker kills
    | it first and the budget never applies). 40 minutes against 180 and 60.
    |
    | Neither bounds the FIRST pass — that is the pre-existing behaviour and a
    | sweep always does at least one, budget or no budget. When a run stops on a
    | budget it records the target it could not reach (coverage_gap_from), and
    | the next run inherits it, so the attempt is retried rather than forgotten.
    |
    | `pass_overlap` re-reads the last few messages of each pass on the next one.
    | Offsets are positions in a LIVE mailbox: mail arriving mid-sweep shifts
    | everything down (harmless — a re-read, and the captures table dedupes it),
    | but a deletion shifts the other way and would slide one message through the
    | seam unread. The overlap costs a handful of duplicate reads per pass and
    | closes that seam.
    |
    */

    'max_passes' => (int) env('EWF_MAX_PASSES', 40),

    'max_sweep_seconds' => (int) env('EWF_MAX_SWEEP_SECONDS', 2400),

    'pass_overlap' => (int) env('EWF_PASS_OVERLAP', 10),

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
