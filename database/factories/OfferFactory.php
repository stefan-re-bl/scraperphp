<?php

namespace Database\Factories;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offer> */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'segment' => 'servicios', 'active' => true, 'rules' => ['scoring_profile' => 'generic', 'keywords' => ['servicios']]];
    }
}
