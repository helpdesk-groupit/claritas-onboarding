<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCreateTest extends TestCase
{
    use RefreshDatabase;

    private function hrManager(): User
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    public function test_hr_manager_can_open_create_form(): void
    {
        Company::create(['name' => 'Acme Sdn Bhd']);
        $user = $this->hrManager();

        $this->actingAs($user)->get(route('employees.create'))
            ->assertStatus(200)
            ->assertSee('Add New Employee')
            ->assertSee('Personal Details');
    }

    public function test_regular_employee_cannot_open_create_form(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        $this->actingAs($user)->get(route('employees.create'))->assertStatus(403);
    }

    public function test_store_creates_employee_with_all_sections(): void
    {
        Company::create(['name' => 'Acme Sdn Bhd', 'address' => '1 Jalan Test']);
        $user = $this->hrManager();

        $payload = [
            'full_name' => 'Test New Hire',
            'preferred_name' => 'Testy',
            'official_document_id' => '990101-14-5555',
            'date_of_birth' => '1999-01-01',
            'sex' => 'male',
            'marital_status' => 'married',
            'religion' => 'None',
            'race' => 'Other',
            'personal_email' => 'testy@example.com',
            'company' => 'Acme Sdn Bhd',
            'designation' => 'Engineer',
            'department' => 'Tech',
            'office_location' => '1 Jalan Test',
            'employment_type' => 'permanent',
            'employment_status' => 'active',
            'company_email' => 'testy@acme.com',
            'start_date' => '2026-07-01',
            'edu_qualification' => [0 => 'BSc Computer Science'],
            'edu_institution' => [0 => 'Test University'],
            'edu_year' => [0 => 2021],
            'edu_experience_total' => '3',
            'spouses' => [0 => ['name' => 'Spouse One', 'tel_no' => '0123456789', 'is_working' => 1]],
            'emergency' => [
                1 => ['name' => 'Mom', 'tel_no' => '0111', 'relationship' => 'Parent'],
                2 => ['name' => 'Dad', 'tel_no' => '0222', 'relationship' => 'Parent'],
            ],
            'cat_a_100' => 2,
        ];

        $response = $this->actingAs($user)->post(route('employees.store'), $payload);

        $emp = Employee::where('full_name', 'Test New Hire')->first();
        $this->assertNotNull($emp, 'Employee was not created');
        $response->assertRedirect(route('employees.show', $emp));

        $this->assertNull($emp->active_until);
        $this->assertSame('active', $emp->employment_status);
        $this->assertSame('Acme Sdn Bhd', $emp->company);
        // google_id auto-mirrors company_email
        $this->assertSame('testy@acme.com', $emp->google_id);

        $this->assertDatabaseHas('employee_education_histories', [
            'employee_id' => $emp->id, 'qualification' => 'BSc Computer Science', 'years_experience' => '3',
        ]);
        $this->assertDatabaseHas('employee_spouse_details', [
            'employee_id' => $emp->id, 'name' => 'Spouse One', 'is_working' => 1,
        ]);
        $this->assertDatabaseHas('employee_emergency_contacts', [
            'employee_id' => $emp->id, 'contact_order' => 1, 'name' => 'Mom',
        ]);
        $this->assertDatabaseHas('employee_emergency_contacts', [
            'employee_id' => $emp->id, 'contact_order' => 2, 'name' => 'Dad',
        ]);
        $this->assertDatabaseHas('employee_child_registrations', [
            'employee_id' => $emp->id, 'cat_a_100' => 2,
        ]);
    }

    public function test_store_blocks_duplicate_active_employee(): void
    {
        Company::create(['name' => 'Acme Sdn Bhd']);
        $user = $this->hrManager();
        Employee::factory()->create(['full_name' => 'Dupe Person', 'company' => 'Acme Sdn Bhd', 'active_until' => null]);

        $response = $this->actingAs($user)->post(route('employees.store'), [
            'full_name' => 'Dupe Person',
            'company' => 'Acme Sdn Bhd',
        ]);

        $response->assertSessionHasErrors('full_name');
        $this->assertSame(1, Employee::where('full_name', 'Dupe Person')->count());
    }

    public function test_store_requires_full_name_and_company(): void
    {
        $user = $this->hrManager();

        $this->actingAs($user)->post(route('employees.store'), [])
            ->assertSessionHasErrors(['full_name', 'company']);
    }
}
