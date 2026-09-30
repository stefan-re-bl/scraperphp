<?php

namespace App\Services;

use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Reply;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    public function funnel(): array
    {
        $acquired = Lead::count();
        $qualified = Lead::whereIn('status', ['QUALIFIED', 'QUEUED', 'CONTACTED', 'REPLIED', 'INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'])->count();
        $contacted = ContactAttempt::whereNotNull('performed_at')->count();
        $responses = Reply::count();
        $interested = Lead::whereIn('status', ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'])->count();
        $proposals = Proposal::count();
        $won = Lead::where('status', 'WON')->count();
        $lost = Lead::where('status', 'LOST')->count();

        return [
            'baseline' => [
                'contacts' => 100,
                'responses' => 18,
                'interested' => 4,
                'proposals' => 2,
            ],
            'counts' => compact('acquired', 'qualified', 'contacted', 'responses', 'interested', 'proposals', 'won', 'lost'),
            'rates' => [
                'reply_rate' => $this->rate($responses, $contacted),
                'interest_rate' => $this->rate($interested, $contacted),
                'proposal_rate' => $this->rate($proposals, $contacted),
                'close_rate' => $this->rate($won, $contacted),
            ],
            'sample_size' => $contacted,
        ];
    }

    public function segmented(string $dimension = 'campaign'): array
    {
        return match ($dimension) {
            'campaign' => $this->byCampaign(),
            'channel' => $this->byColumn('contact_attempts.channel'),
            'offer' => $this->byOffer(),
            'segment' => $this->byColumn('leads.segment'),
            'city' => $this->byColumn('leads.location'),
            'country' => $this->byColumn('leads.country'),
            'source' => $this->byColumn('leads.source'),
            'language' => $this->byColumn('leads.language'),
            'variant' => $this->byColumn('contact_attempts.variant'),
            default => $this->byCampaign(),
        };
    }

    private function rate(int $value, int $base): float
    {
        return $base === 0 ? 0.0 : round(($value / $base) * 100, 2);
    }

    private function byCampaign(): array
    {
        return DB::table('campaigns')
            ->leftJoin('contact_attempts', 'campaigns.id', '=', 'contact_attempts.campaign_id')
            ->leftJoin('replies', 'contact_attempts.id', '=', 'replies.contact_attempt_id')
            ->leftJoin('proposals', 'contact_attempts.lead_id', '=', 'proposals.lead_id')
            ->selectRaw('campaigns.name as label')
            ->selectRaw('count(distinct contact_attempts.id) as contacts')
            ->selectRaw('count(distinct replies.id) as replies')
            ->selectRaw("count(distinct case when replies.category in ('interested','meeting','proposal','won') then replies.id end) as positive_replies")
            ->selectRaw("count(distinct case when leads_status.status in ('INTERESTED','PROPOSAL','NEGOTIATION','WON') then leads_status.id end) as interested")
            ->selectRaw('count(distinct proposals.id) as proposals')
            ->selectRaw("count(distinct case when leads_status.status = 'WON' then leads_status.id end) as won")
            ->leftJoin('leads as leads_status', 'contact_attempts.lead_id', '=', 'leads_status.id')
            ->groupBy('campaigns.id', 'campaigns.name')
            ->get()
            ->map(fn ($row) => $this->ratesForRow((array) $row))
            ->all();
    }

    private function byOffer(): array
    {
        return DB::table('offers')
            ->leftJoin('leads', 'offers.id', '=', 'leads.suggested_offer_id')
            ->leftJoin('contact_attempts', 'leads.id', '=', 'contact_attempts.lead_id')
            ->leftJoin('replies', 'contact_attempts.id', '=', 'replies.contact_attempt_id')
            ->leftJoin('proposals', 'leads.id', '=', 'proposals.lead_id')
            ->selectRaw('offers.name as label')
            ->selectRaw('count(distinct contact_attempts.id) as contacts')
            ->selectRaw('count(distinct replies.id) as replies')
            ->selectRaw("count(distinct case when replies.category in ('interested','meeting','proposal','won') then replies.id end) as positive_replies")
            ->selectRaw("count(distinct case when leads.status in ('INTERESTED','PROPOSAL','NEGOTIATION','WON') then leads.id end) as interested")
            ->selectRaw('count(distinct proposals.id) as proposals')
            ->selectRaw("count(distinct case when leads.status = 'WON' then leads.id end) as won")
            ->groupBy('offers.id', 'offers.name')
            ->get()
            ->map(fn ($row) => $this->ratesForRow((array) $row))
            ->all();
    }

    private function byColumn(string $column): array
    {
        return DB::table('leads')
            ->leftJoin('contact_attempts', 'leads.id', '=', 'contact_attempts.lead_id')
            ->leftJoin('replies', 'contact_attempts.id', '=', 'replies.contact_attempt_id')
            ->leftJoin('proposals', 'leads.id', '=', 'proposals.lead_id')
            ->selectRaw("coalesce({$column}, 'sin_dato') as label")
            ->selectRaw('count(distinct contact_attempts.id) as contacts')
            ->selectRaw('count(distinct replies.id) as replies')
            ->selectRaw("count(distinct case when replies.category in ('interested','meeting','proposal','won') then replies.id end) as positive_replies")
            ->selectRaw("count(distinct case when leads.status in ('INTERESTED','PROPOSAL','NEGOTIATION','WON') then leads.id end) as interested")
            ->selectRaw('count(distinct proposals.id) as proposals')
            ->selectRaw("count(distinct case when leads.status = 'WON' then leads.id end) as won")
            ->groupBy('label')
            ->get()
            ->map(fn ($row) => $this->ratesForRow((array) $row))
            ->all();
    }

    private function ratesForRow(array $row): array
    {
        $contacts = (int) ($row['contacts'] ?? 0);
        $row['reply_rate'] = $this->rate((int) $row['replies'], $contacts);
        $row['positive_reply_rate'] = $this->rate((int) $row['positive_replies'], $contacts);
        $row['interest_rate'] = $this->rate((int) $row['interested'], $contacts);
        $row['proposal_rate'] = $this->rate((int) $row['proposals'], $contacts);
        $row['close_rate'] = $this->rate((int) $row['won'], $contacts);
        $row['sample_size'] = $contacts;

        return $row;
    }
}
