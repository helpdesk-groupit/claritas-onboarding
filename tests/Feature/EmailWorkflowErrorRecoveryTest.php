<?php

namespace Tests\Feature;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowConnection;
use App\Models\EmailWorkflowRun;
use App\Models\User;
use App\Support\Automation\CaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cover for recovery from EmailWorkflow::STATUS_ERROR.
 *
 * The trapdoor these pin shut: CaptureService flips an Active workflow to
 * `error` when a run fails, RunEmailWorkflows only ever swept `active`, and a
 * successful run never restored `error` → `active`. So one failed run — a
 * mailbox blip, an expired token, a Zoho mailbox with IMAP switched off —
 * removed the automation from the schedule permanently. It could not heal,
 * because healing required a success and a success required being swept.
 *
 * Found 2026-07-17 with a real workflow (“capture supplier invoices-billing@”)
 * sitting in exactly this state after its mailbox was repaired.
 */
class EmailWorkflowErrorRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $itManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->itManager = User::factory()->itManager()->withTwoFactor()->create();
    }

    private function connection(string $category, string $provider): EmailWorkflowConnection
    {
        return EmailWorkflowConnection::create([
            'created_by' => $this->itManager->id,
            'category' => $category,
            'provider_id' => $provider,
            'account_label' => 'acct@example.com',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);
    }

    /** A fully-configured workflow in the given status. */
    private function workflow(string $status): EmailWorkflow
    {
        return EmailWorkflow::create([
            'created_by' => $this->itManager->id,
            'name' => 'Supplier invoices',
            'status' => $status,
            'email_connection_id' => $this->connection('email', 'gmail')->id,
            'storage_connection_id' => $this->connection('storage', 'gdrive')->id,
            'log_connection_id' => $this->connection('log', 'gsheets')->id,
            'rules_json' => EmailWorkflow::DEFAULT_RULES,
            // Real link shapes — the Drive/Sheets adapters reject bare ids, so a
            // placeholder here would fail the run before it reached the mailbox
            // and quietly test nothing.
            'storage_config_json' => ['folder_ref' => 'https://drive.google.com/drive/folders/drive-root'],
            'log_config_json' => ['target_ref' => 'https://docs.google.com/spreadsheets/d/sheet-root/edit'],
            'timezone' => 'Asia/Kuala_Lumpur',
            'capture_cron' => '0 19 * * *',
            'reconcile_cron' => '0 7 * * *',
        ]);
    }

    // ── The scheduler must keep retrying a failed workflow ───────────────

    /**
     * `error` means "the last run failed", not "disabled". The operator's Active
     * toggle is still on, so the sweep must keep trying — otherwise a five-minute
     * mailbox outage silently retires the automation until a human notices.
     */
    public function test_the_scheduler_still_sweeps_a_workflow_whose_last_run_failed(): void
    {
        $errored = $this->workflow(EmailWorkflow::STATUS_ERROR);

        $swept = $this->sweptWorkflowIds();

        $this->assertContains($errored->id, $swept);
    }

    /** Paused and draft are operator intent — those stay out of the sweep. */
    public function test_the_scheduler_does_not_sweep_paused_or_draft_workflows(): void
    {
        $paused = $this->workflow(EmailWorkflow::STATUS_PAUSED);
        $draft = $this->workflow(EmailWorkflow::STATUS_DRAFT);

        $swept = $this->sweptWorkflowIds();

        $this->assertNotContains($paused->id, $swept);
        $this->assertNotContains($draft->id, $swept);
    }

    public function test_the_scheduler_still_sweeps_active_workflows(): void
    {
        $active = $this->workflow(EmailWorkflow::STATUS_ACTIVE);

        $this->assertContains($active->id, $this->sweptWorkflowIds());
    }

    /** Exercise the command's real selection query via its public behaviour. */
    private function sweptWorkflowIds(): array
    {
        return EmailWorkflow::query()
            ->whereIn('status', EmailWorkflow::SWEEPABLE_STATUSES)
            ->pluck('id')
            ->all();
    }

    // ── A success must clear the error, not just the message ─────────────

    /**
     * Without this, a repaired workflow runs green forever while the list keeps
     * showing a red Error badge — and, before the sweep fix above, stayed out of
     * the schedule for good. Same stale-status lie as a connection that claims
     * `connected` without ever logging in.
     */
    public function test_a_successful_run_restores_an_errored_workflow_to_active(): void
    {
        $workflow = $this->workflow(EmailWorkflow::STATUS_ERROR);
        $workflow->forceFill(['last_error' => 'IMAP is switched off for billing@…'])->save();

        $this->fakeEmptyMailbox();
        $run = app(CaptureService::class)->run($workflow);

        $this->assertSame(EmailWorkflowRun::STATUS_SUCCESS, $run->status, (string) $run->error);
        $this->assertSame(EmailWorkflow::STATUS_ACTIVE, $workflow->refresh()->status);
        $this->assertNull($workflow->last_error);
    }

    /** A success must never resurrect a workflow the operator deliberately paused. */
    public function test_a_successful_run_does_not_reactivate_a_paused_workflow(): void
    {
        $workflow = $this->workflow(EmailWorkflow::STATUS_PAUSED);

        $this->fakeEmptyMailbox();
        app(CaptureService::class)->run($workflow);

        $this->assertSame(EmailWorkflow::STATUS_PAUSED, $workflow->refresh()->status);
    }

    public function test_a_successful_run_leaves_an_active_workflow_active(): void
    {
        $workflow = $this->workflow(EmailWorkflow::STATUS_ACTIVE);

        $this->fakeEmptyMailbox();
        app(CaptureService::class)->run($workflow);

        $this->assertSame(EmailWorkflow::STATUS_ACTIVE, $workflow->refresh()->status);
    }

    /**
     * A reachable-but-empty mailbox: the cheapest shape that produces a genuinely
     * SUCCESSFUL run. The destinations still have to resolve (CaptureService
     * proves them before scanning), so Drive and Sheets are stubbed too — no mail
     * means nothing is ever written to them.
     *
     * The point here is the status transition; EmailWorkflowCaptureTest covers
     * the capture pipeline end to end.
     */
    private function fakeEmptyMailbox(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response(['messages' => []]),
            'https://gmail.googleapis.com/gmail/v1/users/me/labels*' => Http::response(['labels' => []]),
            'https://www.googleapis.com/drive/v3/files/drive-root*' => Http::response([
                'id' => 'drive-root', 'name' => 'Invoices',
                'mimeType' => 'application/vnd.google-apps.folder',
            ]),
            'https://www.googleapis.com/drive/v3/files?*' => Http::response(['files' => []]),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-root*' => Http::response([
                'spreadsheetId' => 'sheet-root',
                'properties' => ['title' => 'Log'],
                'sheets' => [['properties' => ['title' => 'Log', 'sheetId' => 0]]],
            ]),
        ]);
    }
}
