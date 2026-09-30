<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\ContactAttempt;
use App\Models\Exclusion;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\Lead;
use App\Models\LlmBridgeRun;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use App\Models\ScrapeRun;
use App\Services\EmailContactService;
use App\Services\ExperimentService;
use App\Services\ExportService;
use App\Services\FollowUpService;
use App\Services\GoogleMapsScraperService;
use App\Services\LeadFilterService;
use App\Services\LeadImporter;
use App\Services\LeadPipeline;
use App\Services\LlmBridgeService;
use App\Services\MetricsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SalesController extends Controller
{
    public function dashboard(MetricsService $metrics)
    {
        $funnel = $metrics->funnel();

        return view('sales.dashboard', [
            'metrics' => $funnel,
            'readyToContact' => Lead::where('status', 'QUEUED')->orderByDesc('score_total')->limit(8)->get(),
            'overdueFollowUps' => ContactAttempt::with('lead', 'campaign')
                ->where('status', 'planned')
                ->whereDate('due_at', '<=', now())
                ->latest()
                ->limit(8)
                ->get(),
            'pendingReplies' => Reply::with('lead')
                ->where('requires_human', true)
                ->latest()
                ->limit(8)
                ->get(),
            'pendingProposals' => Proposal::with('lead')
                ->where(fn ($query) => $query->whereNull('result')->orWhere('result', 'pending'))
                ->latest()
                ->limit(8)
                ->get(),
            'possibleDuplicates' => Lead::with('possibleDuplicateOf')
                ->where('duplicate_status', 'possible_duplicate')
                ->latest()
                ->limit(8)
                ->get(),
            'activeCampaigns' => Campaign::with('offer')->where('status', 'active')->latest()->limit(6)->get(),
        ]);
    }

    public function campaigns()
    {
        return view('sales.campaigns', [
            'campaigns' => Campaign::with('offer')->withCount(['queries', 'contactAttempts'])->latest()->get(),
            'offers' => Offer::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function campaignWorkspace(Request $request, Campaign $campaign, MetricsService $metrics, LeadFilterService $filters)
    {
        $tab = $request->query('tab', 'summary');
        $baseFilters = ['campaign' => $campaign->id] + $filters->fromRequest($request);
        $leadQuery = $filters->query($baseFilters);
        $leads = $tab === 'leads' ? $leadQuery->paginate(25)->withQueryString() : collect();

        return view('sales.campaign-workspace', [
            'campaign' => $campaign->load('offer'),
            'tab' => $tab,
            'stats' => $this->campaignStats($campaign),
            'filters' => $baseFilters,
            'filterQuery' => $filters->cleanQuery($request->query()),
            'leads' => $leads,
            'statuses' => Lead::STATUSES,
            'offers' => Offer::where('active', true)->orderBy('name')->get(),
            'campaigns' => Campaign::orderBy('name')->get(),
            'sources' => Lead::select('source')->distinct()->pluck('source')->filter()->values(),
            'signals' => DB::table('signals')->select('type')->distinct()->pluck('type')->filter()->values(),
            'queries' => $campaign->queries()->latest()->limit(20)->get(),
            'scrapeRuns' => $campaign->scrapeRuns()->latest()->limit(20)->get(),
            'imports' => $campaign->importRuns()->latest()->limit(20)->get(),
            'queueLeads' => $this->campaignLeads($campaign, ['QUEUED', 'CONTACTED'])->with(['suggestedOffer', 'signalRows', 'scoreComponents', 'contactAttempts'])->limit(20)->get(),
            'templates' => MessageTemplate::where('active', true)->get(),
            'experiments' => $campaign->experiments()->with('variants')->latest()->get(),
            'metricRows' => $metrics->segmented($request->query('dimension', 'city')),
            'dimension' => $request->query('dimension', 'city'),
            'recentReplies' => Reply::with('lead')
                ->whereHas('lead.contactAttempts', fn ($query) => $query->where('campaign_id', $campaign->id))
                ->latest()
                ->limit(8)
                ->get(),
            'pendingFollowUps' => ContactAttempt::with('lead')
                ->where('campaign_id', $campaign->id)
                ->where('status', 'planned')
                ->whereDate('due_at', '<=', now())
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }

    public function storeCampaign(Request $request)
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
            'status' => 'active',
        ]);

        return back()->with('status', 'Campaña creada.');
    }

    public function offers()
    {
        return view('sales.offers', [
            'offers' => Offer::latest()->get(),
        ]);
    }

    public function storeOffer(Request $request)
    {
        Offer::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'target_customer' => ['nullable', 'string', 'max:255'],
            'segment' => ['nullable', 'string', 'max:255'],
            'pains' => ['nullable', 'string'],
            'benefits' => ['nullable', 'string'],
            'compatibility_signals' => ['nullable', 'string'],
            'assets' => ['nullable', 'string'],
            'demo_url' => ['nullable', 'string', 'max:255'],
            'portfolio_url' => ['nullable', 'string', 'max:255'],
            'suggested_cta' => ['nullable', 'string', 'max:255'],
        ]) + ['active' => true]);

        return back()->with('status', 'Oferta creada.');
    }

    public function importForm()
    {
        return view('sales.import', [
            'campaigns' => Campaign::orderBy('name')->get(),
        ]);
    }

    public function import(Request $request, LeadImporter $importer)
    {
        $data = $request->validate([
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'csv' => ['required', 'file', 'mimes:csv,txt'],
            'query' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $run = $importer->importCsv($request->file('csv'), Campaign::findOrFail($data['campaign_id']), $data);

        $target = $request->filled('redirect_campaign')
            ? route('campaigns.show', ['campaign' => $data['campaign_id'], 'tab' => 'leads'])
            : '/leads';

        return redirect($target)->with('status', "Importación lista. Nuevos: {$run->imported_count}. Posibles duplicados: {$run->possible_duplicate_count}. Duplicados: {$run->duplicate_count}.");
    }

    public function leads(Request $request, LeadFilterService $filters)
    {
        $filterData = $filters->fromRequest($request);

        return view('sales.leads', [
            'leads' => $filters->query($filterData)->paginate(25)->withQueryString(),
            'filters' => $filterData,
            'filterQuery' => $filters->cleanQuery($request->query()),
            'statuses' => Lead::STATUSES,
            'campaigns' => Campaign::orderBy('name')->get(),
            'offers' => Offer::where('active', true)->orderBy('name')->get(),
            'sources' => Lead::select('source')->distinct()->pluck('source')->filter()->values(),
            'signals' => DB::table('signals')->select('type')->distinct()->pluck('type')->filter()->values(),
        ]);
    }

    public function leadShow(Lead $lead)
    {
        $lead->load([
            'suggestedOffer',
            'signalRows',
            'scoreComponents',
            'contactAttempts.campaign',
            'replies.contactAttempt',
            'proposals',
            'sources.importRun',
            'statusHistories',
            'possibleDuplicateOf',
            'possibleDuplicates',
        ]);

        return view('sales.lead-detail', [
            'lead' => $lead,
            'statuses' => Lead::STATUSES,
            'campaigns' => Campaign::where('status', 'active')->where('kill_switch', false)->orderBy('name')->get(),
            'templates' => MessageTemplate::where('active', true)->get(),
            'categories' => Reply::CATEGORIES,
        ]);
    }

    public function bulkLeadAction(Request $request, LeadFilterService $filters, LeadPipeline $pipeline)
    {
        $data = $request->validate([
            'action' => ['required', 'in:queue,change_offer,change_status,exclude,mark_review,export'],
            'selected_ids' => ['nullable', 'array'],
            'selected_ids.*' => ['integer', 'exists:leads,id'],
            'offer_id' => ['nullable', 'exists:offers,id'],
            'status' => ['nullable', 'in:'.implode(',', Lead::STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $ids = array_map('intval', $data['selected_ids'] ?? []);

        if ($data['action'] === 'export') {
            return redirect()->route('exports.csv', ['dataset' => 'leads', 'ids' => implode(',', $ids)]);
        }

        if ($ids === []) {
            return back()->withErrors('Selecciona al menos un lead.');
        }

        $leads = Lead::whereIn('id', $ids)->get();

        foreach ($leads as $lead) {
            match ($data['action']) {
                'queue' => $pipeline->move($lead, 'QUEUED', 'bulk_queue'),
                'change_status' => $pipeline->move($lead, $data['status'], $data['reason'] ?: 'bulk_status_update'),
                'change_offer' => $lead->update(['suggested_offer_id' => $data['offer_id']]),
                'mark_review' => $lead->update(['notes' => trim(($lead->notes ? "{$lead->notes}\n" : '').'Revisar manualmente.')]),
                'exclude' => $this->excludeLead($lead, $data['reason'] ?? 'bulk_exclusion'),
            };
        }

        return back()->with('status', count($ids).' leads actualizados.');
    }

    public function updateLeadStatus(Request $request, Lead $lead, LeadPipeline $pipeline)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', Lead::STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $pipeline->move($lead, $data['status'], $data['reason'] ?: 'manual_update');

        return back()->with('status', 'Estado actualizado.');
    }

    public function queue(Request $request)
    {
        $campaign = $request->query('campaign') ? Campaign::find($request->query('campaign')) : null;
        $query = $campaign
            ? $this->campaignLeads($campaign, ['QUEUED', 'CONTACTED'])
            : Lead::whereIn('status', ['QUEUED', 'CONTACTED']);

        return view('sales.queue', [
            'campaign' => $campaign,
            'leads' => $query
                ->orderByDesc('score_total')
                ->with(['contactAttempts', 'suggestedOffer', 'signalRows', 'scoreComponents'])
                ->paginate(12)
                ->withQueryString(),
            'campaigns' => Campaign::where('status', 'active')->where('kill_switch', false)->get(),
            'templates' => MessageTemplate::where('active', true)->get(),
        ]);
    }

    public function storeContactAttempt(Request $request, LeadPipeline $pipeline, FollowUpService $followUps)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'channel' => ['required', 'string', 'max:255'],
            'variant' => ['nullable', 'string', 'max:255'],
            'step' => ['required', 'in:initial,follow_up_1,follow_up_2,closed'],
            'planned_at' => ['nullable', 'date'],
            'performed_at' => ['nullable', 'date'],
            'message_snapshot' => ['nullable', 'string'],
            'result' => ['nullable', 'string', 'max:255'],
            'message_template_id' => ['nullable', 'exists:message_templates,id'],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        $lead = Lead::findOrFail($data['lead_id']);

        $hasActive = $lead->contactAttempts()
            ->whereIn('status', ['planned', 'sent'])
            ->exists();

        if ($hasActive && ($data['performed_at'] ?? null) === null) {
            return back()->withErrors('El lead ya tiene una secuencia activa.');
        }

        $attempt = ContactAttempt::create($data + [
            'status' => filled($data['performed_at'] ?? null) ? 'sent' : 'planned',
            'manual_intervention' => true,
            'dry_run' => Campaign::findOrFail($data['campaign_id'])->dry_run,
        ]);

        if (filled($data['performed_at'] ?? null)) {
            $pipeline->move($lead, 'CONTACTED', 'contact_attempt_recorded');
            $followUps->scheduleNext($lead, Campaign::findOrFail($data['campaign_id']), $attempt->step);
        }

        return back()->with('status', 'Contacto registrado.');
    }

    public function replies()
    {
        return view('sales.replies', [
            'leads' => Lead::whereIn('status', ['CONTACTED', 'REPLIED', 'INTERESTED', 'PROPOSAL'])->orderBy('name')->get(),
            'attempts' => ContactAttempt::whereNotNull('performed_at')->latest()->get(),
            'categories' => Reply::CATEGORIES,
            'replies' => Reply::latest()->limit(50)->get(),
        ]);
    }

    public function storeReply(Request $request, LeadPipeline $pipeline, FollowUpService $followUps)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'contact_attempt_id' => ['nullable', 'exists:contact_attempts,id'],
            'category' => ['required', 'in:'.implode(',', Reply::CATEGORIES)],
            'body' => ['nullable', 'string'],
            'received_at' => ['required', 'date'],
            'objection_type' => ['nullable', 'string', 'max:255'],
            'objection_notes' => ['nullable', 'string'],
        ]);

        $reply = Reply::create($data + ['requires_human' => true]);
        $lead = Lead::findOrFail($data['lead_id']);

        $followUps->pauseForReply($lead);

        $pipeline->move($lead, $this->statusForReply($reply->category), 'reply_recorded');

        return back()->with('status', 'Respuesta registrada. Seguimientos pendientes pausados.');
    }

    public function proposals()
    {
        return view('sales.proposals', [
            'leads' => Lead::whereIn('status', ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'])->orderBy('name')->get(),
            'proposals' => Proposal::latest()->limit(50)->get(),
        ]);
    }

    public function pipeline(Request $request, ?Campaign $campaign = null)
    {
        $query = $campaign ? $this->campaignLeads($campaign) : Lead::query();
        $leads = $query->with(['suggestedOffer', 'replies', 'proposals'])
            ->whereIn('status', ['REPLIED', 'INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'])
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('status');

        return view('sales.pipeline', [
            'campaign' => $campaign,
            'columns' => ['REPLIED', 'INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'],
            'leadsByStatus' => $leads,
            'statuses' => Lead::STATUSES,
        ]);
    }

    public function storeProposal(Request $request, LeadPipeline $pipeline)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'service' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'version' => ['required', 'integer', 'min:1'],
            'sent_at' => ['nullable', 'date'],
            'response' => ['nullable', 'string'],
            'objections' => ['nullable', 'string'],
            'modifications' => ['nullable', 'string'],
            'result' => ['nullable', 'in:pending,won,lost'],
        ]);

        Proposal::create($data);
        $lead = Lead::findOrFail($data['lead_id']);
        $status = match ($data['result'] ?? 'pending') {
            'won' => 'WON',
            'lost' => 'LOST',
            default => 'PROPOSAL',
        };

        $pipeline->move($lead, $status, 'proposal_recorded');

        return back()->with('status', 'Propuesta registrada.');
    }

    public function exports(ExportService $exports)
    {
        return view('sales.exports', [
            'datasets' => array_keys(ExportService::DATASETS),
            'databasePath' => database_path('database.sqlite'),
            'databaseExists' => File::exists(database_path('database.sqlite')),
        ]);
    }

    public function exportCsv(Request $request, string $dataset, ExportService $exports, LeadFilterService $filters)
    {
        return $exports->csv($dataset, $filters->fromRequest($request));
    }

    public function exportJson(Request $request, ExportService $exports, LeadFilterService $filters)
    {
        return response()->json($exports->json($filters->fromRequest($request)))
            ->header('Content-Disposition', 'attachment; filename="commercial-export.json"');
    }

    public function exportPhones(Request $request, ExportService $exports, LeadFilterService $filters)
    {
        return $exports->phones($filters->fromRequest($request));
    }

    public function exportSqlite()
    {
        return response()->download(database_path('database.sqlite'), 'ventas-snapshot.sqlite');
    }

    public function scraper()
    {
        return view('sales.scraper', [
            'campaigns' => Campaign::with('queries')->latest()->get(),
            'queries' => CampaignQuery::latest()->limit(100)->get(),
            'runs' => ScrapeRun::latest()->limit(50)->get(),
        ]);
    }

    public function storeCampaignQuery(Request $request)
    {
        CampaignQuery::create($request->validate([
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'query' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'result_limit' => ['required', 'integer', 'min:1', 'max:500'],
        ]) + ['status' => 'ready']);

        return back()->with('status', 'Query creada.');
    }

    public function runScraper(Request $request, GoogleMapsScraperService $scraper)
    {
        $data = $request->validate([
            'campaign_query_id' => ['required', 'exists:campaign_queries,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        $query = CampaignQuery::findOrFail($data['campaign_query_id']);
        $dryRun = $request->boolean('dry_run', true);
        $run = $scraper->start($query->campaign, $query, $dryRun);

        $message = $dryRun
            ? "Dry-run listo para la query #{$query->id}."
            : "Scraper iniciado en segundo plano. Run #{$run->id}. Refresca resultados en unos minutos.";

        return back()->with('status', $message);
    }

    public function runCampaignAcquisition(Request $request, Campaign $campaign, GoogleMapsScraperService $scraper)
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'result_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $queryData = $data;
        unset($queryData['dry_run']);

        $query = CampaignQuery::create($queryData + [
            'campaign_id' => $campaign->id,
            'status' => 'ready',
        ]);
        $dryRun = $request->boolean('dry_run', true);
        $run = $scraper->start($campaign, $query, $dryRun);
        $message = $dryRun
            ? "Dry-run listo. Query #{$query->id}."
            : "Adquisición iniciada en segundo plano. Run #{$run->id}. Refresca esta pestaña para ver encontrados, importados y errores.";

        return redirect()->route('campaigns.show', ['campaign' => $campaign->id, 'tab' => 'acquisition'])
            ->with('status', $message);
    }

    public function templates()
    {
        return view('sales.templates', [
            'templates' => MessageTemplate::latest()->get(),
            'offers' => Offer::where('active', true)->get(),
        ]);
    }

    public function storeTemplate(Request $request)
    {
        MessageTemplate::create($request->validate([
            'offer_id' => ['nullable', 'exists:offers,id'],
            'name' => ['required', 'string', 'max:255'],
            'language' => ['required', 'string', 'max:10'],
            'channel' => ['required', 'string', 'max:80'],
            'segment' => ['nullable', 'string', 'max:255'],
            'stage' => ['required', 'in:initial,follow_up_1,follow_up_2'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]) + ['active' => true]);

        return back()->with('status', 'Plantilla creada.');
    }

    public function sendEmail(Request $request, EmailContactService $email, LeadPipeline $pipeline, FollowUpService $followUps)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'message_template_id' => ['required', 'exists:message_templates,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        $lead = Lead::findOrFail($data['lead_id']);
        $campaign = Campaign::findOrFail($data['campaign_id']);
        $template = MessageTemplate::findOrFail($data['message_template_id']);
        $attempt = $email->sendInitial($lead, $campaign, $template, $request->boolean('dry_run', true));
        $pipeline->move($lead, 'CONTACTED', 'email_contact_recorded');
        $followUps->scheduleNext($lead, $campaign, $attempt->step);

        return back()->with('status', 'Email registrado.');
    }

    public function experiments()
    {
        return view('sales.experiments', [
            'campaigns' => Campaign::latest()->get(),
            'templates' => MessageTemplate::latest()->get(),
            'experiments' => Experiment::with('variants')->latest()->get(),
        ]);
    }

    public function storeExperiment(Request $request)
    {
        $experiment = Experiment::create($request->validate([
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'name' => ['required', 'string', 'max:255'],
            'variable' => ['required', 'string', 'max:80'],
            'status' => ['required', 'in:draft,active,paused,completed'],
        ]));

        return back()->with('status', "Experimento {$experiment->name} creado.");
    }

    public function storeVariant(Request $request)
    {
        ExperimentVariant::create($request->validate([
            'experiment_id' => ['required', 'exists:experiments,id'],
            'message_template_id' => ['nullable', 'exists:message_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]));

        return back()->with('status', 'Variante creada.');
    }

    public function assignVariant(Request $request, ExperimentService $experiments)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'experiment_id' => ['required', 'exists:experiments,id'],
        ]);
        $variant = $experiments->assign(Lead::findOrFail($data['lead_id']), Experiment::findOrFail($data['experiment_id']));

        return back()->with('status', $variant ? "Variante asignada: {$variant->name}." : 'El experimento no tiene variantes.');
    }

    public function segmentedMetrics(Request $request, MetricsService $metrics)
    {
        $dimension = $request->query('dimension', 'campaign');

        return view('sales.metrics', [
            'dimension' => $dimension,
            'rows' => $metrics->segmented($dimension),
        ]);
    }

    public function exclusions()
    {
        return view('sales.exclusions', [
            'exclusions' => Exclusion::latest()->paginate(100),
        ]);
    }

    public function settings()
    {
        return view('sales.settings', [
            'offers' => Offer::latest()->get(),
            'templates' => MessageTemplate::latest()->get(),
            'exclusions' => Exclusion::latest()->limit(30)->get(),
        ]);
    }

    public function storeExclusion(Request $request)
    {
        Exclusion::updateOrCreate($request->validate([
            'type' => ['required', 'in:email,domain,phone,name,source_identifier'],
            'value' => ['required', 'string', 'max:255'],
        ]), [
            'reason' => $request->input('reason'),
        ]);

        return back()->with('status', 'Exclusión guardada.');
    }

    public function deleteExclusion(Exclusion $exclusion)
    {
        $exclusion->delete();

        return back()->with('status', 'Exclusión eliminada.');
    }

    public function llmBridge()
    {
        return view('sales.llm', [
            'leads' => Lead::orderByDesc('score_total')->limit(200)->get(),
            'runs' => LlmBridgeRun::latest()->limit(50)->get(),
        ]);
    }

    public function createLlmPrompt(Request $request, LlmBridgeService $bridge)
    {
        $data = $request->validate([
            'use_case' => ['required', 'string', 'max:80'],
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
        ]);
        $run = $bridge->createPrompt($data['use_case'], array_map('intval', $data['lead_ids']));

        return redirect('/llm-bridge')->with('status', "Prompt creado #{$run->id}.");
    }

    public function importLlmJson(Request $request, LlmBridgeService $bridge)
    {
        $data = $request->validate([
            'llm_bridge_run_id' => ['required', 'exists:llm_bridge_runs,id'],
            'json' => ['required', 'string'],
        ]);
        $result = $bridge->importJson(LlmBridgeRun::findOrFail($data['llm_bridge_run_id']), $data['json']);

        if (! $result['ok']) {
            return back()->withErrors($result['errors']);
        }

        return back()->with('status', 'Sugerencias importadas.');
    }

    public function exportAnalytics(Request $request, ExportService $exports, LeadFilterService $filters)
    {
        return $exports->analyticsCsv($filters->fromRequest($request));
    }

    public function campaignKillSwitch(Campaign $campaign, FollowUpService $followUps)
    {
        $campaign->update(['kill_switch' => true, 'status' => 'paused']);
        ContactAttempt::where('campaign_id', $campaign->id)
            ->where('status', 'planned')
            ->update(['status' => 'paused', 'result' => 'campaign_kill_switch']);

        return back()->with('status', 'Campaña pausada. Follow-ups pendientes cancelados.');
    }

    private function statusForReply(string $category): string
    {
        return match ($category) {
            'interested', 'meeting', 'information_request', 'price_question' => 'INTERESTED',
            'proposal' => 'PROPOSAL',
            'won' => 'WON',
            'lost', 'negative' => 'LOST',
            default => 'REPLIED',
        };
    }

    private function campaignStats(Campaign $campaign): array
    {
        $leadBase = $this->campaignLeads($campaign);
        $leadIds = (clone $leadBase)->pluck('leads.id');

        return [
            'leads' => $leadIds->count(),
            'qualified' => (clone $leadBase)->where('score_total', '>=', 45)->count(),
            'queue' => (clone $leadBase)->where('status', 'QUEUED')->count(),
            'contacts' => ContactAttempt::where('campaign_id', $campaign->id)->whereNotNull('performed_at')->count(),
            'replies' => Reply::whereIn('lead_id', $leadIds)->count(),
            'interested' => (clone $leadBase)->whereIn('status', ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'])->count(),
            'proposals' => Proposal::whereIn('lead_id', $leadIds)->count(),
            'won' => (clone $leadBase)->where('status', 'WON')->count(),
            'lost' => (clone $leadBase)->where('status', 'LOST')->count(),
            'possible_duplicates' => (clone $leadBase)->where('duplicate_status', 'possible_duplicate')->count(),
        ];
    }

    private function campaignLeads(Campaign $campaign, array $statuses = []): Builder
    {
        $query = Lead::query()->where(function ($nested) use ($campaign) {
            $nested->whereHas('sources.importRun', fn ($source) => $source->where('campaign_id', $campaign->id))
                ->orWhereHas('contactAttempts', fn ($attempt) => $attempt->where('campaign_id', $campaign->id))
                ->orWhere(function ($fallback) use ($campaign) {
                    $fallback->where('segment', $campaign->segment)
                        ->where('country', $campaign->country);
                });
        });

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        return $query;
    }

    private function excludeLead(Lead $lead, string $reason): void
    {
        foreach ([
            'email' => $lead->email,
            'domain' => $lead->domain,
            'phone' => $lead->phone,
            'source_identifier' => $lead->source_identifier,
        ] as $type => $value) {
            if ($value) {
                Exclusion::firstOrCreate(['type' => $type, 'value' => $value], ['reason' => $reason]);
            }
        }

        $lead->update([
            'status' => 'PAUSED',
            'excluded_at' => now(),
            'exclusion_reason' => $reason,
        ]);
    }
}
