<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadOfferScore;
use App\Models\Offer;
use App\Services\LeadScorer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeadOfferScore> */
class LeadOfferScoreFactory extends Factory
{
    public function definition(): array
    {
        return ['lead_id' => Lead::factory(), 'offer_id' => Offer::factory(), 'total' => 0,
            'dimensions' => ['fit' => 0, 'need' => 0, 'contact' => 0, 'evidence' => 0],
            'explanation' => ['unknowns' => ['Sin evidencia evaluada'], 'evidence' => [], 'evidence_ids' => []],
            'confidence' => 0, 'coverage' => 0, 'rule_version' => LeadScorer::ruleVersion(), 'calculated_at' => now()];
    }
}
