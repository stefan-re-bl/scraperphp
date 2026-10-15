<?php

namespace App\Services;

class CsvSerializer
{
    public static function cell(mixed $value): mixed
    {
        // Numeric database values remain numeric. Strings from external sources are always protected.
        return is_string($value) && preg_match('/^[\s]*[=+@\-\t\r\n]/u', $value) ? "'".$value : $value;
    }

    public static function row(mixed $handle, array $values): void
    {
        fputcsv($handle, array_map(self::cell(...), $values), escape: '');
    }
}
