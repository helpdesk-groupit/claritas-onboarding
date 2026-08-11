<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Mail\AarfAcknowledgementMail;
use App\Models\Aarf;
use App\Models\AssetAssignment;
use App\Models\AssetInventory;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\PersonalDetail;
use App\Models\User;
use App\Models\WorkDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Handing a chosen asset to a new hire BEFORE their start date, from the asset listing.
 *
 * The gap this closes: a new hire has no `employees` row until `employees:activate` creates it
 * on their start date, so every assignee picker in the asset module offered employees only —
 * IT could not give a specific laptop to somebody who starts next Monday, and the onboarding
 * auto-assign just grabbed whichever machine of that type happened to be free. Since the hire
 * also has no login before day one, the AARF has to reach them by email and be acknowledged
 * from the tokenised link.
 *
 * Assignment identity here is an ASSIGNEE (employee:N / onboarding:N), not an employee id.
 * That is what the tests below pin: the two kinds of assignee behave the same everywhere.
 */
class PreStartAssetAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Mail::fake();
    }

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager', 'is_active' => true]);
    }

    /** A new hire who starts in a month: onboarding + AARF, deliberately NO employees row. */
    private function newHire(array $work = [], array $personal = []): Onboarding
    {
        $onboarding = Onboarding::factory()->create(['status' => 'active']);
        PersonalDetail::factory()->create(array_merge([
            'onboarding_id' => $onboarding->id,
            'full_name' => 'Aisyah Binti Rahim',
            'personal_email' => 'aisyah.personal@example.test',
        ], $personal));
        WorkDetail::factory()->create(array_merge([
            'onboarding_id' => $onboarding->id,
            'company_email' => 'aisyah@claritas.test',
            'start_date' => now()->addMonth()->toDateString(),
        ], $work));
        Aarf::create([
            'onboarding_id' => $onboarding->id,
            'aarf_reference' => 'AARF-PRESTART-'.$onboarding->id,
            'acknowledgement_token' => Str::random(64),
        ]);

        return $onboarding->refresh();
    }

    /** The full Section A–E payload the asset edit form posts, with the assignment overridden. */
    private function editPayload(AssetInventory $asset, array $overrides = []): array
    {
        return array_merge([
            'asset_tag' => $asset->asset_tag,
            'asset_category' => 'it_equipment',
            'asset_type' => $asset->asset_type,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'ownership_type' => 'company',
            'status' => 'available',
            'asset_condition' => 'good',
        ], $overrides);
    }

    public function test_a_new_hire_with_no_employee_row_can_be_given_a_specific_asset(): void
    {
        $onboarding = $this->newHire();
        $asset = AssetInventory::factory()->create(['asset_tag' => 'LAP-CHOSEN', 'asset_type' => 'laptop']);

        $this->assertNull(Employee::where('onboarding_id', $onboarding->id)->first(),
            'The point of the feature: no employees row exists before the start date.');

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]))
            ->assertRedirect();

        $asset->refresh();
        $this->assertSame('assigned', $asset->status);
        // The FK cannot hold a pre-start hire — the assignment is carried by asset_assignments.
        $this->assertNull($asset->assigned_employee_id);
        $this->assertDatabaseHas('asset_assignments', [
            'asset_inventory_id' => $asset->id,
            'onboarding_id' => $onboarding->id,
            'status' => 'assigned',
        ]);
        $this->assertSame('Aisyah Binti Rahim', $asset->resolvedAssigneeName());
    }

    public function test_the_aarf_is_emailed_to_the_new_hires_work_email(): void
    {
        $onboarding = $this->newHire();
        $asset = AssetInventory::factory()->create();

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]));

        // Work email, not the personal one: it is the address the hire is told to watch.
        Mail::assertSent(AarfAcknowledgementMail::class, fn ($mail) => $mail->hasTo('aisyah@claritas.test')
            && $mail->actionLabel === 'assigned'
            && $mail->aarf->onboarding_id === $onboarding->id);
    }

    public function test_the_personal_email_is_used_when_no_work_email_exists_yet(): void
    {
        // company_email is nullable on onboarding — a hire is often created before IT has
        // issued the mailbox, and the AARF must still reach them.
        $onboarding = $this->newHire(['company_email' => null]);
        $asset = AssetInventory::factory()->create();

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]));

        Mail::assertSent(AarfAcknowledgementMail::class, fn ($mail) => $mail->hasTo('aisyah.personal@example.test'));
    }

    public function test_the_operator_is_told_on_screen_when_no_address_exists_to_email(): void
    {
        // Neither address on file → nothing is sent. A log line nobody reads is how an
        // unacknowledged handover goes unnoticed, so this has to reach the flash message.
        $onboarding = $this->newHire(['company_email' => null], ['personal_email' => null]);
        $asset = AssetInventory::factory()->create();

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]))
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'NOT emailed'));

        Mail::assertNothingSent();

        // The assignment itself still stands — the missing address is a comms problem.
        $this->assertDatabaseHas('asset_assignments', [
            'asset_inventory_id' => $asset->id,
            'onboarding_id' => $onboarding->id,
            'status' => 'assigned',
        ]);
    }

    public function test_the_new_hire_acknowledges_from_the_emailed_link_without_logging_in(): void
    {
        $onboarding = $this->newHire();
        $asset = AssetInventory::factory()->create(['asset_tag' => 'LAP-ACK']);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]));

        $token = $onboarding->aarf->refresh()->acknowledgement_token;

        // Fully anonymous: a pre-start hire has no account to log in with.
        $this->post(route('aarf.acknowledge', $token))->assertRedirect(route('aarf.view', $token));
        $this->get(route('aarf.view', $token))->assertOk()->assertSee('LAP-ACK');

        $aarf = $onboarding->aarf->refresh();
        $this->assertTrue($aarf->acknowledged);
        $this->assertNotNull($aarf->acknowledged_at);
        $this->assertSame([], $aarf->pending_asset_ids ?? [],
            'Acknowledging clears the pending list, so a later handover starts clean.');
    }

    public function test_the_asset_can_be_registered_and_handed_to_a_new_hire_in_one_step(): void
    {
        $onboarding = $this->newHire();

        $this->actingAs($this->itManager())
            ->post(route('assets.store'), [
                'asset_tag' => 'LAP-NEW-001',
                'asset_category' => 'it_equipment',
                'asset_type' => 'laptop',
                'brand' => 'Dell',
                'model' => 'Latitude 5450',
                'serial_number' => 'SN-NEW-001',
                'ownership_type' => 'company',
                'status' => 'available',
                'asset_condition' => 'good',
                'assigned_onboarding_id' => $onboarding->id,
            ])
            ->assertRedirect();

        $asset = AssetInventory::where('asset_tag', 'LAP-NEW-001')->firstOrFail();
        $this->assertSame('assigned', $asset->status);
        $this->assertDatabaseHas('asset_assignments', [
            'asset_inventory_id' => $asset->id,
            'onboarding_id' => $onboarding->id,
            'status' => 'assigned',
        ]);
        Mail::assertSent(AarfAcknowledgementMail::class, fn ($mail) => $mail->hasTo('aisyah@claritas.test'));
    }

    public function test_taking_the_asset_back_from_a_pre_start_hire_reaches_their_aarf(): void
    {
        // Before this feature every "old assignee" lookup went through
        // Employee::where('onboarding_id', …), which is null before the start date — so a
        // release from a new hire logged nothing on their AARF and emailed nobody.
        $onboarding = $this->newHire();
        $asset = AssetInventory::factory()->create(['asset_tag' => 'LAP-BACK', 'status' => 'assigned']);
        AssetAssignment::create([
            'onboarding_id' => $onboarding->id,
            'asset_inventory_id' => $asset->id,
            'assigned_date' => now()->toDateString(),
            'status' => 'assigned',
        ]);
        $onboarding->aarf->addPendingAsset($asset->id);

        $this->actingAs($this->itManager())
            ->post(route('assets.release', $asset))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Aisyah Binti Rahim'));

        $aarf = $onboarding->aarf->refresh();
        // releaseAsset logs by brand/model, not by tag — the point is that the new hire's
        // AARF records the return at all, which it previously did not.
        $this->assertStringContainsString('returned by Aisyah Binti Rahim', (string) $aarf->asset_changes);
        $this->assertSame([], $aarf->pending_asset_ids ?? []);
        Mail::assertSent(AarfAcknowledgementMail::class, fn ($mail) => $mail->hasTo('aisyah@claritas.test')
            && $mail->actionLabel === 'returned');
    }

    public function test_swapping_a_new_hires_asset_notifies_both_sides(): void
    {
        $onboarding = $this->newHire();
        $employee = Employee::factory()->create([
            'full_name' => 'Existing Staff',
            'company_email' => 'existing@claritas.test',
        ]);
        $asset = AssetInventory::factory()->create([
            'asset_tag' => 'LAP-SWAP',
            'status' => 'assigned',
            'assigned_employee_id' => $employee->id,
        ]);
        AssetAssignment::create([
            'employee_id' => $employee->id,
            'asset_inventory_id' => $asset->id,
            'assigned_date' => now()->toDateString(),
            'status' => 'assigned',
        ]);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]));

        // Old holder told it went back; new hire told it is theirs to acknowledge.
        Mail::assertSent(AarfAcknowledgementMail::class, fn ($mail) => $mail->hasTo('existing@claritas.test')
            && $mail->actionLabel === 'returned');
        Mail::assertSent(AarfAcknowledgementMail::class, fn ($mail) => $mail->hasTo('aisyah@claritas.test')
            && $mail->actionLabel === 'assigned');

        $asset->refresh();
        $this->assertNull($asset->assigned_employee_id, 'The FK must not keep pointing at the old holder.');
        $this->assertDatabaseHas('asset_assignments', [
            'asset_inventory_id' => $asset->id,
            'employee_id' => $employee->id,
            'status' => 'returned',
        ]);
    }

    public function test_a_hire_activated_before_the_form_was_submitted_is_filed_under_their_employee_row(): void
    {
        // employees:activate can run between page load and submit. Filing the asset under the
        // onboarding then would leave it keyed to an identity that has been superseded.
        $onboarding = $this->newHire();
        $employee = Employee::factory()->create([
            'onboarding_id' => $onboarding->id,
            'full_name' => 'Aisyah Binti Rahim',
            'company_email' => 'aisyah@claritas.test',
        ]);
        $asset = AssetInventory::factory()->create();

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]));

        $this->assertSame($employee->id, $asset->refresh()->assigned_employee_id);
    }

    public function test_an_offboarded_onboarding_cannot_be_handed_an_asset(): void
    {
        $onboarding = $this->newHire();
        $onboarding->update(['status' => 'offboarded']);
        $asset = AssetInventory::factory()->create();

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]))
            ->assertSessionHasErrors('assigned_onboarding_id');

        $this->assertDatabaseMissing('asset_assignments', ['asset_inventory_id' => $asset->id]);
    }

    public function test_a_role_that_cannot_edit_section_d_does_not_release_the_asset_by_saving(): void
    {
        // it_executive may edit an asset but NOT Section D, so their form carries no assignment
        // fields at all. Reading "not assigned" from an absent field silently released the
        // asset from whoever held it, emailed them a return notice, and left the row saying
        // assigned — a pre-existing bug the assignee rewrite had to close, not inherit.
        $onboarding = $this->newHire();
        $asset = AssetInventory::factory()->create(['asset_tag' => 'LAP-KEEP', 'status' => 'assigned']);
        AssetAssignment::create([
            'onboarding_id' => $onboarding->id,
            'asset_inventory_id' => $asset->id,
            'assigned_date' => now()->toDateString(),
            'status' => 'assigned',
        ]);

        $executive = User::factory()->create(['role' => 'it_executive', 'is_active' => true]);

        $this->actingAs($executive)
            ->put(route('assets.update', $asset), [
                'asset_tag' => $asset->asset_tag,
                'asset_category' => 'it_equipment',
                'asset_type' => $asset->asset_type,
                'brand' => 'Rebranded',
                'model' => $asset->model,
                'serial_number' => $asset->serial_number,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('asset_assignments', [
            'asset_inventory_id' => $asset->id,
            'onboarding_id' => $onboarding->id,
            'status' => 'assigned',
        ]);
        Mail::assertNotSent(AarfAcknowledgementMail::class);
    }

    public function test_a_second_asset_of_the_same_type_is_flagged_not_silently_stacked(): void
    {
        // The onboarding auto-assign already reserves whichever laptop was free, so choosing
        // the right one afterwards leaves the hire holding two. Neither is released
        // automatically — IT decides — but they must be told, or nobody notices.
        $onboarding = $this->newHire();
        $autoAssigned = AssetInventory::factory()->create([
            'asset_tag' => 'LAP-AUTO', 'asset_type' => 'laptop', 'status' => 'assigned',
        ]);
        AssetAssignment::create([
            'onboarding_id' => $onboarding->id,
            'asset_inventory_id' => $autoAssigned->id,
            'assigned_date' => now()->toDateString(),
            'status' => 'assigned',
        ]);

        $chosen = AssetInventory::factory()->create(['asset_tag' => 'LAP-CHOSEN', 'asset_type' => 'laptop']);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $chosen), $this->editPayload($chosen, [
                'assigned_onboarding_id' => $onboarding->id,
            ]))
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'already holds another laptop')
                && str_contains($msg, 'LAP-AUTO'));

        // Both still assigned — the warning informs, it does not act.
        $this->assertSame('assigned', $autoAssigned->refresh()->status);
        $this->assertSame('assigned', $chosen->refresh()->status);
    }

    public function test_a_first_asset_of_its_type_draws_no_duplicate_warning(): void
    {
        $onboarding = $this->newHire();
        $asset = AssetInventory::factory()->create(['asset_tag' => 'LAP-ONLY', 'asset_type' => 'laptop']);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->editPayload($asset, [
                'assigned_onboarding_id' => $onboarding->id,
            ]))
            ->assertSessionHas('success', fn ($msg) => ! str_contains($msg, 'already holds'));
    }

    public function test_the_reassign_endpoint_accepts_a_new_hire_as_well_as_an_employee(): void
    {
        // assets.reassign carries no UI — assignment is done through Section D of the edit
        // form — but the route is registered and POST-reachable, so it must not stay
        // employee-only while every other assign path understands a pre-start hire.
        $onboarding = $this->newHire();
        $asset = AssetInventory::factory()->create(['asset_tag' => 'LAP-ROW', 'status' => 'available']);

        $this->actingAs($this->itManager())
            ->post(route('assets.reassign', $asset), ['new_onboarding_id' => $onboarding->id])
            ->assertRedirect(route('assets.show', $asset));

        $asset->refresh();
        $this->assertSame('assigned', $asset->status);
        $this->assertNull($asset->assigned_employee_id);
        $this->assertDatabaseHas('asset_assignments', [
            'asset_inventory_id' => $asset->id,
            'onboarding_id' => $onboarding->id,
            'status' => 'assigned',
        ]);
        Mail::assertSent(AarfAcknowledgementMail::class, fn ($mail) => $mail->hasTo('aisyah@claritas.test')
            && $mail->actionLabel === 'assigned');
    }

    public function test_the_reassign_endpoint_needs_a_target(): void
    {
        $asset = AssetInventory::factory()->create(['status' => 'available']);

        $this->actingAs($this->itManager())
            ->post(route('assets.reassign', $asset), [])
            ->assertSessionHasErrors(['new_employee_id', 'new_onboarding_id']);

        $this->assertSame('available', $asset->refresh()->status);
    }

    public function test_the_listing_offers_no_per_row_assign_control(): void
    {
        // Deliberate: IT assigns by opening the asset and editing Section D. A row-level
        // Assign button existed briefly and was removed — don't reintroduce it.
        AssetInventory::factory()->create(['asset_tag' => 'LAP-ROW', 'status' => 'available']);

        $this->actingAs($this->itManager())
            ->get(route('assets.index'))
            ->assertOk()
            ->assertDontSee('assign-asset-btn', false)
            ->assertDontSee('name="new_onboarding_id"', false);
    }

    public function test_the_edit_form_preselects_the_new_hire_holding_the_asset(): void
    {
        // This field used to be a read-only "(pending activation)" box that posted an empty
        // employee id, so an onboarding-held asset could not be reassigned or taken back
        // through the form at all.
        $onboarding = $this->newHire();
        $asset = AssetInventory::factory()->create(['asset_tag' => 'LAP-EDIT', 'status' => 'assigned']);
        AssetAssignment::create([
            'onboarding_id' => $onboarding->id,
            'asset_inventory_id' => $asset->id,
            'assigned_date' => now()->toDateString(),
            'status' => 'assigned',
        ]);

        $this->actingAs($this->itManager())
            ->get(route('assets.edit', $asset))
            ->assertOk()
            ->assertSee('name="assigned_onboarding_id" id="editAssignedOnboardingId"', false)
            ->assertSee('value="'.$onboarding->id.'"', false)
            ->assertSee('Aisyah Binti Rahim');
    }

    public function test_the_picker_offers_pre_start_hires_and_not_activated_ones(): void
    {
        $pending = $this->newHire();
        $activated = $this->newHire(['company_email' => 'started@claritas.test'], ['full_name' => 'Already Started']);
        Employee::factory()->create(['onboarding_id' => $activated->id, 'full_name' => 'Already Started']);

        $response = $this->actingAs($this->itManager())->get(route('assets.index'));

        $response->assertOk()
            ->assertSee('assigned_onboarding_id', false)
            // Offering an activated hire under BOTH identities is how one person ends up
            // holding the same asset twice.
            ->assertSee('data-onbid="'.$pending->id.'"', false)
            ->assertDontSee('data-onbid="'.$activated->id.'"', false);
    }
}
