<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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
            'campaign',
            'status',
            'segment',
            'city',
            'country',
            'min_score',
            'max_score',
            'offer_id',
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

        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }

    public function query(array $filters = []): Builder
    {
        $query = Lead::query()
            ->with([
                'suggestedOffer',
                'signalRows',
                'contactAttempts',
                'replies',
                'proposals',
                'possibleDuplicateOf',
                'sources.importRun',
            ]);

        if ($ids = $this->ids($filters['ids'] ?? null)) {
            $query->whereIn('id', $ids);
        }

        if ($campaignId = $filters['campaign'] ?? null) {
            $query->where(function (Builder $nested) use ($campaignId) {
                $nested->whereHas('sources.importRun', fn (Builder $source) => $source->where('campaign_id', $campaignId))
                    ->orWhereHas('contactAttempts', fn (Builder $attempt) => $attempt->where('campaign_id', $campaignId));
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($segment = $filters['segment'] ?? null) {
            $query->where('segment', 'like', "%{$segment}%");
        }

        if ($city = $filters['city'] ?? null) {
            $query->where('location', 'like', "%{$city}%");
        }

        if ($country = $filters['country'] ?? null) {
            $query->where('country', $country);
        }

        if (isset($filters['min_score'])) {
            $query->where('score_total', '>=', (int) $filters['min_score']);
        }

        if (isset($filters['max_score'])) {
            $query->where('score_total', '<=', (int) $filters['max_score']);
        }

        if ($offerId = $filters['offer_id'] ?? null) {
            $query->where('suggested_offer_id', $offerId);
        }

        if ($source = $filters['source'] ?? null) {
            $query->where('source', $source);
        }

        if ($channel = $filters['channel'] ?? null) {
            $query->whereHas('contactAttempts', fn (Builder $attempt) => $attempt->where('channel', $channel));
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
            $this->whereRelationExists($query, 'contactAttempts', $filters['contacted'], fn (Builder $attempt) => $attempt->whereNotNull('performed_at'));
        }

        if (($filters['replied'] ?? null) !== null) {
            $this->whereRelationExists($query, 'replies', $filters['replied']);
        }

        foreach (['interested', 'proposal', 'won', 'lost'] as $flag) {
            if (($filters[$flag] ?? null) !== null && $this->truthy($filters[$flag])) {
                $query->whereIn('status', $this->statusesForFlag($flag));
            }
        }

        if ($from = $filters['acquired_from'] ?? null) {
            $query->whereDate('acquired_at', '>=', $from);
        }

        if ($to = $filters['acquired_to'] ?? null) {
            $query->whereDate('acquired_at', '<=', $to);
        }

        [$column, $direction] = self::SORTS[$filters['sort'] ?? 'score'] ?? self::SORTS['score'];

        return $query->orderBy($column, $direction)->orderByDesc('id');
    }

    public function cleanQuery(array $filters): array
    {
        unset($filters['page']);

        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
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
        if (! $value) {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('intval', $items)));
    }
}
