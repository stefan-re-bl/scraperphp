<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadStatusHistory;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class LeadPipeline
{
    public function move(Lead $lead, string $status, string $reason): Lead
    {
        if (! in_array($status, Lead::STATUSES, true)) {
            throw new InvalidArgumentException("Estado invalido: {$status}");
        }

        $from = $lead->status;

        if ($from === $status) {
            return $lead;
        }

        $lead->forceFill(['status' => $status])->save();

        LeadStatusHistory::create([
            'lead_id' => $lead->id,
            'from_status' => $from,
            'to_status' => $status,
            'reason' => $reason,
            'changed_at' => Carbon::now(),
        ]);

        return $lead->refresh();
    }
}
