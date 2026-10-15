<?php

namespace Database\Factories;

use App\Models\ClientService;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientService>
 */
class ClientServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory()->state(['is_customer' => true]), 'name' => 'Mantenimiento web', 'type' => 'maintenance', 'status' => 'active', 'amount_minor' => 1500000, 'currency' => 'ARS',
        ];
    }
}
