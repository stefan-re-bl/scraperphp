<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use Illuminate\Support\Carbon;

class LimitService
{
    public function usage(Campaign $campaign, ?string $channel = null): array
    {
        $used = ContactAttempt::where('campaign_id', $campaign->id)
            ->whereDate('performed_at', Carbon::today())
            ->when($channel, fn ($query) => $query->where('channel', $channel))
            ->count();

        $limit = $campaign->daily_limit;

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'blocked' => $campaign->kill_switch || $used >= $limit,
        ];
    }
}
