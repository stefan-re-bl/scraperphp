<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadOfferScore;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignMembership
{
    public function attach(Lead $lead, Campaign $campaign, bool $explicitContact = false): void
    {
        DB::table('campaign_lead')->insertOrIgnore([
            'campaign_id' => $campaign->id, 'lead_id' => $lead->id,
            'offer_id' => $campaign->offer_id ?? $lead->suggested_offer_id,
            'status' => $lead->campaigns()->exists() || $lead->is_customer ? 'ENRICHED' : $lead->status,
            'contact_enabled' => $explicitContact,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($explicitContact) {
            $lead->campaigns()->updateExistingPivot($campaign->id, ['contact_enabled' => true]);
        }
    }

    public function query(Campaign $campaign, array $statuses = []): Builder
    {
        $query = Lead::query()->inCampaign($campaign->id);
        if ($statuses !== []) {
            $query->where(function ($nested) use ($campaign, $statuses) {
                $nested->whereHas('campaigns', fn ($members) => $members->where('campaigns.id', $campaign->id)->whereIn('campaign_lead.status', $statuses))
                    ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('campaigns')->whereIn('status', $statuses));
            });
        }

        return $query;
    }

    public function resolve(Lead $lead, ?int $campaignId): ?Campaign
    {
        if ($campaignId) {
            $campaign = Campaign::findOrFail($campaignId);
            if (! $this->query($campaign)->whereKey($lead->id)->exists()) {
                throw ValidationException::withMessages(['campaign_id' => 'El prospecto no pertenece a esta campaña.']);
            }
            $this->attach($lead, $campaign);

            return $campaign;
        }
        if (! $lead->campaigns()->exists()) {
            $ids = DB::table('lead_sources')->join('import_runs', 'import_runs.id', '=', 'lead_sources.import_run_id')->where('lead_sources.lead_id', $lead->id)->pluck('import_runs.campaign_id')
                ->merge($lead->contactAttempts()->pluck('campaign_id'))->filter()->unique();
            foreach (Campaign::whereIn('id', $ids)->get() as $knownCampaign) {
                $this->attach($lead, $knownCampaign);
            }
        }
        $campaigns = $lead->campaigns()->get();
        if ($campaigns->count() > 1) {
            throw ValidationException::withMessages(['campaign_id' => 'Seleccioná la campaña para atribuir esta acción.']);
        }

        return $campaigns->first();
    }

    public function ready(?Campaign $campaign = null): Builder
    {
        $eligibleCampaign = function ($q) {
            $q->where(fn ($permission) => $permission->where('leads.is_customer', false)->orWhere('campaign_lead.contact_enabled', true))->where('campaigns.kill_switch', false)->where('campaigns.status', 'active')
                ->whereIn('campaign_lead.status', ['QUEUED', 'CONTACTED'])
                ->where(fn ($due) => $due->whereNull('campaign_lead.next_action_at')->orWhere('campaign_lead.next_action_at', '<=', now()))
                ->where(function ($state) {
                    $state->where('campaign_lead.status', 'QUEUED')->orWhereExists(function ($attempt) {
                        $attempt->selectRaw('1')->from('contact_attempts')->whereColumn('contact_attempts.lead_id', 'campaign_lead.lead_id')
                            ->whereColumn('contact_attempts.campaign_id', 'campaign_lead.campaign_id')->where('contact_attempts.status', 'planned')
                            ->where(fn ($due) => $due->whereNull('due_at')->orWhere('due_at', '<=', now()));
                    });
                });
        };
        $query = Lead::whereNull('excluded_at')->where('duplicate_status', '!=', 'possible_duplicate')->where('classification_pending', false);
        if ($campaign) {
            $query->whereHas('campaigns', function ($q) use ($campaign, $eligibleCampaign) {
                $q->where('campaigns.id', $campaign->id);
                $eligibleCampaign($q);
            });
        } else {
            $query->where(function ($q) use ($eligibleCampaign) {
                $q->whereHas('campaigns', $eligibleCampaign)->orWhere(fn ($standalone) => $standalone->where('is_customer', false)->whereDoesntHave('campaigns')->where('status', 'QUEUED')
                    ->where(fn ($due) => $due->whereNull('next_action_at')->orWhere('next_action_at', '<=', now())));
            });
        }

        if ($campaign) {
            return app(LeadFilterService::class)->withScoreContext($query, $campaign->id)->selectRaw('? as queue_campaign_id', [$campaign->id]);
        }
        $context = Campaign::query()->select('campaigns.id')->join('campaign_lead', 'campaign_lead.campaign_id', '=', 'campaigns.id')
            ->whereColumn('campaign_lead.lead_id', 'leads.id');
        $eligibleCampaign($context);
        $contextScore = LeadOfferScore::query()->current()->select('lead_offer_scores.total')->whereColumn('lead_offer_scores.lead_id', 'campaign_lead.lead_id')
            ->whereColumn('lead_offer_scores.offer_id', 'campaign_lead.offer_id')->limit(1);
        $context->orderByRaw('campaign_lead.next_action_at IS NULL')->orderBy('campaign_lead.next_action_at')
            ->orderByRaw('('.$contextScore->toSql().') IS NULL', $contextScore->getBindings())
            ->orderByRaw('('.$contextScore->toSql().') desc', $contextScore->getBindings())->orderBy('campaigns.id')->limit(1);
        $offerContext = (clone $context)->select('campaign_lead.offer_id');
        $offerSql = $offerContext->toSql();
        $offerBindings = $offerContext->getBindings();
        $score = LeadOfferScore::query()->current()->select('lead_offer_scores.total')->whereColumn('lead_offer_scores.lead_id', 'leads.id')
            ->whereRaw('lead_offer_scores.offer_id = ('.$offerSql.')', $offerBindings)->limit(1);
        $scoreSql = 'CASE WHEN ('.$offerSql.') IS NULL THEN leads.score_total ELSE ('.$score->toSql().') END';
        $scoreBindings = [...$offerBindings, ...$score->getBindings()];

        return $query->select('leads.*')->selectSub($context, 'queue_campaign_id')->selectRaw('('.$offerSql.') as resolved_score_offer_id', $offerBindings)
            ->selectRaw('('.$scoreSql.') as resolved_score', $scoreBindings)
            ->selectRaw('CASE WHEN ('.$offerSql.") IS NULL THEN 'legacy_global' WHEN (".$scoreSql.") IS NULL THEN 'unknown' ELSE 'offer' END as resolved_score_source", [...$offerBindings, ...$scoreBindings]);
    }

    public function assertContactEnabled(Lead $lead, ?Campaign $campaign): void
    {
        if ($lead->is_customer && (! $campaign || ! DB::table('campaign_lead')->where('lead_id', $lead->id)->where('campaign_id', $campaign->id)->where('contact_enabled', true)->exists())) {
            throw ValidationException::withMessages(['lead_id' => 'El cliente requiere una oportunidad habilitada expresamente para contactar.']);
        }
    }

    public function offer(Lead $lead, ?Campaign $campaign): ?Offer
    {
        $offerId = $campaign ? DB::table('campaign_lead')->where('lead_id', $lead->id)->where('campaign_id', $campaign->id)->value('offer_id') : null;

        return Offer::find($offerId ?? $campaign?->offer_id ?? $lead->suggested_offer_id);
    }

    /** @return array<int|string, int|null> */
    public function offerContext(Lead $lead): array
    {
        $contexts = $lead->campaigns->mapWithKeys(fn (Campaign $campaign): array => [$campaign->id => $campaign->pivot->offer_id ?? $campaign->offer_id ?? $lead->suggested_offer_id])->all();
        $contexts['default'] = $lead->campaigns->count() === 1 ? array_values($contexts)[0] : $lead->suggested_offer_id;

        return $contexts;
    }

    public function nextAction(Lead $lead, ?Campaign $campaign, ?string $dueAt): void
    {
        if ($campaign) {
            $lead->campaigns()->updateExistingPivot($campaign->id, ['next_action_at' => $dueAt]);
            $dueAt = DB::table('campaign_lead')->join('campaigns', 'campaigns.id', '=', 'campaign_lead.campaign_id')
                ->where('lead_id', $lead->id)->whereNotIn('campaign_lead.status', ['PAUSED', 'WON', 'LOST'])
                ->where('campaigns.status', 'active')->where('campaigns.kill_switch', false)->min('next_action_at');
        }
        $lead->update(['next_action_at' => $dueAt]);
    }

    public function status(Lead $lead, ?Campaign $campaign): string
    {
        return $campaign ? (DB::table('campaign_lead')->where('campaign_id', $campaign->id)->where('lead_id', $lead->id)->value('status') ?? $lead->status) : $lead->status;
    }
}
