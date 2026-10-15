<?php

namespace App\Services;

use App\Models\ImportRun;
use App\Models\ScrapeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;

class BackupService
{
    public function create(): string
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('El respaldo disponible requiere SQLite.');
        }
        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/ventas-'.now()->format('Ymd-His').'-'.Str::uuid().'.sqlite';
        DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($path));
        $this->verify($path);

        return $path;
    }

    public function verify(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('No existe el respaldo.');
        }
        $pdo = new PDO('sqlite:file:'.$path.'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $check = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        if ($check !== ['ok']) {
            throw new \RuntimeException('El respaldo no pasó la verificación de integridad.');
        }
        if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
            throw new \RuntimeException('El respaldo contiene relaciones rotas y no se puede restaurar.');
        }
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['leads', 'campaigns', 'contact_attempts', 'campaign_lead', 'migrations'] as $required) {
            if (! in_array($required, $tables)) {
                throw new \RuntimeException('El archivo no es un respaldo compatible de esta aplicación.');
            }
        }
        $expected = DB::table('migrations')->pluck('migration')->sort()->values()->all();
        $actual = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        if ($expected !== $actual) {
            throw new \RuntimeException('El respaldo corresponde a otra versión de la aplicación.');
        }

        return ['integrity' => 'ok', 'leads' => (int) $pdo->query('SELECT count(*) FROM leads')->fetchColumn(), 'campaigns' => (int) $pdo->query('SELECT count(*) FROM campaigns')->fetchColumn(), 'sha256' => hash_file('sha256', $path)];
    }

    public function restore(string $path): string
    {
        $this->verify($path);
        $target = DB::connection()->getDatabaseName();
        if ($target === ':memory:' || DB::connection()->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('La restauración requiere una base SQLite en archivo.');
        }
        if (DB::table('jobs')->whereNotNull('reserved_at')->exists() || ScrapeRun::where('status', 'running')->exists() || ImportRun::where('status', 'processing')->exists()) {
            throw new \RuntimeException('Esperá que terminen las tareas en curso antes de restaurar.');
        }
        $previous = $this->create();
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        DB::disconnect();
        $temporary = $target.'.restore-'.Str::uuid();
        File::copy($path, $temporary);
        if (! rename($temporary, $target)) {
            File::delete($temporary);
            throw new \RuntimeException('No se pudo reemplazar la base. El respaldo previo está disponible.');
        }
        DB::purge();

        return $previous;
    }
}
