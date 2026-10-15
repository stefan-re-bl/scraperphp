<?php

namespace App\Jobs;

use App\Models\ScrapeRun;
use App\Services\AcquisitionFailure;
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
        try {
            $run = $scraper->executeRun(ScrapeRun::findOrFail($this->runId));
            if ($run->status === 'running') {
                self::dispatch($run->id)->onQueue($this->queue ?? 'sales')->delay($scraper->retryDelay($run));
            }
        } catch (Throwable $exception) {
            throw AcquisitionFailure::publicException($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        ScrapeRun::whereKey($this->runId)->whereNotIn('status', ['completed', 'cancelled', 'dry_run'])->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(GoogleMapsScraperService::CLAIM_SECONDS)))->update(['status' => 'failed', 'phase' => 'failed', 'claimed_at' => null, 'claim_token' => null, 'stderr' => AcquisitionFailure::message($exception), 'finished_at' => now()]);
    }
}
