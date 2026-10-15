<?php

namespace App\Services;

class ContactNormalizer
{
    public function hasComparablePhone(?string $value): bool
    {
        $value = trim((string) $value);
        $digits = preg_replace('/\D+/', '', $value);

        return preg_match('/^\+?[\d\s().-]+$/D', $value) === 1
            && strlen($digits) >= 8 && strlen($digits) <= 15
            && preg_match('/[1-9]/', $digits) === 1;
    }

    public function email(?string $value): ?string
    {
        preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', (string) $value, $match);

        return isset($match[0]) ? mb_strtolower($match[0]) : null;
    }

    public function phone(?string $value, ?string $country = 'Argentina'): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $value = '+'.$digits;
        }
        if (str_starts_with(trim((string) $value), '+')) {
            return '+'.$digits;
        }
        $codes = ['argentina' => '54', 'ar' => '54', 'chile' => '56', 'cl' => '56', 'uruguay' => '598', 'uy' => '598', 'españa' => '34', 'es' => '34'];
        $code = $codes[mb_strtolower((string) $country)] ?? null;
        if (! $code) {
            return null;
        }
        $digits = ltrim($digits, '0');

        return '+'.(str_starts_with($digits, $code) ? $digits : $code.$digits);
    }

    public function url(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        $value = trim($value);
        $url = preg_match('~^https?://~i', $value) ? $value : 'https://'.$value;
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || isset($parts['user']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) {
            return null;
        }

        return $url;
    }

    public function whatsappPhone(?string $value, ?string $country = 'Argentina'): ?string
    {
        if (! preg_match('/^[+\d\s().-]+$/u', (string) $value)) {
            return null;
        }
        $phone = $this->phone($value, $country);
        if (! $phone) {
            return null;
        }
        $digits = ltrim($phone, '+');
        if (str_starts_with($digits, '54')) {
            $national = substr($digits, 2);
            if (str_starts_with($national, '9') && in_array(strlen($national), [11, 13], true)) {
                $national = substr($national, 1);
            }
            if (strlen($national) === 12) {
                $national = preg_replace('/^(\d{2,4})15(\d{6,8})$/', '$1$2', $national);
            }
            if (strlen($national) !== 10) {
                return null;
            }
            $digits = '549'.$national;
        }

        return preg_match('/^[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }

    public function domain(?string $value): ?string
    {
        $url = $this->url($value);

        return $url ? preg_replace('/^www\./', '', mb_strtolower((string) parse_url($url, PHP_URL_HOST))) : null;
    }

    public function exclusion(string $type, string $value): string
    {
        return match ($type) {
            'email' => $this->email($value) ?? mb_strtolower(trim($value)),
            'domain' => $this->domain($value) ?? mb_strtolower(trim($value)),
            'phone' => $this->phone($value) ?? trim($value),
            'name' => mb_strtolower(trim($value)),
            default => trim($value),
        };
    }
}
