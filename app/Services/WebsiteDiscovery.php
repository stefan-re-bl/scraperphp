<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Str;

class WebsiteDiscovery
{
    /** @param list<array{url: string, text: string}> $links
     * @return list<string>
     */
    public function discover(string $base, array $links): array
    {
        $origin = new Uri($base);
        $found = [];
        foreach (array_slice($links, 0, 200) as $link) {
            try {
                $url = UriResolver::resolve($origin, new Uri($link['url']))->withFragment('');
            } catch (\Throwable) {
                continue;
            }
            if ($url->getScheme() !== $origin->getScheme() || $url->getHost() !== $origin->getHost()
                || $url->getPort() !== $origin->getPort() || $url->getUserInfo() !== '' || $url->getQuery() !== ''
                || (string) $url === (string) $origin || preg_match('~\.(?:pdf|zip|png|jpe?g|gif|svg|webp|mp4|mp3|css|js|xml|docx?|xlsx?)$~i', $url->getPath())) {
                continue;
            }
            $text = Str::ascii(mb_strtolower($url->getPath().' '.$link['text']));
            if (preg_match('~(?:login|logout|wp-admin|carrito|checkout|privacidad|privacy|cookies|calendar/\d|page/\d|fecha|sesion)~', $text)) {
                continue;
            }
            $priority = null;
            foreach (['contact|contacto', 'servicio|service|especialidad', 'reserva|turno|booking|agenda', 'tienda|shop|producto|product|catalogo|catalog'] as $index => $terms) {
                if (preg_match('~'.$terms.'~', $text)) {
                    $priority = $index;
                    break;
                }
            }
            if ($priority !== null) {
                $found[(string) $url] = $priority;
            }
        }
        asort($found, SORT_NUMERIC);

        return array_slice(array_keys($found), 0, 4);
    }
}
