<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\Lead;
use App\Models\MessageTemplate;
use Illuminate\Support\Facades\DB;

class ExperimentService
{
    public function assign(Lead $lead, Experiment $experiment): ?ExperimentVariant
    {
        return DB::transaction(function () use ($lead, $experiment) {
            Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            Experiment::whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $variants = $experiment->variants()->withCount('assignments')->get();

            if ($variants->isEmpty()) {
                return null;
            }

            $existing = DB::table('lead_experiment_variant')
                ->where('lead_id', $lead->id)
                ->whereIn('experiment_variant_id', $variants->pluck('id'))
                ->first();

            if ($existing) {
                return ExperimentVariant::find($existing->experiment_variant_id);
            }

            $variant = $variants->sortBy('assignments_count')->first();

            DB::table('lead_experiment_variant')->insert([
                'lead_id' => $lead->id,
                'experiment_variant_id' => $variant->id,
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $variant;
        });
    }

    public function assigned(Lead $lead, ?Campaign $campaign): ?ExperimentVariant
    {
        if (! $campaign) {
            return null;
        }
        $variants = ExperimentVariant::whereHas('experiment', fn ($q) => $q->where('campaign_id', $campaign->id)->where('status', 'active'))
            ->whereIn('id', DB::table('lead_experiment_variant')->where('lead_id', $lead->id)->select('experiment_variant_id'))->get();

        // Multiple active assignments have no unambiguous preparation context.
        return $variants->count() === 1 ? $variants->first() : null;
    }

    public function template(ExperimentVariant $variant): ?MessageTemplate
    {
        $template = MessageTemplate::find($variant->message_template_id);
        if ($template && filled($variant->content)) {
            $template->body = $variant->content;
        }

        return $template;
    }

    public function preparedTemplate(Lead $lead, ?Campaign $campaign, string $channel, string $stage, ?int $offerId): ?MessageTemplate
    {
        $variant = $this->assigned($lead, $campaign);
        $template = $variant ? $this->template($variant) : null;
        if (! $template || ! $template->active || $template->channel !== $channel || $template->stage !== $stage
            || $template->language !== $lead->language || (filled($template->segment) && $template->segment !== $lead->segment)
            || ($template->offer_id && $template->offer_id !== $offerId)) {
            return null;
        }

        return $template;
    }

    public function attribution(Lead $lead, Campaign $campaign, array $data): array
    {
        $variant = $this->assigned($lead, $campaign);
        $template = $variant ? $this->template($variant) : null;
        if (! $template || ! $template->active || $template->id != ($data['message_template_id'] ?? null)
            || $template->channel !== ($data['channel'] ?? null) || $template->stage !== ($data['step'] ?? null)
            || $template->language !== $lead->language || (filled($template->segment) && $template->segment !== $lead->segment)) {
            return ['experiment_variant_id' => null, 'variant' => null];
        }
        $offer = app(CampaignMembership::class)->offer($lead, $campaign);
        if ($template->offer_id && $template->offer_id !== $offer?->id) {
            return ['experiment_variant_id' => null, 'variant' => null];
        }
        $message = app(TemplateRenderer::class)->render($template, $lead, $offer);
        if (($data['message_snapshot'] ?? '') !== $message['body'] || ($data['subject'] ?? '') !== $message['subject'] || $message['missing'] !== []) {
            return ['experiment_variant_id' => null, 'variant' => null];
        }

        return ['experiment_variant_id' => $variant->id, 'variant' => $variant->name];
    }
}
