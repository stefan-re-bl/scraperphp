<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\SystemLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailContactService
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly LimitService $limits) {}

    public function sendInitial(Lead $lead, Campaign $campaign, MessageTemplate $template, bool $forceDryRun = false): ContactAttempt
    {
        app(ExclusionService::class)->assertContactable($lead, $campaign);
        $dryRun = $forceDryRun || $campaign->dry_run || $campaign->mode !== 'automatic' || in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array']);
        if (! $dryRun && $this->limits->usage($campaign, 'email')['blocked']) {
            throw ValidationException::withMessages(['campaign_id' => 'Se alcanzó el límite diario.']);
        }
        if (! $template->active || $template->channel !== 'email' || ! filter_var($lead->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['message_template_id' => 'Revisá la plantilla y el email del prospecto.']);
        }
        if ($template->stage !== 'initial' || $template->language !== $lead->language || (filled($template->segment) && $template->segment !== $lead->segment)) {
            throw ValidationException::withMessages(['message_template_id' => 'La plantilla no corresponde a este contacto inicial.']);
        }
        $offer = app(CampaignMembership::class)->offer($lead, $campaign);
        if ($template->offer_id && $template->offer_id !== $offer?->id) {
            throw ValidationException::withMessages(['message_template_id' => 'La plantilla pertenece a otra oferta.']);
        }
        $preparedTemplate = app(ExperimentService::class)->preparedTemplate($lead, $campaign, 'email', $template->stage, $offer?->id);
        if ($preparedTemplate?->id === $template->id) {
            $template = $preparedTemplate;
        }
        $message = $this->renderer->render($template, $lead, $offer);
        if ($message['missing'] !== []) {
            throw ValidationException::withMessages(['message_template_id' => 'Faltan datos: '.implode(', ', $message['missing'])]);
        }
        $attempt = DB::transaction(function () use ($lead, $campaign, $template, $dryRun, $message) {
            $lead->refresh();
            $lead->newQuery()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $campaign->refresh();
            app(ExclusionService::class)->assertContactable($lead, $campaign);
            if (! $dryRun && in_array(app(CampaignMembership::class)->status($lead, $campaign), ['PAUSED', 'WON', 'LOST'])) {
                throw ValidationException::withMessages(['lead_id' => 'La oportunidad está pausada o cerrada.']);
            }
            if (! $dryRun && $this->limits->usage($campaign, 'email')['blocked']) {
                throw ValidationException::withMessages(['campaign_id' => 'Se alcanzó el límite diario.']);
            }
            if (! $dryRun && $lead->contactAttempts()->where('campaign_id', $campaign->id)->whereIn('status', ['planned', 'sending'])->exists()) {
                throw ValidationException::withMessages(['lead_id' => 'El prospecto ya tiene un contacto pendiente.']);
            }
            if (! $dryRun && $lead->contactAttempts()->where('campaign_id', $campaign->id)->where('step', $template->stage)->where('status', 'sent')->exists()) {
                throw ValidationException::withMessages(['lead_id' => 'Este paso ya fue realizado.']);
            }
            if (! $dryRun) {
                app(CampaignMembership::class)->attach($lead, $campaign);
            }

            $created = ContactAttempt::create(array_merge([
                'lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'message_template_id' => $template->id,
                'offer_id' => app(CampaignMembership::class)->offer($lead, $campaign)?->id, 'channel' => 'email', 'subject' => $message['subject'], 'step' => $template->stage,
                'status' => $dryRun ? 'dry_run' : 'sending', 'manual_intervention' => false,
                'dry_run' => $dryRun, 'message_snapshot' => $message['body'], 'result' => $dryRun ? 'would_send' : null,
            ], app(ExperimentService::class)->attribution($lead, $campaign, ['message_template_id' => $template->id, 'channel' => 'email', 'step' => $template->stage, 'subject' => $message['subject'], 'message_snapshot' => $message['body']])));
            if (! $dryRun) {
                app(ScoreSnapshotService::class)->capture($created);
            }

            return $created;
        });
        if (! $dryRun) {
            try {
                Mail::raw($message['body'], fn ($mail) => $mail->to($lead->email)->subject($message['subject'] ?: 'Consulta'));
            } catch (\Throwable $exception) {
                $attempt->update(['status' => 'failed', 'result' => mb_substr($exception->getMessage(), 0, 255)]);
                throw $exception;
            }
            $attempt->update(['status' => 'sent', 'result' => 'sent', 'performed_at' => now()]);
            try {
                DB::transaction(function () use ($lead, $campaign, $attempt) {
                    app(LeadPipeline::class)->move($lead->refresh(), 'CONTACTED', 'email_contact_recorded', $campaign->refresh());
                    app(FollowUpService::class)->scheduleNext($lead, $campaign, $attempt->step);
                });
            } catch (\Throwable $exception) {
                $attempt->update(['result' => 'sent_requires_review', 'manual_intervention' => true]);
                SystemLog::create(['type' => 'contact_review', 'message' => $exception->getMessage(), 'context' => ['lead_id' => $lead->id, 'contact_attempt_id' => $attempt->id]]);
            }
        }
        SystemLog::create(['type' => 'email', 'message' => $dryRun ? 'Simulación de email registrada.' : 'Email enviado.', 'context' => ['lead_id' => $lead->id, 'contact_attempt_id' => $attempt->id]]);

        return $attempt->refresh();
    }
}
