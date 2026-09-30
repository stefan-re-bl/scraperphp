<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\ScoreComponent;
use App\Models\ScoringRule;
use App\Models\Signal;
use Illuminate\Support\Carbon;

class LeadScorer
{
    public function score(Lead $lead): int
    {
        ScoreComponent::where('lead_id', $lead->id)->delete();

        $components = [];

        $rules = $this->rules();

        $this->add($components, 'contact_quality_email', filled($lead->email), $rules['contact_quality_email'] ?? 25, $lead->email);
        $this->add($components, 'contact_quality_phone', filled($lead->phone), $rules['contact_quality_phone'] ?? 20, $lead->phone);
        $this->add($components, 'data_quality_website', filled($lead->website_url), $rules['data_quality_website'] ?? 15, $lead->website_url);
        $this->add($components, 'source_identifier_available', filled($lead->source_identifier), $rules['source_identifier_available'] ?? 10, $lead->source_identifier);

        $category = mb_strtolower((string) $lead->category);
        $healthMatch = str_contains($category, 'psic') ||
            str_contains($category, 'clinic') ||
            str_contains($category, 'salud') ||
            str_contains($category, 'doctor') ||
            str_contains($category, 'terap');
        $this->add($components, 'offer_fit_health', $healthMatch, $rules['offer_fit_health'] ?? 20, $lead->category);

        if (blank($lead->website_url)) {
            Signal::updateOrCreate([
                'lead_id' => $lead->id,
                'type' => 'no_website',
            ], [
                'value' => 'true',
                'evidence' => 'El CSV importado no contiene sitio web.',
                'detected_at' => Carbon::now(),
            ]);
            $this->add($components, 'problem_no_website', true, $rules['problem_no_website'] ?? 10, 'Sin sitio web visible.');
        }

        foreach ($lead->signals ?? [] as $signal) {
            $this->add($components, 'signal_'.$signal, true, $rules['signal_'.$signal] ?? 5, $signal);
        }

        $total = array_sum(array_column($components, 'points'));

        foreach ($components as $component) {
            ScoreComponent::create([
                'lead_id' => $lead->id,
                ...$component,
            ]);
        }

        $lead->forceFill([
            'score_total' => $total,
            'signals' => Signal::where('lead_id', $lead->id)->pluck('type')->all(),
            'data_quality' => $total >= 45 ? 'usable' : 'partial',
        ])->save();

        return $total;
    }

    private function add(array &$components, string $name, bool $condition, int $points, ?string $evidence): void
    {
        if (! $condition) {
            return;
        }

        $components[] = [
            'component' => $name,
            'weight' => 1,
            'value' => 'true',
            'points' => $points,
            'evidence' => $evidence,
        ];
    }

    private function rules(): array
    {
        $defaults = [
            'contact_quality_email' => 25,
            'contact_quality_phone' => 20,
            'data_quality_website' => 15,
            'source_identifier_available' => 10,
            'offer_fit_health' => 20,
            'problem_no_website' => 10,
            'signal_no_visible_booking' => 15,
            'signal_manual_contact_dependency' => 15,
            'signal_hard_to_find_contact' => -10,
            'signal_website_unreachable' => -15,
        ];

        foreach ($defaults as $component => $points) {
            ScoringRule::firstOrCreate(['component' => $component], [
                'points' => $points,
                'weight' => 1,
                'active' => true,
                'description' => $component,
            ]);
        }

        return ScoringRule::where('active', true)->pluck('points', 'component')->all();
    }
}
