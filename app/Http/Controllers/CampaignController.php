<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Reply;
use App\Models\SavedLeadView;
use App\Services\CampaignMembership;
use App\Services\FollowUpService;
use App\Services\GoogleMapsScraperService;
use App\Services\LeadFilterService;
use App\Services\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function campaigns(): mixed
    {
        return view('sales.campaigns', [
            'campaigns' => Campaign::with('offer')->withCount(['queries', 'contactAttempts' => fn ($q) => $q->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')])->latest()->get(),
            'offers' => Offer::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function campaignWorkspace(Request $request, Campaign $campaign, MetricsService $metrics, LeadFilterService $filters): mixed
    {
        $request->validate(['tab' => 'nullable|in:summary,leads,acquisition,contact,pipeline,experiments,metrics', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'dimension' => 'nullable|in:campaign,channel,offer,segment,city,country,source,language,variant']);
        $tab = $request->query('tab', 'summary');
        $baseFilters = ['campaign' => $campaign->id] + $filters->fromRequest($request);
        $isSummary = $tab === 'summary';
        $isLeads = $tab === 'leads';
        $isAcquisition = $tab === 'acquisition';

        return view('sales.campaign-workspace', [
            'campaign' => $campaign->load('offer'), 'tab' => $tab,
            'stats' => $isSummary || $tab === 'metrics' ? $metrics->campaignStats($campaign) : null,
            'filters' => $baseFilters, 'filterQuery' => $filters->cleanQuery($baseFilters),
            'savedViews' => $isLeads ? SavedLeadView::where('scope', 'campaign:'.$campaign->id)->orderBy('name')->get() : collect(),
            'leads' => $isLeads ? $filters->query($baseFilters, ['campaigns', 'suggestedOffer', 'offerScores.offer', 'signalRows', 'possibleDuplicateOf'])->paginate(25)->withQueryString() : collect(),
            'statuses' => Lead::STATUSES,
            'offers' => Offer::where('active', true)->orWhere('id', $campaign->offer_id)->orderBy('name')->get(),
            'campaigns' => $isLeads ? Campaign::orderBy('name')->get() : collect(),
            'sources' => $isLeads ? Lead::select('source')->distinct()->pluck('source')->filter()->values() : collect(),
            'signals' => $isLeads ? DB::table('signals')->select('type')->distinct()->pluck('type')->filter()->values() : collect(),
            'queries' => $isSummary || $isAcquisition ? $campaign->queries()->latest()->limit(20)->get() : collect(),
            'scrapeRuns' => $isAcquisition ? $campaign->scrapeRuns()->latest()->limit(20)->get() : collect(),
            'imports' => $isAcquisition ? $campaign->importRuns()->latest()->limit(20)->get() : collect(),
            'queueLeads' => $tab === 'contact' ? app(CampaignMembership::class)->ready($campaign)->with(['campaigns', 'suggestedOffer', 'offerScores.offer', 'signalRows', 'scoreComponents', 'contactAttempts'])->orderByRaw('resolved_score is null')->orderByDesc('resolved_score')->limit(20)->get() : collect(),
            'templates' => collect(),
            'experiments' => $tab === 'experiments' ? $campaign->experiments()->with(['variants' => fn ($query) => $query->withCount(['contactAttempts as real_attempts_count' => fn ($attempts) => $attempts->where('dry_run', false)->where('status', 'sent')->whereNotNull('performed_at')])])->latest()->get() : collect(),
            'metricRows' => $tab === 'metrics' ? $metrics->segmented($request->query('dimension', 'city'), ['campaign' => $campaign->id] + $request->only(['from', 'to'])) : [],
            'dimension' => $request->query('dimension', 'city'),
            'recentReplies' => $isSummary ? Reply::with('lead')->where('campaign_id', $campaign->id)->latest()->limit(8)->get() : collect(),
            'pendingFollowUps' => $isSummary ? ContactAttempt::with('lead')->where('campaign_id', $campaign->id)->where('status', 'planned')->where('due_at', '<=', now())->latest()->limit(8)->get() : collect(),
        ]);
    }

    public function storeCampaign(Request $request): mixed
    {
        Campaign::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'segment' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'language' => ['required', 'string', 'max:10'],
            'source' => ['required', 'in:google_maps,csv_manual,referral,web'],
            'channel' => ['required', 'string', 'max:255'],
            'offer_id' => ['nullable', 'exists:offers,id'],
            'cta' => ['nullable', 'string', 'max:255'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'in:manual,assisted,automatic'],
            'scrape_limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'follow_up_1_delay_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'follow_up_2_delay_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]) + [
            'dry_run' => $request->boolean('dry_run', true),
            'kill_switch' => $request->boolean('kill_switch'),
            'status' => $request->boolean('kill_switch') ? 'paused' : 'active',
        ]);

        return back()->with('status', 'Campaña creada.');
    }

    public function campaignKillSwitch(Campaign $campaign, FollowUpService $followUps): mixed
    {
        $campaign->update(['kill_switch' => true, 'status' => 'paused']);
        foreach ($campaign->scrapeRuns()->whereIn('status', ['queued', 'running', 'failed'])->get() as $run) {
            app(GoogleMapsScraperService::class)->cancel($run);
        }
        ContactAttempt::where('campaign_id', $campaign->id)
            ->where('status', 'planned')
            ->update(['status' => 'paused', 'result' => 'campaign_kill_switch']);

        return back()->with('status', 'Campaña pausada. Follow-ups pendientes cancelados.');
    }

    public function update(Request $request, Campaign $campaign): mixed
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'segment' => 'nullable|string|max:255', 'country' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255',
            'offer_id' => 'nullable|exists:offers,id', 'language' => 'required|string|max:10', 'source' => 'required|in:google_maps,csv_manual,referral,web',
            'channel' => 'required|in:email,phone,whatsapp,manual', 'daily_limit' => 'required|integer|min:1|max:500',
            'scrape_limit' => 'required|integer|min:1|max:500', 'follow_up_1_delay_days' => 'required|integer|min:1|max:60', 'follow_up_2_delay_days' => 'required|integer|min:1|max:90',
        ]);
        $campaign->update($data);

        return back()->with('status', 'Campaña actualizada.');
    }

    public function resume(Campaign $campaign): mixed
    {
        DB::transaction(function () use ($campaign) {
            $campaign->update(['kill_switch' => false, 'status' => 'active']);
            $campaign->contactAttempts()->where('status', 'paused')->where('result', 'campaign_kill_switch')
                ->whereHas('lead', fn ($q) => $q->whereNull('excluded_at')->whereHas('campaigns', fn ($c) => $c->where('campaigns.id', $campaign->id)->whereNotIn('campaign_lead.status', ['PAUSED', 'WON', 'LOST', 'REPLIED', 'INTERESTED', 'PROPOSAL', 'NEGOTIATION'])))
                ->whereDoesntHave('lead.replies', fn ($q) => $q->where('campaign_id', $campaign->id))
                ->update(['status' => 'planned', 'result' => null]);
        });

        return back()->with('status', 'Campaña reactivada.');
    }

    public function attachLead(Request $request, Campaign $campaign): mixed
    {
        $data = $request->validate(['lead_id' => 'required|exists:leads,id']);
        app(CampaignMembership::class)->attach(Lead::findOrFail($data['lead_id']), $campaign, true);

        return back()->with('status', 'Prospecto vinculado.');
    }
}
