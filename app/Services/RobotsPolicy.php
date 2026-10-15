<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RobotsPolicy
{
    public function policy(string $url, EnrichmentRequestBudget $budget): array
    {
        $parts = parse_url($url);
        $origin = strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $key = hash('sha256', 'robots:'.$origin);
        $cached = DB::table('enrichment_request_states')->where('key', $key)->where('expires_at', '>', now())->first();
        if ($cached && $cached->payload) {
            return json_decode($cached->payload, true, flags: JSON_THROW_ON_ERROR);
        }
        try {
            $response = $budget->fetch($origin.'/robots.txt', false, min(16000, (int) config('enrichment.robots_max_bytes', 16000)));
            if (in_array($response['status'], [404, 410], true)) {
                $policy = ['rules' => [], 'available' => true, 'status' => 'not_found', 'crawl_delay' => 0];
            } elseif (! $response['successful'] || preg_match('/<\s*(?:html|body|!doctype)/i', $response['body'])) {
                $policy = $this->unavailable();
            } else {
                $policy = $this->parse($response['body'], (string) config('enrichment.user_agent', 'CRTWebEvidenceBot/1.0'));
            }
        } catch (\Throwable) {
            $policy = $this->unavailable();
        }
        DB::table('enrichment_request_states')->updateOrInsert(['key' => $key], [
            'payload' => json_encode($policy, JSON_THROW_ON_ERROR),
            'expires_at' => now()->addHours($policy['available'] ? min(24, (int) config('enrichment.robots_ttl_hours', 24)) : 1),
        ]);

        return $policy;
    }

    public function parse(string $body, string $userAgent): array
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $delay = 0;
        $hasDirectives = false;
        $flush = function () use (&$groups, &$agents, &$rules, &$delay, &$hasDirectives): void {
            if ($agents !== []) {
                $groups[] = ['agents' => $agents, 'rules' => $rules, 'crawl_delay' => $delay];
            }
            $agents = $rules = [];
            $delay = 0;
            $hasDirectives = false;
        };
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (! str_contains($line, ':')) {
                continue;
            }
            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = strtolower($directive);
            if ($directive === 'user-agent') {
                if ($hasDirectives) {
                    $flush();
                }
                $agents[] = strtolower($value);
            } elseif ($agents !== [] && in_array($directive, ['allow', 'disallow'], true)) {
                $hasDirectives = true;
                if ($value !== '' && strlen($value) <= 1024) {
                    $rules[] = ['pattern' => $value, 'allow' => $directive === 'allow'];
                }
            } elseif ($agents !== [] && $directive === 'crawl-delay' && is_numeric($value)) {
                $hasDirectives = true;
                $delay = max(0, min(86400, (float) $value));
            }
        }
        $flush();
        $best = -1;
        $selected = [];
        foreach ($groups as $group) {
            $specificity = -1;
            foreach ($group['agents'] as $agent) {
                if ($agent === '*') {
                    $specificity = max($specificity, 0);
                } elseif ($agent !== '' && str_contains(strtolower($userAgent), $agent)) {
                    $specificity = max($specificity, strlen($agent));
                }
            }
            if ($specificity > $best) {
                $best = $specificity;
                $selected = [$group];
            } elseif ($specificity === $best && $specificity >= 0) {
                $selected[] = $group;
            }
        }

        return ['rules' => array_merge(...array_map(fn (array $group): array => $group['rules'], $selected)),
            'available' => true, 'status' => 'parsed', 'crawl_delay' => $selected === [] ? 0 : max(array_column($selected, 'crawl_delay'))];
    }

    public function allows(string $url, array $policy): bool
    {
        if (! $policy['available']) {
            return false;
        }
        $parts = parse_url($url);
        $path = $this->canonicalOctets(($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : ''));
        $allowed = true;
        $longest = -1;
        foreach ($policy['rules'] as $rule) {
            $pattern = $this->canonicalOctets($rule['pattern']);
            $anchored = str_ends_with($pattern, '$');
            if ($anchored) {
                $pattern = substr($pattern, 0, -1);
            }
            $expression = '~^'.str_replace('\\*', '.*', preg_quote($pattern, '~')).($anchored ? '$' : '').'~';
            $length = strlen(str_replace('*', '', $pattern));
            if (preg_match($expression, $path) && ($length > $longest || ($length === $longest && $rule['allow']))) {
                $longest = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    private function canonicalOctets(string $value): string
    {
        $value = preg_replace_callback('/%[0-9a-f]{2}/i', function (array $match): string {
            $character = chr(hexdec(substr($match[0], 1)));

            return preg_match('/[a-z0-9._~-]/i', $character) ? $character : strtoupper($match[0]);
        }, $value);

        return preg_replace_callback('/[\x80-\xff]/', fn (array $match): string => '%'.strtoupper(bin2hex($match[0])), $value);
    }

    private function unavailable(): array
    {
        return ['rules' => [], 'available' => false, 'status' => 'unavailable', 'crawl_delay' => 0];
    }
}
