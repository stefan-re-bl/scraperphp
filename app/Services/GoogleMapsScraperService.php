<?php

namespace App\Services;

use App\Jobs\ExecuteScrapeRun;
use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\ImportRun;
use App\Models\ScrapeRun;
use App\Models\SystemLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class GoogleMapsScraperService
{
    public const CLAIM_SECONDS = 1200;

    public function __construct(private readonly LeadImporter $importer) {}

    public function run(Campaign $campaign, CampaignQuery $query, bool $dryRun = true): ScrapeRun
    {
        return $this->start($campaign, $query, $dryRun);
    }

    public function start(Campaign $campaign, CampaignQuery $query, bool $dryRun = true): ScrapeRun
    {
        if ($campaign->kill_switch || $campaign->status === 'paused') {
            throw ValidationException::withMessages(['campaign_id' => 'La campaña está pausada.']);
        }
        $directory = storage_path('app/scrapes');
        File::ensureDirectoryExists($directory);
        $token = Str::uuid()->toString();
        $input = $directory.'/query-'.$token.'.txt';
        $output = $directory.'/scrape-'.$token.'.csv';
        File::put($input, trim($query->query.' '.$query->location).PHP_EOL);
        $arguments = $this->command($input, $output, $query->result_limit);
        $run = ScrapeRun::create([
            'campaign_id' => $campaign->id, 'campaign_query_id' => $query->id, 'source' => 'google_maps',
            'query' => $query->query, 'location' => $query->location, 'result_limit' => $query->result_limit,
            'status' => $dryRun ? 'dry_run' : 'queued', 'phase' => $dryRun ? 'simulation' : 'queued',
            'dry_run' => $dryRun, 'timeout_seconds' => (int) config('services.scraper.timeout', 600),
            'output_file' => $output, 'arguments' => $arguments, 'command' => implode(' ', array_map('escapeshellarg', $arguments)),
            'finished_at' => $dryRun ? now() : null,
        ]);
        SystemLog::create(['type' => 'scraping', 'message' => $dryRun ? 'Simulación preparada.' : 'Búsqueda en cola.', 'context' => ['scrape_run_id' => $run->id]]);
        if (! $dryRun) {
            ExecuteScrapeRun::dispatch($run->id)->onQueue('sales');
        }

        return $run->refresh();
    }

    public function executeRun(ScrapeRun $run): ScrapeRun
    {
        try {
            return $this->executeClaimedRun($run);
        } catch (\Throwable $exception) {
            throw AcquisitionFailure::publicException($exception);
        }
    }

    private function executeClaimedRun(ScrapeRun $run): ScrapeRun
    {
        $token = Str::uuid()->toString();
        $claimed = DB::transaction(function () use ($run, $token) {
            $run->refresh();
            if ($run->claimed_at && $run->claimed_at->greaterThanOrEqualTo(now()->subSeconds(self::CLAIM_SECONDS))) {
                return false;
            }
            if ($run->dry_run || in_array($run->status, ['completed', 'cancelled']) || $run->cancel_requested_at || $run->campaign->kill_switch || $run->campaign->status === 'paused') {
                if (! $run->dry_run && $run->status !== 'completed') {
                    $run->update(['status' => 'cancelled', 'phase' => 'cancelled']);
                }

                return false;
            }

            return ScrapeRun::whereKey($run->id)->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::CLAIM_SECONDS)))->update([
                'claim_token' => $token, 'claimed_at' => now(), 'heartbeat_at' => now(), 'status' => 'running', 'phase' => 'searching',
                'started_at' => now(), 'attempts' => DB::raw('attempts + 1'),
            ]) === 1;
        });
        if (! $claimed) {
            return $run->refresh();
        }
        try {
            if ($run->exit_code === 0 && File::exists((string) $run->output_file)) {
                return $this->importExistingOutput($run, $token);
            }
            if (! $run->arguments) {
                throw new \RuntimeException('Esta ejecución antigua debe repetirse desde Adquisición.');
            }
            $process = new Process(app(DockerCommandLauncher::class)->prepare($run->arguments), base_path(), timeout: $run->timeout_seconds);
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $run->refresh();
                if ($run->claim_token !== $token) {
                    $process->stop(1);
                    throw new AcquisitionClaimLost('La reserva de búsqueda pertenece a otra tarea.');
                }
                if ($run->cancel_requested_at || $run->campaign->refresh()->kill_switch || $run->campaign->status === 'paused') {
                    $process->stop(1);
                    $this->ownedUpdate($run, $token, ['status' => 'cancelled', 'phase' => 'cancelled', 'finished_at' => now(), 'claimed_at' => null]);

                    return $run->refresh();
                }
                $this->ownedUpdate($run, $token, ['heartbeat_at' => now(), 'claimed_at' => now()]);
                usleep(250000);
            }
            $this->ownedUpdate($run, $token, ['exit_code' => $process->getExitCode(), 'stdout' => mb_substr($process->getOutput(), 0, 60000), 'stderr' => mb_substr($process->getErrorOutput(), 0, 60000)]);
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('La búsqueda no terminó correctamente. '.$process->getErrorOutput());
            }

            return $this->importExistingOutput($run, $token);
        } catch (AcquisitionClaimLost) {
            return $run->refresh();
        } catch (\Throwable $exception) {
            $exception = AcquisitionFailure::publicException($exception);
            ScrapeRun::whereKey($run->id)->where('claim_token', $token)->update(['status' => 'failed', 'phase' => 'failed', 'stderr' => mb_substr($exception->getMessage(), 0, 60000), 'finished_at' => now(), 'claimed_at' => null]);
            throw $exception;
        } finally {
            ScrapeRun::whereKey($run->id)->where('claim_token', $token)->update(['claimed_at' => null, 'claim_token' => null]);
        }
    }

    public function importExistingOutput(ScrapeRun $run, ?string $token = null): ScrapeRun
    {
        if ($token === null) {
            $token = Str::uuid()->toString();
            if (! ScrapeRun::whereKey($run->id)->whereNotIn('status', ['completed', 'cancelled'])->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::CLAIM_SECONDS)))
                ->update(['claim_token' => $token, 'claimed_at' => now()])) {
                return $run->refresh();
            }
            try {
                return $this->importExistingOutput($run->refresh(), $token);
            } finally {
                ScrapeRun::whereKey($run->id)->where('claim_token', $token)->update(['claim_token' => null, 'claimed_at' => null]);
            }
        }

        if ($run->status === 'completed' || $run->cancel_requested_at || $run->campaign->kill_switch || $run->campaign->status === 'paused') {
            if ($run->status !== 'completed') {
                $this->ownedUpdate($run, $token, ['status' => 'cancelled', 'phase' => 'cancelled', 'claimed_at' => null, 'finished_at' => now()]);
            }

            return $run;
        }
        if (! File::exists((string) $run->output_file)) {
            throw new \RuntimeException('No se encontró el archivo de resultados.');
        }
        $this->ownedUpdate($run, $token, ['status' => 'running', 'phase' => 'importing', 'heartbeat_at' => now(), 'claimed_at' => now()]);
        $existing = ImportRun::where('scrape_run_id', $run->id)->first();
        $meta = ['source' => 'google_maps', 'query' => $run->query, 'location' => $run->location, 'campaign_query_id' => $run->campaign_query_id, 'scrape_run_id' => $run->id, 'max_rows' => $run->result_limit, 'cancel_run_id' => $run->id];
        if ($existing) {
            $meta['run_id'] = $existing->id;
        }
        $import = $this->importer->importCsv($run->output_file, $run->campaign, $meta);
        $run->refresh();
        $status = $import->status === 'completed' ? 'completed' : ($import->status === 'cancelled' || $run->cancel_requested_at ? 'cancelled' : ($import->status === 'failed' ? 'failed' : 'running'));
        $this->ownedUpdate($run, $token, ['status' => $status, 'phase' => $status === 'running' ? 'waiting_import' : $status,
            'raw_count' => $import->raw_count, 'imported_count' => $import->imported_count, 'duplicate_count' => $import->duplicate_count,
            'error_count' => $import->error_count, 'finished_at' => $status === 'running' ? null : now()]);

        return $run->refresh();
    }

    public function cancel(ScrapeRun $run): void
    {
        if (in_array($run->status, ['queued', 'running', 'failed'])) {
            $run->update(['cancel_requested_at' => now(), 'status' => $run->status === 'running' ? 'running' : 'cancelled', 'phase' => $run->status === 'running' ? 'cancelling' : 'cancelled']);
        }
    }

    public function retry(ScrapeRun $run): void
    {
        if (! in_array($run->status, ['failed', 'cancelled']) || ($run->claimed_at && $run->claimed_at->greaterThanOrEqualTo(now()->subSeconds(self::CLAIM_SECONDS)))) {
            throw ValidationException::withMessages(['run' => 'La búsqueda todavía está en curso.']);
        }
        if ($run->campaign->kill_switch || $run->campaign->status === 'paused') {
            throw ValidationException::withMessages(['run' => 'Reactivá la campaña antes de reintentar.']);
        }
        DB::transaction(function () use ($run): void {
            if (ImportRun::where('scrape_run_id', $run->id)->where('status', 'cancelled')
                ->where('claimed_at', '>=', now()->subSeconds(LeadImporter::CLAIM_SECONDS))->exists()) {
                throw ValidationException::withMessages(['run' => 'Espera que la importación libere su reserva antes de reintentar.']);
            }
            $updated = ScrapeRun::whereKey($run->id)->whereIn('status', ['failed', 'cancelled'])
                ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::CLAIM_SECONDS)))
                ->update(['status' => 'queued', 'phase' => 'queued', 'claimed_at' => null, 'claim_token' => null, 'cancel_requested_at' => null, 'stderr' => null, 'finished_at' => null,
                    'timeout_seconds' => (int) config('services.scraper.timeout', 600)]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['run' => 'La búsqueda está siendo procesada por otra tarea.']);
            }
            ImportRun::where('scrape_run_id', $run->id)->where('status', 'cancelled')
                ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(LeadImporter::CLAIM_SECONDS)))
                ->update(['status' => 'queued', 'claim_token' => null, 'claimed_at' => null, 'error_message' => null, 'finished_at' => null]);
        });
        ExecuteScrapeRun::dispatch($run->id)->onQueue('sales');
    }

    private function ownedUpdate(ScrapeRun $run, string $token, array $values): void
    {
        if (ScrapeRun::whereKey($run->id)->where('claim_token', $token)->update($values) !== 1) {
            throw new AcquisitionClaimLost('La reserva de búsqueda pertenece a otra tarea.');
        }
    }

    public function retryDelay(ScrapeRun $run): int
    {
        $import = ImportRun::where('scrape_run_id', $run->id)->where('status', 'processing')->first();
        if ($import) {
            return $this->importer->retryDelay($import);
        }

        return $run->claimed_at ? max(1, (int) now()->diffInSeconds($run->claimed_at->copy()->addSeconds(self::CLAIM_SECONDS), false) + 1) : 1;
    }

    private function command(string $input, string $output, int $limit): array
    {
        $depth = max(1, min(10, (int) ceil($limit / 20)));
        $args = ['-input', $input, '-results', $output, '-lang', 'es', '-c', '1', '-depth', (string) $depth];
        if (config('services.scraper.docker', true)) {
            $args[1] = '/data/'.basename($input);
            $args[3] = '/data/'.basename($output);

            return ['docker', 'run', '--rm', '-v', dirname($input).':/data', config('services.scraper.image'), ...$args];
        }

        return [config('services.scraper.binary'), ...$args];
    }
}
