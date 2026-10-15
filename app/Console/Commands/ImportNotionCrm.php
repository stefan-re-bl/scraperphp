<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use App\Services\NotionImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class ImportNotionCrm extends Command
{
    protected $signature = 'crm:import-notion {zip : Path to the original Notion ZIP} {--apply : Apply the reviewed import} {--decisions= : JSON decision file} {--approved-report= : Previously reviewed preview JSON} {--report= : Output JSON report path} {--write-decisions= : Write a decision template without private notes}';

    protected $description = 'Preview or apply a reviewed, idempotent Notion CRM import.';

    public function handle(NotionImporter $importer, BackupService $backups): int
    {
        try {
            if ($this->option('write-decisions') && ($this->option('apply') || is_file($this->option('write-decisions')))) {
                throw new RuntimeException('El archivo de decisiones debe ser nuevo y generarse en vista previa.');
            }
            $reportPath = $this->option('report') ?: storage_path('app/private/notion-reports/preview-'.now()->format('Ymd-His').'-'.Str::uuid().'.json');
            foreach (array_filter([$reportPath, $this->option('write-decisions')]) as $output) {
                File::ensureDirectoryExists(dirname($output));
                if (! is_writable(dirname($output)) || (file_exists($output) && ! is_writable($output))) {
                    throw new RuntimeException('El directorio del informe no permite escritura.');
                }
                foreach (array_filter([$this->argument('zip'), $this->option('decisions'), $this->option('approved-report'), DB::connection()->getDatabaseName()]) as $input) {
                    if (realpath($output) !== false && realpath($output) === realpath($input)) {
                        throw new RuntimeException('El informe debe guardarse en un archivo diferente de los archivos de entrada.');
                    }
                }
            }
            $decisions = $this->option('decisions') ? $this->readJson($this->option('decisions')) : [];
            $plan = $importer->preview($this->argument('zip'), $decisions);
            $report = $plan['report'];
            if ($this->option('apply')) {
                if (! $this->option('approved-report')) {
                    throw new RuntimeException('La aplicación requiere --approved-report con la vista previa revisada.');
                }
                $approved = $this->readJson($this->option('approved-report'));
                if (! isset($approved['plan_sha256']) || ! hash_equals($report['plan_sha256'], $approved['plan_sha256'])) {
                    throw new RuntimeException('El ZIP, las decisiones o la cartera cambiaron. Genera y revisa otra vista previa.');
                }
                if (DB::table('jobs')->whereNotNull('reserved_at')->exists() || DB::table('scrape_runs')->where('status', 'running')->exists() || DB::table('import_runs')->where('status', 'processing')->exists()) {
                    throw new RuntimeException('Espera a que terminen las tareas en curso antes de importar.');
                }
                if ($report['ready'] > 0) {
                    $report['backup'] = $backups->create();
                    $report['result'] = $importer->apply($plan);
                } else {
                    $report['result'] = ['created' => 0, 'matched' => 0, 'pending' => $report['ambiguous']];
                }
            }
            $this->writeJson($reportPath, $report);
            if ($this->option('write-decisions')) {
                $template = [];
                foreach ($report['rows'] as $row) {
                    $template[$row['key']] = ['action' => $row['ready'] ? 'import' : 'skip'];
                }
                $this->writeJson($this->option('write-decisions'), $template);
            }
            $this->info('Leídos: '.$report['read'].'. Nuevos: '.$report['new'].'. Coincidencias: '.$report['existing'].'. Posibles duplicados: '.$report['possible_duplicates'].'. Ambiguos: '.$report['ambiguous'].'. Listos: '.$report['ready'].'. Notas descartadas: '.$report['discarded_notes'].'.');
            if (isset($report['result'])) {
                $this->info('Persistidos: '.$report['result']['created'].' nuevos, '.$report['result']['matched'].' vinculados.');
            }
            $this->info('Informe: '.$reportPath);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error(get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'La operación se detuvo sin exponer datos del ZIP. Verifica el formato y los archivos de revisión.');

            return self::FAILURE;
        }
    }

    private function readJson(string $path): array
    {
        if (! is_file($path) || filesize($path) > 10 * 1024 * 1024) {
            throw new RuntimeException('No se pudo leer el archivo JSON de revisión.');
        }
        $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('El archivo JSON debe contener un objeto de revisión.');
        }

        return $data;
    }

    private function writeJson(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path));
        if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el informe.');
        }
        chmod($path, 0600);
    }
}
