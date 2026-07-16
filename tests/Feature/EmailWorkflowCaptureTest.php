<?php

namespace Tests\Feature;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowCapture;
use App\Models\EmailWorkflowConnection;
use App\Models\EmailWorkflowRun;
use App\Models\User;
use App\Support\Automation\CaptureService;
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

        app(CaptureService::class)->run($this->workflow);

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

        $run = app(CaptureService::class)->run($this->workflow);

        $this->assertSame(2, $run->scanned_count, 'A cap must stop the sweep, not just the page.');
        $this->assertSame(1, $this->sentCount('users/me/messages?'), 'Must not fetch a page it cannot use.');
    }

    public function test_a_sweep_that_hits_a_configured_cap_says_so_instead_of_reporting_a_clean_run(): void
    {
        // Reaching a cap means older mail in the window went unexamined — and the
        // window slides forward, so it is lost for good. Reported as a bare
        // success it is indistinguishable from "nothing to capture".
        config(['email-workflow.message_limit' => 1]);
        Http::fake($this->googleStack());

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.run', $this->workflow->id))
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'ceiling')
                && str_contains($m, 'not examined'));
    }

    public function test_an_unlimited_sweep_is_never_flagged_as_truncated(): void
    {
        config(['email-workflow.message_limit' => 0]);
        Http::fake($this->googleStack());

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.run', $this->workflow->id))
            ->assertRedirect()
            ->assertSessionMissing('warning')
            ->assertSessionHas('success');
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

    public function test_run_now_reports_the_outcome_to_the_operator(): void
    {
        Http::fake($this->googleStack());

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.run', $this->workflow->id))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'captured 1'));

        $this->assertSame(
            EmailWorkflowRun::TRIGGER_MANUAL,
            EmailWorkflowRun::sole()->trigger
        );
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
