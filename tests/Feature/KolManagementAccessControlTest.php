<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Superadmin → Role Management → Manage Access → By Page → "KOL Management".
 *
 * The KOL Management link hands the user to a SEPARATE application over SSO, so
 * before this existed the only way to give (or take) it was to change someone's
 * role or their department — both of which carry a pile of unrelated access with
 * them. The override is the narrow control.
 *
 * These tests drive the real HTTP endpoints rather than calling
 * canAccessKolPortal() directly, because the failure mode that matters is the
 * sidebar and the mint route disagreeing.
 */
class KolManagementAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same convention as the rest of this suite — these roles are in
        // TWO_FACTOR_REQUIRED_ROLES, so without this every request lands on
        // /two-factor/setup instead of the route under test.
        $this->withoutMiddleware(EnforceTwoFactor::class);

        Config::set('services.kol_portal.url', 'https://kol.claritasapp.com');
        Config::set('services.kol_portal.shared_secret', 'test-shared-secret-that-both-apps-hold');
    }

    /** An employee with a linked user account, which is what Manage Access needs. */
    private function staff(string $role, string $department = 'Technology'): Employee
    {
        $user = User::factory()->create(['role' => $role]);

        return Employee::factory()->withUser($user)->create(['department' => $department]);
    }

    private function setAccess(Employee $employee, string $level): \Illuminate\Testing\TestResponse
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        return $this->actingAs($superadmin)->post(
            route('superadmin.permissions.update', $employee),
            ['permissions' => ['kol_management' => $level]]
        );
    }

    // ── The override grants ───────────────────────────────────────────────

    public function test_a_superadmin_can_grant_the_kol_link_to_someone_whose_role_would_not_carry_it(): void
    {
        $employee = $this->staff('employee', 'Marketing');

        // Baseline: not in the KOL department, not an admin role.
        $this->actingAs($employee->user)->get(route('kol-portal.redirect'))->assertForbidden();

        $this->setAccess($employee, 'full')->assertSessionHas('success');

        $this->assertSame('full', UserPermission::where('user_id', $employee->user_id)
            ->where('resource', 'kol_management')->value('access_level'));

        $this->actingAs($employee->user->fresh())
            ->get(route('kol-portal.redirect'))
            ->assertRedirect();
    }

    public function test_granting_it_also_reveals_the_sidebar_link(): void
    {
        $employee = $this->staff('employee', 'Marketing');

        $this->actingAs($employee->user)->get(route('profile'))
            ->assertOk()
            ->assertDontSee('KOL Management');

        $this->setAccess($employee, 'full');

        $this->actingAs($employee->user->fresh())->get(route('profile'))
            ->assertOk()
            ->assertSee('KOL Management');
    }

    // ── The override withholds ────────────────────────────────────────────

    public function test_a_superadmin_can_withhold_the_kol_link_from_a_role_that_would_otherwise_have_it(): void
    {
        $employee = $this->staff('it_manager');

        // Baseline: IT roles carry it by default.
        $this->actingAs($employee->user)->get(route('kol-portal.redirect'))->assertRedirect();

        $this->setAccess($employee, 'none');

        // The nav hides it AND the mint route refuses it — the second is the one
        // that matters, since the first is only a hidden link.
        $this->actingAs($employee->user->fresh())->get(route('profile'))
            ->assertOk()
            ->assertDontSee('KOL Management');

        $this->actingAs($employee->user->fresh())
            ->get(route('kol-portal.redirect'))
            ->assertForbidden();
    }

    public function test_it_also_withholds_from_the_kol_department_default(): void
    {
        $employee = $this->staff('employee', 'KOL');

        $this->actingAs($employee->user)->get(route('kol-portal.redirect'))->assertRedirect();

        $this->setAccess($employee, 'none');

        $this->actingAs($employee->user->fresh())
            ->get(route('kol-portal.redirect'))
            ->assertForbidden();
    }

    // ── Default means default ─────────────────────────────────────────────

    public function test_clearing_the_override_restores_the_role_and_department_defaults(): void
    {
        $employee = $this->staff('it_manager');
        $this->setAccess($employee, 'none');
        $this->actingAs($employee->user->fresh())->get(route('kol-portal.redirect'))->assertForbidden();

        // "Default" posts an empty value, which deletes the row rather than
        // storing a level — that is what hands the decision back to the role.
        $this->setAccess($employee, '');

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $employee->user_id,
            'resource' => 'kol_management',
        ]);

        $this->actingAs($employee->user->fresh())
            ->get(route('kol-portal.redirect'))
            ->assertRedirect();
    }

    // ── Whitelisting ──────────────────────────────────────────────────────

    public function test_kol_management_is_a_recognised_resource(): void
    {
        $this->assertContains('kol_management', UserPermission::validResources());
    }

    public function test_it_offers_grant_and_deny_only_and_rejects_a_crafted_view_or_edit_post(): void
    {
        // The UI renders a dash in those two columns; this is the server-side
        // half of the same rule. 'view'/'edit' would be stored happily by the
        // enum column, so the whitelist has to be per module, not global.
        $this->assertSame(['', 'full', 'none'], UserPermission::levelsFor('kol_management'));

        $employee = $this->staff('employee', 'Marketing');
        $this->setAccess($employee, 'view');

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $employee->user_id,
            'resource' => 'kol_management',
        ]);

        $this->actingAs($employee->user->fresh())
            ->get(route('kol-portal.redirect'))
            ->assertForbidden();
    }

    public function test_other_modules_keep_all_four_levels(): void
    {
        $this->assertSame(UserPermission::ACCESS_LEVELS, UserPermission::levelsFor('onboarding'));
        $this->assertSame(UserPermission::ACCESS_LEVELS, UserPermission::levelsFor('employees.personal_info.full_name'));
    }

    // ── Who may set it ────────────────────────────────────────────────────

    public function test_only_a_superadmin_may_change_the_override(): void
    {
        $employee = $this->staff('employee', 'Marketing');
        $itManager = User::factory()->create(['role' => 'it_manager']);

        $this->actingAs($itManager)->post(
            route('superadmin.permissions.update', $employee),
            ['permissions' => ['kol_management' => 'full']]
        )->assertForbidden();

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $employee->user_id,
            'resource' => 'kol_management',
        ]);
    }

    // ── The Manage Access UI itself ───────────────────────────────────────

    public function test_the_role_management_page_offers_kol_management_on_the_by_page_tab_only(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->staff('employee', 'Marketing');

        $html = $this->actingAs($superadmin)->get(route('superadmin.roles.index'))
            ->assertOk()
            ->assertSee('name="permissions[kol_management]"', false)
            ->getContent();

        // Page-level only: no section or field radios exist for it, and the By
        // Section / By Field accordions must not render an empty panel for it.
        $this->assertStringNotContainsString('name="permissions[kol_management.', $html);
        $this->assertStringNotContainsString('id="sec-acc-kol_management"', $html);
        $this->assertStringNotContainsString('id="fld-mod-kol_management"', $html);

        // Exactly three radios on its row — Default / Full Access / No Access.
        // View Only and Edit Only are dashes, not unselectable radios, so the
        // table never implies a choice the server would reject.
        $this->assertSame(
            3,
            substr_count($html, 'name="permissions[kol_management]"'),
            'KOL Management should offer Default, Full Access and No Access only.'
        );

        preg_match_all(
            '/name="permissions\[kol_management\]"\s+value="([a-z]*)"/',
            $html,
            $matches
        );
        $this->assertSame(['', 'full', 'none'], $matches[1]);
    }
}
