<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/** Optional, bounded Overpass lookup. No geocoding or name searches. */
class OsmEvidenceSource
{
    public function __construct(private readonly SecondaryIdentityResolver $identities, private readonly PublicWebsiteFetcher $fetcher, private readonly ContactNormalizer $normalizer) {}

    public function observe(Lead $lead, EnrichmentRequestBudget $budget, bool $force = false): array
    {
        if (! config('enrichment.osm.enabled', false)) {
            return ['observations' => [], 'managed' => false, 'status' => 'disabled'];
        }
        $endpoint = config('enrichment.osm.endpoint');
        $userAgent = config('enrichment.osm.user_agent');
        $coordinates = $lead->sources()->orderBy('id')->get()->map(fn ($source) => $source->metadata['coordinates'] ?? null)->first(fn ($value) => is_array($value));
        if (! config('enrichment.osm.terms_acknowledged') || ! is_string($endpoint) || ! is_string($userAgent)
            || ! preg_match('/(?:https?:\/\/|@)/', $userAgent) || ! $this->validCoordinates($coordinates)) {
            return ['observations' => [$this->unknown('configuration_or_coordinates', 'La fuente complementaria requiere configuración, identificación y coordenadas válidas.')], 'managed' => true, 'status' => 'unconfigured'];
        }
        $hash = hash('sha256', json_encode([$lead->name, $lead->phone, $lead->website_url, $lead->city, $lead->location, $coordinates, $endpoint], JSON_THROW_ON_ERROR));
        $state = DB::table('enrichment_source_states')->where('lead_id', $lead->id)->where('source', 'osm')->first();
        if (! $force && $state && $state->input_sha256 === $hash && $state->next_check_at > now()->toDateTimeString()) {
            return ['observations' => [], 'managed' => false, 'status' => 'cached'];
        }
        try {
            $this->fetcher->normalizeUrl($endpoint);
            if (parse_url($endpoint, PHP_URL_QUERY) !== null || ! str_ends_with(parse_url($endpoint, PHP_URL_PATH) ?: '', '/interpreter')) {
                throw new \RuntimeException('Endpoint Overpass no válido.');
            }
            $radius = max(1, min(150, (int) config('enrichment.osm.radius_metres', 100)));
            $lat = (float) $coordinates['latitude'];
            $lon = (float) $coordinates['longitude'];
            $query = '[out:json][timeout:5][maxsize:1048576];nwr(around:'.$radius.','.$lat.','.$lon.')["name"][~"^(amenity|shop|office)$"~"."];out center 20;';
            $response = $budget->fetch($endpoint.'?data='.rawurlencode($query), false, 120000,
                max(60, (int) config('enrichment.osm.min_interval_seconds', 60)), $userAgent);
            if (! $response['successful']) {
                throw new \RuntimeException('Fuente complementaria temporalmente no disponible.');
            }
            $data = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
            $matches = [];
            $conflicts = [];
            foreach (array_slice($data['elements'] ?? [], 0, 20) as $element) {
                $tags = $element['tags'] ?? [];
                if (! is_array($tags) || ! is_string($tags['name'] ?? null)) {
                    continue;
                }
                $candidate = ['name' => $tags['name'], 'phone' => $this->scalar($tags['contact:phone'] ?? $tags['phone'] ?? null),
                    'website' => $this->scalar($tags['contact:website'] ?? $tags['website'] ?? null), 'city' => $this->scalar($tags['addr:city'] ?? null),
                    'address' => $this->scalar($tags['addr:street'] ?? null) && $this->scalar($tags['addr:housenumber'] ?? null) ? $this->scalar($tags['addr:street']).' '.$this->scalar($tags['addr:housenumber']) : null];
                $resolution = $this->identities->compare($lead, $candidate);
                if ($resolution['status'] === 'conflict' && $candidate['website']
                    && $this->normalizer->domain($candidate['website']) === $this->normalizer->domain($lead->website_url)) {
                    $conflicts[] = ['candidate' => $candidate, 'reasons' => $resolution['reasons']];
                }

                $elementLat = $element['lat'] ?? $element['center']['lat'] ?? null;
                $elementLon = $element['lon'] ?? $element['center']['lon'] ?? null;
                if ($resolution['status'] === 'strong' && is_numeric($elementLat) && is_numeric($elementLon)
                    && $this->distance($lat, $lon, (float) $elementLat, (float) $elementLon) <= $radius
                    && in_array($element['type'] ?? '', ['node', 'way', 'relation'], true) && is_numeric($element['id'] ?? null)) {
                    $matches[] = ['reference' => 'https://www.openstreetmap.org/'.$element['type'].'/'.(int) $element['id'], 'candidate' => $candidate, 'tags' => $tags];
                }
            }
            $observations = [];
            if (count($matches) === 1) {
                $match = $matches[0];
                $observations[] = $this->observation('osm_identity_verified', 'true', 'Nombre e identificadores compatibles dentro del radio geográfico aprobado.', $match['reference']);
                if ($match['candidate']['address']) {
                    $observations[] = $this->observation('address_published', $match['candidate']['address'], 'Dirección pública corroborada en OpenStreetMap.', $match['reference']);
                }
                foreach (['phone' => 'channel_phone_visible', 'website' => 'website_reference_public'] as $field => $type) {
                    if (is_string($match['candidate'][$field]) && $match['candidate'][$field] !== '') {
                        $normalized = $field === 'phone' ? $this->normalizer->phone($match['candidate'][$field], $lead->country) : $this->normalizer->url($match['candidate'][$field]);
                        if (! $normalized) {
                            continue;
                        }
                        $observations[] = $this->observation($type, mb_substr($normalized, 0, 500), 'Dato público de la entidad corroborada en OpenStreetMap.', $match['reference']);
                    }
                }
            } else {
                $observations[] = $this->unknown(count($matches) > 1 ? 'ambiguous' : 'unmatched', 'La consulta no produjo una correspondencia única y suficientemente sólida.');
            }
            if ($conflicts !== []) {
                $observations[] = ['type' => 'identity_conflict', 'value' => 'conflict', 'evidence' => 'Una referencia de OpenStreetMap comparte el dominio pero contradice el nombre o ubicación registrados.',
                    'source' => 'osm', 'source_reference' => 'osm:identity_comparison', 'extraction_method' => 'identity_comparison', 'evidence_kind' => 'conflict', 'confidence' => 0,
                    'metadata' => ['observations' => array_slice($conflicts, 0, 3), 'independence_key' => 'openstreetmap', 'identity_status' => 'conflict']];
            }
            $status = count($matches) === 1 ? 'matched' : 'unknown';
        } catch (\Throwable) {
            $observations = [$this->unknown('unavailable', 'No se pudo verificar esta fuente complementaria dentro de los límites configurados.')];
            $status = 'unavailable';
        }
        $stateUpdate = ['input_sha256' => $hash, 'last_checked_at' => now(),
            'next_check_at' => now()->addHours($status === 'matched' ? max(24, (int) config('enrichment.osm.ttl_hours', 168)) : 1),
            'result' => json_encode(['status' => $status], JSON_THROW_ON_ERROR)];

        return ['observations' => $observations, 'managed' => true, 'status' => $status, 'state' => $stateUpdate];
    }

