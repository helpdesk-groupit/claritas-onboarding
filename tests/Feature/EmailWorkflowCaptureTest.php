<?php

namespace Tests\Feature;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowCapture;
use App\Models\EmailWorkflowConnection;
use App\Models\EmailWorkflowRun;
use App\Models\User;
use App\Support\Automation\CaptureService;
use App\Support\Automation\Contracts\EmailSourceAdapter;
use App\Support\Automation\EmailAdapterFactory;
use App\Support\Automation\OAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end cover for the capture runtime: Gmail → DetectionEngine → Drive → Sheets.
 *
 * The whole Google surface is faked, so these assert our orchestration — ordering,
 * idempotency, resumability, error isolation — rather than Google's behaviour.
 * The adapters' request shapes are pinned by assertions on the recorded requests.
 */
class EmailWorkflowCaptureTest extends TestCase
{
    use RefreshDatabase;

    private User $itManager;

    private EmailWorkflow $workflow;

    private const MESSAGE_ID = 'msg-1001';

    private const ATTACHMENT = 'invoice-8842.pdf';

    protected function setUp(): void
    {
        parent::setUp();

        $this->itManager = User::factory()->itManager()->withTwoFactor()->create();
        $this->workflow = $this->makeWorkflow();
    }

    // ── The headline: one full capture ───────────────────────────────────

    public function test_a_matching_email_is_stored_in_drive_and_logged_to_sheets(): void
    {
        Http::fake($this->googleStack());

        $run = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_SUCCESS, $run->status, $run->error ?? '');
        $this->assertSame(1, $run->scanned_count);
        $this->assertSame(1, $run->matched_count);
        $this->assertSame(1, $run->captured_count);
        $this->assertSame(0, $run->failed_count);

        $capture = EmailWorkflowCapture::sole();
        $this->assertSame(EmailWorkflowCapture::STATUS_LOGGED, $capture->status);
        $this->assertSame('drive-file-1', $capture->stored_file_id);
        $this->assertSame(self::MESSAGE_ID.'|'.self::ATTACHMENT, $capture->idempotency_key);
        // RM 1,250.00 in the body → parsed, so no review flag.
        $this->assertEquals(1250.00, (float) $capture->amount);
        $this->assertSame('MYR', $capture->currency);
        $this->assertFalse($capture->needs_review);

        // The workflow's own counters are what the list page reads.
        $this->assertSame(1, $this->workflow->fresh()->captured_count);
        $this->assertNotNull($this->workflow->fresh()->last_run_at);

