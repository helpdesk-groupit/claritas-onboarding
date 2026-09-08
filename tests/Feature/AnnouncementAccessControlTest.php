<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Superadmin → Role Management → Manage Access → "Announcements".
 *
 * Before this, who could publish company announcements was written out by hand
 * in AnnouncementController and again in each of the sidebar's four role
 * branches, and the only way to change it was to change somebody's role. The
 * override is the narrow control, at three depths: the page, the sections
 * inside it, and the individual controls.
 *
 * These tests drive the real HTTP endpoints rather than calling the User
 * helpers directly, because the failure mode that matters is a hidden button
 * over a route that still accepts the request.
 */
class AnnouncementAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same convention as the rest of this suite — these roles are in
        // TWO_FACTOR_REQUIRED_ROLES, so without this every request lands on
        // /two-factor/setup instead of the route under test.
        $this->withoutMiddleware(EnforceTwoFactor::class);
    }

    /** An employee with a linked user account, which is what Manage Access needs. */
    private function staff(string $role, array $employee = []): Employee
    {
        $user = User::factory()->create(['role' => $role]);

        return Employee::factory()->withUser($user)->create($employee);
    }

    /** @param array<string,string> $permissions resource => level */
    private function setAccess(Employee $employee, array $permissions): \Illuminate\Testing\TestResponse
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        return $this->actingAs($superadmin)->post(
            route('superadmin.permissions.update', $employee),
            ['permissions' => $permissions]
        );
    }

    private function announcementBy(User $user, array $attributes = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Public Holiday Notice',
            'body' => 'The office is closed on Monday.',
            'companies' => null,
            'created_by' => $user->id,
        ], $attributes));
    }

    // ── The module row (By Page) ──────────────────────────────────────────

    public function test_a_superadmin_can_grant_announcements_to_a_role_that_would_not_carry_it(): void
    {
        $employee = $this->staff('employee');

        $this->actingAs($employee->user)->get(route('announcements.index'))->assertForbidden();

        $this->setAccess($employee, ['announcements' => 'full'])->assertSessionHas('success');

        $this->actingAs($employee->user->fresh())->get(route('announcements.index'))->assertOk();
        $this->actingAs($employee->user->fresh())->get(route('announcements.create'))->assertOk();
    }

    public function test_granting_it_also_reveals_the_sidebar_link(): void
    {
        $employee = $this->staff('employee');

        $this->actingAs($employee->user)->get(route('profile'))
            ->assertOk()
            ->assertDontSee(route('announcements.index'));

        $this->setAccess($employee, ['announcements' => 'full']);

        $this->actingAs($employee->user->fresh())->get(route('profile'))
            ->assertOk()
            ->assertSee(route('announcements.index'));
    }

    public function test_no_access_hides_the_link_and_refuses_the_routes(): void
    {
        $employee = $this->staff('hr_manager');

        // Baseline: HR managers carry it by default.
        $this->actingAs($employee->user)->get(route('announcements.index'))->assertOk();

        $this->setAccess($employee, ['announcements' => 'none']);

        // The nav hides it AND the routes refuse — the second is the one that
        // matters, since the first is only a hidden link.
        $this->actingAs($employee->user->fresh())->get(route('profile'))
            ->assertOk()
            ->assertDontSee(route('announcements.index'));

        $user = $employee->user->fresh();
        $this->actingAs($user)->get(route('announcements.index'))->assertForbidden();
        $this->actingAs($user)->get(route('announcements.create'))->assertForbidden();
        $this->actingAs($user)->post(route('announcements.store'), ['title' => 'Sneaked in'])->assertForbidden();

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_view_only_opens_the_page_read_only_and_refuses_every_write(): void
    {
        $employee = $this->staff('hr_manager');
        $mine = $this->announcementBy($employee->user);

        $this->setAccess($employee, ['announcements' => 'view']);
        $user = $employee->user->fresh();

        // The listing still opens, and still shows their own announcement.
        $this->actingAs($user)->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Public Holiday Notice')
            ->assertDontSee(route('announcements.create'));

        $this->actingAs($user)->get(route('announcements.create'))->assertForbidden();
        $this->actingAs($user)->get(route('announcements.edit', $mine))->assertForbidden();
        $this->actingAs($user)->delete(route('announcements.destroy', $mine))->assertForbidden();

        $this->assertDatabaseHas('announcements', ['id' => $mine->id]);
    }

    public function test_clearing_the_override_restores_the_role_default(): void
    {
        $employee = $this->staff('hr_manager');
        $this->setAccess($employee, ['announcements' => 'none']);
        $this->actingAs($employee->user->fresh())->get(route('announcements.index'))->assertForbidden();

        // "Default" posts an empty value, which deletes the row rather than
        // storing a level — that is what hands the decision back to the role.
        $this->setAccess($employee, ['announcements' => '']);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $employee->user_id,
            'resource' => 'announcements',
        ]);

        $this->actingAs($employee->user->fresh())->get(route('announcements.index'))->assertOk();
    }

    // ── The capability rows (By Field, under "Actions") ───────────────────

    public function test_withholding_publish_hides_the_button_and_refuses_the_post(): void
    {
        $employee = $this->staff('hr_manager');

        $this->setAccess($employee, ['announcements.actions.publish' => 'none']);
        $user = $employee->user->fresh();

        $this->actingAs($user)->get(route('announcements.index'))
            ->assertOk()
            ->assertDontSee(route('announcements.create'));

        $this->actingAs($user)->get(route('announcements.create'))->assertForbidden();
        $this->actingAs($user)->post(route('announcements.store'), ['title' => 'Sneaked in'])->assertForbidden();

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_withholding_delete_leaves_publishing_and_editing_intact(): void
    {
        $employee = $this->staff('hr_manager');
        $mine = $this->announcementBy($employee->user);

        $this->setAccess($employee, ['announcements.actions.delete' => 'none']);
        $user = $employee->user->fresh();

        $this->actingAs($user)->delete(route('announcements.destroy', $mine))->assertForbidden();
        $this->assertDatabaseHas('announcements', ['id' => $mine->id]);

        // The neighbouring capabilities are untouched.
        $this->actingAs($user)->get(route('announcements.create'))->assertOk();
        $this->actingAs($user)->get(route('announcements.edit', $mine))->assertOk();
    }

    public function test_withholding_edit_leaves_deleting_intact(): void
    {
        $employee = $this->staff('hr_manager');
        $mine = $this->announcementBy($employee->user);

        $this->setAccess($employee, ['announcements.actions.edit' => 'none']);
        $user = $employee->user->fresh();

        $this->actingAs($user)->get(route('announcements.edit', $mine))->assertForbidden();
        $this->actingAs($user)->put(route('announcements.update', $mine), ['title' => 'Rewritten'])->assertForbidden();
        $this->assertDatabaseHas('announcements', ['title' => 'Public Holiday Notice']);

        $this->actingAs($user)->delete(route('announcements.destroy', $mine))->assertRedirect();
        $this->assertDatabaseMissing('announcements', ['id' => $mine->id]);
    }

    /**
     * The module gate is the outer one. A narrower grant must not become a way
     * back into a page the By Page row closed.
     */
    public function test_an_action_grant_cannot_reopen_a_module_set_to_no_access(): void
    {
        $employee = $this->staff('hr_manager');

        $this->setAccess($employee, [
            'announcements' => 'none',
            'announcements.actions.publish' => 'full',
        ]);

        $user = $employee->user->fresh();
        $this->actingAs($user)->get(route('announcements.create'))->assertForbidden();
        $this->actingAs($user)->post(route('announcements.store'), ['title' => 'Sneaked in'])->assertForbidden();
        $this->assertDatabaseCount('announcements', 0);
    }

    // ── Colleagues' announcements ─────────────────────────────────────────

    /**
     * The one row that does NOT inherit the module level. Every author has only
     * ever seen their own; an inherited 'full' would have opened every HR
     * manager's listing onto their colleagues' the moment this shipped.
     */
    public function test_by_default_an_author_sees_only_their_own_announcements(): void
    {
        $mine = $this->staff('hr_manager');
        $theirs = $this->staff('hr_manager');

        $this->announcementBy($mine->user, ['title' => 'Mine — Payroll Cutoff']);
        $other = $this->announcementBy($theirs->user, ['title' => 'Theirs — Fire Drill']);

        $this->actingAs($mine->user)->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Mine — Payroll Cutoff')
            ->assertDontSee('Theirs — Fire Drill');

        // And cannot reach it by id either.
        $this->actingAs($mine->user)->get(route('announcements.edit', $other))->assertForbidden();
        $this->actingAs($mine->user)->delete(route('announcements.destroy', $other))->assertForbidden();
        $this->assertDatabaseHas('announcements', ['id' => $other->id]);
    }

    public function test_the_colleagues_grant_opens_the_listing_and_the_actions(): void
    {
        $mine = $this->staff('hr_manager');
        $theirs = $this->staff('hr_manager');

        $other = $this->announcementBy($theirs->user, ['title' => 'Theirs — Fire Drill']);

        $this->setAccess($mine, ['announcements.actions.others' => 'full']);
        $user = $mine->user->fresh();

        $this->actingAs($user)->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Theirs — Fire Drill');

        $this->actingAs($user)->get(route('announcements.edit', $other))->assertOk();
        $this->actingAs($user)->delete(route('announcements.destroy', $other))->assertRedirect();
        $this->assertDatabaseMissing('announcements', ['id' => $other->id]);
    }

    // ── The form fields (By Field, under "Announcement Form") ─────────────

    public function test_a_field_the_user_cannot_edit_is_ignored_rather_than_stored(): void
    {
        $employee = $this->staff('hr_manager');

        $this->setAccess($employee, ['announcements.compose.body' => 'none']);

        $this->actingAs($employee->user->fresh())->post(route('announcements.store'), [
            'title' => 'Payroll Cutoff',
            'body' => 'A message they were never offered a box for.',
        ])->assertRedirect(route('announcements.index'));

        $announcement = Announcement::firstOrFail();
        $this->assertSame('Payroll Cutoff', $announcement->title);
        $this->assertNull($announcement->body, 'A value the form did not offer must not be settable by a crafted POST.');
    }

    public function test_an_uneditable_field_survives_an_edit_that_tries_to_change_it(): void
    {
        $employee = $this->staff('hr_manager');
        $mine = $this->announcementBy($employee->user);

        $this->setAccess($employee, ['announcements.compose.title' => 'view']);

        $this->actingAs($employee->user->fresh())
            ->put(route('announcements.update', $mine), [
                'title' => 'Rewritten behind their back',
                'body' => 'The body they may legitimately fix.',
            ])->assertRedirect(route('announcements.index'));

        $mine->refresh();
        $this->assertSame('Public Holiday Notice', $mine->title);
        $this->assertSame('The body they may legitimately fix.', $mine->body);
    }

    /**
     * `title` is required, so somebody holding the Publish grant while the Title
     * is View Only would reach a form they could never submit.
     */
    public function test_publishing_is_withheld_when_the_required_title_is_not_editable(): void
    {
        $employee = $this->staff('hr_manager');

        $this->setAccess($employee, ['announcements.compose.title' => 'view']);
        $user = $employee->user->fresh();

        $this->actingAs($user)->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('do not have edit access to the announcement Title');

        $this->actingAs($user)->get(route('announcements.create'))->assertForbidden();
        $this->actingAs($user)->post(route('announcements.store'), ['title' => 'Sneaked in'])->assertForbidden();
    }

    /**
     * Null targeting means EVERY company — the widest possible reach — so
     * somebody denied the audience control must not inherit it by leaving the
     * field blank.
     */
    public function test_withholding_target_companies_narrows_a_new_announcement_to_the_publishers_own(): void
    {
        $employee = $this->staff('hr_manager', ['company' => 'Claritas Asia Sdn Bhd']);

        $this->setAccess($employee, ['announcements.compose.companies' => 'none']);

        $this->actingAs($employee->user->fresh())->post(route('announcements.store'), [
            'title' => 'Payroll Cutoff',
            'companies' => ['Some Other Company Sdn Bhd'],
        ])->assertRedirect(route('announcements.index'));

        $this->assertSame(['Claritas Asia Sdn Bhd'], Announcement::firstOrFail()->companies);
    }

    public function test_withholding_target_companies_leaves_an_existing_audience_untouched(): void
    {
        $employee = $this->staff('hr_manager', ['company' => 'Claritas Asia Sdn Bhd']);
        $mine = $this->announcementBy($employee->user, ['companies' => ['Enlinea Sdn Bhd']]);

        $this->setAccess($employee, ['announcements.compose.companies' => 'none']);

        $this->actingAs($employee->user->fresh())->put(route('announcements.update', $mine), [
            'title' => 'Public Holiday Notice',
            'body' => 'Corrected wording.',
            'companies' => ['Some Other Company Sdn Bhd'],
        ])->assertRedirect(route('announcements.index'));

        $this->assertSame(['Enlinea Sdn Bhd'], $mine->refresh()->companies);
    }

    /**
     * The read-only and hidden branches of the two forms are separate Blade
     * paths from the ordinary editable ones, so nothing else in this file
     * renders them — and a mistake there is a 500 in production, not a failed
     * assertion.
     */
    public function test_the_edit_form_renders_its_read_only_and_hidden_branches(): void
    {
        $employee = $this->staff('hr_manager');
        $mine = $this->announcementBy($employee->user, [
            'companies' => ['Enlinea Sdn Bhd'],
            'attachment_paths' => ['announcements/existing.pdf'],
        ]);

        $this->setAccess($employee, [
            'announcements.compose.title' => 'view',
            'announcements.compose.companies' => 'view',
            'announcements.compose.attachments' => 'view',
            'announcements.compose.body' => 'none',
        ]);

        $this->actingAs($employee->user->fresh())
            ->get(route('announcements.edit', $mine))
            ->assertOk()
            ->assertSee('View only — you cannot change the title.')
            ->assertSee('View only — the audience will not change.')
            ->assertSee('View only — attachments will not change.')
            // The withheld field is gone entirely. Assert on the MARKUP: the
            // page's own script mentions 'editBodyField' by name whether or not
            // the textarea rendered, so a bare string assertion would pass on
            // the JS and pin nothing (same trap as the confirm-modal selectors).
            ->assertDontSee('id="editBodyField"', false)
            ->assertDontSee('name="body"', false)
            // A read-only field must not post a value either.
            ->assertDontSee('name="title"', false)
            ->assertDontSee('name="companies[]"', false)
            ->assertDontSee('name="keep_attachments[]"', false);
    }

    public function test_the_create_form_states_the_audience_it_chose_for_a_publisher_who_cannot(): void
    {
        $employee = $this->staff('hr_manager', ['company' => 'Claritas Asia Sdn Bhd']);

        $this->setAccess($employee, ['announcements.compose.companies' => 'none']);

        $this->actingAs($employee->user->fresh())
            ->get(route('announcements.create'))
            ->assertOk()
            ->assertSee('You cannot choose the audience')
            ->assertSee('Claritas Asia Sdn Bhd')
            ->assertDontSee('name="companies[]"', false);
    }

    // ── The dashboard widget is deliberately NOT gated ───────────────────

    public function test_no_access_does_not_stop_an_employee_being_told_things(): void
    {
        $employee = $this->staff('hr_manager');
        $this->setAccess($employee, ['announcements' => 'none']);

        // 'No Access' means "may not publish announcements", never "may not
        // receive them" — the widget feed is open to every authenticated user.
        $this->actingAs($employee->user->fresh())
            ->get(route('announcements.feed'))
            ->assertOk()
            ->assertJsonStructure(['html', 'current_page', 'last_page', 'total']);
    }

    // ── Whitelisting ─────────────────────────────────────────────────────

    public function test_the_module_and_its_rows_are_recognised_resources(): void
    {
        $resources = UserPermission::validResources();

        $this->assertContains('announcements', $resources);
        $this->assertContains('announcements.compose', $resources);
        $this->assertContains('announcements.compose.title', $resources);
        $this->assertContains('announcements.actions', $resources);
        $this->assertContains('announcements.actions.delete', $resources);
    }

    public function test_the_module_offers_no_edit_only_and_rejects_a_crafted_post(): void
    {
        // 'Edit Only' would mean "may change but may not read", and this is a
        // listing you have to open before you can act on anything in it.
        $this->assertSame(['', 'full', 'view', 'none'], UserPermission::levelsFor('announcements'));
        $this->assertSame(['', 'full', 'view', 'none'], UserPermission::levelsFor('announcements.compose'));
        $this->assertSame(['', 'full', 'view', 'none'], UserPermission::levelsFor('announcements.compose.title'));

        $employee = $this->staff('employee');
        $this->setAccess($employee, ['announcements' => 'edit']);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $employee->user_id,
            'resource' => 'announcements',
        ]);
    }

    public function test_the_action_rows_are_grant_or_withhold_only(): void
    {
        // A section narrows the set for itself AND its fields — 'View Only' on
        // "Delete" has nothing to mean.
        $this->assertSame(['', 'full', 'none'], UserPermission::levelsFor('announcements.actions'));
        $this->assertSame(['', 'full', 'none'], UserPermission::levelsFor('announcements.actions.delete'));

        $employee = $this->staff('hr_manager');
        $mine = $this->announcementBy($employee->user);

        $this->setAccess($employee, ['announcements.actions.delete' => 'view']);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $employee->user_id,
            'resource' => 'announcements.actions.delete',
        ]);

        // Nothing stored means the role default still applies.
        $this->actingAs($employee->user->fresh())
            ->delete(route('announcements.destroy', $mine))
            ->assertRedirect();
    }

    public function test_the_other_modules_keep_all_four_levels(): void
    {
        $this->assertSame(UserPermission::ACCESS_LEVELS, UserPermission::levelsFor('onboarding'));
        $this->assertSame(UserPermission::ACCESS_LEVELS, UserPermission::levelsFor('onboarding.personal_details'));
        $this->assertSame(UserPermission::ACCESS_LEVELS, UserPermission::levelsFor('employees.personal_info.full_name'));
        $this->assertSame(['', 'full', 'none'], UserPermission::levelsFor('kol_management'));
    }

    public function test_only_a_superadmin_may_change_the_override(): void
    {
        $employee = $this->staff('employee');
        $hrManager = User::factory()->create(['role' => 'hr_manager']);

        $this->actingAs($hrManager)->post(
            route('superadmin.permissions.update', $employee),
            ['permissions' => ['announcements' => 'full']]
        )->assertForbidden();

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $employee->user_id,
            'resource' => 'announcements',
        ]);
    }

    // ── The Manage Access UI itself ──────────────────────────────────────

    public function test_the_role_management_page_offers_announcements_on_all_three_tabs(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->staff('employee');

        $html = $this->actingAs($superadmin)->get(route('superadmin.roles.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="permissions[announcements]"', $html);
        $this->assertStringContainsString('name="permissions[announcements.compose]"', $html);
        $this->assertStringContainsString('name="permissions[announcements.compose.title]"', $html);
        $this->assertStringContainsString('name="permissions[announcements.actions.delete]"', $html);
        $this->assertStringContainsString('id="sec-acc-announcements"', $html);
        $this->assertStringContainsString('id="fld-mod-announcements"', $html);
    }

    /**
     * The table must never render a radio the save would silently drop, at any
     * of the three depths — which is why it reads levelsFor() per row rather
     * than the module's set once.
     */
    public function test_the_table_offers_exactly_the_levels_the_save_accepts(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->staff('employee');

        $html = $this->actingAs($superadmin)->get(route('superadmin.roles.index'))
            ->assertOk()
            ->getContent();

        foreach ([
            'announcements' => ['', 'full', 'view', 'none'],
            'announcements.compose' => ['', 'full', 'view', 'none'],
            'announcements.compose.title' => ['', 'full', 'view', 'none'],
            'announcements.actions' => ['', 'full', 'none'],
            'announcements.actions.delete' => ['', 'full', 'none'],
            'onboarding.personal_details.full_name' => ['', 'full', 'view', 'edit', 'none'],
        ] as $resource => $expected) {
            preg_match_all(
                '/name="permissions\['.preg_quote($resource, '/').'\]"\s+value="([a-z]*)"/',
                $html,
                $matches
            );

            $this->assertSame($expected, $matches[1], "Wrong level columns rendered for {$resource}.");
        }
    }
}
