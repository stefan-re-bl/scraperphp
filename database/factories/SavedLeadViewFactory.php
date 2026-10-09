<?php

namespace Database\Factories;

use App\Models\SavedLeadView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedLeadView>
 */
class SavedLeadViewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->sentence(2), 'scope' => 'global', 'filters' => ['status' => 'QUEUED'],
        ];
    }
}
