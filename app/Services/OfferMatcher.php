<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Offer;

class OfferMatcher
{
    public function evaluate(Lead $lead, Offer $offer): array
    {
        $evaluation = app(LeadScorer::class)->evaluateOffer($lead, $offer);

        return ['score' => $evaluation['total'], 'reason' => $this->reason($evaluation), 'eligible' => $this->eligible($offer, $evaluation)];
    }

    public function match(Lead $lead): ?Offer
    {
        $best = $this->recommend(app(LeadScorer::class)->evaluateOffers($lead));
        $lead->offer_match_reason = $best ? $this->reason($best['evaluation']) : 'No hay evidencia suficiente para sugerir una oferta.';

        return $best['offer'] ?? null;
    }

    public function recommend(array $evaluations): ?array
    {
        return collect($evaluations)->filter(fn (array $row): bool => $row['offer']->active && $this->eligible($row['offer'], $row['evaluation']))
            ->sort(function (array $left, array $right): int {
                return ($right['evaluation']['total'] <=> $left['evaluation']['total']) ?: ($left['offer']->id <=> $right['offer']->id);
            })->first();
    }

    public function reason(array $evaluation): string
    {
        $reasons = collect($evaluation['explanation']['rules'])->whereIn('dimension', ['fit', 'need'])->pluck('reason')->unique()->values()->all();

        return ($reasons !== [] ? implode(' ', $reasons) : 'Evidencia comercial insuficiente.').' Confianza: '.round($evaluation['confidence'] * 100).' %. No garantiza interés ni conversión.';
    }

    private function eligible(Offer $offer, array $evaluation): bool
    {
        return $evaluation['total'] >= max(1, (int) ($offer->rules['minimum'] ?? 20))
            && $evaluation['dimensions']['fit'] >= 40 && $evaluation['confidence'] >= config('commercial_scoring.minimum_confidence', 0.35)
            && $evaluation['coverage'] >= config('commercial_scoring.minimum_coverage', 0.25)
            && $evaluation['explanation']['contradictions'] === [];
    }
}
