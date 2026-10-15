<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadOfferScore;
use App\Models\Offer;
use App\Models\ScoringRule;
use App\Models\Signal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public static function ruleVersion(): string
    {
        $configuration = config('commercial_scoring', []);

        return ($configuration['version'] ?? 'commercial-v1').'-'.substr(hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)), 0, 12);
    }

    public function rules(): void
    {
        foreach (self::DEFAULTS as $component => [$points, $dimension, $description]) {
            ScoringRule::firstOrCreate(['component' => $component, 'offer_id' => null], ['points' => $points, 'weight' => 1, 'active' => true, 'dimension' => $dimension, 'description' => $description]);
        }
    }

    public function score(Lead $lead): int
    {
        $this->rules();

        return DB::transaction(function () use ($lead): int {
            $lead->refresh();
            $context = $this->context($lead);
            $rules = $this->ruleSet($lead->suggested_offer_id, $context['rules']);
            $conditions = [
                'contact_quality_email' => [$context['email_valid'], $lead->email],
                'contact_quality_phone' => [$context['phone_valid'], $lead->phone],
                'data_quality_website' => [filled($lead->website_url), $lead->website_url],
                'source_identifier_available' => [filled($lead->source_identifier), $lead->source_identifier],
            ];
            foreach ($context['positive'] as $type => $signal) {
                $conditions['signal_'.$type] = [true, $signal->evidence];
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
            $total = (int) round(max(0, min(100, array_sum($totals))));
            $lead->update(['score_total' => $total, 'data_quality_score' => max(0, min(100, $totals['data'])),
                'contactability_score' => max(0, min(100, $totals['contact'])), 'offer_fit_score' => max(0, min(100, $totals['fit'])),
                'signals' => array_keys($context['positive']), 'data_quality' => $totals['contact'] > 0 ? 'usable' : 'partial']);
            $explicit = DB::table('campaign_lead')->join('campaigns', 'campaigns.id', '=', 'campaign_lead.campaign_id')
                ->where('campaign_lead.lead_id', $lead->id)->selectRaw('COALESCE(campaign_lead.offer_id, campaigns.offer_id) AS selected_offer_id')->pluck('selected_offer_id')->filter()->all();
            $offers = Offer::query()->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $explicit)->when($lead->suggested_offer_id, fn ($q) => $q->orWhereKey($lead->suggested_offer_id)))->orderBy('id')->get();
            $evaluations = [];
            foreach ($offers as $offer) {
                $evaluation = $this->calculateOffer($lead, $offer, $context);
                LeadOfferScore::updateOrCreate(['lead_id' => $lead->id, 'offer_id' => $offer->id], $evaluation);
                if ($offer->active) {
                    $evaluations[] = ['offer' => $offer, 'evaluation' => $evaluation];
                }
            }
            if (! in_array($lead->suggested_offer_id, $explicit) && ! str_contains((string) $lead->offer_match_reason, 'Selección manual')) {
                $best = app(OfferMatcher::class)->recommend($evaluations);
                $lead->update(($best ? ['suggested_offer_id' => $best['offer']->id] : []) +
                    ['offer_match_reason' => $best ? app(OfferMatcher::class)->reason($best['evaluation']) : 'No hay evidencia suficiente para sugerir una oferta.']);
            }

            return $total;
        });
    }

    /** Read-only evaluation, also used at the instant before a real contact. */
    public function evaluateOffer(Lead $lead, Offer $offer): array
    {
        return $this->calculateOffer($lead, $offer, $this->context($lead));
    }

    public function evaluateOffers(Lead $lead): array
    {
        $context = $this->context($lead);

        return Offer::where('active', true)->orderBy('id')->get()->map(fn (Offer $offer): array => ['offer' => $offer, 'evaluation' => $this->calculateOffer($lead, $offer, $context)])->all();
    }

    public function forOffer(Lead $lead, Offer|int $offer): ?LeadOfferScore
    {
        return LeadOfferScore::where('lead_id', $lead->id)->where('offer_id', $offer instanceof Offer ? $offer->id : $offer)->current()->first();
    }

    public function profile(Offer $offer): string
    {
        $explicit = $offer->rules['scoring_profile'] ?? null;
        if (in_array($explicit, ['booking', 'web', 'ecommerce', 'generic'], true)) {
            return $explicit;
        }
        $name = $this->text($offer->name);
        if (preg_match('/turnos|reservas|consultorio|agenda/', $name)) {
            return 'booking';
        }
        if (preg_match('/ecommerce|e-commerce|tienda online|tienda virtual|comercio electronico/', $name)) {
            return 'ecommerce';
        }

        return str_contains($name, 'web') || str_contains($name, 'landing') ? 'web' : 'generic';
    }

    private function context(Lead $lead): array
    {
        $positive = [];
        $negative = [];
        $unknowns = [];
        $contradictions = [];
        $current = $lead->signalRows()->orderBy('id')->get()->filter(fn (Signal $signal): bool => ($signal->is_current ?? true)
            && (! $signal->expires_at || $signal->expires_at->isFuture()) && $signal->detected_at->lessThanOrEqualTo(now()) && $signal->review_status !== 'rejected');
        $identityConflict = $current->contains(fn (Signal $signal): bool => $signal->type === 'identity_conflict' && $this->truth($signal->value));
        foreach ($current as $signal) {
            if ($signal->source === 'crm' || ($signal->metadata['not_a_score_predictor'] ?? false)) {
                continue;
            }
            $kind = $signal->evidence_kind ?? 'observed';
            $concept = $this->concept($signal->type);
            if ($kind === 'conflict' || $signal->type === 'identity_conflict' || ($signal->metadata['identity_status'] ?? null) === 'conflict') {
                $contradictions[] = ['concept' => $concept, 'evidence_ids' => [$signal->id], 'reason' => 'La fuente presenta una contradicción pendiente de resolución.'];

                continue;
            }
            if ($signal->review_status !== 'confirmed' || $kind === 'unknown' || in_array($signal->type, config('commercial_scoring.ignored', []), true)
                || ($identityConflict && in_array($signal->source, ['website', 'osm', 'dns', 'http'], true))) {
                $unknowns[] = 'No se utiliza evidencia sin confirmar o con identidad incierta: '.Signal::label($signal->type);

                continue;
            }
            $set = $this->truth($signal->value) ? 'positive' : 'negative';
            if ($set === 'positive') {
                if (! isset($positive[$concept]) || $this->confidence($signal) > $this->confidence($positive[$concept])) {
                    $positive[$concept] = $signal;
                }
            } elseif (! isset($negative[$concept]) || $this->confidence($signal) > $this->confidence($negative[$concept])) {
                $negative[$concept] = $signal;
            }
        }
        foreach (array_intersect(array_keys($positive), array_keys($negative)) as $concept) {
            $contradictions[] = ['concept' => $concept, 'evidence_ids' => [$positive[$concept]->id, $negative[$concept]->id], 'reason' => 'Existen observaciones positivas y negativas incompatibles.'];
            unset($positive[$concept], $negative[$concept]);
        }

        return ['signal_state' => $current->filter(fn (Signal $signal): bool => $this->stateRelevant($signal))->map($this->signalState(...))->values()->all(),
            'rules' => ScoringRule::orderBy('id')->get(), 'positive' => $positive, 'negative' => $negative, 'unknowns' => array_values(array_unique($unknowns)), 'contradictions' => $contradictions,
            'email_valid' => filter_var($lead->email, FILTER_VALIDATE_EMAIL) !== false,
            'phone_valid' => app(ContactNormalizer::class)->phone($lead->phone, $lead->country) !== null];
    }

    private function calculateOffer(Lead $lead, Offer $offer, array $context): array
    {
        $ruleSet = $this->ruleSet($offer->id, $context['rules']);
        $profile = $this->profile($offer);
        $configuration = config('commercial_scoring.profiles.'.$profile, []);
        $rules = [];
        $used = [];
        $dimensions = ['fit' => 0, 'need' => 0, 'contact' => 0, 'evidence' => 0];
        $keywords = $offer->rules['keywords'] ?? array_filter([$offer->segment, $offer->target_customer]);
        $text = $this->text(implode(' ', [$lead->category, $lead->segment]));
        $matched = array_values(array_filter((array) $keywords, fn ($keyword): bool => is_string($keyword) && filled($keyword) && str_contains($text, $this->text($keyword))));
        if ($matched !== []) {
            $dimensions['fit'] = (int) config('commercial_scoring.keyword_fit', 70);
            $rules[] = ['component' => 'category_offer_compatibility', 'dimension' => 'fit', 'points' => $dimensions['fit'], 'weight' => 1,
                'evidence_ids' => [], 'reason' => 'Compatibilidad del rubro con las palabras configuradas: '.implode(', ', $matched)];
        }
        foreach (['fit', 'need'] as $dimension) {
            $configured = $configuration[$dimension] ?? [];
            if ($dimension === 'fit') {
                foreach ($offer->rules['signals'] ?? [] as $type) {
                    $configured[$type] ??= 50;
                }
            }
            foreach ($configured as $type => $defaultPoints) {
                $concept = $this->concept($type);
                $signal = $context['positive'][$concept] ?? null;
                if (! $signal || (isset($signal->metadata['offer_id']) && (int) $signal->metadata['offer_id'] !== $offer->id)) {
                    continue;
                }
                $override = $ruleSet['signal_'.$type] ?? null;
                if ($override && (int) $override->offer_id !== $offer->id) {
                    $override = null;
                }
                if ($override && ! $override->active) {
                    continue;
                }
                $points = max(0, min(100, $override ? $override->points * $override->weight : $defaultPoints));
                $dimensions[$dimension] = max($dimensions[$dimension], $points);
                $used[$signal->id] = $signal;
                $rules[] = ['component' => 'signal_'.$type, 'dimension' => $dimension, 'points' => $points, 'weight' => $override?->weight ?? 1,
                    'evidence_ids' => [$signal->id], 'reason' => Signal::label($type).' (solo evidencia confirmada y vigente)'];
            }
        }
        $solution = $configuration['existing_solution'] ?? null;
        if ($solution && isset($context['positive'][$this->concept($solution)])) {
            $signal = $context['positive'][$this->concept($solution)];
            $used[$signal->id] = $signal;
            if ($dimensions['need'] > ($configuration['existing_solution_need_cap'] ?? 100)) {
                $dimensions['need'] = $configuration['existing_solution_need_cap'];
                $rules[] = ['component' => 'existing_solution_limits_need', 'dimension' => 'need', 'points' => $dimensions['need'], 'weight' => 1,
                    'evidence_ids' => [$signal->id], 'reason' => 'Ya se observó un mecanismo de reserva o compra; la necesidad restante requiere una revisión específica.'];
            }
        }
        foreach (['email' => 25, 'phone' => 20] as $field => $default) {
            if (! $context[$field.'_valid']) {
                continue;
            }
            $component = 'contact_quality_'.$field;
            $rule = $ruleSet[$component] ?? null;
            if ($rule && ! $rule->active) {
                continue;
            }
            $points = max(0, ($rule ? $rule->points * $rule->weight : $default));
            $dimensions['contact'] += 100 * $points / 45;
            $rules[] = ['component' => $component, 'dimension' => 'contact', 'points' => $points, 'weight' => $rule?->weight ?? 1,
                'evidence_ids' => [], 'reason' => 'El registro principal conserva un '.$field.' utilizable; no implica entrega ni disponibilidad comercial.'];
        }
        $dimensions['contact'] = (int) round(min(100, $dimensions['contact']));
        $expected = array_values(array_unique(array_merge(['category', 'email', 'phone', 'website_verified'], array_map($this->concept(...), $configuration['known'] ?? []))));
        $known = [];
        if (filled($lead->category) || filled($lead->segment)) {
            $known[] = 'category';
        }
        foreach (['email', 'phone'] as $field) {
            if ($context[$field.'_valid']) {
                $known[] = $field;
            }
        }
        $sources = $known !== [] ? ['lead_record' => 0.65] : [];
        foreach ($expected as $concept) {
            $signal = $context['positive'][$concept] ?? $context['negative'][$concept] ?? null;
            if ($signal) {
                $known[] = $concept;
                $used[$signal->id] = $signal;
            }
        }
        foreach ($used as $signal) {
            $reference = $signal->source_reference ?? $signal->source_url ?? '';
            $host = parse_url($reference, PHP_URL_HOST);
            $origin = in_array($signal->source, ['website', 'http', 'dns', 'dns_http'], true) ? 'official_domain' : $signal->source;
            $group = $host ? $origin.':'.mb_strtolower($host) : $origin;
            $sources[$group] = max($sources[$group] ?? 0, $this->confidence($signal));
        }
        $known = array_values(array_unique($known));
        $coverage = count(array_intersect($expected, $known)) / max(1, count($expected));
        $confidence = $sources === [] ? 0 : array_sum($sources) / count($sources);
        if ($context['contradictions'] !== []) {
            $confidence *= 0.5;
        }
        $dimensions['evidence'] = (int) round(100 * $coverage * $confidence);
        $unknowns = $context['unknowns'];
        foreach (array_diff($expected, $known) as $concept) {
            $unknowns[] = 'No verificado: '.Signal::label($concept);
        }
        if ($dimensions['need'] === 0) {
            $unknowns[] = 'No existe una necesidad observable confirmada para esta oferta.';
        }
        $weights = config('commercial_scoring.dimension_weights', []);
        $total = 0;
        foreach ($dimensions as $dimension => $value) {
            $total += $value * max(0, (float) ($weights[$dimension] ?? 0));
        }
        $evidence = array_values(array_map($this->evidenceCopy(...), $used));

        return ['total' => (int) round(max(0, min(100, $total))), 'dimensions' => $dimensions, 'confidence' => round($confidence, 4), 'coverage' => round($coverage, 4),
            'rule_version' => self::ruleVersion(), 'calculated_at' => now(),
            'explanation' => ['profile' => $profile, 'offer_state' => $offer->only(['name', 'segment', 'target_customer', 'rules']),
                'signal_state' => $context['signal_state'], 'rule_state' => $context['rules']->filter(fn (ScoringRule $rule): bool => $rule->offer_id === null || (int) $rule->offer_id === $offer->id)
                    ->map(fn (ScoringRule $rule): array => $rule->only(['id', 'offer_id', 'component', 'points', 'weight', 'dimension', 'active']))->values()->all(), 'dimension_weights' => $weights, 'rules' => $rules, 'evidence' => $evidence, 'evidence_ids' => array_keys($used),
                'unknowns' => array_values(array_unique($unknowns)), 'contradictions' => $context['contradictions'],
                'input_state' => $lead->only(['category', 'segment', 'country', 'website_url', 'email', 'phone']),
                'independent_sources' => count(array_diff(array_keys($sources), ['lead_record'])), 'hypotheses' => ['Pesos iniciales prudentes; requieren validación comercial.', 'Las reglas de una dimensión usan el máximo, sin sumar señales correlacionadas.']]];
    }

    private function ruleSet(?int $offerId, Collection $allRules): Collection
    {
        $rules = $allRules->whereNull('offer_id')->keyBy('component');
        if ($offerId) {
            foreach ($allRules->where('offer_id', $offerId) as $rule) {
                $rules->put($rule->component, $rule);
            }
        }

        return $rules;
    }

    private function stateRelevant(Signal $signal): bool
    {
        if ($signal->source === 'crm' || ($signal->metadata['not_a_score_predictor'] ?? false)) {
            return false;
        }

        return $signal->type === 'identity_conflict' || $signal->evidence_kind === 'conflict' || ($signal->metadata['identity_status'] ?? null) === 'conflict'
            || ($signal->review_status === 'confirmed' && $signal->evidence_kind !== 'unknown' && ! in_array($signal->type, config('commercial_scoring.ignored', []), true));
    }

    private function signalState(Signal $signal): array
    {
        return $signal->only(['id', 'type', 'value', 'source', 'evidence', 'review_status', 'evidence_kind', 'source_reference', 'extraction_method', 'confidence', 'rule_version'])
            + ['detected_at' => $signal->detected_at?->toDateTimeString(), 'expires_at' => $signal->expires_at?->toDateTimeString(),
                'metadata_identity_status' => $signal->metadata['identity_status'] ?? null, 'metadata_offer_id' => $signal->metadata['offer_id'] ?? null];
    }

    private function evidenceCopy(Signal $signal): array
    {
        return ['id' => $signal->id, 'type' => $signal->type, 'value' => $signal->value, 'source' => $signal->source,
            'source_reference' => $signal->source_reference ?? $signal->source_url, 'extraction_method' => $signal->extraction_method,
            'confidence' => $this->confidence($signal), 'evidence_kind' => $signal->evidence_kind ?? 'observed',
            'detected_at' => $signal->detected_at?->toIso8601String(), 'expires_at' => $signal->expires_at?->toIso8601String(),
            'rule_version' => $signal->rule_version, 'evidence' => $signal->evidence];
    }

    private function confidence(Signal $signal): float
    {
        $confidence = max(0, min(1, (float) ($signal->confidence ?? 0.8)));

        return $signal->evidence_kind === 'inferred' ? min(0.7, $confidence) : $confidence;
    }

    private function concept(string $type): string
    {
        return config('commercial_scoring.concepts.'.$type, $type);
    }

    private function truth(?string $value): bool
    {
        return ! in_array(mb_strtolower(trim((string) $value)), ['', 'false', 'no', '0', 'unknown', 'null'], true);
    }

    private function text(?string $value): string
    {
        return mb_strtolower(Str::ascii((string) $value));
    }
}
