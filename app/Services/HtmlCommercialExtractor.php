<?php

namespace App\Services;

use App\Models\Lead;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/** Extracts bounded, public observations; no script execution or remote documents. */
class HtmlCommercialExtractor
{
    public function __construct(private readonly ContactNormalizer $normalizer, private readonly SecondaryIdentityResolver $identities) {}

    public function extract(Lead $lead, string $url, string $html, array $headers = []): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<meta charset="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $evidence = [];
        $links = [];
        $contacts = [];
        $socials = [];
        $technologies = [];
        $identities = [];
        $add = function (string $type, string $value, string $description, string $method, string $kind = 'observed', float $confidence = 0.9, array $metadata = []) use (&$evidence, $url): void {
            $evidence[$type] = ['type' => $type, 'value' => mb_substr($value, 0, 2048), 'evidence' => mb_substr($description, 0, 1000),
                'source' => 'website', 'source_reference' => $url, 'evidence_url' => $url, 'extraction_method' => $method,
                'evidence_kind' => $kind, 'confidence' => $confidence, 'metadata' => ['independence_key' => 'website:'.parse_url($url, PHP_URL_HOST)] + $metadata];
        };
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $text = $this->clean($anchor->textContent);
            if (strlen($href) > 2048) {
                continue;
            }
            $links[] = ['url' => $href, 'text' => $text];
            if (str_starts_with(strtolower($href), 'mailto:')) {
                $email = $this->normalizer->email(rawurldecode(explode('?', substr($href, 7), 2)[0]));
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $contacts['email'][] = $email;
                    $add('channel_email_visible', $email, 'Email publicado en un enlace mailto.', 'mailto_link', metadata: ['original' => mb_substr($href, 0, 200)]);
                }
            } elseif (str_starts_with(strtolower($href), 'tel:')) {
                $original = rawurldecode(substr($href, 4));
                $phone = $this->normalizer->phone($original, $lead->country);
                if ($phone && $this->normalizer->hasComparablePhone($original)) {
                    $contacts['phone'][] = $phone;
                    $add('channel_phone_visible', $phone, 'Teléfono publicado en un enlace tel.', 'tel_link', metadata: ['original' => mb_substr($original, 0, 100)]);
                }
            }
            $host = $this->host($href);
            foreach (['instagram' => ['instagram.com'], 'facebook' => ['facebook.com'], 'linkedin' => ['linkedin.com']] as $network => $domains) {
                if ($this->matchesHost($host, $domains)) {
                    $socials[$network] = $href;
                    $add('social_'.$network, $href, 'Enlace público al perfil de '.$network.'.', 'anchor');
                }
            }
            if ($this->matchesHost($host, ['wa.me', 'api.whatsapp.com', 'web.whatsapp.com'])) {
                $contacts['whatsapp'][] = $href;
                $add('channel_whatsapp', 'true', 'Enlace público de WhatsApp.', 'anchor');
            }
        }
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $decoded = json_decode($script->textContent, true);
            if (! is_array($decoded)) {
                $add('structured_data_unknown', 'unknown', 'JSON-LD no interpretable; no se utilizaron sus campos.', 'json_ld', 'unknown', 0);

                continue;
            }
            foreach ($this->jsonNodes($decoded) as $node) {
                $types = (array) ($node['@type'] ?? []);
                foreach ($types as $type) {
                    if (! is_string($type)) {
                        continue;
                    }
                    $type = preg_replace('~^https?://schema.org/~', '', $type);
                    if (in_array($type, ['LocalBusiness', 'Organization', 'MedicalBusiness', 'Dentist', 'MedicalClinic', 'ProfessionalService', 'Store', 'Restaurant', 'HealthAndBeautyBusiness', 'BeautySalon', 'Physician'], true)) {
                        $address = $node['address'] ?? [];
                        $identities[] = ['name' => $this->scalar($node['name'] ?? null), 'website' => $this->scalar($node['url'] ?? null) ?? $url,
                            'phone' => $this->scalar($node['telephone'] ?? null), 'city' => is_array($address) ? $this->scalar($address['addressLocality'] ?? null) : null,
                            'address' => is_array($address) ? $this->scalar($address['streetAddress'] ?? null) : $this->scalar($address)];
                        if (is_array($address)) {
                            $parts = array_filter([$this->scalar($address['streetAddress'] ?? null), $this->scalar($address['addressLocality'] ?? null), $this->scalar($address['addressRegion'] ?? null)]);
                            if ($parts !== []) {
                                $add('address_published', implode(', ', $parts), 'Dirección publicada en datos estructurados.', 'json_ld');
                            }
                        }
                    }
                    foreach (array_slice((array) ($node['sameAs'] ?? []), 0, 10) as $socialUrl) {
                        if (! is_string($socialUrl) || strlen($socialUrl) > 2048) {
                            continue;
                        }
                        foreach (['instagram' => ['instagram.com'], 'facebook' => ['facebook.com'], 'linkedin' => ['linkedin.com']] as $network => $domains) {
                            if ($this->matchesHost($this->host($socialUrl), $domains)) {
                                $socials[$network] = $socialUrl;
                                $add('social_'.$network, $socialUrl, 'Perfil público declarado en datos estructurados.', 'json_ld_same_as');
                            }
                        }
                    }
                    if ($type === 'Product') {
                        $add('products_visible', 'true', 'Producto publicado en datos estructurados.', 'json_ld');
                        $add('product_sales', 'true', 'Producto con información comercial pública.', 'json_ld');
                        $add('catalog_visible', 'true', 'Catálogo con producto estructurado.', 'json_ld');
                    }
                    if ($type === 'Service' && is_string($node['name'] ?? null)) {
                        $add('service_offered', $this->clean($node['name']), 'Servicio publicado: '.$this->clean($node['name']), 'json_ld');
                    }
                    if (in_array($type, ['Organization', 'LocalBusiness', 'ContactPoint', 'MedicalBusiness', 'ProfessionalService', 'Dentist', 'Physician', 'MedicalClinic'], true)) {
                        if (is_string($node['email'] ?? null) && ($email = $this->normalizer->email($node['email'])) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $contacts['email'][] = $email;
                            $add('channel_email_visible', $email, 'Email publicado en datos estructurados.', 'json_ld');
                        }
                        if (is_string($node['telephone'] ?? null) && ($phone = $this->normalizer->phone($node['telephone'], $lead->country)) && $this->normalizer->hasComparablePhone($node['telephone'])) {
                            $contacts['phone'][] = $phone;
                            $add('channel_phone_visible', $phone, 'Teléfono publicado en datos estructurados.', 'json_ld');
                        }
                    }
                }
            }
        }
        foreach ($xpath->query('//meta[@name="generator"]') as $meta) {
            foreach (config('enrichment.technologies', []) as $name => $signatures) {
                if (isset($signatures['generator']) && str_starts_with(strtolower($meta->getAttribute('content')), strtolower($signatures['generator']))) {
                    $technologies[$name] = 'generator_meta';
                }
            }
        }
        $resources = [];
        foreach ($xpath->query('//script[@src]|//link[@href]|//iframe[@src]') as $resource) {
            $resources[] = ['url' => $resource->getAttribute($resource->hasAttribute('src') ? 'src' : 'href'), 'element' => $resource->tagName];
        }
        foreach (config('enrichment.technologies', []) as $name => $signatures) {
            foreach ($resources as $resource) {
                $host = $this->host($resource['url']);
                if ($this->matchesHost($host, $signatures['resource_hosts'] ?? [])) {
                    $technologies[$name] = 'resource_host';
                }
                $path = parse_url($resource['url'], PHP_URL_PATH) ?: '';
                if ($resource['element'] === 'iframe' && $this->matchesHost($host, $signatures['booking_hosts'] ?? [])
                    && $this->bookingPathMatches($path, $signatures)) {
                    $technologies[$name] = 'booking_embed';
                }
                foreach ($signatures['resource_paths'] ?? [] as $needle) {
                    if (str_contains($path, $needle)) {
                        $technologies[$name] = 'resource_path';
                    }
                }
            }
            foreach ($headers as $header => $values) {
                if (in_array(strtolower($header), $signatures['headers'] ?? [], true)) {
                    $technologies[$name] = 'http_header';
                }
            }
            foreach ($xpath->query('//*[@class]') as $element) {
                if (array_intersect(preg_split('/\s+/', $element->getAttribute('class')), $signatures['classes'] ?? []) !== []) {
                    $technologies[$name] = 'dom_class';
                }
                if (array_intersect(preg_split('/\s+/', $element->getAttribute('class')), $signatures['booking_classes'] ?? []) !== []) {
                    $technologies[$name] = 'dom_booking';
                }
            }
            foreach ($links as $link) {
                if ($this->matchesHost($this->host($link['url']), $signatures['booking_hosts'] ?? []) && $this->bookingPathMatches(parse_url($link['url'], PHP_URL_PATH) ?: '', $signatures)) {
                    $technologies[$name] = 'booking_link';
                }
            }
        }
        $bookingPlatforms = array_intersect(array_keys(array_filter($technologies, fn (string $method): bool => in_array($method, ['booking_link', 'booking_embed', 'dom_booking'], true))), ['calendly', 'doctoralia', 'agendapro', 'simplybook', 'google_calendar', 'bookly']);
        foreach ($technologies as $technology => $method) {
            $add('technology_'.$technology, 'true', 'Firma observable de '.$technology.'.', $method);
        }
        if ($technologies !== []) {
            $add('technology_detected', implode(',', array_keys($technologies)), 'Tecnologías con firmas observables: '.implode(', ', array_keys($technologies)), 'signature_catalog');
        }
        if ($bookingPlatforms !== []) {
            $add('booking_system_visible', implode(',', $bookingPlatforms), 'Enlace o recurso de reserva: '.implode(', ', $bookingPlatforms), 'platform_signature');
            $add('booking_business', 'true', 'Sistema público de reservas identificado.', 'platform_signature');
        }
        foreach ($xpath->query('//form') as $form) {
            $fields = [];
            foreach ($xpath->query('.//input|.//textarea', $form) as $input) {
                $fields[] = strtolower($input->tagName.' '.$input->getAttribute('name').' '.$input->getAttribute('type'));
            }
            if (preg_match('/message|mensaje|consulta|contact|telefono|phone|textarea/', implode(' ', $fields).' '.$form->getAttribute('action'))) {
                $contacts['form'][] = 'true';
                $add('channel_form', 'true', 'Formulario con campos de contacto o consulta.', 'form_fields');
            }
        }
        foreach ($xpath->query('//h1|//h2|//h3') as $heading) {
            if (preg_match('/^(?:nuestros )?(?:servicios|services|especialidades|tratamientos)$/i', $this->clean($heading->textContent))) {
                $sibling = $heading->nextSibling;
                $steps = 0;
                while ($sibling && $steps++ < 5) {
                    if ($sibling instanceof DOMElement && in_array($sibling->tagName, ['ul', 'ol', 'div', 'section'], true)) {
                        $services = [];
                        foreach ($xpath->query('.//li|.//h3|.//h4', $sibling) as $service) {
                            $name = $this->clean($service->textContent);
                            if ($name !== '' && mb_strlen($name) <= 160) {
                                $services[] = $name;
                            }
                        }
                        if ($services !== []) {
                            $services = array_slice(array_unique($services), 0, 8);
                            $add('service_offered', implode(', ', $services), 'Servicios publicados en una sección explícita.', 'dom_services', metadata: ['normalized_values' => $services]);
                        }
                        break;
                    }
                    $sibling = $sibling->nextSibling;
                }
            }
        }
        $clone = clone $document;
        $visible = new DOMXPath($clone);
        foreach ($visible->query('//script|//style|//noscript|//*[@hidden]|//*[@aria-hidden="true"]') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $visibleText = $this->clean($clone->textContent);
        preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $visibleText, $emails);
        foreach (array_slice($emails[0], 0, 10) as $email) {
            $email = $this->normalizer->email($email);
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $contacts['email'][] = $email;
                $add('channel_email_visible', $email, 'Email publicado en texto visible.', 'visible_text');
            }
        }
        $body = Str::ascii(mb_strtolower($visibleText));
        foreach (['turnos por whatsapp', 'turno por whatsapp', 'pedir turno por whatsapp', 'solicitar turno por whatsapp', 'llamar para turnos', 'agenda por whatsapp', 'reservas por whatsapp'] as $phrase) {
            if (str_contains($body, $phrase)) {
                $add('manual_booking_dependency', 'true', 'Texto público: '.$phrase.'. La dependencia requiere revisión.', 'visible_text', 'inferred', 0.6);
                $add('manual_contact_dependency', 'true', 'Texto público: '.$phrase.'. La dependencia requiere revisión.', 'visible_text', 'inferred', 0.6);
                $add('booking_business', 'true', 'El sitio describe cómo solicitar una reserva.', 'visible_text', 'inferred', 0.7);
                break;
            }
        }
        if (preg_match('/\b(?:equipo de profesionales|varias especialidades|nuestras sedes)\b/', $body)) {
            $add('operational_complexity', 'true', 'El sitio declara equipo, especialidades o sedes.', 'visible_text', 'inferred', 0.6);
        }
        foreach ($links as $link) {
            $label = Str::ascii(mb_strtolower($link['text']));
            if (preg_match('/\b(?:reservar online|reserva online|turno online|agenda online)\b/', $label)) {
                $add('booking_business', 'true', 'Enlace explícito para una reserva online.', 'anchor_text');
                $add('booking_system_visible', 'generic', 'Enlace explícito para una reserva online; plataforma desconocida.', 'anchor_text', 'observed', 0.8);
            }
            if (preg_match('/\b(?:catalogo|productos|tienda|shop)\b/', $label)) {
                $add('catalog_visible', 'true', 'Enlace comercial a catálogo, productos o tienda.', 'anchor_text', 'observed', 0.8);
            }
            if (preg_match('/\b(?:comprar|anadir al carrito|agregar al carrito|checkout|buy now)\b/', $label)) {
                $add('online_purchase_visible', 'true', 'Acción de compra online publicada.', 'anchor_text');
                $add('ecommerce_visible', 'true', 'Acción de compra online publicada.', 'anchor_text');
            }
        }
        foreach ($xpath->query('//button|//input[@type="submit"]') as $button) {
            $label = Str::ascii(mb_strtolower($button instanceof DOMElement && $button->tagName === 'input' ? $button->getAttribute('value') : $button->textContent));
            if (preg_match('/\b(?:comprar|agregar al carrito|anadir al carrito|buy now|add to cart)\b/', $label)) {
                $add('online_purchase_visible', 'true', 'Control de compra online observable.', 'dom_control');
                $add('ecommerce_visible', 'true', 'Control de compra online observable.', 'dom_control');
            }
        }
        foreach (['email' => 'channel_email_visible', 'phone' => 'channel_phone_visible'] as $method => $type) {
            if (isset($contacts[$method], $evidence[$type])) {
                $values = array_values(array_unique($contacts[$method]));
                sort($values);
                $evidence[$type]['value'] = $values[0];
                $evidence[$type]['metadata']['normalized_values'] = $values;
            }
        }
        $disagreements = [];
        if (isset($contacts['phone']) && $lead->phone && ($registeredPhone = $this->normalizer->phone($lead->phone, $lead->country))
            && ! in_array($registeredPhone, $contacts['phone'], true)) {
            $disagreements['phone'] = ['registered' => $registeredPhone, 'published' => array_slice(array_unique($contacts['phone']), 0, 5)];
        }
        if (isset($contacts['email']) && $lead->email && ! in_array(strtolower($lead->email), $contacts['email'], true)) {
            $disagreements['email'] = ['registered' => strtolower($lead->email), 'published' => array_slice(array_unique($contacts['email']), 0, 5)];
        }
        if ($disagreements !== []) {
            $add('contact_data_conflict', 'conflict', 'Los contactos públicos difieren de los registrados; pueden ser canales adicionales y requieren revisión.', 'source_comparison', 'conflict', 0,
                ['observations' => $disagreements]);
        }
        $resolutions = array_map(fn (array $candidate): array => $this->identities->compare($lead, $candidate, true), $identities);
        $conflict = collect($resolutions)->first(fn (array $resolution): bool => $resolution['status'] === 'conflict');
        $ambiguous = collect($resolutions)->first(fn (array $resolution): bool => $resolution['status'] === 'ambiguous');
        $identity = $conflict ?? $ambiguous ?? (collect($resolutions)->sortByDesc('confidence')->first() ?? $this->identities->compare($lead, [], true));
        if (in_array($identity['status'], ['conflict', 'ambiguous'], true)) {
            $evidence = array_filter($evidence, fn (array $item): bool => str_starts_with($item['type'], 'technology_'));
            $add('identity_conflict', $identity['status'], implode(' ', $identity['reasons']), 'identity_comparison', $identity['status'] === 'conflict' ? 'conflict' : 'unknown', 0,
                ['identity_status' => $identity['status'], 'observations' => array_slice($identities, 0, 5)]);
            $contacts = $socials = [];
        } else {
            foreach ($evidence as &$item) {
                $item['confidence'] = min($item['confidence'], $identity['confidence']);
                $item['metadata']['identity_status'] = $identity['status'];
            }
            unset($item);
        }

        return ['evidence' => array_values($evidence), 'links' => array_slice($links, 0, 200), 'contacts' => $contacts, 'social_urls' => $socials, 'identity' => $identity];
    }

    private function jsonNodes(array $node, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }
        $nodes = isset($node['@type']) ? [$node] : [];
        foreach ($node as $key => $value) {
            if (is_array($value) && (in_array($key, ['@graph', 'contactPoint', 'hasOfferCatalog', 'itemListElement', 'itemOffered', 'makesOffer', 'offers', 'hasPart'], true) || array_is_list($node))) {
                $nodes = array_merge($nodes, $this->jsonNodes($value, $depth + 1));
            }
        }

        return array_slice($nodes, 0, 100);
    }

    private function host(string $url): string
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        $parts = parse_url($url);

        return ! isset($parts['user']) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ? strtolower(rtrim($parts['host'] ?? '', '.')) : '';
    }

    private function matchesHost(string $host, array $domains): bool
    {
        return $host !== '' && collect($domains)->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain));
    }

    private function bookingPathMatches(string $path, array $signatures): bool
    {
        return ! isset($signatures['booking_paths']) || collect($signatures['booking_paths'])->contains(fn (string $needle): bool => str_contains($path, $needle));
    }

    private function scalar(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? mb_substr($this->clean((string) $value), 0, 500) : null;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_convert_encoding($value, 'UTF-8', 'UTF-8')));
    }
}