    private function scalar(mixed $value): ?string
    {
        return is_string($value) || is_numeric($value) ? mb_substr((string) $value, 0, 500) : null;
    }

    private function validCoordinates(?array $coordinates): bool
    {
        return is_numeric($coordinates['latitude'] ?? null) && is_numeric($coordinates['longitude'] ?? null)
            && abs((float) $coordinates['latitude']) <= 90 && abs((float) $coordinates['longitude']) <= 180;
    }

    private function distance(float $lat, float $lon, float $otherLat, float $otherLon): float
    {
        $latitude = deg2rad($otherLat - $lat);
        $longitude = deg2rad($otherLon - $lon);
        $a = sin($latitude / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($otherLat)) * sin($longitude / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    private function observation(string $type, string $value, string $evidence, string $reference): array
    {
        return ['type' => $type, 'value' => $value, 'evidence' => $evidence, 'source' => 'osm', 'source_reference' => $reference,
            'evidence_url' => $reference, 'extraction_method' => 'overpass_geographic_match', 'evidence_kind' => 'observed', 'confidence' => 0.9,
            'metadata' => ['independence_key' => 'openstreetmap', 'identity_status' => 'strong', 'attribution' => '© OpenStreetMap contributors', 'license' => 'ODbL', 'license_url' => 'https://www.openstreetmap.org/copyright']];
    }

    private function unknown(string $reason, string $evidence): array
    {
        return ['type' => 'osm_unknown', 'value' => 'unknown', 'evidence' => $evidence, 'source' => 'osm', 'source_reference' => 'osm:lookup',
            'extraction_method' => 'overpass_geographic_match', 'evidence_kind' => 'unknown', 'confidence' => 0,
            'metadata' => ['reason' => $reason, 'independence_key' => 'openstreetmap']];
    }
}
