<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\ImportRun;
use App\Services\LeadImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportLeadFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $runId) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(LeadImporter $importer): void
    {
        $run = ImportRun::findOrFail($this->runId);
        $campaign = Campaign::findOrFail($run->campaign_id);
        if ($campaign->kill_switch || $campaign->status === 'paused') {
            $run->update(['status' => 'cancelled']);

            return;
        }
        try {
            $importer->importCsv($run->file_path, $campaign, ($run->raw_metadata ?? []) + ['mapping' => $run->mapping ?? [], 'run_id' => $run->id]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ImportRun::whereKey($this->runId)->update(['status' => 'failed', 'error_message' => $exception?->getMessage(), 'finished_at' => now(), 'claimed_at' => null, 'claim_token' => null]);
    }
}
