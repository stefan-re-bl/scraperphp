<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Offer;

class TemplateRenderer
{
    public function select(Lead $lead, string $channel, string $stage, ?int $offerId = null): ?MessageTemplate
    {
        return MessageTemplate::where('active', true)->where('channel', $channel)->where('stage', $stage)
            ->where('language', $lead->language)->where(fn ($q) => $q->whereNull('offer_id')->orWhere('offer_id', $offerId ?? $lead->suggested_offer_id))
            ->where(fn ($q) => $q->whereNull('segment')->orWhere('segment', $lead->segment))
            ->orderByRaw('case when offer_id is null then 1 else 0 end')->orderByRaw('case when segment is null then 1 else 0 end')->orderBy('id')->first();
    }

    public function render(MessageTemplate $template, Lead $lead, ?Offer $offer = null): array
    {
        $offer ??= $lead->suggestedOffer;
        $problem = $lead->signalRows()->where('review_status', 'confirmed')->whereIn('type', ['observed_problem', 'weak_cta', 'manual_booking_dependency'])->value('evidence');
        $variables = ['nombre' => $lead->contact_person ?: $lead->name, 'negocio' => $lead->name,
            'ciudad' => $lead->city ?: $lead->location, 'problema_detectado' => $problem,
            'oferta' => $offer?->name, 'demo' => $offer?->demo_url, 'portfolio' => $offer?->portfolio_url,
            'CTA' => $offer?->suggested_cta, 'cta' => $offer?->suggested_cta];
        $missing = [];
        $replace = function (string $text) use ($variables, &$missing): string {
            return preg_replace_callback('/\{\{\s*([\w]+)\s*\}\}/u', function ($match) use ($variables, &$missing) {
                if (! filled($variables[$match[1]] ?? null)) {
                    $missing[] = $match[1];

                    return $match[0];
                }

                return (string) $variables[$match[1]];
            }, $text);
        };
        $subject = $replace($template->subject ?? '');
        $body = $replace($template->body);

        return ['subject' => $subject, 'body' => $body, 'missing' => array_values(array_unique($missing))];
    }
}
