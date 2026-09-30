<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\ScrapeRun;
use App\Models\SystemLog;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class GoogleMapsScraperService
{
    public function __construct(private readonly LeadImporter $importer) {}

    public function run(Campaign $campaign, CampaignQuery $query, bool $dryRun = true): ScrapeRun
    {
        $run = $this->prepareRun($campaign, $query, $dryRun);

        if ($dryRun) {
            $run->update(['finished_at' => now()]);

            return $run->refresh();
        }

        return $this->executeRun($run);
    }

    public function start(Campaign $campaign, CampaignQuery $query, bool $dryRun = true): ScrapeRun
    {
        if ($dryRun) {
            return $this->run($campaign, $query, true);
        }

        $run = $this->prepareRun($campaign, $query, false);
        $this->launchBackgroundProcess($run);

        return $run->refresh();
    }

    public function executeRun(ScrapeRun $run): ScrapeRun
    {
        if ($run->status === 'completed') {
            return $run->refresh();
        }

        if (File::exists((string) $run->output_file) && File::size((string) $run->output_file) > 0 && $run->status === 'running') {
            return $this->importExistingOutput($run);
        }

        try {
            $process = Process::fromShellCommandline((string) $run->command);
            $process->setTimeout($run->timeout_seconds);
            $process->run();

            $run->update([
                'exit_code' => $process->getExitCode(),
                'stdout' => mb_substr($process->getOutput(), 0, 60000),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 60000),
                'status' => $process->isSuccessful() ? 'completed' : 'failed',
                'finished_at' => now(),
            ]);

            if ($process->isSuccessful() && File::exists((string) $run->output_file)) {
                $this->importExistingOutput($run->refresh());
            }
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'stderr' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            SystemLog::create([
                'type' => 'scraping_error',
                'message' => $exception->getMessage(),
                'context' => ['scrape_run_id' => $run->id],
            ]);
        }

        return $run->refresh();
    }

    public function importExistingOutput(ScrapeRun $run): ScrapeRun
    {
        if (! File::exists((string) $run->output_file)) {
            $run->update([
                'status' => 'failed',
                'stderr' => 'No existe el CSV de salida.',
                'finished_at' => now(),
            ]);

            return $run->refresh();
        }

        $campaign = $run->campaign()->firstOrFail();
        $import = $this->importer->importCsv($run->output_file, $campaign, [
            'query' => $run->query,
            'location' => $run->location,
            'campaign_query_id' => $run->campaign_query_id,
            'scrape_run_id' => $run->id,
            'max_rows' => $run->result_limit,
        ]);

        $run->update([
            'status' => 'completed',
            'raw_count' => $import->raw_count,
            'imported_count' => $import->imported_count,
            'duplicate_count' => $import->duplicate_count,
            'error_count' => $import->error_count,
            'finished_at' => now(),
        ]);

        return $run->refresh();
    }

    private function prepareRun(Campaign $campaign, CampaignQuery $query, bool $dryRun): ScrapeRun
    {
        $outputDir = storage_path('app/scrapes');
        File::ensureDirectoryExists($outputDir);

        $output = $outputDir.'/scrape-'.$campaign->id.'-'.$query->id.'-'.now()->format('YmdHis').'.csv';
        $input = $outputDir.'/query-'.$campaign->id.'-'.$query->id.'-'.now()->format('YmdHis').'.txt';
        $search = trim($query->query.' '.$query->location);
        File::put($input, $search.PHP_EOL);

        $args = $this->command($input, $output, $query->result_limit);

        $run = ScrapeRun::create([
            'campaign_id' => $campaign->id,
            'campaign_query_id' => $query->id,
            'source' => 'google_maps',
            'query' => $query->query,
            'location' => $query->location,
            'result_limit' => $query->result_limit,
            'status' => $dryRun ? 'dry_run' : 'running',
            'dry_run' => $dryRun,
            'timeout_seconds' => (int) env('GOOGLE_MAPS_SCRAPER_TIMEOUT', 300),
            'output_file' => $output,
            'command' => implode(' ', array_map('escapeshellarg', $args)),
            'started_at' => now(),
        ]);

        SystemLog::create([
            'type' => 'scraping',
            'message' => $dryRun ? 'Dry-run de scraping preparado.' : 'Scraping iniciado.',
            'context' => ['scrape_run_id' => $run->id, 'query' => $search],
        ]);

        if ($dryRun) {
            return $run;
        }

        return $run;
    }

    private function launchBackgroundProcess(ScrapeRun $run): void
    {
        $log = storage_path("logs/scraper-run-{$run->id}.log");
        $command = sprintf(
            'nohup %s %s scraper:execute %d > %s 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $run->id,
            escapeshellarg($log),
        );

        Process::fromShellCommandline($command, base_path())->setTimeout(5)->run();
    }

    private function command(string $input, string $output, int $limit): array
    {
        $depth = max(1, min(10, (int) ceil($limit / 20)));

        if (env('GOOGLE_MAPS_SCRAPER_USE_DOCKER', true)) {
            $dataDir = dirname($input);

            return [
                'docker',
                'run',
                '--rm',
                '-v',
                $dataDir.':/data',
                env('GOOGLE_MAPS_SCRAPER_IMAGE', 'gosom/google-maps-scraper:latest'),
                '-input',
                '/data/'.basename($input),
                '-results',
                '/data/'.basename($output),
                '-lang',
                'es',
                '-c',
                '1',
                '-depth',
                (string) $depth,
            ];
        }

        return [
            env('GOOGLE_MAPS_SCRAPER_BIN', 'google-maps-scraper'),
            '-input',
            $input,
            '-results',
            $output,
            '-lang',
            'es',
            '-c',
            '1',
            '-depth',
            (string) $depth,
        ];
    }
}
