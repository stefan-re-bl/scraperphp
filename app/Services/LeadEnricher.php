<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class LeadEnricher
{
    public function __construct(
        private readonly PublicWebsiteFetcher $fetcher,
        private readonly HtmlCommercialExtractor $extractor,
        private readonly WebsiteDiscovery $discovery,
        private readonly RobotsPolicy $robots,
        private readonly EvidenceReconciler $reconciler,
        private readonly OsmEvidenceSource $osm,
        private readonly ContactNormalizer $normalizer,
    ) {}

    public function enrich(Lead $lead, bool $force = false): Lead
    {
        $lead->refresh();
        $inputHash = $this->inputHash($lead);
        $state = DB::table('enrichment_source_states')->where('lead_id', $lead->id)->where('source', 'website')->first();
        $cached = ! $force && $state && $state->input_sha256 === $inputHash && $state->next_check_at > now()->toDateTimeString();
        $budget = new EnrichmentRequestBudget($this->fetcher);
        $observations = $this->baseObservations($lead);
        $managed = ['import', 'google_maps', 'crm'];
        $contacts = $this->baseContactMethods($lead);
        $socials = [];
        $summary = ['status' => 'unknown', 'pages' => [], 'bytes' => 0, 'page_count' => 0];
        if (! $cached) {
            $managed = array_merge($managed, ['website', 'dns_http', 'system']);
            if (blank($lead->website_url)) {
                $summary['status'] = 'unregistered';
            } else {
                try {
                    [$url] = $this->fetcher->normalizeUrl($lead->website_url);
                    $policy = $this->robots->policy($url, $budget);
                    if (! $policy['available']) {
                        $observations[] = $this->observation('website_unreachable', 'unknown', 'No se pudo verificar la política de extracción dentro de los límites de seguridad.', 'website', $url, 'robots_policy', 'unknown', 0);
                    }
                    $pending = [$url];
                    $visited = [];
                    while ($pending !== [] && $budget->pages() < max(1, min(5, (int) config('enrichment.max_pages', 5)))) {
                        $page = array_shift($pending);
                        if (isset($visited[$page])) {
                            continue;
                        }
                        $visited[$page] = true;
                        if (! $this->robots->allows($page, $policy)) {
                            $observations[] = $this->observation('website_access_restricted', 'unknown', 'La política de extracción no permite verificar esta página.', 'website', $page, 'robots_policy', 'unknown', 0, ['reason' => $policy['status']]);
                            $summary['pages'][] = ['url' => $page, 'status' => 'restricted'];

                            continue;
                        }
                        try {
                            $response = $budget->fetch($page, true, min(120000, (int) config('enrichment.max_page_bytes', 120000)), max((float) config('enrichment.domain_min_interval_seconds', 1), $policy['crawl_delay']));
                            $summary['pages'][] = ['url' => $page, 'status' => $response['status']];
                            if (! $response['successful'] || ! preg_match('/<\s*(?:!doctype|html|head|body|title|a|p|div|script|form|meta|section|h[1-6])\b/i', $response['body'])) {
                                $observations[] = $this->observation('website_unreachable', (string) $response['status'], 'No se pudo verificar contenido HTML comercial en esta página.', 'website', $page, 'http_status', 'unknown', 0);

                                continue;
                            }
                            $analysis = $this->extractor->extract($lead, $response['url'], $response['body'], $response['headers']);
                            $observations = array_merge($observations, $analysis['evidence']);
                            $observations[] = $this->observation('dns_public', 'true', 'Resolución A/AAAA exclusivamente pública; no demuestra entregabilidad de correo.', 'dns_http', $response['url'], 'public_dns_validation', 'observed', 0.9,
                                ['addresses' => $response['addresses'], 'independence_key' => 'website:'.parse_url($response['url'], PHP_URL_HOST)]);
                            if (in_array($analysis['identity']['status'], ['strong', 'registered_url'], true)) {
                                $observations[] = $this->observation('website_verified', 'true', 'Página del sitio registrado consultada sin contradicción de identidad.', 'website', $response['url'], 'http_identity_check', 'observed', $analysis['identity']['confidence']);
                                $observations[] = $this->observation('website_functional', 'true', 'Respuesta HTTP satisfactoria con HTML analizable; no se probó toda la funcionalidad del sitio.', 'website', $response['url'], 'http_html', 'observed', 0.8);
                                if (str_starts_with($response['url'], 'http://')) {
                                    $observations[] = $this->observation('http_page_observed', 'true', 'La página consultada usa HTTP; la versión HTTPS no se verificó.', 'website', $response['url'], 'url_scheme', 'observed', 0.9);
                                }
                                foreach ($analysis['contacts'] as $method => $values) {
                                    $contacts[] = $method === 'email' ? 'email_visible' : ($method === 'phone' ? 'phone_visible' : $method);
                                }
                                $socials = array_merge($socials, $analysis['social_urls']);
                                $contacts = array_merge($contacts, array_keys($analysis['social_urls']));
                                if ($page === $url) {
                                    $pending = $this->discovery->discover($url, $analysis['links']);
                                }
                                $summary['status'] = 'verified';
                            } else {
                                $summary['status'] = $analysis['identity']['status'];
                            }
                        } catch (\Throwable) {
                            $observations[] = $this->observation('website_unreachable', 'unknown', 'No se pudo obtener esta página dentro de los límites de seguridad, tiempo o frecuencia.', 'website', $page, 'bounded_http', 'unknown', 0);
                            $summary['pages'][] = ['url' => $page, 'status' => 'unavailable'];
                        }
                        if ($budget->remainingSeconds() <= 0.05 || $budget->remainingBytes() <= 0) {
                            break;
                        }
                    }
                } catch (\Throwable) {
                    $observations[] = $this->observation('website_unreachable', 'unknown', 'La dirección del sitio no pudo verificarse como destino público permitido.', 'website', 'registered_site', 'destination_validation', 'unknown', 0);
                }
            }
            $summary['bytes'] = $budget->bytes();
            $summary['page_count'] = $budget->pages();
        }
        $secondary = $this->osm->observe($lead, $budget, $force);
        if ($secondary['managed']) {
            $managed[] = 'osm';
            $observations = array_merge($observations, $secondary['observations']);
        }
        DB::transaction(function () use ($lead, $observations, $managed, $cached, $contacts, $socials, $summary, $inputHash, $secondary): void {
            $this->reconciler->apply($lead, $observations, $managed);
            if (isset($secondary['state'])) {
                DB::table('enrichment_source_states')->updateOrInsert(['lead_id' => $lead->id, 'source' => 'osm'], $secondary['state']);
            }
            $current = $lead->signalRows()->current()->where('review_status', '!=', 'rejected')->get();
            $attributes = ['signals' => $current->pluck('type')->unique()->values()->all()];
            if (! $cached) {
                $booking = $current->contains(fn ($signal) => $signal->type === 'booking_system_visible' && $signal->review_status === 'confirmed');
                $attributes += ['contact_methods' => array_values(array_unique($contacts)), 'social_urls' => $socials,
                    'booking_visible' => $booking, 'primary_cta' => $booking ? 'booking_system' : (count($contacts) > count($this->baseContactMethods($lead)) ? 'contact_or_booking' : null), 'enriched_at' => now()];
                DB::table('enrichment_source_states')->updateOrInsert(['lead_id' => $lead->id, 'source' => 'website'], [
                    'input_sha256' => $inputHash, 'last_checked_at' => now(), 'next_check_at' => now()->addHours(max(1, (int) config('enrichment.ttl_hours', 24))),
                    'result' => json_encode($summary, JSON_THROW_ON_ERROR),
                ]);
            }
            $lead->forceFill($attributes)->save();
        });

        return $lead->refresh();
    }

    /** Offline analysis reuses the extractor and reconciliation without external requests. */
    public function analyzeWebsiteContent(Lead $lead, string $url, string $html, bool $successful = true, ?int $status = null): array
    {
        $limit = max(1, min(120000, (int) config('services.enrichment.max_response_bytes', 120000), (int) config('enrichment.max_page_bytes', 120000)));
        if (strlen($html) > $limit || ! $successful) {
            $analysis = ['evidence' => [$this->observation('website_unreachable', (string) ($status ?? 'unknown'), 'Contenido no verificable dentro de los límites permitidos.', 'website', $url, 'offline_analysis', 'unknown', 0)], 'contacts' => [], 'social_urls' => []];
        } else {
            $analysis = $this->extractor->extract($lead, $url, $html);
        }
        DB::transaction(function () use ($lead, $analysis): void {
            $this->reconciler->apply($lead, $analysis['evidence'], ['website']);
            $current = $lead->signalRows()->current()->where('review_status', '!=', 'rejected')->get();
            $lead->forceFill(['signals' => $current->pluck('type')->unique()->values()->all(),
                'booking_visible' => $current->contains(fn ($row) => $row->type === 'booking_system_visible' && $row->review_status === 'confirmed'),
                'primary_cta' => isset($analysis['contacts']['form']) || isset($analysis['contacts']['whatsapp']) ? 'contact_or_booking' : null])->save();
            if ($lead->booking_visible) {
                $lead->update(['primary_cta' => 'booking_system']);
            }
        });

        return ['score_signals' => array_column($analysis['evidence'], 'type'),
            'contact_methods' => array_map(fn (string $method): string => $method === 'email' ? 'email_visible' : $method, array_keys($analysis['contacts'])), 'social_urls' => $analysis['social_urls']];
    }

    private function baseObservations(Lead $lead): array
    {
        $observations = [];
        if ($lead->phone) {
            $valid = $this->normalizer->hasComparablePhone($lead->phone) && $this->normalizer->phone($lead->phone, $lead->country) !== null;
            $observations[] = $this->observation($valid ? 'phone_format_valid' : 'phone_format_invalid', $valid ? preg_replace('/\D/', '', $lead->phone) : 'invalid',
                $valid ? 'Formato telefónico utilizable; no se comprobó disponibilidad ni titularidad.' : 'El teléfono registrado no tiene un formato utilizable.', 'import', 'phone', 'contact_normalizer', 'observed', 0.8);
            if ($valid && str_starts_with(preg_replace('/\D/', '', $lead->phone), '549')) {
                $observations[] = $this->observation('phone_likely_mobile', 'true', 'El prefijo es compatible con móvil; la disponibilidad no se comprobó.', 'import', 'phone', 'prefix', 'inferred', 0.4);
            }
        }
        if (blank($lead->website_url)) {
            $observations[] = $this->observation('no_website', 'unknown', 'No hay un dominio registrado; esto no demuestra una necesidad comercial.', 'import', 'registered_site', 'import_field', 'unknown', 0);
        }
        if (! $lead->email && ! $lead->phone) {
            $observations[] = $this->observation('hard_to_find_contact', 'unknown', 'No hay contacto registrado; no demuestra que el negocio carezca de canales públicos.', 'import', 'contact', 'import_field', 'unknown', 0);
        }
        foreach ($lead->sources()->where('source', 'google_maps')->get() as $source) {
            $observations[] = $this->observation('maps_source_record', $source->source_identifier ?: (string) $source->id, 'Identificador y procedencia conservados desde la fuente inicial de descubrimiento.', 'google_maps', 'google_maps:'.($source->source_identifier ?: $source->id), 'import_field', 'observed', 0.8,
                ['source_identifier' => $source->source_identifier, 'source_url' => $source->source_url, 'independence_key' => 'google_maps']);
        }
        if ($lead->contactAttempts()->exists() || $lead->campaigns()->exists()) {
            $observations[] = $this->observation('crm_history_available', 'true', 'Existe historial comercial propio; sus resultados no son predictores del scoring.', 'crm', 'crm:lead:'.$lead->id, 'crm_history', 'observed', 1,
                ['independence_key' => 'crm', 'not_a_score_predictor' => true]);
        }

        return $observations;
    }

    private function inputHash(Lead $lead): string
    {
        return hash('sha256', json_encode([$lead->only(['name', 'email', 'phone', 'website_url', 'location', 'city', 'country', 'category']),
            $lead->sources()->orderBy('id')->get()->map(fn ($source) => [$source->source, $source->source_identifier, $source->metadata['coordinates'] ?? null])->all()], JSON_THROW_ON_ERROR));
    }

    private function baseContactMethods(Lead $lead): array
    {
        return array_values(array_filter([$lead->email ? 'email' : null, $lead->phone ? 'phone' : null, $lead->website_url ? 'website' : null]));
    }

    private function observation(string $type, string $value, string $evidence, string $source, string $reference, string $method, string $kind, float $confidence, array $metadata = []): array
    {
        return ['type' => $type, 'value' => $value, 'evidence' => $evidence, 'source' => $source, 'source_reference' => $reference,
            'evidence_url' => str_starts_with($reference, 'http') ? $reference : null, 'extraction_method' => $method, 'evidence_kind' => $kind, 'confidence' => $confidence,
            'metadata' => ['independence_key' => $source === 'website' || $source === 'dns_http' ? 'website:'.parse_url($reference, PHP_URL_HOST) : $source] + $metadata];
    }
}
