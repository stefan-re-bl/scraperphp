<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\ScoringRule;
use Illuminate\Support\Facades\DB;

class LeadScorer
{
    public const DEFAULTS = [
        'contact_quality_email' => [25, 'contact', 'Email válido'],
        'contact_quality_phone' => [20, 'contact', 'Teléfono utilizable'],
        'data_quality_website' => [15, 'data', 'Sitio identificado'],
        'source_identifier_available' => [10, 'data', 'Identificador de fuente'],
        'problem_no_website' => [10, 'fit', 'Sin sitio registrado'],
        'signal_ads_active' => [20, 'fit', 'Invierte en captación'],
        'signal_clear_offer' => [10, 'fit', 'Oferta comercial clara'],
        'signal_observed_problem' => [15, 'fit', 'Problema confirmado'],
        'signal_weak_cta' => [5, 'fit', 'Contacto poco claro'],
    ];

    public function rules(): void
    {
        foreach (self::DEFAULTS as $component => [$points, $dimension, $description]) {
            ScoringRule::firstOrCreate(['component' => $component, 'offer_id' => null], ['points' => $points, 'weight' => 1, 'active' => true, 'dimension' => $dimension, 'description' => $description]);
        }
    }

    public function score(Lead $lead): int
    {
        $this->rules();

        return DB::transaction(function () use ($lead) {
            $lead->refresh();
            $rules = ScoringRule::whereNull('offer_id')->orderBy('id')->get()->keyBy('component');
            if ($lead->suggested_offer_id) {
                foreach (ScoringRule::where('offer_id', $lead->suggested_offer_id)->get() as $rule) {
                    $rules->put($rule->component, $rule);
                }
            }
            $signals = $lead->signalRows()->where('review_status', 'confirmed')->get()->keyBy('type');
            $conditions = [
                'contact_quality_email' => [filter_var($lead->email, FILTER_VALIDATE_EMAIL) !== false, $lead->email],
                'contact_quality_phone' => [app(ContactNormalizer::class)->phone($lead->phone, $lead->country) !== null, $lead->phone],
                'data_quality_website' => [filled($lead->website_url), $lead->website_url],
                'source_identifier_available' => [filled($lead->source_identifier), $lead->source_identifier],
                'problem_no_website' => [blank($lead->website_url), 'Sin sitio registrado; pendiente de revisión comercial.'],
            ];
            foreach ($signals as $type => $signal) {
                if ($type !== 'no_website') {
                    $conditions['signal_'.$type] = [$signal->value !== 'false', $signal->evidence];
                }
            }
            $lead->scoreComponents()->delete();
            $totals = ['data' => 0, 'contact' => 0, 'fit' => 0];
            foreach ($rules as $component => $rule) {
                if (! $rule->active || ! ($conditions[$component][0] ?? false)) {
                    continue;
                }
                $points = $rule->points * $rule->weight;
                $totals[$rule->dimension] = ($totals[$rule->dimension] ?? 0) + $points;
                $lead->scoreComponents()->create(['component' => $component, 'weight' => $rule->weight, 'value' => 'true', 'points' => $points, 'evidence' => $conditions[$component][1]]);
            }
            $total = array_sum($totals);
            $lead->update([
                'score_total' => $total, 'data_quality_score' => $totals['data'],
                'contactability_score' => $totals['contact'], 'offer_fit_score' => $totals['fit'],
                'signals' => $lead->signalRows()->where('review_status', '!=', 'rejected')->orderBy('type')->pluck('type')->all(),
                'data_quality' => $totals['contact'] > 0 ? 'usable' : 'partial',
            ]);

            return $total;
        });
    }
}
