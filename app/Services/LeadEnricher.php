<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Signal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class LeadEnricher
{
    private const BOOKING_SYSTEMS = [
        'calendly' => ['calendly'],
        'doctoralia' => ['doctoralia'],
        'agendapro' => ['agendapro', 'agenda pro'],
        'turnosweb' => ['turnosweb', 'turnos web'],
        'bookly' => ['bookly'],
        'simplybook' => ['simplybook', 'simply book'],
        'google_calendar' => ['calendar.google', 'google calendar'],
    ];

    private const MANUAL_BOOKING_TERMS = [
        'turnos por whatsapp',
        'turno por whatsapp',
        'pedir turno por whatsapp',
        'solicitar turno por whatsapp',
        'llamar para turnos',
        'turnos al',
        'agenda por whatsapp',
        'contactanos para reservar',
    ];

    public function enrich(Lead $lead): Lead
    {
        $signals = [];
        $contactMethods = $this->baseContactMethods($lead);
        $socialUrls = $lead->social_urls ?? [];

        $this->enrichPhone($lead, $signals);

        if (blank($lead->website_url)) {
            $this->addSignal($lead, $signals, 'no_website', 'true', 'No hay sitio web en los datos disponibles.', 'import');
        } else {
            $url = $this->normalizedUrl($lead->website_url);

            if (! str_starts_with($url, 'https://')) {
                $this->addSignal($lead, $signals, 'no_https', 'true', $url, 'website');
            }

            if (app()->environment('testing')) {
                $lead->primary_cta = null;
            } else {
                try {
                    $response = Http::timeout(8)->get($url);
                    $analysis = $this->analyzeWebsiteContent($lead, $url, (string) $response->body(), $response->successful(), $response->status());
                    $signals = array_merge($signals, $analysis['score_signals']);
                    $contactMethods = array_values(array_unique(array_merge($contactMethods, $analysis['contact_methods'])));
                    $socialUrls = array_merge($socialUrls, $analysis['social_urls']);
                } catch (\Throwable $exception) {
                    $this->addSignal($lead, $signals, 'website_unreachable', 'error', $exception->getMessage(), 'website');
                }
            }
        }

        if ($lead->phone && ! $lead->booking_visible) {
            $this->addSignal($lead, $signals, 'manual_contact_dependency', 'true', 'Tiene teléfono y no hay sistema de reserva visible.', 'system');
        }

        if (! $lead->email && ! $lead->phone) {
            $this->addSignal($lead, $signals, 'hard_to_find_contact', 'true', 'No hay email ni teléfono importado.', 'import');
        }

        $lead->forceFill([
            'contact_methods' => array_values(array_unique($contactMethods)),
            'social_urls' => array_filter($socialUrls),
            'signals' => array_values(array_unique(array_merge($lead->signals ?? [], $signals))),
            'enriched_at' => Carbon::now(),
        ])->save();

        return $lead->refresh();
    }

    public function analyzeWebsiteContent(Lead $lead, string $url, string $html, bool $successful = true, ?int $status = null): array
    {
        $signals = [];
        $contactMethods = [];
        $socialUrls = [];
        $body = mb_strtolower(substr(strip_tags($html), 0, 120000));
        $raw = mb_strtolower(substr($html, 0, 120000));

        if (! $successful) {
            $this->addSignal($lead, $signals, 'website_unreachable', (string) $status, $url, 'website');
        }

        $booking = $this->containsAny($body, ['turno', 'reserva', 'agenda']);
        $systems = $this->bookingSystems($raw);
        $visibleBooking = $systems !== [] || $this->containsAny($body, ['reservar online', 'agenda online', 'turno online']);
        $hasContact = $this->containsAny($body, ['contact', 'whatsapp', '@', 'teléfono', 'telefono']);
        $clearCta = $this->containsAny($body, ['reserv', 'consult', 'contact', 'pedir turno', 'solicitar turno']);
        $manualBooking = $this->containsAny($body, self::MANUAL_BOOKING_TERMS);
        $complexOperation = $this->containsAny($body, ['equipo', 'profesionales', 'sedes', 'especialidades', 'consultorios']);

        if (str_contains($raw, 'wa.me') || str_contains($raw, 'whatsapp')) {
            $contactMethods[] = 'whatsapp';
            $this->signal($lead, 'channel_whatsapp', 'true', $this->snippet($body, 'whatsapp') ?: 'Se detectó WhatsApp visible.', 'website');
        }

        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $html, $match)) {
            $contactMethods[] = 'email_visible';
            $this->signal($lead, 'channel_email_visible', mb_strtolower($match[0]), 'Se detectó email visible en el sitio.', 'website');
        }

        if (str_contains($raw, '<form') || $this->containsAny($body, ['formulario', 'enviar consulta'])) {
            $contactMethods[] = 'form';
            $this->signal($lead, 'channel_form', 'true', 'Se detectó formulario o consulta web.', 'website');
        }

        foreach ($this->socialLinks($html) as $network => $link) {
            $contactMethods[] = $network;
            $socialUrls[$network] = $link;
            $this->signal($lead, 'social_'.$network, $link, "Se detectó perfil de {$network}.", 'website');
        }

        if ($booking) {
            $this->addSignal($lead, $signals, 'booking_business', 'true', $this->snippet($body, 'turno') ?: 'El sitio contiene señales de turnos o reservas.', 'website');
        }

        if ($systems !== []) {
            $lead->booking_visible = true;
            $lead->primary_cta = 'booking_system';
            $this->signal($lead, 'booking_system_visible', implode(',', $systems), 'Se detectó sistema de turnos: '.implode(', ', $systems).'.', 'website');
        } elseif ($booking && ! $visibleBooking) {
            $this->addSignal($lead, $signals, 'no_visible_booking', 'true', 'Hay señales de turnos, pero no sistema visible.', 'website');
        }

        if ($manualBooking) {
            $this->addSignal($lead, $signals, 'manual_contact_dependency', 'true', $this->firstSnippet($body, self::MANUAL_BOOKING_TERMS) ?: 'La web sugiere gestión manual de turnos.', 'website');
            $this->signal($lead, 'manual_booking_dependency', 'true', 'La web sugiere gestión manual de turnos.', 'website');
        }

        if ($complexOperation) {
            $this->signal($lead, 'operational_complexity', 'true', $this->firstSnippet($body, ['equipo', 'profesionales', 'sedes', 'especialidades', 'consultorios']) ?: 'Se detectó complejidad operativa.', 'website');
        }

        if (! $hasContact) {
            $this->addSignal($lead, $signals, 'hard_to_find_contact', 'true', 'No se detectó contacto claro en la página principal.', 'website');
        }

        if (! $clearCta) {
            $this->addSignal($lead, $signals, 'weak_cta', 'true', 'No se detectó CTA claro en la página principal.', 'website');
        }

        if (mb_strlen(trim($body)) < 500) {
            $this->signal($lead, 'weak_website_content', 'short_home', 'La página principal tiene poco contenido analizable.', 'website');
        }

        if (! preg_match('/<title>[^<]{3,}<\/title>/i', $html)) {
            $this->signal($lead, 'weak_website_content', 'missing_title', 'No se detectó título HTML claro.', 'website');
        }

        if (! $lead->booking_visible) {
            $lead->booking_visible = $visibleBooking;
        }

        if (! $lead->primary_cta) {
            $lead->primary_cta = $clearCta ? 'contact_or_booking' : null;
        }

        $lead->save();

        return [
            'score_signals' => array_values(array_unique($signals)),
            'contact_methods' => array_values(array_unique($contactMethods)),
            'social_urls' => $socialUrls,
        ];
    }

    private function enrichPhone(Lead $lead, array &$signals): void
    {
        if (! $lead->phone) {
            return;
        }

        $digits = preg_replace('/\D+/', '', $lead->phone);

        if (! $digits || strlen($digits) < 8) {
            $this->signal($lead, 'phone_format_invalid', (string) $lead->phone, 'El teléfono tiene pocos dígitos.', 'import');

            return;
        }

        $this->signal($lead, 'phone_format_valid', $digits, 'El teléfono tiene longitud utilizable.', 'import');

        if (str_starts_with($digits, '549') || (str_starts_with($digits, '54') && strlen($digits) >= 12)) {
            $this->signal($lead, 'phone_likely_mobile', $digits, 'El teléfono parece compatible con contacto móvil.', 'import');
        }
    }

    private function addSignal(Lead $lead, array &$signals, string $type, string $value, string $evidence, string $source): void
    {
        $this->signal($lead, $type, $value, $evidence, $source);
        $signals[] = $type;
    }

    private function signal(Lead $lead, string $type, string $value, string $evidence, string $source): void
    {
        Signal::updateOrCreate([
            'lead_id' => $lead->id,
            'type' => $type,
        ], [
            'value' => $value,
            'evidence' => $evidence,
            'source' => $source,
            'detected_at' => Carbon::now(),
        ]);
    }

    private function baseContactMethods(Lead $lead): array
    {
        return array_values(array_filter([
            $lead->email ? 'email' : null,
            $lead->phone ? 'phone' : null,
            $lead->website_url ? 'website' : null,
        ]));
    }

    private function normalizedUrl(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : "https://{$url}";
    }

    private function containsAny(string $body, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($body, $term)) {
                return true;
            }
        }

        return false;
    }

    private function bookingSystems(string $body): array
    {
        $systems = [];

        foreach (self::BOOKING_SYSTEMS as $system => $needles) {
            if ($this->containsAny($body, $needles)) {
                $systems[] = $system;
            }
        }

        return $systems;
    }

    private function socialLinks(string $html): array
    {
        $links = [];

        preg_match_all('/https?:\/\/[^\s"\']+/i', $html, $matches);

        foreach ($matches[0] ?? [] as $url) {
            $clean = rtrim($url, '.,;)</]');
            $lower = mb_strtolower($clean);

            if (str_contains($lower, 'instagram.com') && ! isset($links['instagram'])) {
                $links['instagram'] = $clean;
            }

            if (str_contains($lower, 'facebook.com') && ! isset($links['facebook'])) {
                $links['facebook'] = $clean;
            }

            if (str_contains($lower, 'doctoralia.') && ! isset($links['doctoralia'])) {
                $links['doctoralia'] = $clean;
            }
        }

        return $links;
    }

    private function snippet(string $body, string $term): ?string
    {
        $position = mb_strpos($body, $term);

        if ($position === false) {
            return null;
        }

        return trim(mb_substr($body, max(0, $position - 55), 140));
    }

    private function firstSnippet(string $body, array $terms): ?string
    {
        foreach ($terms as $term) {
            if ($snippet = $this->snippet($body, $term)) {
                return $snippet;
            }
        }

        return null;
    }
}
