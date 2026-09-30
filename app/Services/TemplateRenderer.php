<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\MessageTemplate;

class TemplateRenderer
{
    public function render(MessageTemplate $template, Lead $lead): array
    {
        $offer = $lead->suggestedOffer;
        $problem = collect($lead->signals ?? [])->first() ?: 'un punto operativo detectado';

        $vars = [
            'nombre' => $lead->name,
            'negocio' => $lead->name,
            'ciudad' => $lead->location,
            'problema_detectado' => $problem,
            'oferta' => $offer?->name,
            'demo' => $offer?->demo_url,
            'portfolio' => $offer?->portfolio_url,
            'CTA' => $offer?->suggested_cta ?? 'ver una demo breve',
            'cta' => $offer?->suggested_cta ?? 'ver una demo breve',
        ];

        return [
            'subject' => $this->replace($template->subject ?? '', $vars),
            'body' => $this->replace($template->body, $vars),
        ];
    }

    private function replace(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }

        return $text;
    }
}
