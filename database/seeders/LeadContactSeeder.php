<?php

namespace Database\Seeders;

use App\Models\LeadContact;
use Illuminate\Database\Seeder;

class LeadContactSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        LeadContact::factory()->count(2)->create();
    }
}
