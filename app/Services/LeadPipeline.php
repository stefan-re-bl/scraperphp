<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadPipeline
{
    public function move(Lead $lead, string $status, string $reason, ?Campaign $campaign = null): Lead
    {
        if (! in_array($status, Lead::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Estado inválido.']);
        }
        if (in_array($status, ['QUEUED', 'CONTACTED'])) {
            app(ExclusionService::class)->assertContactable($lead, $campaign);
            $current = app(CampaignMembership::class)->status($lead, $campaign);
            if (in_array($current, ['WON', 'LOST'])) {
                throw ValidationException::withMessages(['status' => 'Reabrí la oportunidad antes de enviarla a cola.']);
            }
        }

        return DB::transaction(function () use ($lead, $status, $reason, $campaign) {
            if ($campaign) {
                app(CampaignMembership::class)->attach($lead, $campaign);
            }
            $from = app(CampaignMembership::class)->status($lead, $campaign);
            if ($from === $status) {
                return $lead;
            }
            $lead->update($status === 'WON' ? ['status' => $status, 'is_customer' => true] : ['status' => $status]);
            if ($campaign) {
                $data = ['status' => $status, 'updated_at' => now()];
                if (in_array($status, ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'])) {
                    $data['interested_at'] = DB::table('campaign_lead')->where('lead_id', $lead->id)->where('campaign_id', $campaign->id)->value('interested_at') ?? now();
                }
                if ($status === 'WON') {
                    $data['won_at'] = now();
                    $data['lost_at'] = null;
                }
                if ($status === 'LOST') {
                    $data['lost_at'] = now();
                    $data['won_at'] = null;
                }
                if (! in_array($status, ['WON', 'LOST'])) {
                    $data['won_at'] = null;
                    $data['lost_at'] = null;
                }
                DB::table('campaign_lead')->where('lead_id', $lead->id)->where('campaign_id', $campaign->id)->update($data);
            }
            if ($status === 'QUEUED' && $from === 'PAUSED') {
                $pending = $lead->contactAttempts()->where('status', 'paused')->where('result', 'pipeline_PAUSED')->when($campaign, fn ($q) => $q->where('campaign_id', $campaign->id));
                $pending->update(['status' => 'planned', 'result' => null]);
                app(CampaignMembership::class)->nextAction($lead, $campaign, $lead->contactAttempts()->where('status', 'planned')->when($campaign, fn ($q) => $q->where('campaign_id', $campaign->id))->min('due_at'));
            }
            LeadStatusHistory::create(['lead_id' => $lead->id, 'campaign_id' => $campaign?->id, 'from_status' => $from, 'to_status' => $status, 'reason' => $reason, 'changed_at' => now()]);
            if (in_array($status, ['PAUSED', 'WON', 'LOST', 'REPLIED', 'INTERESTED', 'PROPOSAL', 'NEGOTIATION'])) {
                $lead->contactAttempts()->where('status', 'planned')->when($campaign, fn ($q) => $q->where('campaign_id', $campaign->id))->update(['status' => 'paused', 'result' => 'pipeline_'.$status]);
                app(CampaignMembership::class)->nextAction($lead, $campaign, null);
            }

            return $lead->refresh();
        });
    }
}
