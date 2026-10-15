<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadEnricher;
use App\Services\LeadScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class EnrichLead implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $leadId, public bool $force = false) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('enrichment:'.$this->leadId))->releaseAfter(30)->expireAfter(60)];
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(LeadEnricher $enricher, LeadScorer $scorer): void
    {
        $lead = Lead::find($this->leadId);
        if (! $lead || $lead->excluded_at) {
            return;
        }
        $enricher->enrich($lead, $this->force);
        $scorer->score($lead);
    }
}
