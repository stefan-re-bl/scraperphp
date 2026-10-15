<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\Lead;
use App\Models\LlmBridgeRun;
use App\Models\MessageTemplate;
use App\Models\Proposal;
use App\Models\Reply;
use App\Services\CampaignMembership;
use App\Services\CommercialWorkService;
use App\Services\ExperimentService;
use App\Services\LlmBridgeService;
use App\Services\MetricsService;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function dashboard(Request $request, MetricsService $metrics): mixed
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $funnel = $metrics->funnel($request->only(['from', 'to']));

        $ready = app(CampaignMembership::class)->ready();
        $followups = ContactAttempt::where('status', 'planned')->where('due_at', '<=', now())->whereHas('lead', fn ($q) => $q->whereNull('excluded_at'))->whereHas('campaign', fn ($q) => $q->where('status', 'active')->where('kill_switch', false));

        return view('sales.dashboard', [
            'metrics' => $funnel,
            'dailyActions' => app(CommercialWorkService::class)->actions(limit: 12),
            'pendingCounts' => [
                'queue' => (clone $ready)->count(),
                'followups' => (clone $followups)->count(),
                'replies' => Reply::where('requires_human', true)->count(),
                'proposals' => Proposal::where(fn ($q) => $q->whereNull('result')->orWhere('result', 'pending'))->count(),
                'duplicates' => Lead::where('duplicate_status', 'possible_duplicate')->count(),
            ],
            'readyToContact' => (clone $ready)->with(['offerScores.offer', 'campaigns'])->orderByRaw('resolved_score is null')->orderByDesc('resolved_score')->limit(8)->get(),
            'overdueFollowUps' => (clone $followups)->with('lead', 'campaign')->latest()->limit(8)->get(),
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

    public function experiments(): mixed
    {
        return view('sales.experiments', [
            'campaigns' => Campaign::latest()->get(),
            'templates' => MessageTemplate::latest()->get(),
            'leads' => Lead::orderBy('name')->get(['id', 'name']),
            'experiments' => Experiment::with(['variants' => fn ($q) => $q->withCount(['contactAttempts as real_attempts_count' => fn ($q) => $q->where('dry_run', false)->where('status', 'sent')->whereNotNull('performed_at')])])->latest()->get(),
        ]);
    }

    public function storeExperiment(Request $request): mixed
    {
        $experiment = Experiment::create($request->validate([
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'name' => ['required', 'string', 'max:255'],
            'variable' => ['required', 'string', 'max:80'],
            'status' => ['required', 'in:draft,active,paused,completed'],
        ]));

        return back()->with('status', "Experimento {$experiment->name} creado.");
    }

    public function storeVariant(Request $request): mixed
    {
        ExperimentVariant::create($request->validate([
            'experiment_id' => ['required', 'exists:experiments,id'],
            'message_template_id' => ['nullable', 'exists:message_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]));

        return back()->with('status', 'Variante creada.');
    }

    public function assignVariant(Request $request, ExperimentService $experiments): mixed
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'experiment_id' => ['required', 'exists:experiments,id'],
        ]);
        $variant = $experiments->assign(Lead::findOrFail($data['lead_id']), Experiment::findOrFail($data['experiment_id']));

        return back()->with('status', $variant ? "Variante asignada: {$variant->name}." : 'El experimento no tiene variantes.');
    }

    public function llmBridge(): mixed
    {
        return view('sales.llm', [
            'leads' => Lead::orderByDesc('score_total')->limit(200)->get(),
            'runs' => LlmBridgeRun::latest()->limit(50)->get(),
        ]);
    }

    public function createLlmPrompt(Request $request, LlmBridgeService $bridge): mixed
    {
        $data = $request->validate([
            'use_case' => ['required', 'string', 'max:80'],
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
        ]);
        $run = $bridge->createPrompt($data['use_case'], array_map('intval', $data['lead_ids']));

        return redirect('/llm-bridge')->with('status', "Prompt creado #{$run->id}.");
    }

    public function importLlmJson(Request $request, LlmBridgeService $bridge): mixed
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
}
