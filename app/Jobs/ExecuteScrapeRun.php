<?php

namespace App\Jobs;

use App\Models\ScrapeRun;
use App\Services\GoogleMapsScraperService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteScrapeRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $runId) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(GoogleMapsScraperService $scraper): void
    {
        $scraper->executeRun(ScrapeRun::findOrFail($this->runId));
    }

    public function failed(?Throwable $exception): void
    {
        ScrapeRun::whereKey($this->runId)->update(['status' => 'failed', 'phase' => 'failed', 'claimed_at' => null, 'stderr' => $exception?->getMessage(), 'finished_at' => now()]);
    }
}
