<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadOfferScore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadFilterService
{
    public const SORTS = [
        'score' => ['score_total', 'desc'],
        'date' => ['created_at', 'desc'],
        'name' => ['name', 'asc'],
        'status' => ['status', 'asc'],
        'activity' => ['updated_at', 'desc'],
    ];

    public function fromRequest(Request $request): array
    {
        $filters = $request->only([
            'q', 'relationship_type', 'manual_priority', 'entity_type', 'classification_pending', 'tag', 'due',
            'campaign',
            'status',
            'segment',
            'city',
            'country',
            'min_score',
            'max_score',
            'offer_id',
            'score_offer_id',
            'rule_version', 'evidence_source', 'evidence_review',
            'source',
            'channel',
            'language',
            'has_email',
            'has_phone',
            'has_web',
            'signal',
            'possible_duplicate',
            'contacted',
            'replied',
            'interested',
            'proposal',
            'won',
            'lost',
            'acquired_from',
            'acquired_to',
            'ids',
            'sort',
        ]);

        return array_filter($filters, fn ($value, $key) => $key === 'ids' || ($value !== null && $value !== ''), ARRAY_FILTER_USE_BOTH);
    }

    public function query(array $filters = [], array $relations = []): Builder
    {
        $query = Lead::query()->with($relations);
        if (array_key_exists('ids', $filters)) {
            $query->whereIn('leads.id', $this->ids($filters['ids']));
        }

        if (array_key_exists('campaign', $filters) && (! is_scalar($filters['campaign']) || ! preg_match('/^[1-9][0-9]*$/D', (string) $filters['campaign']))) {
            return $query->whereRaw('0 = 1');
        }
        $campaignId = isset($filters['campaign']) ? (int) $filters['campaign'] : null;
        if ($campaignId) {
            $query->inCampaign($campaignId);
        }
        if (isset($filters['score_offer_id']) && (! is_scalar($filters['score_offer_id']) || ! preg_match('/^[1-9][0-9]*$/D', (string) $filters['score_offer_id']))) {
            return $query->whereRaw('0 = 1');
        }
        $scoreOfferId = isset($filters['score_offer_id']) ? (int) $filters['score_offer_id'] : null;
        $scoreExpression = $this->scoreExpression($campaignId, $scoreOfferId);
        [$scoreSql, $scoreBindings] = $scoreExpression;
        if ($campaignId || $scoreOfferId) {
            $this->projectScoreContext($query, $scoreExpression);
        }

        if ($status = $filters['status'] ?? null) {
            if ($campaignId) {
                $query->where(function ($q) use ($campaignId, $status) {
                    $q->whereHas('campaigns', fn ($c) => $c->where('campaigns.id', $campaignId)->where('campaign_lead.status', $status))
                        ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('campaigns')->where('status', $status));
                });
            } else {
                $query->where('status', $status);
            }
        }
        if ($term = $filters['q'] ?? null) {
            $query->where(function ($q) use ($term) {
                foreach (['name', 'domain', 'email', 'phone', 'contact_person', 'notes'] as $column) {
                    $q->orWhere($column, 'like', '%'.$term.'%');
                }
                $q->orWhereHas('contacts', fn ($contact) => $contact->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')->orWhere('phone_original', 'like', '%'.$term.'%'));
            });
        }
        if ($type = $filters['relationship_type'] ?? null) {
            match ($type) {
                'customer' => $query->where('is_customer', true),
                'collaborator' => $query->where('is_collaborator', true),
                'prospect' => $query->where('is_customer', false)->where('is_collaborator', false),
                default => $query->whereRaw('0 = 1'),
            };
        }
        foreach (['manual_priority', 'entity_type', 'classification_pending'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($tag = $filters['tag'] ?? null) {
            $query->whereJsonContains('tags', $tag);
        }
        if ($this->truthy($filters['due'] ?? null)) {
            $campaignId ? $query->whereHas('campaigns', fn ($q) => $q->where('campaigns.id', $campaignId)->where('campaign_lead.next_action_at', '<=', now())) : $query->where('next_action_at', '<=', now());
        }
        if ($segment = $filters['segment'] ?? null) {
            $query->where('segment', 'like', "%{$segment}%");
        }

        if ($city = $filters['city'] ?? null) {
            $query->where(fn ($q) => $q->where('city', 'like', "%{$city}%")->orWhere('location', 'like', "%{$city}%"));
        }

        if ($country = $filters['country'] ?? null) {
            $query->where('country', $country);
        }

        if (isset($filters['min_score'])) {
            $query->whereRaw('('.$scoreSql.') >= ?', [...$scoreBindings, (int) $filters['min_score']]);
        }

        if (isset($filters['max_score'])) {
            $query->whereRaw('('.$scoreSql.') <= ?', [...$scoreBindings, (int) $filters['max_score']]);
        }

        if ($offerId = $filters['offer_id'] ?? null) {
            $campaignId ? $query->whereHas('campaigns', fn ($q) => $q->where('campaigns.id', $campaignId)->where('campaign_lead.offer_id', $offerId)) : $query->where('suggested_offer_id', $offerId);
        }

        if ($source = $filters['source'] ?? null) {
            $query->where(fn ($q) => $q->where('source', $source)->orWhereHas('sources', fn ($sourceQuery) => $sourceQuery->where('source', $source)));
        }

        if ($channel = $filters['channel'] ?? null) {
            $query->whereHas('contactAttempts', fn (Builder $attempt) => $attempt->where('channel', $channel)->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId)));
        }

        if ($language = $filters['language'] ?? null) {
            $query->where('language', $language);
        }

        $this->whereFilled($query, 'email', $filters['has_email'] ?? null);
        $this->whereFilled($query, 'phone', $filters['has_phone'] ?? null);
        $this->whereFilled($query, 'website_url', $filters['has_web'] ?? null);

        if ($signal = $filters['signal'] ?? null) {
            $query->whereHas('signalRows', fn (Builder $signalQuery) => $signalQuery->where('type', $signal));
        }

        if (($filters['possible_duplicate'] ?? null) !== null) {
            $query->where('duplicate_status', $this->truthy($filters['possible_duplicate']) ? 'possible_duplicate' : 'unique');
        }

        if (($filters['contacted'] ?? null) !== null) {
            $this->whereRelationExists($query, 'contactAttempts', $filters['contacted'], fn (Builder $attempt) => $attempt->whereNotNull('performed_at')->where('dry_run', false)->where('status', 'sent')->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId)));
        }

        if (($filters['replied'] ?? null) !== null) {
            $this->whereRelationExists($query, 'replies', $filters['replied'], fn ($q) => $q->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId)));
        }

        foreach (['interested', 'proposal', 'won', 'lost'] as $flag) {
            if (($filters[$flag] ?? null) !== null && $this->truthy($filters[$flag])) {
                $query->when($campaignId, fn ($q) => $q->whereHas('campaigns', fn ($c) => $c->where('campaigns.id', $campaignId)->whereIn('campaign_lead.status', $this->statusesForFlag($flag))), fn ($q) => $q->whereIn('status', $this->statusesForFlag($flag)));
            }
        }

        if ($from = $filters['acquired_from'] ?? null) {
            $query->whereDate('acquired_at', '>=', $from);
        }

        if ($to = $filters['acquired_to'] ?? null) {
            $query->whereDate('acquired_at', '<=', $to);
        }

        [$column, $direction] = self::SORTS[$filters['sort'] ?? 'score'] ?? self::SORTS['score'];

        if ($column === 'score_total' && ($campaignId || $scoreOfferId)) {
            return $query->orderByRaw('('.$scoreSql.') IS NULL', $scoreBindings)->orderByRaw('('.$scoreSql.') desc', $scoreBindings)->orderByDesc('leads.id');
        }

        return $query->orderBy($column, $direction)->orderByDesc('leads.id');
    }

    /** Add stored scoring context without calculating or updating any score. */
    public function withScoreContext(Builder $query, ?int $campaignId = null, ?int $offerId = null): Builder
    {
        return $this->projectScoreContext($query, $this->scoreExpression($campaignId, $offerId));
    }

    private function projectScoreContext(Builder $query, array $expression): Builder
    {
        [$scoreSql, $bindings, $offerSql, $offerBindings, $legacy] = $expression;
        $query->select('leads.*')->selectRaw('('.$scoreSql.') as resolved_score', $bindings)
            ->selectRaw('('.$offerSql.') as resolved_score_offer_id', $offerBindings);
        $source = 'CASE WHEN ('.$offerSql.') IS NULL THEN '.($legacy ? "'legacy_global'" : "'unknown'").' WHEN ('.$scoreSql.") IS NULL THEN 'unknown' ELSE 'offer' END";

        return $query->selectRaw($source.' as resolved_score_source', [...$offerBindings, ...$bindings]);
    }

    /** @return array{string,array,string,array,bool} */
    private function scoreExpression(?int $campaignId, ?int $offerId): array
    {
        if (! $campaignId && ! $offerId) {
            return ['leads.score_total', [], 'NULL', [], true];
        }
        if ($campaignId) {
            $context = DB::table('campaign_lead')->select('offer_id')->whereColumn('lead_id', 'leads.id')->where('campaign_id', $campaignId)->limit(1);
            $offerSql = $context->toSql();
            $offerBindings = $context->getBindings();
            $legacy = Campaign::whereKey($campaignId)->value('offer_id') === null;
        } else {
            $offerSql = '?';
            $offerBindings = [$offerId];
            $legacy = false;
        }
        $score = LeadOfferScore::query()->current()->select('lead_offer_scores.total')->whereColumn('lead_offer_scores.lead_id', 'leads.id')
            ->whereRaw('lead_offer_scores.offer_id = ('.$offerSql.')', $offerBindings)->limit(1);
        $scoreSql = 'CASE WHEN ('.$offerSql.') IS NULL THEN '.($legacy ? 'leads.score_total' : 'NULL').' ELSE ('.$score->toSql().') END';

        return [$scoreSql, [...$offerBindings, ...$score->getBindings()], $offerSql, $offerBindings, $legacy];
    }

    public function cleanQuery(array $filters): array
    {
        unset($filters['page']);
        if (array_key_exists('ids', $filters) && ($filters['ids'] === null || $filters['ids'] === [])) {
            $filters['ids'] = '';
        }

        return array_filter($filters, fn ($value, $key) => $key === 'ids' || ($value !== null && $value !== ''), ARRAY_FILTER_USE_BOTH);
    }

    private function whereFilled(Builder $query, string $column, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if ($this->truthy($value)) {
            $query->whereNotNull($column)->where($column, '!=', '');

            return;
        }

        $query->where(fn (Builder $nested) => $nested->whereNull($column)->orWhere($column, ''));
    }

    private function whereRelationExists(Builder $query, string $relation, mixed $value, ?callable $callback = null): void
    {
        if ($this->truthy($value)) {
            $query->whereHas($relation, $callback);

            return;
        }

        $query->whereDoesntHave($relation, $callback);
    }

    private function statusesForFlag(string $flag): array
    {
        return match ($flag) {
            'interested' => ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'],
            'proposal' => ['PROPOSAL', 'NEGOTIATION', 'WON'],
            'won' => ['WON'],
            'lost' => ['LOST'],
        };
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'yes', 'on'], true);
    }

    private function ids(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        if (count($items) > 2000 || $items === []) {
            return [];
        }
        foreach ($items as $item) {
            if (is_bool($item) || ! is_scalar($item) || ! preg_match('/^[1-9][0-9]*$/D', (string) $item) || strlen((string) $item) > 18) {
                return [];
            }
        }

        return array_values(array_unique(array_map('intval', $items)));
    }
}
