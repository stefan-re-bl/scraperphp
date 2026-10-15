<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A per-lead deadline plus a shared, SQLite-backed lease per destination host. */
class EnrichmentRequestBudget
{
    private float $started;

    private int $bytes = 0;

    private int $pages = 0;

    public function __construct(private readonly PublicWebsiteFetcher $fetcher)
    {
        $this->started = microtime(true);
    }

    public function remainingSeconds(): float
    {
        return max(0, min(30, (float) config('enrichment.max_total_seconds', 25)) - (microtime(true) - $this->started));
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function remainingBytes(): int
    {
        return max(0, min(600000, (int) config('enrichment.max_total_bytes', 600000)) - $this->bytes);
    }

    public function fetch(string $url, bool $page = true, ?int $byteLimit = null, ?float $minInterval = null, ?string $userAgent = null): array
    {
        if ($this->remainingSeconds() <= 0.05 || $this->remainingBytes() <= 0
            || ($page && $this->pages >= max(1, min(5, (int) config('enrichment.max_pages', 5))))) {
            throw new \RuntimeException('Se agotó el presupuesto de enriquecimiento.');
        }
        [, $host] = $this->fetcher->normalizeUrl($url);
        $key = hash('sha256', 'host:'.$host);
        $minInterval ??= max(0, (float) config('enrichment.domain_min_interval_seconds', 1));
        $state = DB::table('enrichment_request_states')->where('key', $key)->first();
        if ($state && $state->last_started_at) {
            $delay = Carbon::parse($state->last_started_at)->valueOf() / 1000 + $minInterval - now()->valueOf() / 1000;
            if ($delay > 0) {
                if ($delay > min(1.1, $this->remainingSeconds())) {
                    throw new \RuntimeException('La fuente está limitada por frecuencia; vuelve a intentarlo más tarde.');
                }
                usleep((int) ceil($delay * 1000000));
            }
        }
        $timeout = max(0.05, min(8, (float) config('enrichment.request_timeout_seconds', 8), $this->remainingSeconds()));
        $token = Str::uuid()->toString();
        $acquired = DB::transaction(function () use ($key, $timeout, $minInterval, $token): bool {
            DB::table('enrichment_request_states')->insertOrIgnore(['key' => $key]);

            return DB::table('enrichment_request_states')->where('key', $key)->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->where(fn ($q) => $q->whereNull('last_started_at')->orWhere('last_started_at', '<=', now()->subMilliseconds((int) ceil($minInterval * 1000))->format('Y-m-d H:i:s.u')))
                ->update(['lease_until' => now()->addSeconds((int) ceil($timeout) + 1), 'lease_token' => $token, 'last_started_at' => now()->format('Y-m-d H:i:s.u')]) === 1;
        });
        if (! $acquired) {
            throw new \RuntimeException('La fuente ya tiene una solicitud en curso.');
        }
        if ($page) {
            $this->pages++;
        }
        $limit = min($this->remainingBytes(), $byteLimit ?? min(120000, (int) config('enrichment.max_page_bytes', 120000)));
        $this->bytes += $limit;
        try {
            $result = $this->fetcher->fetch($url, $timeout, $limit, $userAgent ?? (string) config('enrichment.user_agent', 'CRTWebEvidenceBot/1.0'));
            $this->bytes -= $limit - $result['bytes'];

            return $result;
        } finally {
            DB::table('enrichment_request_states')->where('key', $key)->where('lease_token', $token)->update(['lease_until' => null, 'lease_token' => null]);
        }
    }
}
