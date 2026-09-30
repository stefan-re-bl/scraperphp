<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Offer;

class OfferMatcher
{
    public function match(Lead $lead): ?Offer
    {
        $signals = collect($lead->signals ?? []);
        $category = mb_strtolower((string) $lead->category);
        $segment = mb_strtolower((string) $lead->segment);

        return Offer::where('active', true)->get()
            ->sortByDesc(function (Offer $offer) use ($signals, $category, $segment) {
                $score = 0;
                $text = mb_strtolower(implode(' ', [
                    $offer->segment,
                    $offer->target_customer,
                    $offer->pains,
                    $offer->compatibility_signals,
                    $offer->name,
                ]));

                foreach ([$category, $segment] as $needle) {
                    if ($needle && str_contains($text, $needle)) {
                        $score += 25;
                    }
                }

                foreach ($signals as $signal) {
                    if (str_contains($text, mb_strtolower((string) $signal))) {
                        $score += 15;
                    }
                }

                if ($signals->contains('no_website') && str_contains($text, 'web')) {
                    $score += 20;
                }

                if ($signals->contains('manual_contact_dependency') && str_contains($text, 'turn')) {
                    $score += 20;
                }

                return $score;
            })
            ->first();
    }
}
