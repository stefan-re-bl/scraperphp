<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;

class LimitService
{
    public function usage(Campaign $campaign, ?string $channel = null): array
    {
        $timezone = app(PreferencesService::class)->all()['timezone'];
        $start = now()->timezone($timezone)->startOfDay()->utc();
        $end = now()->timezone($timezone)->endOfDay()->utc();
        $query = ContactAttempt::where('campaign_id', $campaign->id)->where('dry_run', false)->when($channel, fn ($q) => $q->where('channel', $channel));
        $used = (clone $query)->where('status', 'sent')->whereBetween('performed_at', [$start, $end])->count();
        $reserved = (clone $query)->where('status', 'sending')->whereBetween('created_at', [$start, $end])->count();

        return ['used' => $used, 'reserved' => $reserved, 'limit' => $campaign->daily_limit, 'remaining' => max(0, $campaign->daily_limit - $used - $reserved), 'blocked' => $campaign->kill_switch || $campaign->status === 'paused' || $used + $reserved >= $campaign->daily_limit];
    }
}
