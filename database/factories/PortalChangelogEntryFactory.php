<?php

namespace Database\Factories;

use App\Models\PortalChangelogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class PortalChangelogEntryFactory extends Factory
{
    protected $model = PortalChangelogEntry::class;

    public function definition(): array
    {
        return [
            'title'         => fake()->sentence(4),
            'description'   => fake()->paragraph(),
            'type'          => fake()->randomElement(array_keys(PortalChangelogEntry::TYPES)),
            'status'        => fake()->randomElement(array_keys(PortalChangelogEntry::STATUSES)),
            'module_area'   => fake()->randomElement(['Ticketing', 'Vendor Management', 'Assets', 'Onboarding', null]),
            'occurred_at'   => now(),
            'changed_by_name' => fake()->name(),
        ];
    }
}
