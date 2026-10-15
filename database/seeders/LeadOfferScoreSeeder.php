<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Services\LeadScorer;
use Illuminate\Database\Seeder;

class LeadOfferScoreSeeder extends Seeder
{
    public function run(): void
    {
        Lead::query()->chunkById(100, function ($leads): void {
            foreach ($leads as $lead) {
                app(LeadScorer::class)->score($lead);
            }
        });
    }
}
