<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\ImportRun;
use App\Services\AcquisitionFailure;
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
        try {
            $run = ImportRun::findOrFail($this->runId);
            $campaign = Campaign::findOrFail($run->campaign_id);
            $result = $importer->importCsv($run->file_path, $campaign, ($run->raw_metadata ?? []) + ['mapping' => $run->mapping ?? [], 'run_id' => $run->id]);
            if ($result->status === 'processing') {
                // Dispatch a fresh delayed job: queue release() would consume this job's tries.
                self::dispatch($run->id)->onQueue($this->queue ?? 'sales')->delay($importer->retryDelay($result));
            }
        } catch (Throwable $exception) {
            throw AcquisitionFailure::publicException($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        ImportRun::whereKey($this->runId)->whereNotIn('status', ['completed', 'cancelled'])->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(LeadImporter::CLAIM_SECONDS)))->update(['status' => 'failed', 'error_message' => AcquisitionFailure::message($exception), 'finished_at' => now(), 'claimed_at' => null, 'claim_token' => null]);
    }
}
