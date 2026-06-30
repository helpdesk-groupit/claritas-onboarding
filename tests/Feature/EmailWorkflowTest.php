<?php

namespace Tests\Feature;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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
            'name'       => 'Supplier invoices',
            'status'     => EmailWorkflow::STATUS_DRAFT,
            'created_by' => $this->itManager->id,
        ]);
    }

    public function test_update_step_2_persists_detection_rules(): void
    {
        $wf = EmailWorkflow::create([
            'created_by' => $this->itManager->id, 'name' => 'WF', 'status' => 'draft',
        ]);

        $this->actingAs($this->itManager)
            ->put(route('it.automation.email-workflow.update', $wf->id), [
                'step'             => 2,
                'subject_enabled'  => '1',
                'subject_mode'     => 'contains',
                'subject_keywords' => 'invoice, receipt',
                'attachment_required' => '1',
                'attachment_types' => ['pdf'],
                'combine_subject_body' => 'or',
                'capture_logic'    => 'attachment_and_text',
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
        $email   = $this->makeConnection('email', 'gmail');
        $storage = $this->makeConnection('storage', 'gdrive');
        $log     = $this->makeConnection('log', 'gsheets');

        $wf = EmailWorkflow::create([
            'created_by'            => $this->itManager->id,
            'name'                  => 'Complete',
            'status'                => 'draft',
            'email_connection_id'   => $email->id,
            'storage_connection_id' => $storage->id,
            'log_connection_id'     => $log->id,
            'storage_config_json'   => ['folder_ref' => 'folder-123', 'monthly_subfolders' => true],
            'log_config_json'       => ['target_ref' => 'sheet-123', 'partition_by_month' => true],
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
                'category'      => 'email',
                'provider_id'   => 'gmail',
                'client_id'     => 'public-client-id',
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

    private function makeConnection(string $category, string $providerId): EmailWorkflowConnection
    {
        return EmailWorkflowConnection::create([
            'created_by'    => $this->itManager->id,
            'category'      => $category,
            'provider_id'   => $providerId,
            'client_id'     => 'cid',
            'client_secret' => 'csecret',
            'access_token'  => 'atoken',
            'status'        => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);
    }
}
