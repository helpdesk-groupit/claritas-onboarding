<?php

namespace Database\Factories;

use App\Models\Onboarding;
use App\Models\WorkDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkDetailFactory extends Factory
{
    protected $model = WorkDetail::class;

    public function definition(): array
    {
        return [
            'onboarding_id'    => Onboarding::factory(),
            'employee_status'  => 'active',
            'staff_status'     => 'new',
            'employment_type'  => 'permanent',
            'designation'      => fake()->jobTitle(),
            'company'          => 'Claritas Asia Sdn. Bhd.',
            'office_location'  => 'Kuala Lumpur HQ',
            'reporting_manager'=> fake()->name(),
            'start_date'       => fake()->dateTimeBetween('+1 month', '+3 months'),
            'company_email'    => fake()->unique()->companyEmail(),
            'department'       => fake()->randomElement(['Technology', 'Human Resources', 'Marketing', 'Finance']),
            // work_details.role is the ACCESS role enum, which has no 'employee' member —
            // 'others' is what OnboardingController stores for a normal hire. The old value
            // was silently truncated by MySQL, so every insert through this factory failed.
            'role'             => 'others',
        ];
    }
}
