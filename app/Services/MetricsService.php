<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\ExperimentVariant;
use App\Models\LeadStatusHistory;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    public function funnel(array $filters = []): array
    {
        $leadFilters = array_diff_key($filters, array_flip(['from', 'to', 'channel', 'variant', 'offer_id', '_group']));
        $leadQuery = app(LeadFilterService::class)->query($leadFilters)
            ->where(fn ($query) => $query->where('source', '!=', 'notion')->orWhereHas('campaigns'));
        $group = $filters['_group'] ?? null;
        if ($group && in_array($group['dimension'], ['segment', 'city', 'country', 'source', 'language'])) {
            $leadQuery->where($group['dimension'], $group['value']);
        }
        $leads = $leadQuery->get(['leads.id', 'leads.score_total', 'leads.status']);
        $ids = $leads->modelKeys();
        $campaignId = $filters['campaign'] ?? null;
        $attempts = ContactAttempt::whereIn('lead_id', $ids)->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($filters['offer_id'] ?? null, fn ($q, $offer) => $q->where('offer_id', $offer))
            ->when($filters['channel'] ?? null, fn ($q, $channel) => $q->where('channel', $channel))
            ->when($filters['variant'] ?? null, fn ($q, $variant) => $q->where('variant', $variant))
            ->when($group && in_array($group['dimension'], ['channel', 'variant', 'experiment_variant_id']), fn ($q) => $q->where($group['dimension'], $group['value'])->when($group['dimension'] === 'variant', fn ($q) => $q->whereNull('experiment_variant_id')))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('performed_at', '>=', Carbon::parse($date, app(PreferencesService::class)->all()['timezone'])->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('performed_at', '<=', Carbon::parse($date, app(PreferencesService::class)->all()['timezone'])->endOfDay()->utc()))->get();
        $outcomeAttempts = ContactAttempt::whereIn('lead_id', $ids)->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')->orderByDesc('performed_at')->orderByDesc('id')->get();
        $contactIds = $attempts->pluck('lead_id')->unique();
        $pairs = $attempts->map(fn ($a) => $a->lead_id.':'.$a->campaign_id)->unique();
        $segmentedContact = isset($filters['channel']) || isset($filters['variant']) || ($group && in_array($group['dimension'], ['channel', 'variant', 'experiment_variant_id']));
        $inCohort = fn ($row) => $pairs->contains($row->lead_id.':'.$row->campaign_id) || (! $row->campaign_id && ! $campaignId && ! isset($filters['offer_id']) && ! isset($filters['channel']) && ! isset($filters['variant']));
        $replyRows = Reply::whereIn('lead_id', $contactIds)->where(fn ($q) => $q->whereNull('contact_attempt_id')->orWhereIn('contact_attempt_id', $outcomeAttempts->modelKeys()))->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($segmentedContact || isset($filters['offer_id']), fn ($q) => $q->whereIn('contact_attempt_id', $attempts->modelKeys()))->get()->filter($inCohort);
        $replyIds = $replyRows->pluck('lead_id')->unique();
        $positive = $replyRows->whereIn('category', ['interested', 'meeting', 'proposal', 'won', 'information_request', 'price_question'])->pluck('lead_id')->unique();
        $proposalRows = Proposal::whereIn('lead_id', $contactIds)->whereNotNull('sent_at')
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($filters['offer_id'] ?? null, fn ($q, $offer) => $q->where('offer_id', $offer))->get()->filter($inCohort)->filter(fn ($row) => ! $segmentedContact || $this->ownsOutcome($row, $attempts, $outcomeAttempts, $row->sent_at));
        $proposalIds = $proposalRows->pluck('lead_id')->unique();
        $historyIds = LeadStatusHistory::whereIn('lead_id', $contactIds)->whereIn('to_status', ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'])
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))->get()->filter($inCohort)->filter(fn ($row) => ! $segmentedContact || $this->ownsOutcome($row, $attempts, $outcomeAttempts, $row->changed_at ?? $row->created_at))->pluck('lead_id');
        $current = DB::table('campaign_lead')->whereIn('lead_id', $contactIds)
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($filters['offer_id'] ?? null, fn ($q, $offer) => $q->where('offer_id', $offer))->get()->filter($inCohort)->filter(fn ($row) => ! $segmentedContact || $this->ownsOutcome($row, $attempts, $outcomeAttempts, ($row->status === 'WON' ? $row->won_at : ($row->status === 'LOST' ? $row->lost_at : $row->interested_at)) ?? $row->updated_at));
        if (! $campaignId && ! isset($filters['offer_id']) && ! $segmentedContact) {
            $memberIds = DB::table('campaign_lead')->whereIn('lead_id', $contactIds)->pluck('lead_id');
            $current = $current->merge($leads->whereIn('id', $contactIds)->whereNotIn('id', $memberIds)->map(fn ($lead) => (object) ['lead_id' => $lead->id, 'status' => $lead->status]));
        }
        $interestIds = $positive->merge($proposalIds)->merge($historyIds)
            ->merge($current->whereIn('status', ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'])->pluck('lead_id'))->unique()->intersect($contactIds);
        $wonIds = $current->where('status', 'WON')->pluck('lead_id')->intersect($contactIds)->unique();
        $lostIds = $current->where('status', 'LOST')->pluck('lead_id')->intersect($contactIds)->unique();
        $counts = [
            'acquired' => $leads->count(), 'qualified' => ($campaignId || isset($filters['score_offer_id'])) ? $leads->where('resolved_score', '>=', 45)->count() : $leads->where('score_total', '>=', 45)->count(),
            'contacted' => $contactIds->count(), 'attempts' => $attempts->count(), 'responses' => $replyIds->count(),
            'interested' => $interestIds->count(), 'proposals' => $proposalIds->count(), 'proposal_versions' => $proposalRows->count(),
            'won' => $wonIds->count(), 'lost' => $lostIds->count(), 'positive_replies' => $positive->count(),
        ];
        $base = $counts['contacted'];

        return ['counts' => $counts, 'rates' => [
            'reply_rate' => $this->rate($counts['responses'], $base), 'interest_rate' => $this->rate($counts['interested'], $base),
            'proposal_rate' => $this->rate($counts['proposals'], $base), 'close_rate' => $this->rate($counts['won'], $base),
        ], 'sample_size' => $base, 'baseline' => [], 'attribution' => 'Las respuestas usan su intento vinculado. Los avances usan el último contacto real anterior en la misma oportunidad. La atribución describe asociación, no causalidad.'];
    }

    public function segmented(string $dimension = 'campaign', array $filters = []): array
    {
        if ($dimension === 'campaign') {
            $groups = Campaign::when($filters['campaign'] ?? null, fn ($q, $id) => $q->whereKey($id))->get()->map(fn ($c) => [$c->name, 'campaign', $c->id]);
        } elseif ($dimension === 'offer') {
            $groups = Offer::get()->map(fn ($o) => [$o->name, 'offer_id', $o->id]);
        } elseif (in_array($dimension, ['channel', 'variant', 'experiment_variant_id'])) {
            $attemptGroups = ContactAttempt::where('dry_run', false)->where('status', 'sent')->whereNotNull('performed_at')
                ->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id));
            if ($dimension === 'variant') {
                $groups = $attemptGroups->select('experiment_variant_id', 'variant')->distinct()->get()->map(function ($row) {
                    if ($row->experiment_variant_id) {
                        $variant = ExperimentVariant::with('experiment')->find($row->experiment_variant_id);

                        return [($variant?->experiment?->name ?? 'Experimento').' · '.($variant?->name ?? $row->variant), 'experiment_variant_id', $row->experiment_variant_id];
                    }

                    return [$row->variant ?: 'Sin dato', 'variant', $row->variant];
                });
            } else {
                $groups = $attemptGroups->distinct()->pluck($dimension)->map(fn ($v) => [$v ?: 'Sin dato', $dimension, $v]);
            }
        } else {
            $dimension = in_array($dimension, ['segment', 'city', 'country', 'source', 'language']) ? $dimension : 'segment';
            $groups = app(LeadFilterService::class)->query(array_intersect_key($filters, array_flip(['campaign', 'ids'])))->reorder()->select($dimension)->distinct()->pluck($dimension)->map(fn ($v) => [$v ?: 'Sin dato', $dimension, $v]);
        }

        return $groups->map(function ($group) use ($filters) {
            $groupFilters = in_array($group[1], ['campaign', 'offer_id']) ? [$group[1] => $group[2]] : ['_group' => ['dimension' => $group[1], 'value' => $group[2]]];
            $metrics = $this->funnel(array_merge($filters, $groupFilters));
            $c = $metrics['counts'];

            return ['label' => $group[0], 'contacts' => $c['contacted'], 'attempts' => $c['attempts'], 'replies' => $c['responses'],
                'positive_replies' => $c['positive_replies'], 'interested' => $c['interested'], 'proposals' => $c['proposals'], 'won' => $c['won'],
                'sample_size' => $c['contacted'], 'positive_reply_rate' => $this->rate($c['positive_replies'], $c['contacted']), ...$metrics['rates']];
        })->all();
    }

    public function campaignStats(Campaign $campaign): array
    {
        $leads = app(CampaignMembership::class)->query($campaign);
        $funnel = $this->funnel(['campaign' => $campaign->id])['counts'];

        return ['leads' => $funnel['acquired'], 'qualified' => $funnel['qualified'], 'contacts' => $funnel['contacted'], 'replies' => $funnel['responses'],
            'interested' => $funnel['interested'], 'proposals' => $funnel['proposals'], 'won' => $funnel['won'], 'lost' => $funnel['lost'],
            'queue' => app(CampaignMembership::class)->query($campaign, ['QUEUED'])->count(),
            'possible_duplicates' => (clone $leads)->where('duplicate_status', 'possible_duplicate')->count()];
    }

    private function ownsOutcome(object $row, Collection $attempts, Collection $outcomeAttempts, mixed $at): bool
    {
        if (! $at) {
            return false;
        }
        $at = Carbon::parse($at);
        $last = $outcomeAttempts->first(fn ($attempt) => $attempt->lead_id === (int) $row->lead_id
            && $attempt->campaign_id == $row->campaign_id && $attempt->performed_at->lte($at));

        return $last && $attempts->contains('id', $last->id);
    }

    public function currentScoreEvaluation(array $filters = []): array
    {
        $ranges = [[0, 24], [25, 44], [45, 64], [65, 100]];
        $rows = [];
        foreach ($ranges as [$min, $max]) {
            $result = $this->funnel(array_merge($filters, ['min_score' => max($min, (int) ($filters['min_score'] ?? 0)), 'max_score' => min($max, (int) ($filters['max_score'] ?? 100))]));
            $rows[] = ['range' => "{$min}–{$max}", 'sample_size' => $result['sample_size'], 'counts' => $result['counts'], 'rates' => $result['rates']];
        }
        $evaluationLeadFilters = array_diff_key($filters, array_flip(['from', 'to', 'channel', 'variant', 'offer_id']));
        $ids = app(LeadFilterService::class)->query($evaluationLeadFilters)->pluck('leads.id');
        $times = $this->period(ContactAttempt::whereIn('lead_id', $ids), $filters)->where('dry_run', false)->where('status', 'sent')->whereNotNull('performed_at')->whereNotNull('preparation_seconds')
            ->when($filters['channel'] ?? null, fn ($q, $value) => $q->where('channel', $value))
            ->when($filters['variant'] ?? null, fn ($q, $value) => $q->where('variant', $value))
            ->when($filters['offer_id'] ?? null, fn ($q, $value) => $q->where('offer_id', $value))
            ->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id));

        $recommendations = [];
        foreach (Offer::orderBy('name')->get() as $offer) {
            $recommendedIds = app(LeadFilterService::class)->query($evaluationLeadFilters)->where('suggested_offer_id', $offer->id)->pluck('leads.id')->all();
            $result = $this->funnel(array_merge($filters, ['ids' => $recommendedIds]));
            $actual = $this->period(ContactAttempt::whereIn('lead_id', $recommendedIds), $filters)->where('offer_id', $offer->id)->when($filters['channel'] ?? null, fn ($q, $value) => $q->where('channel', $value))->when($filters['variant'] ?? null, fn ($q, $value) => $q->where('variant', $value))->when($filters['offer_id'] ?? null, fn ($q, $value) => $q->where('offer_id', $value))->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')
                ->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->distinct()->count('lead_id');
            $recommendations[] = ['offer_id' => $offer->id, 'recommendation' => $offer->name, 'recommended_leads' => count($recommendedIds), 'sample_size' => $result['sample_size'], 'used_recommended_offer' => $actual, 'rates' => $result['rates']];
        }

        return ['scores' => $rows, 'recommendations' => $recommendations, 'offers' => $this->segmented('offer', $filters), 'preparation' => ['sample_size' => (clone $times)->count(), 'average_seconds' => $times->avg('preparation_seconds')],
            'warning' => 'Los rangos usan el score actual y las ofertas efectivamente registradas. Con menos de 30 contactos por grupo la muestra es exploratoria. No demuestra causalidad ni justifica cambiar pesos.'];
    }

    /** Historical cohorts use only scores recorded on real contact events. */
    public function scoreEvaluation(array $filters = []): array
    {
        $selection = array_diff_key($filters, array_flip(['campaign', 'from', 'to', 'min_score', 'max_score', 'score_offer_id', 'offer_id', 'rule_version', 'variant', 'channel', 'status', 'due', 'contacted', 'replied', 'interested', 'proposal', 'won', 'lost']));
        $ids = app(LeadFilterService::class)->query($selection)->pluck('leads.id');
        $attempts = ContactAttempt::whereIn('lead_id', $ids)->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')->where('performed_at', '<=', now())
            ->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->orderBy('performed_at')->orderBy('id')->get();
        $mergeTargets = DB::table('lead_merges')->whereIn('target_lead_id', $ids)->pluck('target_lead_id', 'source_lead_id')->all();
        $cohorts = [];
        $attemptKeys = [];
        foreach ($attempts as $attempt) {
            $snapshot = is_array($attempt->score_snapshot) ? $attempt->score_snapshot : null;
            $known = $this->validSnapshot($attempt, $snapshot, $mergeTargets);
            $version = $known ? $snapshot['rule_version'] : 'unknown';
            $key = implode(':', [$attempt->lead_id, $attempt->campaign_id ?? 'none', $attempt->offer_id ?? 'none', $version]);
            $attemptKeys[$attempt->id] = $key;
            if (! isset($cohorts[$key])) {
                $cohorts[$key] = ['key' => $key, 'lead_id' => $attempt->lead_id, 'campaign_id' => $attempt->campaign_id, 'offer_id' => $attempt->offer_id,
                    'rule_version' => $version, 'score_known' => $known, 'score_total' => $known ? (int) $snapshot['total'] : null,
                    'first_contact_id' => $attempt->id, 'first_contact_at' => $attempt->performed_at->toISOString(),
                    'channel' => $attempt->channel, 'variant' => $attempt->variant, 'preparation_seconds' => $attempt->preparation_seconds,
                    'attempts' => 0, 'responses' => false, 'positive_replies' => false, 'interested' => false, 'proposals' => false, 'proposal_versions' => 0, 'won' => false, 'lost' => false];
            }
            $cohorts[$key]['attempts']++;
        }
        $eligible = [];
        $excludedUnknown = 0;
        $timezone = app(PreferencesService::class)->all()['timezone'];
        foreach ($cohorts as $key => $cohort) {
            $at = Carbon::parse($cohort['first_contact_at']);
            if (($filters['from'] ?? null) && $at->lt(Carbon::parse($filters['from'], $timezone)->startOfDay()->utc())) {
                continue;
            }
            if (($filters['to'] ?? null) && $at->gt(Carbon::parse($filters['to'], $timezone)->endOfDay()->utc())) {
                continue;
            }
            if (($filters['offer_id'] ?? null) && (int) $cohort['offer_id'] !== (int) $filters['offer_id']) {
                continue;
            }
            if (($filters['channel'] ?? null) && $cohort['channel'] !== $filters['channel']) {
                continue;
            }
            if (($filters['variant'] ?? null) && $cohort['variant'] !== $filters['variant']) {
                continue;
            }
            if (($filters['rule_version'] ?? null) && $cohort['rule_version'] !== $filters['rule_version']) {
                $excludedUnknown += ! $cohort['score_known'] ? 1 : 0;

                continue;
            }
            if ($cohort['score_known'] && ((isset($filters['min_score']) && $cohort['score_total'] < (int) $filters['min_score'])
                || (isset($filters['max_score']) && $cohort['score_total'] > (int) $filters['max_score']))) {
                continue;
            }
            $eligible[$key] = $cohort;
        }
        $latest = $attempts->sortByDesc('id')->sortByDesc('performed_at');
        $owner = function (object $event, mixed $at, ?int $linkedId = null, bool $matchOffer = false) use ($latest, $attempts, $attemptKeys, &$eligible): ?string {
            if (! $at) {
                return null;
            }
            $at = Carbon::parse($at);
            $prior = $latest->filter(fn ($row) => $row->lead_id === (int) $event->lead_id && $row->campaign_id == $event->campaign_id && $row->performed_at->lte($at));
            if (! $linkedId && ! $matchOffer && $prior->pluck('offer_id')->unique()->count() > 1) {
                return null;
            }
            $attempt = $linkedId ? $attempts->firstWhere('id', $linkedId) : $prior->first(fn ($row) => ! $matchOffer || $row->offer_id == $event->offer_id);
            if (! $attempt || $attempt->lead_id !== (int) $event->lead_id || $attempt->campaign_id != $event->campaign_id || $attempt->performed_at->gt($at)) {
                return null;
            }
            $key = $attemptKeys[$attempt->id] ?? null;

            return $key && isset($eligible[$key]) && Carbon::parse($eligible[$key]['first_contact_at'])->lte($at) ? $key : null;
        };
        foreach (Reply::whereIn('lead_id', $ids)->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->get() as $reply) {
            if ($key = $owner($reply, $reply->received_at, $reply->contact_attempt_id)) {
                $eligible[$key]['responses'] = true;
                if (in_array($reply->category, ['interested', 'meeting', 'proposal', 'won', 'information_request', 'price_question'], true)) {
                    $eligible[$key]['positive_replies'] = true;
                    $eligible[$key]['interested'] = true;
                }
            }
        }
        foreach (Proposal::whereIn('lead_id', $ids)->whereNotNull('sent_at')->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->get() as $proposal) {
            if ($key = $owner($proposal, $proposal->sent_at, matchOffer: true)) {
                $eligible[$key]['proposals'] = true;
                $eligible[$key]['interested'] = true;
                $eligible[$key]['proposal_versions']++;
            }
        }
        foreach (LeadStatusHistory::whereIn('lead_id', $ids)->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->get() as $history) {
            if ($key = $owner($history, $history->changed_at)) {
                if (in_array($history->to_status, ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'], true)) {
                    $eligible[$key]['interested'] = true;
                }
                if ($history->to_status === 'WON') {
                    $eligible[$key]['won'] = true;
                }
                if ($history->to_status === 'LOST') {
                    $eligible[$key]['lost'] = true;
                }
            }
        }
        $rows = collect(array_values($eligible));
        $versions = $rows->groupBy('rule_version')->map(fn ($group, $version) => ['rule_version' => $version, ...$this->cohortSummary($group), 'scores' => $this->scoreBuckets($group)])->values()->all();
        $comparisons = $rows->groupBy(fn ($row) => implode(':', [$row['campaign_id'] ?? 'none', $row['offer_id'] ?? 'none', $row['rule_version']]))
            ->map(fn ($group) => ['campaign_id' => $group->first()['campaign_id'], 'offer_id' => $group->first()['offer_id'], 'rule_version' => $group->first()['rule_version'],
                ...$this->cohortSummary($group), 'scores' => $this->scoreBuckets($group)])->values()->all();
        $times = $rows->pluck('preparation_seconds')->filter(fn ($value) => $value !== null);

        return ['summary' => $this->cohortSummary($rows), 'scores' => $this->scoreBuckets($rows), 'versions' => $versions, 'comparisons' => $comparisons, 'cohorts' => $rows->all(),
            'offers' => $rows->groupBy('offer_id')->map(fn ($group, $id) => ['offer_id' => $id ?: null, ...$this->cohortSummary($group)])->values()->all(),
            'recommendations' => [], 'preparation' => ['sample_size' => $times->count(), 'average_seconds' => $times->isEmpty() ? null : $times->avg()],
            'known_sample_size' => $rows->where('score_known', true)->count(), 'unknown_sample_size' => $rows->where('score_known', false)->count(), 'excluded_unknown_sample_size' => $excludedUnknown,
            'warning' => 'El score histórico procede solo del snapshot del primer contacto real por prospecto, campaña, oferta y versión. Los contactos sin snapshot válido son desconocidos. Resultados posteriores se atribuyen en su contexto; eventos sin oferta o vínculo suficientes no se atribuyen; versiones con los mismos prospectos no son muestras independientes. Menos de 30 cohortes por grupo es exploratorio y no demuestra causalidad.'];
    }

    public function validSnapshot(ContactAttempt $attempt, ?array $snapshot, ?array $mergeTargets = null): bool
    {
        if (! $snapshot || ($snapshot['known'] ?? false) !== true || ! is_int($snapshot['total'] ?? null) || $snapshot['total'] < 0 || $snapshot['total'] > 100
            || ! filled($snapshot['rule_version'] ?? null)
            || ($snapshot['campaign_id'] ?? null) != $attempt->campaign_id || ($snapshot['offer_id'] ?? null) != $attempt->offer_id
            || ! filled($snapshot['calculated_at'] ?? null)) {
            return false;
        }
        if (($snapshot['lead_id'] ?? null) != $attempt->lead_id) {
            $sourceId = $snapshot['lead_id'] ?? null;
            $merged = $mergeTargets !== null ? (isset($mergeTargets[$sourceId]) && (int) $mergeTargets[$sourceId] === $attempt->lead_id)
                : DB::table('lead_merges')->where('source_lead_id', $sourceId)->where('target_lead_id', $attempt->lead_id)->exists();
            if (! $merged) {
                return false;
            }
        }
        try {
            return Carbon::parse($snapshot['calculated_at'])->lte($attempt->performed_at);
        } catch (\Throwable) {
            return false;
        }
    }

    private function scoreBuckets(Collection $cohorts): array
    {
        $rows = [];
        foreach ([[0, 24], [25, 44], [45, 64], [65, 100]] as [$min, $max]) {
            $selected = $cohorts->where('score_known', true)->filter(fn ($row) => $row['score_total'] >= $min && $row['score_total'] <= $max);
            $rows[] = ['range' => "{$min}–{$max}", ...$this->cohortSummary($selected)];
        }
        $rows[] = ['range' => 'Desconocido', ...$this->cohortSummary($cohorts->where('score_known', false))];

        return $rows;
    }

    private function cohortSummary(Collection $cohorts): array
    {
        $counts = ['acquired' => $cohorts->count(), 'qualified' => $cohorts->where('score_known', true)->where('score_total', '>=', 45)->count(),
            'contacted' => $cohorts->count(), 'attempts' => $cohorts->sum('attempts'), 'responses' => $cohorts->where('responses', true)->count(),
            'positive_replies' => $cohorts->where('positive_replies', true)->count(), 'interested' => $cohorts->where('interested', true)->count(),
            'proposals' => $cohorts->where('proposals', true)->count(), 'proposal_versions' => $cohorts->sum('proposal_versions'), 'won' => $cohorts->where('won', true)->count(), 'lost' => $cohorts->where('lost', true)->count()];
        $base = $counts['contacted'];

        return ['sample_size' => $base, 'known_sample_size' => $cohorts->where('score_known', true)->count(), 'unknown_sample_size' => $cohorts->where('score_known', false)->count(), 'counts' => $counts,
            'rates' => ['reply_rate' => $this->rate($counts['responses'], $base), 'interest_rate' => $this->rate($counts['interested'], $base),
                'proposal_rate' => $this->rate($counts['proposals'], $base), 'close_rate' => $this->rate($counts['won'], $base)]];
    }

    private function period(Builder $query, array $filters): Builder
    {
        if (empty($filters['from']) && empty($filters['to'])) {
            return $query;
        }
        $timezone = app(PreferencesService::class)->all()['timezone'];

        return $query->when($filters['from'] ?? null, fn ($q, $date) => $q->where('performed_at', '>=', Carbon::parse($date, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('performed_at', '<=', Carbon::parse($date, $timezone)->endOfDay()->utc()));
    }

    private function rate(int $value, int $base): float
    {
        return $base === 0 ? 0.0 : round($value * 100 / $base, 2);
    }
}
