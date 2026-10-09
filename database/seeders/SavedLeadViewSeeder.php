<?php

namespace Database\Seeders;

use App\Models\SavedLeadView;
use Illuminate\Database\Seeder;

class SavedLeadViewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SavedLeadView::firstOrCreate(['name' => 'Listos para contactar', 'scope' => 'global'], ['filters' => ['status' => 'QUEUED']]);
        SavedLeadView::firstOrCreate(['name' => 'Colaboradores', 'scope' => 'global'], ['filters' => ['relationship_type' => 'collaborator']]);
    }
}
