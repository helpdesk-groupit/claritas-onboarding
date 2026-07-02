<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAddressCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $user = User::factory()->superadmin()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    public function test_address_change_cascades_to_matching_employees_only(): void
    {
        $company = Company::create(['name' => 'Cozzi Muar', 'address' => 'OLD ADDRESS']);
        // Uses the company default → should update
        $onDefault = Employee::factory()->create(['company' => 'Cozzi Muar', 'office_location' => 'OLD ADDRESS']);
        // Has a custom office → should be preserved
        $custom = Employee::factory()->create(['company' => 'Cozzi Muar', 'office_location' => 'Remote - Penang']);
        // Different company with the same string → must NOT be touched
        $other = Employee::factory()->create(['company' => 'Other Co', 'office_location' => 'OLD ADDRESS']);

        $this->actingAs($this->superadmin())->put(route('superadmin.companies.update', $company), [
            'name' => 'Cozzi Muar',
            'address' => 'NEW ADDRESS',
        ])->assertSessionHasNoErrors();

        $this->assertSame('NEW ADDRESS', $onDefault->fresh()->office_location);
        $this->assertSame('Remote - Penang', $custom->fresh()->office_location);
        $this->assertSame('OLD ADDRESS', $other->fresh()->office_location);
    }

    public function test_no_cascade_when_address_unchanged(): void
    {
        $company = Company::create(['name' => 'Cozzi KL', 'address' => 'SAME ADDRESS']);
        $emp = Employee::factory()->create(['company' => 'Cozzi KL', 'office_location' => 'SAME ADDRESS']);

        $this->actingAs($this->superadmin())->put(route('superadmin.companies.update', $company), [
            'name' => 'Cozzi KL',
            'address' => 'SAME ADDRESS',
        ])->assertSessionHasNoErrors();

        $this->assertSame('SAME ADDRESS', $emp->fresh()->office_location);
    }
}