        // Bytes actually went up, into the month sub-folder, under the template name.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'upload/drive/v3/files')
            && $r->method() === 'POST'
            && $r['name'] === '2026-07-15_'.self::ATTACHMENT
            && $r['parents'] === ['drive-subfolder-2026-07']);

        // And a row landed in the month tab.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'values')
            && str_contains($r->url(), 'append')
            && str_contains(urldecode($r->url()), "'2026-07'"));
    }

    public function test_the_logged_row_matches_the_configured_column_mapping(): void
    {
        Http::fake($this->googleStack());

        app(CaptureService::class)->run($this->workflow);

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'append')) {
                return false;
            }

            // Column order is the sheet's header order, per DEFAULT_LOG_CONFIG.
            $row = $r['values'][0];

            return $row[0] === '2026-07-15'                       // Date received
                && $row[1] === 'Acme Supplies <billing@acme.test>' // Vendor/From
                && $row[2] === 'Invoice INV-8842 for June'         // Subject
                && $row[3] === 1250.0                              // Amount
                && $row[5] === self::ATTACHMENT                    // File name (ORIGINAL — half the idempotency key)
                && $row[6] === 'https://drive.test/file/1'         // File link
                && $row[7] === self::MESSAGE_ID;                   // Message ID
        });
    }

    // ── Idempotency: the property that makes re-runs safe ────────────────

    public function test_a_second_run_skips_an_already_logged_document(): void
    {
        Http::fake($this->googleStack());

        app(CaptureService::class)->run($this->workflow);
        $second = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_SUCCESS, $second->status);
        $this->assertSame(0, $second->captured_count);
        $this->assertSame(1, $second->skipped_count);

        // Still exactly one capture, and the workflow counter didn't double-count.
        $this->assertSame(1, EmailWorkflowCapture::count());
        $this->assertSame(1, $this->workflow->fresh()->captured_count);

        // The decisive assertion: no second upload, no second row.
        $this->assertSame(1, $this->sentCount('upload/drive/v3/files'));
        $this->assertSame(1, $this->sentCount('append'));
    }

    public function test_duplicate_capture_of_the_same_key_is_rejected_by_the_database(): void
    {
        $attrs = [
            'email_workflow_id' => $this->workflow->id,
            'message_id' => self::MESSAGE_ID,
            'attachment_name' => self::ATTACHMENT,
            'idempotency_key' => self::MESSAGE_ID.'|'.self::ATTACHMENT,
            'key_hash' => EmailWorkflowCapture::hashKey(self::MESSAGE_ID.'|'.self::ATTACHMENT),
        ];

        EmailWorkflowCapture::create($attrs);

        // The UNIQUE index — not app logic — is the real guarantee.
        $this->expectException(\Illuminate\Database\QueryException::class);
        EmailWorkflowCapture::create($attrs);
    }

    // ── Resumability: a crash must not lose or duplicate a document ───────

    public function test_a_run_that_stored_but_failed_to_log_resumes_without_re_uploading(): void
    {
        // The append fails once then recovers, driven by a sequence rather than a
        // second Http::fake() call — fake() MERGES stubs instead of replacing
        // them, so re-faking would leave the 500 in place and never recover.
        Http::fake($this->googleStack([
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*:append*' => Http::sequence()
                ->push(['error' => 'backend error'], 500)
                ->push(['updates' => ['updatedRows' => 1]], 200),
        ]));

        // Run 1: Drive stores the bytes, Sheets refuses the row.
        $first = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_PARTIAL, $first->status);
        $this->assertSame(1, $first->failed_count);

        $capture = EmailWorkflowCapture::sole();
        $this->assertSame(EmailWorkflowCapture::STATUS_FAILED, $capture->status);
        // The file is already in Drive — that fact must survive for the retry.
        $this->assertSame('drive-file-1', $capture->stored_file_id);

        // Run 2: Sheets recovers, and the capture resumes at the log step.
        $second = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_SUCCESS, $second->status);
        $this->assertSame(1, $second->captured_count);
        $this->assertSame(EmailWorkflowCapture::STATUS_LOGGED, $capture->fresh()->status);

        // The point of the whole exercise: one upload across BOTH runs.
        $this->assertSame(1, $this->sentCount('upload/drive/v3/files'));
    }

    public function test_a_capture_that_failed_before_storing_uploads_on_retry(): void
    {
        // The download dies, so nothing reaches Drive. The retry must still
        // upload — a resume keyed off status alone would skip the store step and
        // log a row pointing at a file that was never created.
        Http::fake($this->googleStack([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/*/attachments/*' => Http::sequence()
                ->push(['error' => 'transient'], 500)
                ->push(['data' => rtrim(strtr(base64_encode('%PDF-1.4 bytes'), '+/', '-_'), '=')], 200),
        ]));

        $first = app(CaptureService::class)->run($this->workflow);
        $this->assertSame(EmailWorkflowRun::STATUS_PARTIAL, $first->status);

        $capture = EmailWorkflowCapture::sole();
        $this->assertSame(EmailWorkflowCapture::STATUS_FAILED, $capture->status);
        $this->assertNull($capture->stored_file_id, 'Nothing should have been stored.');

        $second = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_SUCCESS, $second->status);
        $this->assertSame(EmailWorkflowCapture::STATUS_LOGGED, $capture->fresh()->status);
        $this->assertSame('drive-file-1', $capture->fresh()->stored_file_id);
        $this->assertSame(1, $this->sentCount('upload/drive/v3/files'));
    }

    public function test_an_existing_drive_file_is_reused_rather_than_duplicated(): void
    {
        // A file with the target name already sits in the folder (e.g. a prior
        // run whose capture row was wiped) — we must not create a "(1)" copy.
        Http::fake($this->googleStack(listFiles: [[
            'id' => 'pre-existing', 'name' => '2026-07-15_'.self::ATTACHMENT,
            'mimeType' => 'application/pdf', 'webViewLink' => 'https://drive.test/file/pre',
        ]]));

        $run = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(1, $run->captured_count);
        $this->assertSame('pre-existing', EmailWorkflowCapture::sole()->stored_file_id);
        $this->assertSame(0, $this->sentCount('upload/drive/v3/files'));
    }

    // ── Failure isolation + guards ───────────────────────────────────────

    public function test_an_unreachable_drive_folder_fails_the_run_with_an_actionable_message(): void
    {
        Http::fake($this->googleStack([
            'https://www.googleapis.com/drive/v3/files/drive-root*' => Http::response(['error' => 'not found'], 404),
        ]));

        $run = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('not accessible', $run->error);
        // A token minted before the registry asked for full Drive access is the
        // usual cause, so the message must point at re-consent, not at the link.
        $this->assertStringContainsString('Connect', $run->error);

        // Nothing was claimed, and the mailbox was never even read.
        $this->assertSame(0, EmailWorkflowCapture::count());
        $this->assertSame(0, $this->sentCount('gmail/v1/users/me/messages'));
    }

    public function test_drive_is_authorized_for_full_access_so_pasted_folder_links_resolve(): void
    {
        // Deliberate trade-off, not an oversight: `drive.file` reaches only files
        // this app created, so the wizard's pasted-folder-link flow needs `drive`.
        // Narrowing this again silently breaks every link-configured workflow.
        $this->assertSame(
            ['https://www.googleapis.com/auth/drive'],
            \App\Support\Automation\ProviderRegistry::find('gdrive')['scopes']
        );
    }

    public function test_a_reconsent_records_the_scope_the_provider_actually_granted(): void
    {
        // The registry's ask and the token's grant diverge whenever a scope
        // changes after a connection was authorized — trust the grant.
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/drive',
            ]),
        ]);

        $drive = $this->workflow->storageConnection;
        $drive->update(['scopes' => ['https://www.googleapis.com/auth/drive.file']]); // stale grant

        app(\App\Support\Automation\OAuthService::class)
            ->exchangeCode($drive, 'auth-code', 'https://app.test/callback');

        $this->assertSame(['https://www.googleapis.com/auth/drive'], $drive->fresh()->scopes);
        $this->assertSame(EmailWorkflowConnection::STATUS_CONNECTED, $drive->fresh()->status);
    }

    public function test_the_sweep_window_comes_from_config(): void
    {
        config(['email-workflow.since_days' => 45]);
        Http::fake($this->googleStack());

        // The configured window governs the SCHEDULED sweep; a manual Run now is
        // deliberately bounded to a shorter window (see the manual-bound test).
        app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'gmail/v1/users/me/messages?')
            && str_contains(urldecode($r->url()), 'newer_than:45d'));
    }

    public function test_an_unlimited_sweep_follows_every_page_to_the_end(): void
    {
        // The default. maxResults caps a PAGE, not the sweep — an adapter that
        // ignores nextPageToken truncates silently and still reports success.
        config(['email-workflow.message_limit' => 0]);

        Http::fake($this->googleStack([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::sequence()
                ->push(['messages' => [['id' => self::MESSAGE_ID]], 'nextPageToken' => 'p2'])
                ->push(['messages' => [['id' => self::MESSAGE_ID]], 'nextPageToken' => 'p3'])
                ->push(['messages' => [['id' => self::MESSAGE_ID]]]),   // last page: no token
        ]));

        $run = app(CaptureService::class)->run($this->workflow);

        // Three pages walked, so three message stubs scanned.
        $this->assertSame(3, $run->scanned_count);
        $this->assertSame(3, $this->sentCount('users/me/messages?'));
        // Unlimited cannot truncate, so it must never warn about a ceiling.
        $this->assertFalse(app(CaptureService::class)->hitCeiling($run));
    }

    public function test_an_unlimited_sweep_stops_when_the_window_is_exhausted(): void
    {
        // No nextPageToken on the first page → exactly one request. A loop that
        // keeps paging here would hammer the provider forever.
        config(['email-workflow.message_limit' => 0]);
        Http::fake($this->googleStack());

        app(CaptureService::class)->run($this->workflow);

        $this->assertSame(1, $this->sentCount('users/me/messages?'));
    }

    public function test_a_configured_cap_stops_paging_early(): void
    {
        config(['email-workflow.message_limit' => 2]);

        Http::fake($this->googleStack([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::sequence()
                ->push(['messages' => [['id' => self::MESSAGE_ID], ['id' => self::MESSAGE_ID]], 'nextPageToken' => 'p2'])
                ->push(['messages' => [['id' => self::MESSAGE_ID]], 'nextPageToken' => 'p3']),
        ]));

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertSame(2, $run->scanned_count, 'A cap must stop the sweep, not just the page.');
        $this->assertSame(1, $this->sentCount('users/me/messages?'), 'Must not fetch a page it cannot use.');
    }

    public function test_the_first_capped_sweep_says_the_backlog_was_not_read(): void
    {
        // With no earlier sweep, whatever sits behind the cap has been read by
        // nobody. Worth saying once — but only once. Coverage is a property of
        // the SCHEDULED sweep (the one that must be complete); a manual Run now
        // is a bounded test and never warns, so this asserts the scheduled run
        // records it on the run row, which is where the history reads it.
        config(['email-workflow.message_limit' => 1]);
        Http::fake($this->googleStack());

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertNotNull($run->coverage_warning);
        $this->assertStringContainsString('first sweep', $run->coverage_warning);
        $this->assertStringContainsString('was not read', $run->coverage_warning);
    }

    public function test_filling_the_cap_is_quiet_when_the_sweep_still_reaches_past_the_previous_run(): void
    {
        // THE DESIGN: re-read the newest N daily, let the dedupe skip the
        // overlap, leave the old backlog alone. A sweep that reaches back past
        // the previous run has covered every message that arrived since — so
        // nothing new was missed and there is nothing to say. Warning here would
        // fire on every healthy run and train the operator to ignore it.
        config(['email-workflow.message_limit' => 1]);

        // Previous sweep ran AFTER the mail this sweep reaches back to (2026-07-15).
        EmailWorkflowRun::create([
            'email_workflow_id' => $this->workflow->id,
            'status' => EmailWorkflowRun::STATUS_SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        Http::fake($this->googleStack());

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertNull($run->coverage_warning);
    }

    // ── Coverage: the sweep keeps going until it has caught up ───────────

    public function test_a_sweep_keeps_paging_older_until_it_reaches_the_previous_run(): void
    {
        // THE FIX. One pass used to be the whole sweep, so a mailbox taking more
        // than `message_limit` a day left the gap between two runs read by
        // neither — and the window then carried it away for good (that is exactly
        // what happened to admin@claritas.asia on 27, 29, 30 Jul and 3, 4, 7 Aug
        // 2026). The cap is a memory bound now; coverage is its own stop
        // condition, so the sweep asks for the next slice back until it has
        // overlapped what the previous run already covered.
        config(['email-workflow.message_limit' => 2, 'email-workflow.pass_overlap' => 0]);

        // The assertions below compare a stored timestamp against now() computed
        // again at assert time; a second boundary crossed mid-test makes them
        // differ by one second. Freeze so the test measures coverage, not clocks.
        $this->freezeTime();

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 3);
        $adapter = $this->fakeEmailAdapter($this->datedMessages(6));   // now … now-5h

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        // Two passes: now/-1h, then -2h/-3h — which reaches the previous run.
        $this->assertSame(2, $run->passes);
        $this->assertSame(4, $run->scanned_count);
        $this->assertSame(
            [['limit' => 2, 'offset' => 0], ['limit' => 2, 'offset' => 2]],
            $adapter->searchCalls
        );

        // Caught up, so there is nothing to warn about and the run says how far
        // back it got — which is what the NEXT run joins onto.
        $this->assertNull($run->coverage_warning);
        $this->assertNull($run->coverage_gap_from);
        // To the second: the column is a MySQL timestamp, so microseconds are
        // truncated on the way in and an exact-instant compare would never hold.
        $this->assertSame(
            now()->subHours(3)->format('Y-m-d H:i:s'),
            $run->covered_back_to->format('Y-m-d H:i:s')
        );
    }

    public function test_a_sweep_stops_as_soon_as_it_has_caught_up(): void
    {
        // The other half of the same property: continuation must not become a
        // "read everything" loop. One pass reaching past the previous run is a
        // complete sweep, and asking for a second slice would be pure waste on
        // every healthy workflow in the fleet.
        config(['email-workflow.message_limit' => 2, 'email-workflow.pass_overlap' => 0]);

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 1);
        $adapter = $this->fakeEmailAdapter($this->datedMessages(6));

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertSame(1, $run->passes);
        $this->assertSame(2, $run->scanned_count);
        $this->assertCount(1, $adapter->searchCalls);
        $this->assertNull($run->coverage_warning);
    }

    public function test_each_pass_re_reads_the_seam_so_a_shifting_mailbox_cannot_drop_a_message(): void
    {
        // An offset is a position in a LIVE mailbox: a deletion mid-sweep shifts
        // everything up, and the message on the seam would slide through unread
        // — permanently, since the next run starts from the newest. The overlap
        // buys that back for a handful of duplicate reads, which the captures
        // table dedupes away.
        config(['email-workflow.message_limit' => 2, 'email-workflow.pass_overlap' => 1]);

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 4);
        $adapter = $this->fakeEmailAdapter($this->datedMessages(8));

        app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        // Steps of (limit - overlap) = 1, so each pass re-reads the last message
        // of the one before it.
        $this->assertSame(
            [['limit' => 2, 'offset' => 0], ['limit' => 2, 'offset' => 1],
                ['limit' => 2, 'offset' => 2], ['limit' => 2, 'offset' => 3]],
            $adapter->searchCalls
        );
    }

    public function test_the_first_ever_run_reads_one_pass_and_does_not_walk_the_mailbox(): void
    {
        // There is no previous coverage to join onto, and reading an entire
        // mailbox because somebody switched a workflow on is not what they asked
        // for. It says so instead, and names the command that does it on purpose.
        config(['email-workflow.message_limit' => 2, 'email-workflow.pass_overlap' => 0]);

        Http::fake($this->googleStack());
        $adapter = $this->fakeEmailAdapter($this->datedMessages(50));

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertSame(1, $run->passes);
        $this->assertCount(1, $adapter->searchCalls);
        $this->assertStringContainsString('first sweep', $run->coverage_warning);
        $this->assertStringContainsString('--catch-up', $run->coverage_warning);
    }

    public function test_a_sweep_that_runs_out_of_budget_records_the_gap_it_could_not_close(): void
    {
        // The budget exists so a pathological mailbox cannot run a sweep past the
        // point where the reaper declares it dead. When it bites, the run must
        // say so — this is the ONE case left where mail is genuinely unread, and
        // it is now rare enough to be worth an operator's attention.
        config([
            'email-workflow.message_limit' => 2,
            'email-workflow.pass_overlap' => 0,
            'email-workflow.max_passes' => 1,
        ]);

        // See the paging test: an exact-timestamp assertion needs a frozen clock.
        $this->freezeTime();

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 10);
        $this->fakeEmailAdapter($this->datedMessages(20));

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertSame(1, $run->passes);
        $this->assertStringContainsString('ran out of budget', $run->coverage_warning);
        $this->assertStringContainsString('read by no run', $run->coverage_warning);
        // Not green: the sweep KNOWS there is mail behind it that nothing has
        // read, and the badge is all most operators ever look at.
        $this->assertSame(EmailWorkflowRun::STATUS_PARTIAL, $run->status);
        // The unmet target is state, not just prose — the next run reads it.
        $this->assertSame(
            now()->subHours(10)->format('Y-m-d H:i:s'),
            $run->coverage_gap_from->format('Y-m-d H:i:s')
        );
    }

    public function test_the_next_run_inherits_an_unclosed_gap_instead_of_forgetting_it(): void
    {
        // Without inheritance the hole is permanent: run N+1 would target run N's
        // START time, which is newer than the mail run N never reached, so a
        // single budget-limited night would silently cost a day of documents.
        config([
            'email-workflow.message_limit' => 2,
            'email-workflow.pass_overlap' => 0,
            'email-workflow.max_passes' => 1,
        ]);

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 10);
        $this->fakeEmailAdapter($this->datedMessages(20));

        $first = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);
        $this->assertNotNull($first->coverage_gap_from);

        // Budget restored: the second run must chase the INHERITED target
        // (now-10h), not its predecessor's start time (which is ~now and would
        // have let it stop after a single pass).
        config(['email-workflow.max_passes' => 40]);
        $second = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertSame(6, $second->passes, 'Stopped early — it forgot the inherited target.');
        $this->assertSame(12, $second->scanned_count);
        $this->assertNull($second->coverage_warning);
        $this->assertNull($second->coverage_gap_from);
    }

    public function test_a_target_older_than_the_window_is_clamped_to_the_window(): void
    {
        // Mail older than since_days can never be captured, so chasing past the
        // window is work that cannot produce a document — and it is what bounds
        // the worst case at one window's worth of reading.
        config([
            'email-workflow.message_limit' => 2,
            'email-workflow.pass_overlap' => 0,
            'email-workflow.since_days' => 1,
        ]);

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 24 * 30);      // long-paused workflow
        $this->fakeEmailAdapter($this->datedMessages(50));

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        // 24h of messages, two per pass — it stops at the window edge, not at the
        // two-month-old previous run.
        $this->assertSame(13, $run->passes);
        $this->assertNull($run->coverage_warning);
    }

    public function test_a_catch_up_sweep_reads_the_whole_window(): void
    {
        // The deliberate backlog recovery: ignore the previous run's coverage and
        // read the window out. Safe to repeat — the captures table dedupes.
        config(['email-workflow.message_limit' => 2, 'email-workflow.pass_overlap' => 0]);

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 1);            // a normal run would stop after one pass
        $adapter = $this->fakeEmailAdapter($this->datedMessages(6));

        $run = app(CaptureService::class)->run(
            $this->workflow, EmailWorkflowRun::TRIGGER_MANUAL, $this->itManager->id, catchUp: true
        );

        $this->assertSame(6, $run->scanned_count);
        $this->assertCount(4, $adapter->searchCalls);   // 3 full passes + the empty one that ends it
        $this->assertNull($run->coverage_warning);
    }

    public function test_catch_up_refuses_to_run_fleet_wide(): void
    {
        // It reads the entire window per mailbox. Firing that across every
        // workflow at once is never what someone means, and the cost is real.
        Http::fake($this->googleStack());

        $this->artisan('email-workflows:run --catch-up --sync')
            ->expectsOutputToContain('--catch-up needs --workflow=')
            ->assertFailed();

        $this->assertSame(0, EmailWorkflowRun::count());
    }

    public function test_the_catch_up_flag_reaches_the_capture_service(): void
    {
        config(['email-workflow.message_limit' => 2, 'email-workflow.pass_overlap' => 0]);

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 1);
        $this->fakeEmailAdapter($this->datedMessages(6));

        $this->artisan('email-workflows:run --workflow='.$this->workflow->id.' --catch-up --sync')
            ->assertSuccessful();

        // Without the flag reaching CaptureService this would have stopped at 2.
        $this->assertSame(6, EmailWorkflowRun::latest('id')->first()->scanned_count);
    }

    public function test_a_failed_continuation_pass_keeps_what_the_sweep_already_captured(): void
    {
        // Pass one already proved the mailbox works and its documents are already
        // in Drive. Throwing away a good run because the CATCH-UP hit a problem —
        // a provider refusing a deep offset, a transient 5xx — would report the
        // whole sweep as failed when most of it succeeded, and would make the
        // continuation a liability rather than an improvement.
        config(['email-workflow.message_limit' => 2, 'email-workflow.pass_overlap' => 0]);

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 10);
        $this->fakeEmailAdapter($this->datedMessages(20), failFromCall: 2);

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        // The run survives, keeps pass one's work, and says what went wrong.
        $this->assertSame(EmailWorkflowRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(1, $run->passes, 'A pass that read nothing must not be counted as work.');
        $this->assertSame(2, $run->scanned_count);
        $this->assertStringContainsString('refused the request for older mail', $run->coverage_warning);
        // And the target it never reached is inherited, so the next run retries.
        $this->assertNotNull($run->coverage_gap_from);
    }

    public function test_a_failed_first_pass_still_fails_the_whole_run(): void
    {
        // The other side of that rule: if the very first pass cannot read the
        // mailbox, nothing about this sweep is trustworthy and it must fail
        // loudly. Softening this would hide a dead connection behind an amber
        // badge for as long as anyone tolerated it.
        Http::fake($this->googleStack());
        $this->fakeEmailAdapter($this->datedMessages(20), failFromCall: 1);

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertSame(EmailWorkflowRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error);
    }

    // ── Adapters honour the offset exactly ───────────────────────────────

    public function test_the_gmail_adapter_skips_the_offset_without_fetching_those_messages(): void
    {
        // Gmail has no $skip, so the offset is walked client-side — but over
        // STUBS. Fetching and discarding full messages would make every later
        // pass cost as much as a first one.
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [['id' => 'm1'], ['id' => 'm2'], ['id' => 'm3']],
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/*' => Http::response($this->gmailMessage()),
        ]);

        $conn = $this->workflow->emailConnection;
        $out = app(EmailAdapterFactory::class)->for($conn)
            ->search($conn, ['since_days' => 30], ['limit' => 2, 'offset' => 1]);

        $this->assertCount(2, $out);
        $this->assertSame(0, $this->sentCount('/messages/m1'), 'Skipped messages must not be fetched.');
        $this->assertSame(1, $this->sentCount('/messages/m2'));
        $this->assertSame(1, $this->sentCount('/messages/m3'));
    }

    public function test_the_outlook_adapter_asks_graph_to_skip_server_side(): void
    {
        // $skip keeps a later pass to one request instead of re-listing (and
        // re-transferring the bodies of) everything already read.
        Http::fake(['https://graph.microsoft.com/v1.0/me/messages*' => Http::response(['value' => []])]);

        $conn = $this->workflow->emailConnection;
        $conn->update(['provider_id' => 'outlook']);

        app(EmailAdapterFactory::class)->for($conn->fresh())
            ->search($conn->fresh(), ['since_days' => 30], ['limit' => 500, 'offset' => 500]);

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), '$skip=500'));
    }

    public function test_the_outlook_adapter_omits_skip_on_a_first_pass(): void
    {
        // The ordinary pass must send exactly the request it always sent — a new
        // parameter on the hot path is a new way for Graph to say no.
        Http::fake(['https://graph.microsoft.com/v1.0/me/messages*' => Http::response(['value' => []])]);

        $conn = $this->workflow->emailConnection;
        $conn->update(['provider_id' => 'outlook']);

        app(EmailAdapterFactory::class)->for($conn->fresh())
            ->search($conn->fresh(), ['since_days' => 30], ['limit' => 500, 'offset' => 0]);

        Http::assertSent(fn (Request $r) => ! str_contains(urldecode($r->url()), '$skip'));
    }

    public function test_a_sweep_below_its_cap_is_never_flagged(): void
    {
        config(['email-workflow.message_limit' => 500]);
        Http::fake($this->googleStack());

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->assertNull($run->coverage_warning);
    }

    public function test_a_scheduled_sweeps_coverage_warning_is_readable_in_the_run_history(): void
    {
        // A scheduled run has no flash message, so the history is the only place
        // its warning can ever be read.
        config(['email-workflow.message_limit' => 1]);
        Http::fake($this->googleStack());

        app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->actingAs($this->itManager)
            ->getJson(route('it.automation.email-workflow.runs', $this->workflow->id))
            ->assertOk()
            ->assertJsonPath('runs.0.error', fn ($e) => $e !== null && str_contains($e, 'first sweep'));
    }

    public function test_a_lying_date_header_can_neither_stop_the_sweep_nor_reach_the_database(): void
    {
        // The Date: header is written by the sender, and the mailbox returned the
        // message because it ARRIVED in the window — so a date outside the window
        // is simply wrong. Folding it in would let one message decide coverage
        // for the whole sweep (break after pass 1, claim success), and writing it
        // would raise SQLSTATE 22007 against covered_back_to's MySQL TIMESTAMP
        // (1970..2038), failing a sweep that captured everything.
        config(['email-workflow.message_limit' => 2, 'email-workflow.pass_overlap' => 0]);

        Http::fake($this->googleStack());
        $this->previousRun(hoursAgo: 2);

        // now, LIE, LIE, -3h, -4h, -5h — one bad date per pass, which is the
        // realistic shape: a stray sender with a wrong clock among good mail.
        $messages = $this->datedMessages(6);
        $messages[1]['date'] = '1969-01-01T00:00:00+00:00';   // pre-epoch: unstorable
        $messages[2]['date'] = '9999-12-31T23:59:59+00:00';   // far future: unstorable

        $this->fakeEmailAdapter($messages);

        $run = app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        // Without the window guard, the 1969 header would be the minimum: the
        // sweep would break after pass 1 believing it had reached back past the
        // previous run, and then fail outright writing it to covered_back_to.
        $this->assertNotSame(EmailWorkflowRun::STATUS_FAILED, $run->status, $run->error ?? '');
        $this->assertSame(2, $run->passes);
        $this->assertTrue(
            $run->covered_back_to->greaterThan(now()->subDay()),
            'covered_back_to must come from a real date, not a lying header.'
        );
    }

    // ── Messages the parser could not read ───────────────────────────────

    public function test_messages_the_parser_could_not_read_are_counted_on_the_run(): void
    {
        // These used to leave no trace in any counter: scanned_count records what
        // the adapter RETURNED, so a message dropped inside the adapter simply
        // never existed as far as the run was concerned. ~20 documents a day
        // disappeared this way under a green tick.
        Http::fake($this->googleStack());
        $this->fakeEmailAdapter(unreadable: [
            ['ref' => 'message #115 in the imap window', 'error' => 'ErrorException: Must use comma to separate addresses: Billing'],
            ['ref' => 'message #116 in the imap window', 'error' => 'ErrorException: Must use comma to separate addresses: Billing'],
        ]);

        $run = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(2, $run->unreadable_count);
        // Not success: the sweep finished without doing its whole job, and the
        // badge is the only thing most operators ever look at.
        $this->assertSame(EmailWorkflowRun::STATUS_PARTIAL, $run->status);
        $this->assertStringContainsString('could not be read', $run->coverage_warning);
        $this->assertStringContainsString('Must use comma', $run->coverage_warning);
    }

    public function test_a_sweep_that_read_everything_reports_nothing_unreadable(): void
    {
        // The quiet case must stay quiet, or the amber badge means nothing.
        Http::fake($this->googleStack());

        $run = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(0, $run->unreadable_count);
        $this->assertSame(EmailWorkflowRun::STATUS_SUCCESS, $run->status);
        $this->assertNull($run->coverage_warning);
    }

    public function test_the_run_history_shows_the_unreadable_count(): void
    {
        // A scheduled sweep has no flash message, so the history panel is the
        // only place this can ever be read.
        Http::fake($this->googleStack());
        $this->fakeEmailAdapter(unreadable: [
            ['ref' => 'message #21 in the imap window', 'error' => 'ErrorException: Must use comma to separate addresses: Billing'],
        ]);

        app(CaptureService::class)->run($this->workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);

        $this->actingAs($this->itManager)
            ->getJson(route('it.automation.email-workflow.runs', $this->workflow->id))
            ->assertOk()
            ->assertJsonPath('runs.0.unreadable', 1)
            ->assertJsonPath('runs.0.error', fn ($e) => $e !== null && str_contains($e, 'could not be read'));
    }

    public function test_a_run_killed_without_finishing_is_reaped_not_left_running_forever(): void
    {
        // An OOM is fatal and uncatchable; a worker timeout or a SIGHUP kills the
        // process outright. None of them reach CaptureService's try/catch, so the
        // row says `running` forever and the list page shows a busy workflow that
        // isn't. (This is not hypothetical — a dropped SSH session did it.)
        $orphan = EmailWorkflowRun::create([
            'email_workflow_id' => $this->workflow->id,
            'status' => EmailWorkflowRun::STATUS_RUNNING,
            'started_at' => now()->subMinutes(CaptureService::STALE_RUN_MINUTES + 5),
        ]);

        Http::fake($this->googleStack());
        app(CaptureService::class)->run($this->workflow);

        $orphan->refresh();
        $this->assertSame(EmailWorkflowRun::STATUS_FAILED, $orphan->status);
        $this->assertStringContainsString('Interrupted', $orphan->error);
        $this->assertNotNull($orphan->finished_at);
    }

    public function test_a_long_running_sweep_is_not_declared_dead_while_it_works(): void
    {
        // An unlimited sweep on a large mailbox is legitimately slow. Reaping it
        // mid-flight would be a self-inflicted version of the same lie.
        $inFlight = EmailWorkflowRun::create([
            'email_workflow_id' => $this->workflow->id,
            'status' => EmailWorkflowRun::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
        ]);

        Http::fake($this->googleStack());
        app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_RUNNING, $inFlight->fresh()->status);
    }

    public function test_reaping_does_not_touch_another_workflows_runs(): void
    {
        $other = EmailWorkflow::create([
            'created_by' => $this->itManager->id, 'name' => 'Other', 'status' => 'draft',
        ]);
        $theirs = EmailWorkflowRun::create([
            'email_workflow_id' => $other->id,
            'status' => EmailWorkflowRun::STATUS_RUNNING,
            'started_at' => now()->subMinutes(CaptureService::STALE_RUN_MINUTES + 5),
        ]);

        Http::fake($this->googleStack());
        app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_RUNNING, $theirs->fresh()->status);
    }

    public function test_a_sweep_raises_a_low_memory_limit(): void
    {
        // The CLI default (128M) is what the scheduler runs under, and one fat
        // attachment exhausts it inside the IMAP read loop. A PHP OOM is fatal
        // and uncatchable, so the run row would be orphaned in `running`.
        $original = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '128M');
            Http::fake($this->googleStack());

            app(CaptureService::class)->run($this->workflow);

            $this->assertSame(CaptureService::MEMORY_FLOOR, ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    public function test_a_sweep_never_lowers_an_already_generous_memory_limit(): void
    {
        $original = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '1G');
            Http::fake($this->googleStack());

            app(CaptureService::class)->run($this->workflow);

            $this->assertSame('1G', ini_get('memory_limit'), 'Must not shrink a bigger limit.');
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    public function test_a_sweep_leaves_an_unlimited_memory_limit_alone(): void
    {
        $original = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '-1');
            Http::fake($this->googleStack());

            app(CaptureService::class)->run($this->workflow);

            $this->assertSame('-1', ini_get('memory_limit'), 'Unlimited beats any floor we would set.');
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    public function test_a_non_matching_email_is_left_alone(): void
    {
        Http::fake($this->googleStack([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/*' => Http::response(
                $this->gmailMessage(subject: 'Team lunch on Friday', attachment: 'menu.pdf')
            ),
        ]));

        $run = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->scanned_count);
        $this->assertSame(0, $run->matched_count);
        $this->assertSame(0, EmailWorkflowCapture::count());
    }

    public function test_a_disconnected_connection_fails_the_run_before_any_api_call(): void
    {
        Http::fake($this->googleStack());
        $this->workflow->storageConnection->update([
            'status' => EmailWorkflowConnection::STATUS_NEEDS_RECONNECT,
        ]);

        $run = app(CaptureService::class)->run($this->workflow->fresh());

        $this->assertSame(EmailWorkflowRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('not connected', $run->error);
        Http::assertNothingSent();
    }

    public function test_an_active_workflow_that_fails_is_flipped_to_error_status(): void
    {
        $this->workflow->update(['status' => EmailWorkflow::STATUS_ACTIVE]);
        Http::fake([
            'https://www.googleapis.com/drive/v3/files/*' => Http::response(['error' => 'boom'], 500),
            '*' => Http::response([], 200),
        ]);

        app(CaptureService::class)->run($this->workflow);

        $fresh = $this->workflow->fresh();
        $this->assertSame(EmailWorkflow::STATUS_ERROR, $fresh->status);
        $this->assertNotNull($fresh->last_error);
    }

    // ── The button + the scheduler ───────────────────────────────────────

    public function test_run_now_requests_a_background_sweep_instead_of_running_inline(): void
    {
        // Run now must NOT sweep in the request: a slow mailbox takes minutes and
        // the edge proxy 504s at ~100s. It marks the workflow and returns at
        // once; the every-minute scheduler runs the sweep out of band.
        Http::fake($this->googleStack());

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.run', $this->workflow->id))
            ->assertRedirect()
            ->assertSessionHas('info', fn ($m) => str_contains($m, 'requested'));

        // Nothing swept inline — no run row, no mailbox call in the request.
        $this->assertSame(0, EmailWorkflowRun::count());
        Http::assertNothingSent();

        // The marker is set and attributed to the operator who pressed it.
        $fresh = $this->workflow->fresh();
        $this->assertTrue($fresh->hasPendingRunRequest());
        $this->assertSame($this->itManager->id, $fresh->run_requested_by);
    }

    public function test_the_scheduler_runs_a_requested_sweep_and_clears_the_marker(): void
    {
        // The other half: the every-minute scheduler (no --force) picks up the
        // marker regardless of cron, runs the FULL sweep (CLI, no edge timeout),
        // labels it MANUAL + attributes it, and clears the marker so it fires
        // once — not every minute forever.
        Http::fake($this->googleStack());
        $this->workflow->requestImmediateRun($this->itManager->id);

        $this->artisan('email-workflows:run --sync')->assertSuccessful();

        $run = EmailWorkflowRun::sole();
        $this->assertSame(EmailWorkflowRun::STATUS_SUCCESS, $run->status, $run->error ?? '');
        $this->assertSame(EmailWorkflowRun::TRIGGER_MANUAL, $run->trigger);
        $this->assertSame($this->itManager->id, $run->triggered_by);
        $this->assertSame(1, $run->captured_count);   // full sweep, not a bounded slice
        $this->assertFalse($this->workflow->fresh()->hasPendingRunRequest());
    }

    public function test_a_requested_sweep_on_a_now_unready_workflow_clears_its_marker(): void
    {
        // State can drift between the click and the tick. A marker on a workflow
        // that no longer configures must not re-fire every minute forever.
        Http::fake();
        $this->workflow->update(['email_connection_id' => null]);   // break readiness
        $this->workflow->requestImmediateRun($this->itManager->id);

        $this->artisan('email-workflows:run --sync')->assertSuccessful();

        $this->assertSame(0, EmailWorkflowRun::count());
        $this->assertFalse($this->workflow->fresh()->hasPendingRunRequest());
        Http::assertNothingSent();
    }

    public function test_the_list_page_renders_the_run_and_history_controls(): void
    {
        $this->workflow->update(['last_run_at' => now()]);
        EmailWorkflowRun::create([
            'email_workflow_id' => $this->workflow->id,
            'status' => EmailWorkflowRun::STATUS_SUCCESS,
            'started_at' => now()->subSeconds(5), 'finished_at' => now(),
        ]);

        $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.index'))
            ->assertOk()
            ->assertSee('ewf-run-btn', false)       // Run now
            ->assertSee('ewf-history-btn', false)   // Run history
            ->assertSee('ewfHistoryModal', false)
            // The latest run's status badge renders beside "Last run".
            ->assertSee('ewf-run-badge', false);
    }

    public function test_the_run_history_endpoint_returns_runs_and_captures(): void
    {
        Http::fake($this->googleStack());
        app(CaptureService::class)->run($this->workflow);

        $this->actingAs($this->itManager)
            ->getJson(route('it.automation.email-workflow.runs', $this->workflow->id))
            ->assertOk()
            ->assertJsonPath('runs.0.status', EmailWorkflowRun::STATUS_SUCCESS)
            ->assertJsonPath('runs.0.captured', 1)
            ->assertJsonPath('captures.0.status', EmailWorkflowCapture::STATUS_LOGGED)
            ->assertJsonPath('captures.0.url', 'https://drive.test/file/1');
    }

    public function test_run_now_is_blocked_on_an_unconfigured_workflow(): void
    {
        Http::fake();
        $bare = EmailWorkflow::create([
            'created_by' => $this->itManager->id, 'name' => 'Bare', 'status' => 'draft',
        ]);

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.run', $bare->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, EmailWorkflowRun::count());
        // Readiness is checked before the marker is set, so nothing is queued.
        $this->assertFalse($bare->fresh()->hasPendingRunRequest());
    }

    public function test_run_now_is_forbidden_for_non_it_users(): void
    {
        Http::fake();
        $employee = User::factory()->withTwoFactor()->create(['role' => 'employee']);

        $this->actingAs($employee)
            ->post(route('it.automation.email-workflow.run', $this->workflow->id))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_scheduler_command_only_runs_workflows_whose_cron_is_due(): void
    {
        Http::fake($this->googleStack());

        // Active, but its cron fires at 19:00 — not "now" in the general case.
        $this->workflow->update([
            'status' => EmailWorkflow::STATUS_ACTIVE,
            'capture_cron' => '0 19 * * *',
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $this->travelTo(now()->setTimezone('Asia/Kuala_Lumpur')->setTime(9, 0));
        $this->artisan('email-workflows:run --sync')->assertSuccessful();
        $this->assertSame(0, EmailWorkflowRun::count(), 'Ran outside its schedule.');

        $this->travelTo(now()->setTimezone('Asia/Kuala_Lumpur')->setTime(19, 0));
        $this->artisan('email-workflows:run --sync')->assertSuccessful();
        $this->assertSame(1, EmailWorkflowRun::count(), 'Did not run at its scheduled time.');
        $this->assertSame(EmailWorkflowRun::TRIGGER_SCHEDULED, EmailWorkflowRun::sole()->trigger);
    }

    public function test_the_scheduler_command_ignores_non_active_workflows(): void
    {
        Http::fake($this->googleStack());
        $this->workflow->update(['status' => EmailWorkflow::STATUS_PAUSED]);

        $this->artisan('email-workflows:run --sync --force')->assertSuccessful();

        $this->assertSame(0, EmailWorkflowRun::count());
    }

    public function test_an_invalid_cron_is_skipped_rather_than_fatal(): void
    {
        Http::fake($this->googleStack());
        $this->workflow->update([
            'status' => EmailWorkflow::STATUS_ACTIVE,
            'capture_cron' => 'not-a-cron',
        ]);

        $this->artisan('email-workflows:run --sync')->assertSuccessful();
        $this->assertSame(0, EmailWorkflowRun::count());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /**
     * Swap in an email adapter that reports whatever it is told to.
     *
     * The real path here is IMAP, which cannot be faked with Http::fake() — the
     * failure lives in a MIME parser, not in an HTTP response. Faking at the
     * contract boundary keeps the assertions on what CaptureService does with an
     * adapter's report, which is the part that was missing.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<int,array{ref:string,error:string}>  $unreadable
     */
    private function fakeEmailAdapter(array $messages = [], array $unreadable = [], ?int $failFromCall = null): EmailSourceAdapter
    {
        $adapter = new class($messages, $unreadable, $failFromCall) implements EmailSourceAdapter
        {
            /** Every $paging array this adapter was asked for, in order. */
            public array $searchCalls = [];

            /**
             * @param  array<int,array<string,mixed>>  $messages
             * @param  array<int,array{ref:string,error:string}>  $unreadable
             */
            public function __construct(
                private array $messages,
                private array $unreadable,
                private ?int $failFromCall = null,
            ) {}

            public function providerId(): string
            {
                return 'imap';
            }

            public function verify(EmailWorkflowConnection $conn): void {}

            /**
             * Honours limit + offset like a real adapter, so a multi-pass sweep
             * walks this list exactly as it would walk a mailbox. A fake that
             * ignored offset would return the same slice forever and make a
             * paging bug look like correct behaviour.
             */
            public function search(EmailWorkflowConnection $conn, array $query, array $paging = []): array
            {
                $this->searchCalls[] = $paging;

                if ($this->failFromCall !== null && count($this->searchCalls) >= $this->failFromCall) {
                    throw new \RuntimeException('Graph rejected the request: Invalid $skip.');
                }

                $limit = max(0, (int) ($paging['limit'] ?? 0));
                $offset = max(0, (int) ($paging['offset'] ?? 0));

                $slice = array_slice($this->messages, $offset);

                return $limit > 0 ? array_slice($slice, 0, $limit) : $slice;
            }

            public function unreadableMessages(): array
            {
                return $this->unreadable;
            }

            public function getMessage(EmailWorkflowConnection $conn, string $messageId): array
            {
                return [];
            }

            public function downloadAttachment(EmailWorkflowConnection $conn, string $messageId, string $attachmentId): string
            {
                return '';
            }

            public function markProcessed(EmailWorkflowConnection $conn, string $messageId, string $label): void {}
        };

        $this->app->instance(EmailAdapterFactory::class, new class(app(OAuthService::class), $adapter) extends EmailAdapterFactory
        {
            public function __construct(OAuthService $oauth, private EmailSourceAdapter $adapter)
            {
                parent::__construct($oauth);
            }

            public function for(EmailWorkflowConnection $conn): EmailSourceAdapter
            {
                return $this->adapter;
            }
        });

        return $adapter;
    }

    /**
     * $count non-matching messages, newest first, one hour apart.
     *
     * Non-matching on purpose: these tests are about how far back a sweep
     * READS, and a matching message would drag Drive and Sheets into an
     * assertion that has nothing to say about coverage.
     *
     * @return array<int,array<string,mixed>>
     */
    private function datedMessages(int $count): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'message_id' => 'msg-'.$i,
                'from' => 'someone@acme.test',
                'subject' => 'Team lunch on Friday',   // no rule matches this
                'body' => 'nothing to capture here',
                'date' => now()->subHours($i)->toIso8601String(),
                'attachments' => [],
            ];
        }

        return $out;
    }

    /** A completed run this workflow can treat as its previous coverage. */
    private function previousRun(int $hoursAgo): EmailWorkflowRun
    {
        return EmailWorkflowRun::create([
            'email_workflow_id' => $this->workflow->id,
            'status' => EmailWorkflowRun::STATUS_SUCCESS,
            'started_at' => now()->subHours($hoursAgo),
            'finished_at' => now()->subHours($hoursAgo),
        ]);
    }

    private function makeWorkflow(): EmailWorkflow
    {
        $connection = fn (string $category, string $provider) => EmailWorkflowConnection::create([
            'created_by' => $this->itManager->id,
            'category' => $category,
            'provider_id' => $provider,
            'account_label' => 'finance@claritas.test',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);

        return EmailWorkflow::create([
            'created_by' => $this->itManager->id,
            'name' => 'Capture supplier invoices',
            'status' => EmailWorkflow::STATUS_DRAFT,
            'email_connection_id' => $connection('email', 'gmail')->id,
            'storage_connection_id' => $connection('storage', 'gdrive')->id,
            'log_connection_id' => $connection('log', 'gsheets')->id,
            'rules_json' => EmailWorkflow::DEFAULT_RULES,
            'storage_config_json' => array_merge(EmailWorkflow::DEFAULT_STORAGE_CONFIG, [
                'folder_ref' => 'https://drive.google.com/drive/folders/drive-root',
            ]),
            'log_config_json' => array_merge(EmailWorkflow::DEFAULT_LOG_CONFIG, [
                'target_ref' => 'https://docs.google.com/spreadsheets/d/sheet-root/edit',
            ]),
            'timezone' => 'Asia/Kuala_Lumpur',
            'capture_cron' => EmailWorkflow::DEFAULT_CAPTURE_CRON,
        ]);
    }

    /**
     * The happy-path Google surface. Pass overrides to break one call, or
     * $listFiles to pretend the destination folder already holds something.
     *
     * Ordered most-specific first: Http::fake() matches on insertion order, so a
     * broad pattern placed early would swallow the narrow ones after it.
     *
     * @param  array<string,mixed>  $overrides
     * @param  array<int,array<string,mixed>>  $listFiles
     * @return array<string,mixed>
     */
    private function googleStack(array $overrides = [], array $listFiles = []): array
    {
        $driveFile = [
            'id' => 'drive-file-1', 'name' => '2026-07-15_'.self::ATTACHMENT,
            'mimeType' => 'application/pdf', 'webViewLink' => 'https://drive.test/file/1',
        ];

        $subfolder = [
            'id' => 'drive-subfolder-2026-07', 'name' => '2026-07',
            'mimeType' => 'application/vnd.google-apps.folder',
            'webViewLink' => 'https://drive.test/folders/2026-07',
        ];

        // Union, not array_merge: `+` keeps the OVERRIDE's value for a duplicate
        // key and places it first, which is what Http::fake()'s first-match-wins
        // resolution needs. array_merge would keep the position but let the base
        // clobber the override's value.
        return $overrides + [
            // ── Gmail ──
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [['id' => self::MESSAGE_ID]],
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/*/attachments/*' => Http::response([
                'data' => rtrim(strtr(base64_encode('%PDF-1.4 fake invoice bytes'), '+/', '-_'), '='),
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/*' => Http::response($this->gmailMessage()),
            'https://gmail.googleapis.com/gmail/v1/users/me/labels*' => Http::response(['labels' => []]),

            // ── Drive ──
            'https://www.googleapis.com/upload/drive/v3/files*' => Http::sequence()
                // 1. open the resumable session → Location header
                ->push('', 200, ['Location' => 'https://upload.test/session/1'])
                // 2. the PUT of the bytes → the created file
                ->push($driveFile, 200)
                ->whenEmpty(Http::response($driveFile, 200)),
            'https://upload.test/session/*' => Http::response($driveFile),
            'https://www.googleapis.com/drive/v3/files/drive-root*' => Http::response([
                'id' => 'drive-root', 'name' => 'Invoices',
                'mimeType' => 'application/vnd.google-apps.folder',
                'webViewLink' => 'https://drive.test/folders/root',
            ]),
            // Both the folder search (GET) and the folder create (POST) carry a
            // query string, so one URL pattern catches both and Http::fake can't
            // discriminate on method — the stub has to. GET = search (empty means
            // "not there, create it"); POST = the create itself.
            'https://www.googleapis.com/drive/v3/files?*' => function (Request $request) use ($subfolder, $listFiles) {
                return $request->method() === 'POST'
                    ? Http::response($subfolder)
                    : Http::response(['files' => $listFiles]);
            },

            // ── Sheets ──
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*:append*' => Http::response(['updates' => ['updatedRows' => 1]]),
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response(['values' => []]),
            'https://sheets.googleapis.com/v4/spreadsheets/*:batchUpdate' => Http::response(['replies' => [[]]]),
            'https://sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
                'spreadsheetId' => 'sheet-root',
                'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/sheet-root',
                'properties' => ['title' => 'Invoice log'],
                'sheets' => [['properties' => ['title' => 'Sheet1']]],
            ]),
        ];
    }

    /**
     * A Gmail `format=full` payload, base64url-encoded exactly as Gmail sends it.
     *
     * @return array<string,mixed>
     */
    private function gmailMessage(
        string $subject = 'Invoice INV-8842 for June',
        string $attachment = self::ATTACHMENT,
    ): array {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return [
            'id' => self::MESSAGE_ID,
            'internalDate' => (string) (strtotime('2026-07-15 10:30:00') * 1000),
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'headers' => [
                    ['name' => 'From', 'value' => 'Acme Supplies <billing@acme.test>'],
                    ['name' => 'Subject', 'value' => $subject],
                ],
                'parts' => [
                    [
                        'mimeType' => 'text/plain',
                        'filename' => '',
                        'body' => ['data' => $b64('Please find attached. Total due RM 1,250.00 by 30 June.')],
                    ],
                    [
                        'mimeType' => 'application/pdf',
                        'filename' => $attachment,
                        'body' => ['attachmentId' => 'att-1', 'size' => 84210],
                    ],
                ],
            ],
        ];
    }

    /** How many recorded requests hit a URL fragment. */
    private function sentCount(string $fragment): int
    {
        // Http::recorded() yields [request, response] pairs. Filtering the full
        // collection is unambiguous; passing a predicate that returns false
        // silently counts nothing.
        return collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), $fragment))
            ->count();
    }
}
