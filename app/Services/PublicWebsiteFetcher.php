<?php

namespace App\Services;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class PublicWebsiteFetcher
{
    public function __construct(private readonly PublicDnsResolver $resolver) {}

    /** @return array{url: string, body: string, successful: bool, status: int} */
    public function fetch(string $input, ?float $timeout = null, ?int $maxBytes = null, ?string $userAgent = null): array
    {
        [$url, $host, $port] = $this->normalizeUrl($input);
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);
        if ($addresses === [] || count(array_filter($addresses, fn (string $address): bool => ! $this->isPublicAddress($address))) > 0) {
            throw new \RuntimeException('El sitio no resuelve exclusivamente a direcciones públicas permitidas.');
        }
        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new \RuntimeException('El transporte disponible no permite fijar un destino público seguro.');
        }

        $limit = max(1, min(120000, (int) config('services.enrichment.max_response_bytes', 120000), $maxBytes ?? 120000));
        $timeout = max(0.05, min(8, $timeout ?? 8));
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        $resolve = filter_var($host, FILTER_VALIDATE_IP) ? [] : ["{$host}:{$port}:{$address}"];
        $sink = new LimitedWebsiteStream($limit);
        try {
            $response = Http::timeout($timeout)->connectTimeout(min(3, $timeout))->setHandler(function (RequestInterface $request, array $options) use ($sink): PromiseInterface {
                $options['sink'] = $sink;

                return (new CurlHandler)($request, $options);
            })->withHeaders(['Accept-Encoding' => 'identity', 'User-Agent' => $userAgent ?? (string) config('enrichment.user_agent', 'CRTWebEvidenceBot/1.0')])->withOptions([
                // CurlHandler is mandatory: another handler may ignore CURLOPT_RESOLVE.
                'proxy' => '',
                'allow_redirects' => false,
                'decode_content' => false,
                'verify' => true,
                'idn_conversion' => false,
                'curl' => [CURLOPT_RESOLVE => $resolve],
                'on_headers' => function (ResponseInterface $response) use ($limit): void {
                    $this->assertTextContent($response->getHeaderLine('Content-Type'));
                    if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                        throw new \RuntimeException('El contenido del sitio supera el límite permitido.');
                    }
                },
            ])->get($url);
            // Fake transports have no streaming sink; they still obey the response limit.
            $this->assertTextContent($response->header('Content-Type'));
            $body = $response->body();
            if (str_contains($body, "\0")) {
                throw new \RuntimeException('La respuesta no es contenido textual permitido.');
            }
            if (strlen($body) > $limit) {
                throw new \RuntimeException('El contenido del sitio supera el límite permitido.');
            }
            if ($response->header('Content-Encoding') && strtolower($response->header('Content-Encoding')) !== 'identity') {
                throw new \RuntimeException('El sitio devolvió contenido comprimido que no puede analizarse con seguridad.');
            }

            return ['url' => $url, 'body' => $body, 'successful' => $response->successful(), 'status' => $response->status(), 'headers' => $response->headers(), 'addresses' => $addresses, 'bytes' => strlen($body)];
        } catch (\Throwable $exception) {
            // HTTP errors can contain complete URLs, credentials or internal diagnostics.
            throw new \RuntimeException('No se pudo obtener el sitio dentro de los límites de seguridad.', 0, $exception);
        } finally {
            $sink->close();
        }
    }

    private function assertTextContent(?string $type): void
    {
        $type = strtolower(trim(explode(';', $type ?? '')[0]));
        if ($type !== '' && ! in_array($type, ['text/html', 'application/xhtml+xml', 'text/plain', 'application/json'], true)) {
            throw new \RuntimeException('La respuesta no es contenido textual permitido.');
        }
    }

    /** @return array{string, string, int} */
    public function normalizeUrl(string $input): array
    {
        $input = trim($input);
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $input)) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\\\\]/', $input)) {
            throw new \RuntimeException('La dirección del sitio no es una URL HTTP o HTTPS válida.');
        }
        $host = strtolower(rtrim(trim($parts['host'] ?? '', '[]'), '.'));
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            if (function_exists('idn_to_ascii')) {
                $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
            }
            if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)
                || $host === 'localhost' || str_ends_with($host, '.localhost')) {
                throw new \RuntimeException('El host del sitio no es un destino público válido.');
            }
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new \RuntimeException('El sitio debe usar un puerto web estándar.');
        }
        $authority = str_contains($host, ':') ? '['.$host.']' : $host;
        $url = $scheme.'://'.$authority.(isset($parts['port']) ? ':'.$port : '').($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        return [$url, $host, $port];
    }

    public function isPublicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $packed = inet_pton($address);
        $blocked = strlen($packed) === 4
            ? ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']
            : ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        // Only native IPv6 global unicast. Reject mapped IPv4, NAT64, multicast,
        // ULA and link-local even when PHP's reserved-range list is incomplete.
        if (strlen($packed) === 16 && (ord($packed[0]) & 0xE0) !== 0x20) {
            return false;
        }
        foreach ($blocked as $network) {
            [$prefix, $bits] = explode('/', $network);
            $prefix = inet_pton($prefix);
            $whole = intdiv((int) $bits, 8);
            $remaining = (int) $bits % 8;
            if (substr($packed, 0, $whole) === substr($prefix, 0, $whole)
                && ($remaining === 0 || (ord($packed[$whole]) & (0xFF << (8 - $remaining))) === (ord($prefix[$whole]) & (0xFF << (8 - $remaining))))) {
                return false;
            }
        }

        return true;
    }
}
