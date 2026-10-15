<?php

namespace App\Services;

use App\Models\ClientService;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Reply;
use Illuminate\Support\Facades\DB;

class CommercialWorkService
{
    /** Existing dates and pending events only; this service never schedules a contact. */
    public function actions(?int $campaignId = null, int $limit = 100, array $filters = []): array
    {
        $limit = max(1, min($limit, 2000));
        if ($campaignId) {
            $filters['campaign'] = $campaignId;
        }
        $selectedIds = app(LeadFilterService::class)->query($filters)->select('leads.id');
        $items = collect();
        $add = function (string $type, int $priority, int $leadId, string $name, ?int $campaign, mixed $due, string $label, string $key) use ($items): void {
            $items->push(['id' => $key, 'type' => $type, 'priority' => $priority, 'lead_id' => $leadId, 'lead' => $name,
                'campaign_id' => $campaign, 'due_at' => $due ? (string) $due : null, 'label' => $label, 'url' => route('leads.show', $leadId)]);
        };
        foreach (Reply::with('lead:id,name')->whereIn('lead_id', clone $selectedIds)->where('requires_human', true)->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))->oldest('received_at')->limit($limit)->get() as $reply) {
            $add('reply', 0, $reply->lead_id, $reply->lead->name, $reply->campaign_id, $reply->received_at, 'Respuesta pendiente de revisión', 'reply:'.$reply->id);
        }
        $opportunities = DB::table('campaign_lead')->join('leads', 'leads.id', '=', 'campaign_lead.lead_id')->join('campaigns', 'campaigns.id', '=', 'campaign_lead.campaign_id')
            ->whereIn('campaign_lead.lead_id', clone $selectedIds)->where('campaigns.status', 'active')->where('campaigns.kill_switch', false)->whereNull('leads.excluded_at')
            ->whereNotIn('campaign_lead.status', ['WON', 'LOST', 'PAUSED'])
            ->where(fn ($q) => $q->whereNotNull('campaign_lead.next_action_at')->orWhereIn('campaign_lead.status', ['REPLIED', 'INTERESTED', 'PROPOSAL', 'NEGOTIATION']))
            ->when($campaignId, fn ($q) => $q->where('campaign_lead.campaign_id', $campaignId))
            ->select('campaign_lead.*', 'leads.name')->orderByRaw('campaign_lead.next_action_at is null')->orderBy('campaign_lead.next_action_at')->limit($limit)->get();
        foreach ($opportunities as $opportunity) {
            $overdue = $opportunity->next_action_at && strtotime($opportunity->next_action_at) <= now()->timestamp;
            $add($overdue ? 'overdue' : 'opportunity', $overdue ? 1 : 3, $opportunity->lead_id, $opportunity->name, $opportunity->campaign_id, $opportunity->next_action_at,
                $opportunity->next_action_at ? 'Próxima acción · '.Lead::statusLabel($opportunity->status) : 'Oportunidad sin próxima fecha · '.Lead::statusLabel($opportunity->status), 'opportunity:'.$opportunity->id);
        }
        if (! $campaignId) {
            foreach (Lead::whereIn('id', clone $selectedIds)->whereDoesntHave('campaigns')->whereNotNull('next_action_at')->whereNull('excluded_at')->whereNotIn('status', ['PAUSED', 'WON', 'LOST'])->orderBy('next_action_at')->limit($limit)->get(['id', 'name', 'next_action_at']) as $lead) {
                $add('next_action', $lead->next_action_at->isPast() ? 1 : 3, $lead->id, $lead->name, null, $lead->next_action_at, 'Próxima acción', 'lead:'.$lead->id);
            }
            foreach (ClientService::with('lead:id,name')->whereIn('lead_id', clone $selectedIds)->whereNotNull('renews_on')->whereNotIn('status', ['completed', 'cancelled'])->whereDate('renews_on', '<=', now()->addDays(30))->orderBy('renews_on')->limit($limit)->get() as $service) {
                $add('renewal', $service->renews_on->isPast() ? 1 : 4, $service->lead_id, $service->lead->name, null, $service->renews_on, 'Renovación · '.$service->name, 'service:'.$service->id);
            }
        }
        foreach (Proposal::with('lead:id,name')->whereIn('lead_id', clone $selectedIds)->where(fn ($q) => $q->whereNull('result')->orWhere('result', 'pending'))->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))->oldest()->limit($limit)->get() as $proposal) {
            $add('proposal', 2, $proposal->lead_id, $proposal->lead->name, $proposal->campaign_id, $proposal->sent_at ?? $proposal->created_at, 'Propuesta pendiente · '.$proposal->service, 'proposal:'.$proposal->id);
        }

        return $items->sortBy([['priority', 'asc'], ['due_at', 'asc'], ['id', 'asc']])->take($limit)->values()->all();
    }
}
