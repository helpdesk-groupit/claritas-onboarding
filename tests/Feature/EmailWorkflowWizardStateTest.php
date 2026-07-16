<?php

namespace Tests\Feature;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cover for the wizard telling the truth about its own state.
 *
 * The module shipped with a stepper that painted a step green from
 * `wizard_step` — a navigation high-water mark. An operator saw all five steps
 * green while the workflow had no log connection at all and could not run. These
 * tests pin the fix: green means visited AND genuinely configured, re-derived on
 * every render, and every "you can't run yet" message names the actual blocker.
 */
class EmailWorkflowWizardStateTest extends TestCase
{
    use RefreshDatabase;

    private User $itManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->itManager = User::factory()->itManager()->withTwoFactor()->create();
    }

    // ── stepDone(): visitation AND completeness ──────────────────────────

    public function test_a_fresh_workflow_has_no_green_steps_despite_seeded_defaults(): void
    {
        // store() seeds rules_json + timezone + crons, so stepComplete(2) and
        // stepComplete(5) are true the instant the row exists. Completeness alone
        // would therefore paint 2 and 5 green on a workflow nobody has configured
        // — the same class of lie the old wizard_step check made.
        $workflow = $this->makeWorkflow(['wizard_step' => 1]);

        $this->assertTrue($workflow->stepComplete(2), 'defaults make rules complete from birth');
        $this->assertTrue($workflow->stepComplete(5), 'defaults make the schedule complete from birth');

        foreach (range(1, 5) as $step) {
            $this->assertFalse($workflow->stepDone($step), "Step {$step} must not be green on a fresh workflow.");
        }
    }

    public function test_jumping_to_the_last_step_does_not_green_the_steps_that_were_skipped(): void
    {
        // Every chip is a link, so a user can open step 5 and save it directly.
        // wizard_step then hits its cap and used to green 1-4 retroactively.
        $workflow = $this->makeWorkflow(['wizard_step' => EmailWorkflow::TOTAL_STEPS]);

        $this->assertFalse($workflow->stepDone(1), 'no email connection');
        $this->assertFalse($workflow->stepDone(3), 'no storage connection');
        $this->assertFalse($workflow->stepDone(4), 'no log connection');
        // 2 and 5 are genuinely configured by defaults, and were visited.
        $this->assertTrue($workflow->stepDone(2));
        $this->assertTrue($workflow->stepDone(5));
    }

    public function test_a_visited_and_configured_step_is_green(): void
    {
        $workflow = $this->makeWorkflow([
            'wizard_step' => EmailWorkflow::TOTAL_STEPS,
            'email_connection_id' => $this->connection('email', 'gmail')->id,
        ]);

        $this->assertTrue($workflow->fresh()->stepDone(1));
    }

    public function test_step_five_can_render_as_done(): void
    {
        // update() caps wizard_step at TOTAL_STEPS, so `5 < 5` is never true and
        // the final step could never go green however complete it was.
        $workflow = $this->makeWorkflow(['wizard_step' => EmailWorkflow::TOTAL_STEPS]);

        $this->assertTrue($workflow->stepDone(5));
    }

    public function test_a_step_goes_grey_again_when_its_connection_is_deleted(): void
    {
        // FKs are nullOnDelete, so removing a connection silently un-configures
        // every workflow using it. wizard_step never regresses, so the chip has
        // to be derived or it keeps asserting a step that is now broken.
        $conn = $this->connection('log', 'gsheets');
        $workflow = $this->makeWorkflow([
            'wizard_step' => EmailWorkflow::TOTAL_STEPS,
            'log_connection_id' => $conn->id,
            'log_config_json' => array_merge(EmailWorkflow::DEFAULT_LOG_CONFIG, ['target_ref' => 'sheet-1']),
        ]);

        $this->assertTrue($workflow->fresh()->stepDone(4));

        $this->actingAs($this->itManager)
            ->delete(route('it.automation.email-workflow.connections.delete', $conn->id))
            ->assertRedirect();

        $this->assertFalse($workflow->fresh()->stepDone(4), 'Step 4 must stop claiming to be done.');
    }

    public function test_a_revoked_connection_stops_its_step_being_green(): void
    {
        $conn = $this->connection('storage', 'gdrive');
        $workflow = $this->makeWorkflow([
            'wizard_step' => EmailWorkflow::TOTAL_STEPS,
            'storage_connection_id' => $conn->id,
            'storage_config_json' => array_merge(EmailWorkflow::DEFAULT_STORAGE_CONFIG, ['folder_ref' => 'folder-1']),
        ]);

        $this->assertTrue($workflow->fresh()->stepDone(3));

        $conn->update(['status' => EmailWorkflowConnection::STATUS_NEEDS_RECONNECT]);

        $this->assertFalse($workflow->fresh()->stepDone(3), 'A revoked token is not a configured step.');
    }

    // ── Readiness: health, not just a non-null FK ────────────────────────

    public function test_a_selected_but_unauthorized_connection_does_not_count_as_ready(): void
    {
        // saveConnection() stores OAuth credentials as `pending` BEFORE consent.
        // Checking only the FK let such a workflow be switched Active, and it
        // then failed at run time in CaptureService::connections().
        $workflow = $this->readyWorkflow();
        $workflow->storageConnection->update(['status' => EmailWorkflowConnection::STATUS_PENDING]);

        $workflow = $workflow->fresh();

        $this->assertFalse($workflow->isReadyToActivate());
        $this->assertStringContainsString('reconnect the storage account', implode(' ', $workflow->missingRequirements()));
    }

    public function test_missing_requirements_names_every_blocker(): void
    {
        $workflow = $this->makeWorkflow();

        $missing = $workflow->missingRequirements();

        $this->assertContains('select a email source on step 1', $missing);
        $this->assertContains('select a storage account on step 3', $missing);
        $this->assertContains('select a log account on step 4', $missing);
        $this->assertContains('set a destination folder on step 3', $missing);
        $this->assertContains('set a log sheet on step 4', $missing);
    }

    public function test_a_fully_configured_workflow_reports_nothing_missing(): void
    {
        $this->assertSame([], $this->readyWorkflow()->missingRequirements());
        $this->assertTrue($this->readyWorkflow()->isReadyToActivate());
    }

    public function test_the_list_page_names_the_blocker_instead_of_saying_finish_setup(): void
    {
        $this->makeWorkflow(['name' => 'Half-built']);

        $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.index'))
            ->assertOk()
            ->assertSee('select a log account on step 4');
    }

    public function test_activation_is_refused_with_the_specific_reason(): void
    {
        $workflow = $this->makeWorkflow(['name' => 'Half-built']);

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.toggle', $workflow->id))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'select a log account on step 4'));

        $this->assertSame(EmailWorkflow::STATUS_DRAFT, $workflow->fresh()->status);
    }

    // ── A dead schedule must never save, and must never read as healthy ──

    public function test_a_prose_schedule_is_rejected_instead_of_saved(): void
    {
        // The field is labelled "cron" but its hint used to read
        // "Default: daily 19:00 local". Typing that back saved an Active
        // workflow that RunEmailWorkflows skipped every minute, forever, with
        // nothing on screen to say so. This happened on production.
        $workflow = $this->makeWorkflow();

        $this->actingAs($this->itManager)
            ->put(route('it.automation.email-workflow.update', $workflow->id), [
                'step' => 5,
                'timezone' => 'Asia/Kuala_Lumpur',
                'capture_cron' => 'daily 19:00 local',
                'reconcile_cron' => '0 7 * * *',
            ])
            ->assertSessionHasErrors('capture_cron');

        $this->assertSame(
            EmailWorkflow::DEFAULT_CAPTURE_CRON,
            $workflow->fresh()->capture_cron,
            'A schedule the scheduler cannot parse must not reach the database.'
        );
    }

    public function test_a_real_cron_expression_saves(): void
    {
        $workflow = $this->makeWorkflow();

        $this->actingAs($this->itManager)
            ->put(route('it.automation.email-workflow.update', $workflow->id), [
                'step' => 5,
                'timezone' => 'Asia/Kuala_Lumpur',
                'capture_cron' => '30 18 * * 1-5',
                'reconcile_cron' => '@daily',   // macros are valid cron too
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('30 18 * * 1-5', $workflow->fresh()->capture_cron);
        $this->assertSame('@daily', $workflow->fresh()->reconcile_cron);
    }

    public function test_an_unparseable_schedule_counts_as_missing(): void
    {
        // Without this the list page reports "Active, nothing missing" about an
        // automation that will never fire again.
        $workflow = $this->readyWorkflow();
        $this->assertTrue($workflow->isReadyToActivate());

        $workflow->forceFill(['capture_cron' => 'daily 19:00 local'])->save();
        $workflow = $workflow->fresh();

        $this->assertFalse($workflow->isReadyToActivate());
        $this->assertStringContainsString('capture schedule', implode(' ', $workflow->missingRequirements()));
    }

    public function test_a_garbage_timezone_is_rejected(): void
    {
        // capture_cron is evaluated in this timezone; an unknown one throws at
        // Carbon::now($tz) inside the scheduler.
        $workflow = $this->makeWorkflow();

        $this->actingAs($this->itManager)
            ->put(route('it.automation.email-workflow.update', $workflow->id), [
                'step' => 5,
                'timezone' => 'Mars/Olympus_Mons',
                'capture_cron' => '0 19 * * *',
                'reconcile_cron' => '0 7 * * *',
            ])
            ->assertSessionHasErrors('timezone');
    }

    /** @dataProvider validCronProvider */
    public function test_is_valid_cron_accepts_real_expressions(string $expression): void
    {
        $this->assertTrue(EmailWorkflow::isValidCron($expression));
    }

    public static function validCronProvider(): array
    {
        return [['0 19 * * *'], ['*/15 * * * *'], ['30 18 * * 1-5'], ['@daily'], ['@hourly']];
    }

    /** @dataProvider deadCronProvider */
    public function test_is_valid_cron_rejects_dead_schedules(?string $expression): void
    {
        $this->assertFalse(EmailWorkflow::isValidCron($expression));
    }

    public static function deadCronProvider(): array
    {
        return [['daily 19:00 local'], ['every day at 7pm'], ['19:00'], [''], [null], ['not a cron']];
    }

    // ── The OAuth round-trip must not lose the wizard ────────────────────

    public function test_oauth_callback_returns_to_the_wizard_step_and_selects_the_new_account(): void
    {
        // The reported failure: press Connect on step 4, consent, get dumped on
        // the LIST page. The account is connected but the workflow keeps a null
        // FK, so nothing appears to have happened.
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        ])]);

        $workflow = $this->makeWorkflow();
        $conn = $this->connection('log', 'gsheets', EmailWorkflowConnection::STATUS_PENDING);

        $this->withSession([
            "ewf_oauth_state_{$conn->id}" => 'st4te',
            "ewf_oauth_return_{$conn->id}" => ['workflow' => $workflow->id, 'step' => 4],
        ])->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.callback', [
                'state' => 'st4te.'.$conn->id, 'code' => 'auth-code',
            ]))
            ->assertRedirect(route('it.automation.email-workflow.edit', [
                'workflow' => $workflow->id, 'step' => 4,
            ]));

        $this->assertSame($conn->id, $workflow->fresh()->log_connection_id,
            'The account the user just authorized should be selected for them.');
    }

    public function test_oauth_callback_never_overrides_an_existing_selection(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 3599,
        ])]);

        $chosen = $this->connection('log', 'gsheets');
        $workflow = $this->makeWorkflow(['log_connection_id' => $chosen->id]);
        $other = $this->connection('log', 'gsheets', EmailWorkflowConnection::STATUS_PENDING);

        $this->withSession([
            "ewf_oauth_state_{$other->id}" => 'st4te',
            "ewf_oauth_return_{$other->id}" => ['workflow' => $workflow->id, 'step' => 4],
        ])->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.callback', [
                'state' => 'st4te.'.$other->id, 'code' => 'auth-code',
            ]))
            ->assertRedirect();

        $this->assertSame($chosen->id, $workflow->fresh()->log_connection_id);
    }

    public function test_oauth_callback_without_wizard_context_still_lands_on_the_list(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 3599,
        ])]);

        $conn = $this->connection('log', 'gsheets', EmailWorkflowConnection::STATUS_PENDING);

        $this->withSession(["ewf_oauth_state_{$conn->id}" => 'st4te'])
            ->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.callback', [
                'state' => 'st4te.'.$conn->id, 'code' => 'auth-code',
            ]))
            ->assertRedirect(route('it.automation.email-workflow.index'))
            ->assertSessionHas('success');
    }

    public function test_connect_start_records_where_to_return_to(): void
    {
        $workflow = $this->makeWorkflow();
        $conn = $this->connection('storage', 'gdrive', EmailWorkflowConnection::STATUS_PENDING);

        $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.connect', [
                'connection' => $conn->id, 'workflow' => $workflow->id, 'step' => 3,
            ]))
            ->assertRedirectContains('accounts.google.com');

        $this->assertSame(
            ['workflow' => $workflow->id, 'step' => 3],
            session("ewf_oauth_return_{$conn->id}")
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $attrs */
    private function makeWorkflow(array $attrs = []): EmailWorkflow
    {
        return EmailWorkflow::create(array_merge([
            'created_by' => $this->itManager->id,
            'name' => 'Capture supplier invoices',
            'status' => EmailWorkflow::STATUS_DRAFT,
            'rules_json' => EmailWorkflow::DEFAULT_RULES,
            'storage_config_json' => EmailWorkflow::DEFAULT_STORAGE_CONFIG,
            'log_config_json' => EmailWorkflow::DEFAULT_LOG_CONFIG,
            'timezone' => 'Asia/Kuala_Lumpur',
            'capture_cron' => EmailWorkflow::DEFAULT_CAPTURE_CRON,
            'reconcile_cron' => EmailWorkflow::DEFAULT_RECONCILE_CRON,
            'wizard_step' => 1,
        ], $attrs));
    }

    private function connection(string $category, string $provider, string $status = EmailWorkflowConnection::STATUS_CONNECTED): EmailWorkflowConnection
    {
        return EmailWorkflowConnection::create([
            'created_by' => $this->itManager->id,
            'category' => $category,
            'provider_id' => $provider,
            'account_label' => 'finance@claritas.test',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'access_token' => $status === EmailWorkflowConnection::STATUS_CONNECTED ? 'access-token' : null,
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => $status,
        ]);
    }

    private function readyWorkflow(): EmailWorkflow
    {
        return $this->makeWorkflow([
            'wizard_step' => EmailWorkflow::TOTAL_STEPS,
            'email_connection_id' => $this->connection('email', 'gmail')->id,
            'storage_connection_id' => $this->connection('storage', 'gdrive')->id,
            'log_connection_id' => $this->connection('log', 'gsheets')->id,
            'storage_config_json' => array_merge(EmailWorkflow::DEFAULT_STORAGE_CONFIG, ['folder_ref' => 'folder-1']),
            'log_config_json' => array_merge(EmailWorkflow::DEFAULT_LOG_CONFIG, ['target_ref' => 'sheet-1']),
        ])->fresh();
    }
}
