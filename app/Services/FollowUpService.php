<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;

class FollowUpService
{
    public function scheduleNext(Lead $lead, Campaign $campaign, string $currentStep): ?ContactAttempt
    {
        if ($lead->replies()->exists()) {
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

        if ($lead->contactAttempts()->whereIn('status', ['planned', 'sent'])->exists()) {
            return null;
        }

        return ContactAttempt::create([
            'lead_id' => $lead->id,
            'campaign_id' => $campaign->id,
            'channel' => $campaign->channel,
            'step' => $next['step'],
            'planned_at' => now()->addDays($next['delay']),
            'due_at' => now()->addDays($next['delay']),
            'status' => 'planned',
            'manual_intervention' => $campaign->mode !== 'automatic',
            'dry_run' => $campaign->dry_run,
        ]);
    }

    public function pauseForReply(Lead $lead): int
    {
        return $lead->contactAttempts()
            ->where('status', 'planned')
            ->update(['status' => 'paused', 'result' => 'paused_after_reply']);
    }
}
