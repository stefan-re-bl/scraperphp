<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->sentence(3), 'segment' => 'servicios', 'country' => 'Argentina', 'city' => 'Córdoba', 'language' => 'es',
            'source' => 'csv_manual', 'channel' => 'email', 'mode' => 'manual', 'daily_limit' => 20, 'scrape_limit' => 50, 'follow_up_1_delay_days' => 3, 'follow_up_2_delay_days' => 7, 'status' => 'active',
        ];
    }
}
