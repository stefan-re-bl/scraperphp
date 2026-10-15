<?php

namespace Database\Seeders;

use App\Models\ClientService;
use Illuminate\Database\Seeder;

class ClientServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        ClientService::factory()->count(2)->create();
    }
}
