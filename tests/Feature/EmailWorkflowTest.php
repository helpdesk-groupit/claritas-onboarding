<?php

namespace Tests\Feature;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowConnection;
use App\Models\User;
use App\Support\Automation\Contracts\EmailSourceAdapter;
use App\Support\Automation\EmailAdapterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Throwable;

/**
 * Feature cover for IT > Automation > Email Workflow.
 *
 * Asserts the headline deliverables: the workflow list, create/store,
 * the Active-status toggle (with the readiness guard), edit/update persisting
 * config JSON, delete, encrypted-at-rest connection secrets, and role gating.
 */
class EmailWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $itManager;

    protected function setUp(): void
    {
        parent::setUp();
        // withTwoFactor() bypasses the force-2FA-enrollment middleware.
        $this->itManager = User::factory()->itManager()->withTwoFactor()->create();
    }

    public function test_it_manager_can_view_the_workflow_list(): void
    {
        $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.index'))
            ->assertOk()
            ->assertSee('Email Workflow');
    }

    public function test_non_it_user_is_forbidden(): void
    {
        $employee = User::factory()->withTwoFactor()->create(['role' => 'employee']);

        $this->actingAs($employee)
            ->get(route('it.automation.email-workflow.index'))
            ->assertForbidden();
    }

    public function test_create_wizard_page_renders(): void
    {
        // Regression: the create wizard 500'd on a brand-new (unsaved) workflow
        // because the test-rules fetch URL called route(..., $workflow->id) with
        // a null id, and a literal {{date}} placeholder broke Blade compilation.
        $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.create'))
            ->assertOk()
            ->assertSee('Name your workflow');
    }

    public function test_edit_wizard_renders_every_step(): void
    {
        $wf = EmailWorkflow::create([
            'created_by' => $this->itManager->id, 'name' => 'Render test', 'status' => 'draft',
        ]);

        foreach (range(1, EmailWorkflow::TOTAL_STEPS) as $step) {
            $this->actingAs($this->itManager)
                ->get(route('it.automation.email-workflow.edit', ['workflow' => $wf->id, 'step' => $step]))
                ->assertOk();
        }
    }

    public function test_create_persists_a_draft_workflow(): void
    {
        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.store'), ['name' => 'Supplier invoices'])
            ->assertRedirect();

        $this->assertDatabaseHas('email_workflows', [
            'name' => 'Supplier invoices',
            'status' => EmailWorkflow::STATUS_DRAFT,
            'created_by' => $this->itManager->id,
        ]);
    }

    public function test_update_step_3_without_filename_template_does_not_500(): void
    {
        // Regression: nested forms in the wizard dropped fields after the
        // connection picker, so a step-3 submit could arrive without
        // filename_template. The nullable rule omits an absent key from $data,
        // and `$data['filename_template'] ?:` then threw "Undefined array key"
        // → 500 in production. It must default gracefully instead.
        $wf = EmailWorkflow::create([
            'created_by' => $this->itManager->id, 'name' => 'WF', 'status' => 'draft',
        ]);

        $this->actingAs($this->itManager)
            ->put(route('it.automation.email-workflow.update', $wf->id), [
                'step' => 3,
                'folder_ref' => 'folder-abc',
                // no filename_template, no monthly_subfolders, no storage_connection_id
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $wf->refresh();
        $this->assertSame('folder-abc', $wf->storage_config_json['folder_ref']);
        $this->assertSame('{{date}}_{{originalName}}', $wf->storage_config_json['filename_template']);
    }

    public function test_update_step_2_persists_detection_rules(): void
    {
        $wf = EmailWorkflow::create([
            'created_by' => $this->itManager->id, 'name' => 'WF', 'status' => 'draft',
        ]);

        $this->actingAs($this->itManager)
            ->put(route('it.automation.email-workflow.update', $wf->id), [
                'step' => 2,
                'subject_enabled' => '1',
                'subject_mode' => 'contains',
                'subject_keywords' => 'invoice, receipt',
                'attachment_required' => '1',
                'attachment_types' => ['pdf'],
                'combine_subject_body' => 'or',
                'capture_logic' => 'attachment_and_text',
            ])
            ->assertRedirect();

        $wf->refresh();
        $this->assertTrue($wf->rules_json['subject']['enabled']);
        $this->assertSame(['invoice', 'receipt'], $wf->rules_json['subject']['keywords']);
        $this->assertSame(['pdf'], $wf->rules_json['attachment']['types']);
    }

    public function test_cannot_activate_an_incomplete_workflow(): void
    {
        $wf = EmailWorkflow::create([
            'created_by' => $this->itManager->id, 'name' => 'Incomplete', 'status' => 'draft',
        ]);

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.toggle', $wf->id))
            ->assertRedirect();

        $this->assertSame('draft', $wf->fresh()->status);
    }

    public function test_can_activate_a_fully_configured_workflow_then_pause(): void
    {
        $email = $this->makeConnection('email', 'gmail');
        $storage = $this->makeConnection('storage', 'gdrive');
        $log = $this->makeConnection('log', 'gsheets');

        $wf = EmailWorkflow::create([
            'created_by' => $this->itManager->id,
            'name' => 'Complete',
            'status' => 'draft',
            'email_connection_id' => $email->id,
            'storage_connection_id' => $storage->id,
            'log_connection_id' => $log->id,
            'storage_config_json' => ['folder_ref' => 'folder-123', 'monthly_subfolders' => true],
            'log_config_json' => ['target_ref' => 'sheet-123', 'partition_by_month' => true],
        ]);

        // Activate.
        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.toggle', $wf->id))
            ->assertRedirect();
        $this->assertSame('active', $wf->fresh()->status);

        // Toggle again → pause.
        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.toggle', $wf->id))
            ->assertRedirect();
        $this->assertSame('paused', $wf->fresh()->status);
    }

    public function test_delete_removes_the_workflow(): void
    {
        $wf = EmailWorkflow::create([
            'created_by' => $this->itManager->id, 'name' => 'Trash me', 'status' => 'draft',
        ]);

        $this->actingAs($this->itManager)
            ->delete(route('it.automation.email-workflow.destroy', $wf->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('email_workflows', ['id' => $wf->id]);
    }

    public function test_connection_secret_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), [
                'category' => 'email',
                'provider_id' => 'gmail',
                'client_id' => 'public-client-id',
                'client_secret' => 'super-secret-value',
            ])
            ->assertRedirect();

        // Raw column must NOT contain the plaintext secret.
        $raw = DB::table('email_workflow_connections')->latest('id')->first();
        $this->assertStringNotContainsString('super-secret-value', $raw->client_secret);

        // But the model decrypts it back transparently.
        $conn = EmailWorkflowConnection::latest('id')->first();
        $this->assertSame('super-secret-value', $conn->client_secret);
    }

    public function test_a_user_cannot_act_on_another_users_workflow(): void
    {
        $other = User::factory()->itExecutive()->withTwoFactor()->create();
        $wf = EmailWorkflow::create([
            'created_by' => $other->id, 'name' => 'Theirs', 'status' => 'draft',
        ]);

        // IT executive (non-manager) only sees own workflows → 404 on edit.
        $viewer = User::factory()->itExecutive()->withTwoFactor()->create();
        $this->actingAs($viewer)
            ->get(route('it.automation.email-workflow.edit', $wf->id))
            ->assertNotFound();
    }

    /**
     * Stand in for the mailbox: `verify()` either returns (login accepted) or
     * throws (refused). The adapters' own network behaviour is not what these
     * assert — the controller's response to each outcome is.
     */
    private function fakeMailbox(?Throwable $refusesWith = null): void
    {
        $adapter = Mockery::mock(EmailSourceAdapter::class);
        $refusesWith
            ? $adapter->shouldReceive('verify')->andThrow($refusesWith)
            : $adapter->shouldReceive('verify')->andReturnNull();

        $factory = Mockery::mock(EmailAdapterFactory::class);
        $factory->shouldReceive('for')->andReturn($adapter);
        $this->app->instance(EmailAdapterFactory::class, $factory);
    }

    /** The production failure: Zoho refusing a mailbox that has IMAP switched off. */
    private function imapDisabled(): Throwable
    {
        return new \Exception('NO [ALERT] You are yet to enable IMAP for your account. Please contact your administrator (Failure)');
    }

    /** @return array<string,mixed> */
    private function imapPayload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'email',
            'provider_id' => 'imap',
            'imap_host' => 'imap.zoho.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'billing@nurengroup.com',
            'imap_password' => 'app-password',
        ], $overrides);
    }

    public function test_saving_an_imap_connection_encrypts_the_app_password_and_connects(): void
    {
        $this->fakeMailbox();

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), [
                'category' => 'email',
                'provider_id' => 'yahoo',
                'imap_host' => 'imap.mail.yahoo.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'imap_username' => 'me@yahoo.com',
                'imap_password' => 'yahoo-app-password',
            ])->assertRedirect();

        $conn = EmailWorkflowConnection::latest('id')->first();
        $this->assertSame('yahoo', $conn->provider_id);
        $this->assertSame(EmailWorkflowConnection::STATUS_CONNECTED, $conn->status);
        $this->assertTrue($conn->isImap());

        // Raw column must not hold the plaintext app password.
        $raw = DB::table('email_workflow_connections')->where('id', $conn->id)->first();
        $this->assertStringNotContainsString('yahoo-app-password', (string) $raw->imap_password);
        // Model decrypts transparently.
        $this->assertSame('yahoo-app-password', $conn->imap_password);
    }

    // ── Verify-then-create: a stored `connected` must have been earned ───

    /**
     * The regression that started all this. On 2026-07-17 two Zoho mailboxes on
     * the same host were added; IMAP was enabled on one and not the other, and
     * BOTH were stored `connected` because saving never logged in. The dead one
     * satisfied missingRequirements(), went Active, and first told the truth as
     * a failed run. A refused login must produce no row at all.
     */
    public function test_a_mailbox_that_refuses_the_login_is_not_saved(): void
    {
        $this->fakeMailbox($this->imapDisabled());

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), $this->imapPayload())
            ->assertRedirect();

        $this->assertDatabaseCount('email_workflow_connections', 0);
    }

    public function test_a_refused_login_explains_the_cause_rather_than_naming_an_exception(): void
    {
        $this->fakeMailbox($this->imapDisabled());

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), $this->imapPayload())
            ->assertRedirect();

        $error = session('error');
        $this->assertStringContainsString('nothing was saved', $error);
        $this->assertStringContainsString('IMAP is switched off for billing@nurengroup.com', $error);
        $this->assertStringContainsString('Zoho Mail → Settings → Mail Accounts → IMAP Access', $error);
        $this->assertStringNotContainsString('ImapServerErrorException', $error);
    }

    /** There is no edit screen for a connection, so the typed fields must come back. */
    public function test_a_refused_login_returns_the_typed_fields_for_correction(): void
    {
        $this->fakeMailbox($this->imapDisabled());

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), $this->imapPayload())
            ->assertSessionHasInput('imap_host', 'imap.zoho.com')
            ->assertSessionHasInput('imap_username', 'billing@nurengroup.com');
    }

    public function test_a_mailbox_that_accepts_the_login_is_saved_as_connected(): void
    {
        $this->fakeMailbox();

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), $this->imapPayload())
            ->assertRedirect();

        $conn = EmailWorkflowConnection::sole();
        $this->assertSame(EmailWorkflowConnection::STATUS_CONNECTED, $conn->status);
        $this->assertTrue($conn->isConnected());
    }

    // ── Test button: keeping a connection honest after birth ─────────────

    /**
     * The end-to-end point of the fix: a connection proven dead must fail the
     * readiness gate, so the workflow cannot sit Active while capturing nothing.
     */
    public function test_testing_a_dead_mailbox_marks_it_error_and_blocks_activation(): void
    {
        $conn = EmailWorkflowConnection::create([
            'created_by' => $this->itManager->id,
            'category' => 'email', 'provider_id' => 'imap',
            'imap_host' => 'imap.zoho.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'billing@nurengroup.com', 'imap_password' => 'app-password',
            'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);
        $workflow = EmailWorkflow::create([
            'created_by' => $this->itManager->id,
            'name' => 'Supplier invoices', 'status' => 'draft',
            'email_connection_id' => $conn->id,
        ]);

        $this->fakeMailbox($this->imapDisabled());

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.test', $conn->id))
            ->assertRedirect();

        $this->assertSame(EmailWorkflowConnection::STATUS_ERROR, $conn->refresh()->status);
        $this->assertFalse($conn->isConnected());
        $this->assertStringContainsString('IMAP is switched off', session('error'));

        // The readiness gate now tells the truth about this workflow.
        $missing = $workflow->refresh()->missingRequirements();
        $this->assertNotEmpty($missing);
        $this->assertStringContainsString('email source', implode(' ', $missing));
        $this->assertFalse($workflow->isReadyToActivate());
    }

    /** Fix the mailbox, press Test, get the green back — without deleting the row. */
    public function test_testing_a_repaired_mailbox_heals_it_back_to_connected(): void
    {
        $conn = EmailWorkflowConnection::create([
            'created_by' => $this->itManager->id,
            'category' => 'email', 'provider_id' => 'imap',
            'imap_host' => 'imap.zoho.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'billing@nurengroup.com', 'imap_password' => 'app-password',
            'status' => EmailWorkflowConnection::STATUS_ERROR,
        ]);

        $this->fakeMailbox();

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.test', $conn->id))
            ->assertRedirect();

        $this->assertSame(EmailWorkflowConnection::STATUS_CONNECTED, $conn->refresh()->status);
        $this->assertStringContainsString('healthy', session('success'));
    }

    public function test_a_user_cannot_test_another_users_connection(): void
    {
        $other = User::factory()->itExecutive()->withTwoFactor()->create();
        $conn = EmailWorkflowConnection::create([
            'created_by' => $other->id,
            'category' => 'email', 'provider_id' => 'imap',
            'imap_host' => 'imap.zoho.com', 'imap_username' => 'theirs@example.com',
            'imap_password' => 'pw', 'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);

        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.test', $conn->id))
            ->assertNotFound();
    }

    public function test_imap_save_requires_host_username_password(): void
    {
        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), [
                'category' => 'email', 'provider_id' => 'imap',
            ])
            ->assertSessionHasErrors(['imap_host', 'imap_username', 'imap_password']);
    }

    public function test_oauth_connect_redirects_to_provider_consent(): void
    {
        $conn = EmailWorkflowConnection::create([
            'created_by' => $this->itManager->id,
            'category' => 'email', 'provider_id' => 'gmail',
            'client_id' => 'my-client-id', 'client_secret' => 'my-secret',
            'status' => EmailWorkflowConnection::STATUS_PENDING,
        ]);

        $res = $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.connect', $conn->id));

        $res->assertRedirect();
        $location = $res->headers->get('Location');
        $this->assertStringContainsString('accounts.google.com', $location);
        $this->assertStringContainsString('client_id=my-client-id', $location);
        $this->assertStringContainsString('access_type=offline', $location);
    }

    /**
     * The Microsoft failure that prompted this: consent is refused by tenant
     * policy, the provider says exactly that in `error_description`, and the
     * callback used to answer with a fixed "Authorization was cancelled or
     * failed" — which is both wrong (nothing was cancelled) and unactionable.
     * Whatever the flash says, it must be derived from what the provider sent.
     */
    public function test_a_rejected_oauth_consent_reports_the_providers_reason_not_a_fixed_string(): void
    {
        $conn = $this->makeConnection('email', 'outlook');

        $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.callback', [
                'error' => 'access_denied',
                'error_description' => 'AADSTS90094: The grant requires admin permission. Trace ID: abc',
                'state' => 'whatever.'.$conn->id,
            ]))
            ->assertRedirect(route('it.automation.email-workflow.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Grant admin consent')
                && ! str_contains($m, 'cancelled'));
    }

    /**
     * The error branch is read before the state check on purpose. A provider is
     * not obliged to echo `state` on an error, and "state mismatch — try
     * connecting again" would bury the real reason under a wrong one.
     */
    public function test_an_oauth_error_without_state_still_explains_itself(): void
    {
        $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.callback', [
                'error' => 'invalid_request',
                'error_description' => 'AADSTS50194: Application is not configured as a multi-tenant application.',
            ]))
            ->assertRedirect(route('it.automation.email-workflow.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Supported account types')
                && ! str_contains($m, 'state mismatch'));
    }

    // ── Microsoft tenant-scoped endpoints (AADSTS50194) ──────────────────
    //
    // Confirmed in production 2026-07-17 from the nginx access log: every
    // Outlook connect returned
    //   AADSTS50194: Application '95b549f4-…' is not configured as a
    //   multi-tenant application. Usage of the /common endpoint is not
    //   supported for such applications created after '10/15/2018'.
    // The registry hardcoded /common, and a single-tenant app registration —
    // the default — can never use it. Permissions were fully granted; the
    // module simply had no way to name a directory.

    public function test_a_microsoft_connection_authorizes_against_its_own_directory(): void
    {
        $conn = $this->makeConnection('email', 'outlook');
        $conn->update(['oauth_tenant' => '3f10bc0a-b3b1-4c7d-b64a-6e2f1c752ece']);

        $res = $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.connect', $conn->id));

        $location = $res->headers->get('Location');
        $this->assertStringContainsString('/3f10bc0a-b3b1-4c7d-b64a-6e2f1c752ece/oauth2/v2.0/authorize', $location);
        // The bug itself: /common is what Microsoft refuses for this app.
        $this->assertStringNotContainsString('/common/', $location);
    }

    /** A multi-tenant registration sets no directory and must still work. */
    public function test_a_microsoft_connection_without_a_directory_falls_back_to_common(): void
    {
        $conn = $this->makeConnection('email', 'outlook');

        $res = $this->actingAs($this->itManager)
            ->get(route('it.automation.email-workflow.connections.connect', $conn->id));

        $this->assertStringContainsString('/common/oauth2/v2.0/authorize', $res->headers->get('Location'));
    }

    /**
     * The trap worth a test of its own: Microsoft binds the code to the
     * directory that issued it. Authorizing on /{tenant} and redeeming on
     * /common fails at the token step — which surfaces as "consent worked but
     * the connection didn't", the exact symptom this whole fix chased.
     */
    public function test_the_token_exchange_uses_the_same_directory_as_the_authorize(): void
    {
        $conn = $this->makeConnection('email', 'outlook');
        $conn->update(['oauth_tenant' => 'contoso.com', 'status' => EmailWorkflowConnection::STATUS_PENDING]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([
                'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600,
            ]),
        ]);

        $this->actingAs($this->itManager)->withSession(["ewf_oauth_state_{$conn->id}" => 'st'])
            ->get(route('it.automation.email-workflow.connections.callback', [
                'code' => 'auth-code', 'state' => 'st.'.$conn->id,
            ]));

        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->url()
            === 'https://login.microsoftonline.com/contoso.com/oauth2/v2.0/token');
        $this->assertSame(EmailWorkflowConnection::STATUS_CONNECTED, $conn->fresh()->status);
    }

    // ── Consent is not proof the mailbox is readable ─────────────────────

    /**
     * The end-to-end point: OAuth consent proves an IDENTITY signed in, not
     * that it owns a mailbox. Graph is delegated — /me/messages reads whoever
     * consented — so an admin account with no Exchange Online licence consents
     * flawlessly and 404s on every read. Before this, that account went green,
     * the workflow was offered for activation, and the truth arrived hours
     * later as a failed run. Same hole b6e4f77 closed for IMAP.
     */
    public function test_consent_from_an_account_with_no_mailbox_is_stored_as_error_not_connected(): void
    {
        $conn = $this->makeConnection('email', 'outlook');
        $conn->update(['status' => EmailWorkflowConnection::STATUS_PENDING]);
        $workflow = EmailWorkflow::create([
            'created_by' => $this->itManager->id,
            'name' => 'Supplier invoices', 'status' => 'draft',
            'email_connection_id' => $conn->id,
        ]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600,
        ])]);
        $this->fakeMailbox($this->mailboxNotEnabled());

        $this->actingAs($this->itManager)->withSession(["ewf_oauth_state_{$conn->id}" => 'st'])
            ->get(route('it.automation.email-workflow.connections.callback', [
                'code' => 'auth-code', 'state' => 'st.'.$conn->id,
            ]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no Exchange Online mailbox'));

        $this->assertSame(EmailWorkflowConnection::STATUS_ERROR, $conn->fresh()->status);
        // The whole point: an unreadable mailbox must not reach Active.
        $this->assertNotEmpty($workflow->fresh()->missingRequirements());
    }

    /** Consent is expensive to redo — a bad mailbox must not cost the grant. */
    public function test_an_unreadable_mailbox_keeps_its_tokens_so_test_can_heal_it_later(): void
    {
        $conn = $this->makeConnection('email', 'outlook');
        $conn->update(['status' => EmailWorkflowConnection::STATUS_PENDING, 'access_token' => null]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'access_token' => 'granted-token', 'refresh_token' => 'rt', 'expires_in' => 3600,
        ])]);
        $this->fakeMailbox($this->mailboxNotEnabled());

        $this->actingAs($this->itManager)->withSession(["ewf_oauth_state_{$conn->id}" => 'st'])
            ->get(route('it.automation.email-workflow.connections.callback', [
                'code' => 'auth-code', 'state' => 'st.'.$conn->id,
            ]));

        $this->assertSame('granted-token', $conn->fresh()->access_token);
    }

    /** A readable mailbox still connects — the guard must not block the happy path. */
    public function test_consent_from_an_account_with_a_real_mailbox_still_connects(): void
    {
        $conn = $this->makeConnection('email', 'outlook');
        $conn->update(['status' => EmailWorkflowConnection::STATUS_PENDING]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600,
        ])]);
        $this->fakeMailbox();

        $this->actingAs($this->itManager)->withSession(["ewf_oauth_state_{$conn->id}" => 'st'])
            ->get(route('it.automation.email-workflow.connections.callback', [
                'code' => 'auth-code', 'state' => 'st.'.$conn->id,
            ]))
            ->assertSessionHas('success');

        $this->assertSame(EmailWorkflowConnection::STATUS_CONNECTED, $conn->fresh()->status);
    }

    /** Storage/log adapters have no verify() on their contracts — don't call it. */
    public function test_a_storage_connection_is_not_mailbox_verified(): void
    {
        $conn = $this->makeConnection('storage', 'gdrive');
        $conn->update(['status' => EmailWorkflowConnection::STATUS_PENDING]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600,
        ])]);
        // No mailbox fake bound: resolving one for storage would blow up here.

        $this->actingAs($this->itManager)->withSession(["ewf_oauth_state_{$conn->id}" => 'st'])
            ->get(route('it.automation.email-workflow.connections.callback', [
                'code' => 'auth-code', 'state' => 'st.'.$conn->id,
            ]))
            ->assertSessionHas('success');

        $this->assertSame(EmailWorkflowConnection::STATUS_CONNECTED, $conn->fresh()->status);
    }

    /** Graph's real 404, with Guzzle's 120-char body summary already applied. */
    private function mailboxNotEnabled(): Throwable
    {
        return new \Exception(
            'HTTP request returned status code 404: {"error":{"code":"MailboxNotEnabledForRESTAPI",'
            .'"message":"The mailbox is either inactive, soft-deleted, or is hosted on- (truncated...)'
        );
    }

    /**
     * The directory lands in the URL PATH, so a value containing a slash could
     * rewrite the endpoint rather than name a tenant. Rejected at the form.
     */
    public function test_a_directory_id_that_could_rewrite_the_endpoint_is_rejected(): void
    {
        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), [
                'category' => 'email', 'provider_id' => 'outlook',
                'client_id' => 'cid', 'client_secret' => 'csecret',
                'oauth_tenant' => '../../evil.com/x',
            ])
            ->assertSessionHasErrors('oauth_tenant');

        $this->assertSame(0, EmailWorkflowConnection::where('provider_id', 'outlook')->count());
    }

    /** Gmail has no {tenant} in its endpoints — a stray value must not stick. */
    public function test_a_directory_id_is_ignored_for_a_provider_that_has_no_tenant(): void
    {
        $this->actingAs($this->itManager)
            ->post(route('it.automation.email-workflow.connections.save'), [
                'category' => 'email', 'provider_id' => 'gmail',
                'client_id' => 'cid', 'client_secret' => 'csecret',
                'oauth_tenant' => 'contoso.com',
            ]);

        $this->assertNull(EmailWorkflowConnection::where('provider_id', 'gmail')->sole()->oauth_tenant);
    }

    private function makeConnection(string $category, string $providerId): EmailWorkflowConnection
    {
        return EmailWorkflowConnection::create([
            'created_by' => $this->itManager->id,
            'category' => $category,
            'provider_id' => $providerId,
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'access_token' => 'atoken',
            'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);
    }
}
