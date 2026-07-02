<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyGroupTest extends TestCase
{
    use RefreshDatabase;

    private function hrManager(): User
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    public function test_group_map_normalizes_names_and_skips_ungrouped(): void
    {
        Company::create(['name' => 'Cozzi Batu Pahat', 'company_group' => 'Cozzi']);
        Company::create(['name' => 'Cozzi Muar', 'company_group' => 'Cozzi']);
        Company::create(['name' => 'Claritas Asia Sdn. Bhd.']); // no group

        $map = Company::groupMap();

        $this->assertSame([
            'cozzi batu pahat' => 'cozzi',
            'cozzi muar' => 'cozzi',
        ], $map);
        $this->assertArrayNotHasKey('claritas asia sdn bhd', $map);
    }

    public function test_norm_name_matches_expected_shape(): void
    {
        $this->assertSame('cozzi batu pahat sdn bhd', Company::normName('Cozzi Batu Pahat Sdn. Bhd.'));
        $this->assertSame('cozzi muar', Company::normName('  Cozzi   Muar  '));
    }

    public function test_create_form_embeds_group_map_for_manager_filter(): void
    {
        Company::create(['name' => 'Cozzi Batu Pahat', 'company_group' => 'Cozzi']);
        Company::create(['name' => 'Cozzi Muar', 'company_group' => 'Cozzi']);
        // A manager at Batu Pahat that Muar employees should be able to pick.
        Employee::factory()->create(['full_name' => 'Ho Chew Ying', 'company' => 'Cozzi Batu Pahat', 'work_role' => 'manager']);
        $user = $this->hrManager();

        $res = $this->actingAs($user)->get(route('employees.create'));
        $res->assertStatus(200);
        // The JS group map must be present so the client filter can share the pool.
        $res->assertSee('cozzi batu pahat', false);
        $res->assertSee('EMP_COMPANY_GROUPS', false);
        // Ho Chew Ying is in the manager option list (JS decides visibility).
        $res->assertSee('Ho Chew Ying', false);
    }

    public function test_company_group_saved_on_store_and_update(): void
    {
        $user = User::factory()->superadmin()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        $this->actingAs($user)->post(route('superadmin.companies.store'), [
            'name' => 'Cozzi KL', 'company_group' => 'Cozzi',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['name' => 'Cozzi KL', 'company_group' => 'Cozzi']);

        $company = Company::where('name', 'Cozzi KL')->first();
        $this->actingAs($user)->put(route('superadmin.companies.update', $company), [
            'name' => 'Cozzi KL', 'company_group' => 'CozziGroup',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['name' => 'Cozzi KL', 'company_group' => 'CozziGroup']);
    }
}
