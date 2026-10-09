<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateScores implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(LeadScorer $scorer): void
    {
        Lead::orderBy('id')->chunkById(100, function ($leads) use ($scorer) {
            foreach ($leads as $lead) {
                $scorer->score($lead);
            }
        });
    }
}
