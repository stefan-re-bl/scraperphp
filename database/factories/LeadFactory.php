<?php

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(), 'category' => 'Servicios', 'segment' => 'servicios', 'country' => 'Argentina', 'city' => 'Córdoba', 'location' => 'Córdoba',
            'language' => 'es', 'email' => fake()->unique()->safeEmail(), 'phone' => '+543516000001', 'source' => 'manual', 'status' => 'QUEUED', 'acquired_at' => now(),
        ];
    }
}
