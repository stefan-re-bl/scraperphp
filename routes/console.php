<?php

use App\Models\ScrapeRun;
use App\Services\BackupService;
use App\Services\GoogleMapsScraperService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('scraper:execute {run}', function (int $run) {
    $scrapeRun = ScrapeRun::findOrFail($run);
    $result = app(GoogleMapsScraperService::class)->executeRun($scrapeRun);

    $this->info("ScrapeRun {$result->id}: {$result->status}. Importados: {$result->imported_count}. Errores: {$result->error_count}.");
})->purpose('Execute a pending scraper run and import its CSV output.');

Artisan::command('sales:backup', function (BackupService $backups): void {
    $path = $backups->create();
    $summary = $backups->verify($path);
    $this->info('Respaldo verificado: '.$path);
    $this->info('SHA-256: '.$summary['sha256'].'. Registros: '.$summary['leads'].'. Campañas: '.$summary['campaigns'].'.');
})->purpose('Create and verify a local SQLite sales backup.');
