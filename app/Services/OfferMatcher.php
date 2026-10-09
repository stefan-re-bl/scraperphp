<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Offer;

class OfferMatcher
{
    public function evaluate(Lead $lead, Offer $offer): array
    {
        $rules = $offer->rules ?? [];
        $text = mb_strtolower(implode(' ', [$lead->category, $lead->segment, $lead->relationship_type]));
        $keywords = $rules['keywords'] ?? array_filter([$offer->segment, $offer->target_customer]);
        $signals = $lead->signalRows()->where('review_status', 'confirmed')->pluck('type')->all();
        $points = 0;
        $reasons = [];
        foreach ($keywords as $word) {
            if (filled($word) && str_contains($text, mb_strtolower($word))) {
                $points += 25;
                $reasons[] = 'Coincide con '.$word;
            }
        }
        foreach ($rules['signals'] ?? [] as $signal) {
            if (in_array($signal, $signals, true)) {
                $points += 15;
                $reasons[] = 'Señal: '.$signal;
            }
        }

        return ['score' => $points, 'reason' => implode('. ', $reasons), 'eligible' => $points >= max(1, (int) ($rules['minimum'] ?? 20))];
    }

    public function match(Lead $lead): ?Offer
    {
        $ranked = Offer::where('active', true)->get()->map(fn ($offer) => ['offer' => $offer, ...$this->evaluate($lead, $offer)])->filter(fn ($row) => $row['eligible'])->sortByDesc('score');
        $best = $ranked->first();
        $lead->offer_match_reason = $best ? $best['reason'] : 'No hay evidencia suficiente para sugerir una oferta.';

        return $best['offer'] ?? null;
    }
}
