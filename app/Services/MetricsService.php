<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\LeadStatusHistory;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    public function funnel(array $filters = []): array
    {
        $leadFilters = array_intersect_key($filters, array_flip(['campaign', 'segment', 'city', 'country', 'source', 'language', 'ids']));
        $leadQuery = app(LeadFilterService::class)->query($leadFilters);
        $group = $filters['_group'] ?? null;
        if ($group && in_array($group['dimension'], ['segment', 'city', 'country', 'source', 'language'])) {
            $leadQuery->where($group['dimension'], $group['value']);
        }
        $leads = $leadQuery->get();
        $ids = $leads->modelKeys();
        $campaignId = $filters['campaign'] ?? null;
        $attempts = ContactAttempt::whereIn('lead_id', $ids)->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($filters['offer_id'] ?? null, fn ($q, $offer) => $q->where('offer_id', $offer))
            ->when($filters['channel'] ?? null, fn ($q, $channel) => $q->where('channel', $channel))
            ->when($filters['variant'] ?? null, fn ($q, $variant) => $q->where('variant', $variant))
            ->when($group && in_array($group['dimension'], ['channel', 'variant']), fn ($q) => $q->where($group['dimension'], $group['value']))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('performed_at', '>=', Carbon::parse($date, app(PreferencesService::class)->all()['timezone'])->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('performed_at', '<=', Carbon::parse($date, app(PreferencesService::class)->all()['timezone'])->endOfDay()->utc()))->get();
        $contactIds = $attempts->pluck('lead_id')->unique();
        $pairs = $attempts->map(fn ($a) => $a->lead_id.':'.$a->campaign_id)->unique();
        $inCohort = fn ($row) => $pairs->contains($row->lead_id.':'.$row->campaign_id) || (! $row->campaign_id && ! $campaignId && ! isset($filters['offer_id']) && ! isset($filters['channel']) && ! isset($filters['variant']));
        $replyRows = Reply::whereIn('lead_id', $contactIds)->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when(isset($filters['channel']) || isset($filters['variant']) || isset($filters['offer_id']), fn ($q) => $q->whereIn('contact_attempt_id', $attempts->modelKeys()))->get()->filter($inCohort);
        $replyIds = $replyRows->pluck('lead_id')->unique();
        $positive = $replyRows->whereIn('category', ['interested', 'meeting', 'proposal', 'won', 'information_request', 'price_question'])->pluck('lead_id')->unique();
        $proposalRows = Proposal::whereIn('lead_id', $contactIds)->whereNotNull('sent_at')
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($filters['offer_id'] ?? null, fn ($q, $offer) => $q->where('offer_id', $offer))->get()->filter($inCohort);
        $proposalIds = $proposalRows->pluck('lead_id')->unique();
        $historyIds = LeadStatusHistory::whereIn('lead_id', $contactIds)->whereIn('to_status', ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'])
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))->get()->filter($inCohort)->pluck('lead_id');
        $current = DB::table('campaign_lead')->whereIn('lead_id', $contactIds)
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($filters['offer_id'] ?? null, fn ($q, $offer) => $q->where('offer_id', $offer))->get()->filter($inCohort);
        if (! $campaignId && ! isset($filters['offer_id'])) {
            $memberIds = DB::table('campaign_lead')->whereIn('lead_id', $contactIds)->pluck('lead_id');
            $current = $current->merge($leads->whereIn('id', $contactIds)->whereNotIn('id', $memberIds)->map(fn ($lead) => (object) ['lead_id' => $lead->id, 'status' => $lead->status]));
        }
        $interestIds = $positive->merge($proposalIds)->merge($historyIds)
            ->merge($current->whereIn('status', ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'])->pluck('lead_id'))->unique()->intersect($contactIds);
        $wonIds = $current->where('status', 'WON')->pluck('lead_id')->intersect($contactIds)->unique();
        $lostIds = $current->where('status', 'LOST')->pluck('lead_id')->intersect($contactIds)->unique();
        $counts = [
            'acquired' => $leads->count(), 'qualified' => $leads->where('score_total', '>=', 45)->count(),
            'contacted' => $contactIds->count(), 'attempts' => $attempts->count(), 'responses' => $replyIds->count(),
            'interested' => $interestIds->count(), 'proposals' => $proposalIds->count(), 'proposal_versions' => $proposalRows->count(),
            'won' => $wonIds->count(), 'lost' => $lostIds->count(), 'positive_replies' => $positive->count(),
        ];
        $base = $counts['contacted'];

        return ['counts' => $counts, 'rates' => [
            'reply_rate' => $this->rate($counts['responses'], $base), 'interest_rate' => $this->rate($counts['interested'], $base),
            'proposal_rate' => $this->rate($counts['proposals'], $base), 'close_rate' => $this->rate($counts['won'], $base),
        ], 'sample_size' => $base, 'baseline' => []];
    }

    public function segmented(string $dimension = 'campaign', array $filters = []): array
    {
        if ($dimension === 'campaign') {
            $groups = Campaign::when($filters['campaign'] ?? null, fn ($q, $id) => $q->whereKey($id))->get()->map(fn ($c) => [$c->name, 'campaign', $c->id]);
        } elseif ($dimension === 'offer') {
            $groups = Offer::get()->map(fn ($o) => [$o->name, 'offer_id', $o->id]);
        } elseif (in_array($dimension, ['channel', 'variant'])) {
            $groups = ContactAttempt::where('dry_run', false)->where('status', 'sent')->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->distinct()->pluck($dimension)->map(fn ($v) => [$v ?: 'Sin dato', $dimension, $v]);
        } else {
            $dimension = in_array($dimension, ['segment', 'city', 'country', 'source', 'language']) ? $dimension : 'segment';
            $groups = app(LeadFilterService::class)->query(array_intersect_key($filters, array_flip(['campaign', 'ids'])))->get()->pluck($dimension)->unique()->map(fn ($v) => [$v ?: 'Sin dato', $dimension, $v]);
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

    private function rate(int $value, int $base): float
    {
        return $base === 0 ? 0.0 : round($value * 100 / $base, 2);
    }
}
