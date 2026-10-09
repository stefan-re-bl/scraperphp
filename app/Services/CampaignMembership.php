<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignMembership
{
    public function attach(Lead $lead, Campaign $campaign): void
    {
        DB::table('campaign_lead')->insertOrIgnore([
            'campaign_id' => $campaign->id, 'lead_id' => $lead->id,
            'offer_id' => $campaign->offer_id ?? $lead->suggested_offer_id,
            'status' => $lead->campaigns()->exists() ? 'ENRICHED' : $lead->status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
            $q->where('campaigns.kill_switch', false)->where('campaigns.status', 'active')
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
        $query = Lead::whereNull('excluded_at');
        if ($campaign) {
            $query->whereHas('campaigns', function ($q) use ($campaign, $eligibleCampaign) {
                $q->where('campaigns.id', $campaign->id);
                $eligibleCampaign($q);
            });
        } else {
            $query->where(function ($q) use ($eligibleCampaign) {
                $q->whereHas('campaigns', $eligibleCampaign)->orWhere(fn ($standalone) => $standalone->whereDoesntHave('campaigns')->where('status', 'QUEUED')
                    ->where(fn ($due) => $due->whereNull('next_action_at')->orWhere('next_action_at', '<=', now())));
            });
        }

        return $query;
    }

    public function offer(Lead $lead, ?Campaign $campaign): ?Offer
    {
        $offerId = $campaign ? DB::table('campaign_lead')->where('lead_id', $lead->id)->where('campaign_id', $campaign->id)->value('offer_id') : null;

        return Offer::find($offerId ?? $campaign?->offer_id ?? $lead->suggested_offer_id);
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
