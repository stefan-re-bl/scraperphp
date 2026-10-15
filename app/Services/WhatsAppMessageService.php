<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Offer;

class WhatsAppMessageService
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly CampaignMembership $memberships, private readonly ContactNormalizer $normalizer) {}

    /**
     * @return array{message: array{subject: string, body: string, missing: list<string>}, phone: string|null, url: string|null, offer: Offer|null, template: MessageTemplate|null}
     */
    public function prepare(Lead $lead, ?Campaign $campaign, string $stage = 'initial'): array
    {
        $offer = $this->memberships->offer($lead, $campaign);
        $template = app(ExperimentService::class)->preparedTemplate($lead, $campaign, 'whatsapp', $stage, $offer?->id) ?? $this->renderer->select($lead, 'whatsapp', $stage, $offer?->id);
        $message = $template ? $this->renderer->render($template, $lead, $offer) : $this->renderer->renderOffer($lead, $offer);

        return ['message' => $message, 'phone' => $this->normalizer->whatsappPhone($lead->phone, $lead->country),
            'url' => $this->url($lead, $message['body']), 'offer' => $offer, 'template' => $template];
    }

    public function url(Lead $lead, string $message): ?string
    {
        $phone = $this->normalizer->whatsappPhone($lead->phone, $lead->country);
        if (! $phone || blank($message) || preg_match('/\{\{.*?\}\}/us', $message)) {
            return null;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
    }
}
