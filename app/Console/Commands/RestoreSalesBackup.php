<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RestoreSalesBackup extends Command
{
    protected $signature = 'sales:restore {path} {--force : Confirm replacement of the database}';

    protected $description = 'Verify and restore a sales backup, retaining the previous database.';

    public function handle(BackupService $backups): int
    {
        try {
            $result = $backups->verify($this->argument('path'));
            $this->info('Respaldo válido. Prospectos: '.$result['leads'].'. Campañas: '.$result['campaigns'].'.');
            if (! $this->option('force')) {
                $this->warn('Usá --force para confirmar la restauración.');

                return self::SUCCESS;
            }
            Artisan::call('down');
            try {
                $previous = $backups->restore($this->argument('path'));
            } finally {
                Artisan::call('up');
            }
            $this->info('Restauración completada. Base previa conservada: '.$previous);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
