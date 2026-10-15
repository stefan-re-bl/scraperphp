<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class NotionValueParser
{
    public function money(?string $value): ?int
    {
        if (blank($value)) {
            return null;
        }
        $value = preg_replace('/[\s\x{00a0}\x{202f}]+/u', '', trim($value));
        $value = preg_replace('/ARS$/i', '', $value);
        if (! preg_match('/^(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Importe no convertible.');
        }

        return $this->minorFromDecimal(str_replace(['.', ','], ['', '.'], $value));
    }

    public function minorFromDecimal(?string $value): ?int
    {
        if (blank($value)) {
            return null;
        }
        if (! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('El importe debe ser positivo y tener como máximo dos decimales.');
        }
        $parts = explode('.', $value);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function decimalFromMinor(?int $value): ?string
    {
        return $value === null ? null : intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }

    public function date(?string $value, string $timezone): ?string
    {
        if (blank($value)) {
            return null;
        }
        $value = mb_strtolower(trim(preg_replace('/[\s\x{00a0}\x{202f}]+/u', ' ', $value)));
        $months = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        if (preg_match('/^(\d{1,2}) de ([a-z]+) de (\d{4})(?: (\d{1,2}):(\d{2}))?$/D', $value, $match)) {
            $day = (int) $match[1];
            $month = $months[$match[2]] ?? 0;
            $year = (int) $match[3];
            $hour = (int) ($match[4] ?? 0);
            $minute = (int) ($match[5] ?? 0);
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[t ](\d{2}):(\d{2}))?$/D', $value, $match)) {
            [$year, $month, $day, $hour, $minute] = [(int) $match[1], (int) $match[2], (int) $match[3], (int) ($match[4] ?? 0), (int) ($match[5] ?? 0)];
        } else {
            throw new InvalidArgumentException('Fecha no convertible.');
        }
        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
            throw new InvalidArgumentException('Fecha inválida.');
        }

        return Carbon::createSafe($year, $month, $day, $hour, $minute, 0, $timezone)->utc()->format('Y-m-d H:i:s');
    }

    public function phone(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        $value = trim($value);
        if (! preg_match('/^(?:\+|00)[\d\s().-]+$/D', $value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        if (str_starts_with($value, '00')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 && ! str_starts_with($digits, '0') ? '+'.$digits : null;
    }
}
