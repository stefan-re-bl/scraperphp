<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Mail;

class EmailContactService
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly LimitService $limits,
    ) {}

    public function sendInitial(Lead $lead, Campaign $campaign, MessageTemplate $template, bool $forceDryRun = false): ContactAttempt
    {
        $usage = $this->limits->usage($campaign, 'email');
        $dryRun = $forceDryRun || $campaign->dry_run || $campaign->mode !== 'automatic';

        if ($usage['blocked'] && ! $dryRun) {
            throw new \RuntimeException('Límite diario o kill switch bloquea el envío.');
        }

        if ($lead->excluded_at) {
            throw new \RuntimeException('El lead está excluido.');
        }

        if ($lead->contactAttempts()->whereIn('status', ['planned', 'sent'])->exists()) {
            throw new \RuntimeException('El lead ya tiene secuencia activa.');
        }

        $message = $this->renderer->render($template, $lead);

        $attempt = ContactAttempt::create([
            'lead_id' => $lead->id,
            'campaign_id' => $campaign->id,
            'message_template_id' => $template->id,
            'channel' => 'email',
            'subject' => $message['subject'],
            'step' => $template->stage,
            'performed_at' => now(),
            'status' => $dryRun ? 'dry_run' : 'sent',
            'manual_intervention' => $campaign->mode !== 'automatic',
            'dry_run' => $dryRun,
            'message_snapshot' => $message['body'],
            'result' => $dryRun ? 'would_send' : 'sent',
        ]);

        if (! $dryRun) {
            Mail::raw($message['body'], function ($mail) use ($lead, $message) {
                $mail->to($lead->email)->subject($message['subject'] ?: 'Consulta');
            });
        }

        SystemLog::create([
            'type' => 'email',
            'message' => $dryRun ? 'Dry-run de email registrado.' : 'Email enviado.',
            'context' => ['lead_id' => $lead->id, 'contact_attempt_id' => $attempt->id],
        ]);

        return $attempt;
    }
}
