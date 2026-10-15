<?php

namespace App\Models;

use App\Services\LeadScorer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadOfferScore extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dimensions' => 'array', 'explanation' => 'array', 'confidence' => 'float', 'coverage' => 'float', 'calculated_at' => 'datetime', 'total' => 'integer'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function isFresh(): bool
    {
        return $this->exists && static::query()->current()->whereKey($this->id)->exists();
    }

    /** Current scores remain read-only; CRM outcomes and notes do not invalidate predictors. */
    public function scopeCurrent(Builder $query): Builder
    {
        $query->where('lead_offer_scores.rule_version', LeadScorer::ruleVersion())->where('lead_offer_scores.calculated_at', '<=', now())
            ->whereExists(function ($offer): void {
                $offer->selectRaw('1')->from('offers')->whereColumn('offers.id', 'lead_offer_scores.offer_id')
                    ->whereColumn('offers.updated_at', '<=', 'lead_offer_scores.calculated_at');
                foreach (['name', 'segment', 'target_customer', 'rules'] as $field) {
                    $offer->whereRaw("coalesce(json_extract(lead_offer_scores.explanation, '$.offer_state.{$field}'), '') = coalesce(offers.{$field}, '')");
                }
            })->whereExists(function ($lead): void {
                $lead->selectRaw('1')->from('leads')->whereColumn('leads.id', 'lead_offer_scores.lead_id')
                    ->where(fn ($q) => $q->whereNull('leads.enriched_at')->orWhereColumn('leads.enriched_at', '<=', 'lead_offer_scores.calculated_at'));
                foreach (['category', 'segment', 'country', 'website_url', 'email', 'phone'] as $field) {
                    $lead->whereRaw("coalesce(json_extract(lead_offer_scores.explanation, '$.input_state.{$field}'), '') = coalesce(leads.{$field}, '')");
                }
            })->whereNotExists(function ($signal): void {
                $signal->selectRaw('1')->from('signals')->whereColumn('signals.lead_id', 'lead_offer_scores.lead_id')
                    ->where('signals.source', '!=', 'crm')->whereRaw("coalesce(json_extract(signals.metadata, '$.not_a_score_predictor'), 0) != 1")
                    ->where(fn ($changed) => $changed->whereColumn('signals.updated_at', '>', 'lead_offer_scores.calculated_at')
                        ->orWhere(fn ($expired) => $expired->whereColumn('signals.expires_at', '>', 'lead_offer_scores.calculated_at')->where('signals.expires_at', '<=', now())));
            })->whereNotExists(function ($rule): void {
                $rule->selectRaw('1')->from('scoring_rules')->where(fn ($owner) => $owner->whereNull('offer_id')->orWhereColumn('scoring_rules.offer_id', 'lead_offer_scores.offer_id'))
                    ->whereColumn('scoring_rules.updated_at', '>', 'lead_offer_scores.calculated_at');
            });
        $stateChecks = [];
        foreach (['type', 'value', 'source', 'evidence', 'review_status', 'evidence_kind', 'source_reference', 'extraction_method', 'confidence', 'rule_version', 'detected_at', 'expires_at'] as $field) {
            $stateChecks[] = "coalesce(signals.{$field}, '') = coalesce(json_extract(recorded.value, '$.{$field}'), '')";
        }
        foreach (['identity_status', 'offer_id'] as $field) {
            $stateChecks[] = "coalesce(json_extract(signals.metadata, '$.{$field}'), '') = coalesce(json_extract(recorded.value, '$.metadata_{$field}'), '')";
        }
        $query->whereRaw("NOT EXISTS (SELECT 1 FROM json_each(lead_offer_scores.explanation, '$.signal_state') AS recorded WHERE NOT EXISTS (SELECT 1 FROM signals WHERE signals.id = json_extract(recorded.value, '$.id') AND signals.is_current = 1 AND ".implode(' AND ', $stateChecks).'))');
        $ignored = config('commercial_scoring.ignored', []);
        $placeholders = implode(',', array_fill(0, count($ignored), '?'));
        $query->whereRaw("NOT EXISTS (SELECT 1 FROM signals WHERE signals.lead_id = lead_offer_scores.lead_id AND signals.source != 'crm' AND coalesce(json_extract(signals.metadata, '$.not_a_score_predictor'), 0) != 1 AND signals.is_current = 1 AND signals.review_status != 'rejected' AND signals.detected_at <= ? AND (signals.expires_at IS NULL OR signals.expires_at > ?) AND (signals.type = 'identity_conflict' OR signals.evidence_kind = 'conflict' OR json_extract(signals.metadata, '$.identity_status') = 'conflict' OR (signals.review_status = 'confirmed' AND coalesce(signals.evidence_kind, 'observed') != 'unknown' AND signals.type NOT IN ({$placeholders}))) AND NOT EXISTS (SELECT 1 FROM json_each(lead_offer_scores.explanation, '$.signal_state') AS recorded WHERE json_extract(recorded.value, '$.id') = signals.id))", [now()->toDateTimeString(), now()->toDateTimeString(), ...$ignored]);
        $ruleChecks = [];
        foreach (['offer_id', 'component', 'points', 'weight', 'dimension', 'active'] as $field) {
            $ruleChecks[] = "coalesce(scoring_rules.{$field}, '') = coalesce(json_extract(recorded.value, '$.{$field}'), '')";
        }
        $query->whereRaw("NOT EXISTS (SELECT 1 FROM json_each(lead_offer_scores.explanation, '$.rule_state') AS recorded WHERE NOT EXISTS (SELECT 1 FROM scoring_rules WHERE scoring_rules.id = json_extract(recorded.value, '$.id') AND ".implode(' AND ', $ruleChecks).'))');
        $query->whereRaw("NOT EXISTS (SELECT 1 FROM scoring_rules WHERE (scoring_rules.offer_id IS NULL OR scoring_rules.offer_id = lead_offer_scores.offer_id) AND NOT EXISTS (SELECT 1 FROM json_each(lead_offer_scores.explanation, '$.rule_state') AS recorded WHERE json_extract(recorded.value, '$.id') = scoring_rules.id))");

        return $query;
    }
}
