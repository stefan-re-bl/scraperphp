<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadContact>
 */
class LeadContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(), 'name' => fake()->name(), 'email' => fake()->safeEmail(), 'is_primary' => false,
        ];
    }
}
