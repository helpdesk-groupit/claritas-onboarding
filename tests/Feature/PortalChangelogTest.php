<?php

namespace Tests\Feature;

use App\Models\PortalChangelogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalChangelogTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->superadmin()->withTwoFactor()->create();
    }

    /** @dataProvider nonSuperadminRoles */
    public function test_non_superadmin_roles_cannot_view_the_index(string $factoryState): void
    {
        $user = User::factory()->{$factoryState}()->withTwoFactor()->create();

        $this->actingAs($user)
            ->get(route('superadmin.changelog.index'))
            ->assertForbidden();
    }

    public static function nonSuperadminRoles(): array
    {
        return [
            'hr_manager'    => ['hrManager'],
            'it_manager'    => ['itManager'],
            'system_admin'  => ['systemAdmin'],
            'finance_manager' => ['financeManager'],
        ];
    }

    public function test_a_plain_employee_cannot_view_the_index(): void
    {
        $user = User::factory()->create(); // default role: employee

        $this->actingAs($user)
            ->get(route('superadmin.changelog.index'))
            ->assertForbidden();
    }

    public function test_superadmin_can_view_the_index(): void
    {
        PortalChangelogEntry::factory()->create(['title' => 'Fixed the login redirect loop']);

        $this->actingAs($this->superadmin())
            ->get(route('superadmin.changelog.index'))
            ->assertOk()
            ->assertSee('Fixed the login redirect loop');
    }

    public function test_superadmin_can_create_an_entry_and_is_credited_by_default(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('superadmin.changelog.store'), [
            '_form'       => 'add',
            'title'       => 'Added bulk vendor import',
            'description' => 'Reads .xlsx/.csv vendor lists.',
            'type'        => 'feature',
            'status'      => 'completed',
            'module_area' => 'Vendor Management',
            'occurred_at' => '2026-08-14T10:00',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('portal_changelog_entries', [
            'title'              => 'Added bulk vendor import',
            'type'               => 'feature',
            'status'             => 'completed',
            'module_area'        => 'Vendor Management',
            'changed_by_user_id' => $admin->id,
            'changed_by_name'    => $admin->name,
        ]);
    }

    public function test_the_recorded_by_name_can_be_overridden_at_creation(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('superadmin.changelog.store'), [
            '_form'           => 'add',
            'title'           => 'Fixed a CSP violation on the vendor form',
            'type'            => 'bug_fix',
            'status'          => 'completed',
            'occurred_at'     => '2026-08-14T10:00',
            'changed_by_name' => 'Claude Code',
        ])->assertSessionHasNoErrors();

        $entry = PortalChangelogEntry::where('title', 'Fixed a CSP violation on the vendor form')->firstOrFail();

        // The credited author is editable, but the account that logged it is still recorded.
        $this->assertSame('Claude Code', $entry->changed_by_name);
        $this->assertSame($admin->id, $entry->changed_by_user_id);
    }

    public function test_title_is_required(): void
    {
        $this->actingAs($this->superadmin())->post(route('superadmin.changelog.store'), [
            '_form'       => 'add',
            'type'        => 'feature',
            'status'      => 'completed',
            'occurred_at' => '2026-08-14T10:00',
        ])->assertSessionHasErrors('title');

        $this->assertDatabaseCount('portal_changelog_entries', 0);
    }

    public function test_an_invalid_status_value_is_rejected(): void
    {
        $this->actingAs($this->superadmin())->post(route('superadmin.changelog.store'), [
            '_form'       => 'add',
            'title'       => 'Something',
            'type'        => 'feature',
            'status'      => 'not_a_real_status',
            'occurred_at' => '2026-08-14T10:00',
        ])->assertSessionHasErrors('status');
    }

    public function test_superadmin_can_edit_an_entry_and_the_original_author_account_is_preserved(): void
    {
        $original = $this->superadmin();
        $editor = User::factory()->superadmin()->withTwoFactor()->create();

        $entry = PortalChangelogEntry::factory()->create([
            'title'              => 'Planned: rebuild the reporting page',
            'status'             => 'planned',
            'changed_by_user_id' => $original->id,
            'changed_by_name'    => $original->name,
        ]);

        $this->actingAs($editor)->put(route('superadmin.changelog.update', $entry), [
            '_form'       => 'edit',
            'entry_id'    => $entry->id,
            'title'       => 'Rebuilt the reporting page',
            'type'        => $entry->type,
            'status'      => 'completed',
            'occurred_at' => '2026-08-20T09:00',
            'changed_by_name' => $original->name,
        ])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame('Rebuilt the reporting page', $entry->title);
        $this->assertSame('completed', $entry->status);
        // changed_by_user_id is the audit fact — an edit by someone else must never rewrite it.
        $this->assertSame($original->id, $entry->changed_by_user_id);
        $this->assertSame($editor->id, $entry->updated_by_user_id);
        $this->assertSame($editor->name, $entry->updated_by_name);
    }

    public function test_superadmin_can_delete_an_entry(): void
    {
        $entry = PortalChangelogEntry::factory()->create();

        $this->actingAs($this->superadmin())
            ->delete(route('superadmin.changelog.destroy', $entry))
            ->assertRedirect();

        $this->assertDatabaseMissing('portal_changelog_entries', ['id' => $entry->id]);
    }

    public function test_non_superadmin_cannot_create_edit_or_delete(): void
    {
        $hr = User::factory()->hrManager()->withTwoFactor()->create();
        $entry = PortalChangelogEntry::factory()->create();

        $this->actingAs($hr)->post(route('superadmin.changelog.store'), [
            'title' => 'Should not be created', 'type' => 'feature', 'status' => 'completed',
            'occurred_at' => '2026-08-14T10:00',
        ])->assertForbidden();

        $this->actingAs($hr)->put(route('superadmin.changelog.update', $entry), [
            'title' => 'Should not update', 'type' => 'feature', 'status' => 'completed',
            'occurred_at' => '2026-08-14T10:00',
        ])->assertForbidden();

        $this->actingAs($hr)->delete(route('superadmin.changelog.destroy', $entry))->assertForbidden();

        $this->assertDatabaseMissing('portal_changelog_entries', ['title' => 'Should not be created']);
        $this->assertDatabaseHas('portal_changelog_entries', ['id' => $entry->id, 'title' => $entry->title]);
    }

    public function test_the_module_area_dropdown_offers_the_known_modules(): void
    {
        $response = $this->actingAs($this->superadmin())->get(route('superadmin.changelog.index'));

        $response->assertOk();
        foreach (['Vendor Management', 'Ticketing / Helpdesk', 'Onboarding'] as $area) {
            $response->assertSee($area);
        }
    }

    public function test_a_module_area_from_the_fixed_list_can_be_recorded(): void
    {
        $this->actingAs($this->superadmin())->post(route('superadmin.changelog.store'), [
            '_form'       => 'add',
            'title'       => 'Widened vendor SST categories',
            'type'        => 'feature',
            'status'      => 'completed',
            'module_area' => 'Vendor Management',
            'occurred_at' => '2026-08-14T10:00',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('portal_changelog_entries', [
            'title'       => 'Widened vendor SST categories',
            'module_area' => 'Vendor Management',
        ]);
    }

    public function test_a_legacy_module_area_not_in_the_fixed_list_survives_an_unrelated_edit(): void
    {
        // module_area isn't enum-validated server-side on purpose, so a value saved before the
        // dropdown existed (or naming a module the fixed list doesn't have yet) must not be
        // rejected or wiped out just because it isn't one of the offered options.
        $entry = PortalChangelogEntry::factory()->create([
            'title'       => 'Old free-text entry',
            'module_area' => 'Some Legacy Tag',
        ]);

        $this->actingAs($this->superadmin())->get(route('superadmin.changelog.index'))
            ->assertOk()
            ->assertSee('data-module="Some Legacy Tag"', false);

        $this->actingAs($this->superadmin())->put(route('superadmin.changelog.update', $entry), [
            '_form'       => 'edit',
            'entry_id'    => $entry->id,
            'title'       => 'Old free-text entry (retitled)',
            'type'        => $entry->type,
            'status'      => $entry->status,
            'module_area' => 'Some Legacy Tag',
            'occurred_at' => '2026-08-14T10:00',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('portal_changelog_entries', [
            'id'          => $entry->id,
            'module_area' => 'Some Legacy Tag',
        ]);
    }

    public function test_listing_is_sorted_newest_occurred_first(): void
    {
        $older = PortalChangelogEntry::factory()->create(['title' => 'Older change', 'occurred_at' => now()->subDays(5)]);
        $newer = PortalChangelogEntry::factory()->create(['title' => 'Newer change', 'occurred_at' => now()->subDay()]);

        $response = $this->actingAs($this->superadmin())->get(route('superadmin.changelog.index'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Newer change') < strpos($content, 'Older change'));
    }

    public function test_status_filter_narrows_the_listing(): void
    {
        PortalChangelogEntry::factory()->create(['title' => 'A planned thing', 'status' => 'planned']);
        PortalChangelogEntry::factory()->create(['title' => 'A completed thing', 'status' => 'completed']);

        $response = $this->actingAs($this->superadmin())
            ->get(route('superadmin.changelog.index', ['status' => 'planned']));

        $response->assertOk()->assertSee('A planned thing')->assertDontSee('A completed thing');
    }

    public function test_the_sidebar_link_is_visible_only_to_superadmin(): void
    {
        // Both superadmin and hr_manager land on hr.dashboard (user.dashboard redirects them
        // there), and sidebar-settings.blade.php is included on that page for both branches.
        $this->actingAs($this->superadmin())
            ->get(route('hr.dashboard'))
            ->assertSee(route('superadmin.changelog.index'), false);

        $hr = User::factory()->hrManager()->withTwoFactor()->create();
        $this->actingAs($hr)
            ->get(route('hr.dashboard'))
            ->assertDontSee(route('superadmin.changelog.index'), false);
    }
}
