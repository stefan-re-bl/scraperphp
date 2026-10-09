<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreferencesService
{
    public const DEFAULTS = ['timezone' => 'America/Argentina/Buenos_Aires', 'currency' => 'USD', 'country' => 'Argentina', 'language' => 'es', 'date_format' => 'd/m/Y H:i'];

    public function all(): array
    {
        if (! Schema::hasTable('application_preferences')) {
            return self::DEFAULTS;
        }

        return DB::table('application_preferences')->pluck('value', 'key')->all() + self::DEFAULTS;
    }

    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('application_preferences')->updateOrInsert(['key' => $key], ['value' => $value]);
        }
    }
}
