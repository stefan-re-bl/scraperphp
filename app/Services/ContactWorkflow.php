<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactWorkflow
{
    public function __construct(private readonly ExclusionService $exclusions, private readonly CampaignMembership $memberships, private readonly FollowUpService $followUps, private readonly LeadPipeline $pipeline) {}

    public function record(array $data): ContactAttempt
    {
        return DB::transaction(function () use ($data) {
            $lead = Lead::lockForUpdate()->findOrFail($data['lead_id']);
            $campaign = Campaign::findOrFail($data['campaign_id']);
            $this->exclusions->assertContactable($lead, $campaign);
            $this->memberships->attach($lead, $campaign);
            if (in_array($this->memberships->status($lead, $campaign), ['PAUSED', 'WON', 'LOST'])) {
                throw ValidationException::withMessages(['lead_id' => 'La oportunidad está pausada o cerrada.']);
            }
            $attempt = isset($data['contact_attempt_id']) ? ContactAttempt::where('lead_id', $lead->id)->where('campaign_id', $campaign->id)->findOrFail($data['contact_attempt_id']) : null;
            if ($attempt && $attempt->step !== $data['step']) {
                throw ValidationException::withMessages(['step' => 'El paso no corresponde al seguimiento seleccionado.']);
            }
            $performed = filled($data['performed_at'] ?? null);
            if (! $attempt && $performed) {
                $attempt = $lead->contactAttempts()->where('campaign_id', $campaign->id)->where('step', $data['step'])->where('status', 'planned')->first();
            }
            $active = $lead->contactAttempts()->where('campaign_id', $campaign->id)->whereIn('status', ['planned', 'sending'])->when($attempt, fn ($q) => $q->where('id', '!=', $attempt->id))->exists();
            if ($active || ($attempt && $attempt->status !== 'planned')) {
                throw ValidationException::withMessages(['lead_id' => 'Ya existe un seguimiento pendiente o este contacto ya fue completado.']);
            }
            if (! $attempt && $lead->contactAttempts()->where('campaign_id', $campaign->id)->where('step', $data['step'])->where('status', 'sent')->exists()) {
                throw ValidationException::withMessages(['lead_id' => 'Este paso ya fue registrado.']);
            }
            unset($data['contact_attempt_id']);
            $data['offer_id'] = $this->memberships->offer($lead, $campaign)?->id;
            $data['status'] = $performed ? 'sent' : 'planned';
            $data['dry_run'] = false;
            $data['manual_intervention'] = true;
            $data['due_at'] = $performed ? ($attempt?->due_at) : ($data['planned_at'] ?? now());
            if ($attempt) {
                $attempt->update($data);
            } else {
                $attempt = ContactAttempt::create($data);
            }
            if ($performed) {
                $this->pipeline->move($lead, 'CONTACTED', 'contact_attempt_recorded', $campaign);
                app(CampaignMembership::class)->nextAction($lead, $campaign, null);
                $this->followUps->scheduleNext($lead, $campaign, $attempt->step);
            } else {
                app(CampaignMembership::class)->nextAction($lead, $campaign, $attempt->due_at?->toDateTimeString());
            }

            return $attempt->refresh();
        });
    }

    public function reschedule(ContactAttempt $attempt, string $dueAt): void
    {
        $this->exclusions->assertContactable($attempt->lead, $attempt->campaign);
        if ($attempt->status !== 'planned') {
            throw ValidationException::withMessages(['due_at' => 'Solo se pueden reprogramar contactos pendientes.']);
        }
        $attempt->update(['planned_at' => $dueAt, 'due_at' => $dueAt]);
        app(CampaignMembership::class)->nextAction($attempt->lead, $attempt->campaign, $dueAt);
    }
}
