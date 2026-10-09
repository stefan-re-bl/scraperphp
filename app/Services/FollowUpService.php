<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;

class FollowUpService
{
    public function scheduleNext(Lead $lead, Campaign $campaign, string $currentStep): ?ContactAttempt
    {
        if ($campaign->kill_switch || $campaign->status === 'paused' || $lead->excluded_at || $lead->replies()->where('campaign_id', $campaign->id)->exists()) {
            return null;
        }
        $next = match ($currentStep) {
            'initial' => ['step' => 'follow_up_1', 'delay' => $campaign->follow_up_1_delay_days],
            'follow_up_1' => ['step' => 'follow_up_2', 'delay' => $campaign->follow_up_2_delay_days],
            default => null,
        };
        if (! $next) {
            return null;
        }
        $previous = $lead->contactAttempts()->where('campaign_id', $campaign->id)->where('step', $currentStep)->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')->latest('performed_at')->first();
        if (! $previous) {
            return null;
        }
        $due = $previous->performed_at->copy()->addDays($next['delay']);
        $attempt = ContactAttempt::firstOrCreate(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'step' => $next['step']], [
            'channel' => $previous->channel, 'planned_at' => $due, 'due_at' => $due,
            'status' => 'planned', 'manual_intervention' => true, 'dry_run' => false,
        ]);
        if ($attempt->status === 'planned') {
            app(CampaignMembership::class)->nextAction($lead, $campaign, $attempt->due_at?->toDateTimeString());
        }

        return $attempt;
    }

    public function pauseForReply(Lead $lead, ?int $campaignId = null): int
    {
        return $lead->contactAttempts()->where('status', 'planned')->when($campaignId, fn ($query) => $query->where('campaign_id', $campaignId))->update(['status' => 'paused', 'result' => 'paused_after_reply']);
    }
}
