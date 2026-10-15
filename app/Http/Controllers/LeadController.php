<?php

namespace App\Http\Controllers;

use App\Jobs\EnrichLead;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Models\Reply;
use App\Models\SavedLeadView;
use App\Models\Signal;
use App\Models\SystemLog;
use App\Services\CampaignMembership;
use App\Services\ContactNormalizer;
use App\Services\ExclusionService;
use App\Services\LeadContactSynchronizer;
use App\Services\LeadFilterService;
use App\Services\LeadIdentityMatcher;
use App\Services\LeadPipeline;
use App\Services\LeadScorer;
use App\Services\NotionValueParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function leads(Request $request, LeadFilterService $filters): mixed
    {
        $data = $filters->fromRequest($request);

        return view('sales.leads', ['leads' => $filters->query($data, ['campaigns', 'suggestedOffer', 'signalRows', 'possibleDuplicateOf', 'offerScores.offer'])->paginate(25)->withQueryString(), 'filters' => $data,
            'filterQuery' => $filters->cleanQuery($data), 'statuses' => Lead::STATUSES,
            'campaigns' => Campaign::orderBy('name')->get(), 'offers' => Offer::where('active', true)->orderBy('name')->get(),
            'sources' => Lead::distinct()->pluck('source'), 'signals' => Signal::distinct()->pluck('type'),
            'savedViews' => SavedLeadView::where('scope', 'global')->orderBy('name')->get()]);
    }

    private function validateLead(Request $request, ?Lead $lead = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'category' => 'nullable|string|max:255', 'segment' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255', 'country' => 'nullable|string|max:255',
            'language' => 'required|string|max:10', 'website_url' => 'nullable|string|max:2048', 'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:40', 'contact_person' => 'nullable|string|max:255', 'contact_role' => 'nullable|string|max:255',
            'relationship_type' => 'required|in:prospect,collaborator,customer',
            'entity_type' => 'sometimes|required|in:person,organization', 'is_collaborator' => 'sometimes|boolean',
            'manual_priority' => 'sometimes|nullable|in:high,medium,low', 'classification_pending' => 'sometimes|boolean',
            'estimated_value' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'],
            'estimated_currency' => 'sometimes|nullable|required_with:estimated_value|alpha:ascii|size:3', 'tags' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:20000', 'source' => 'required|in:google_maps,csv_manual,referral,web,manual,notion',
            'suggested_offer_id' => 'nullable|exists:offers,id',
        ]);
        if (($data['entity_type'] ?? $lead?->entity_type) === 'person' && $lead?->contacts()->exists()) {
            throw ValidationException::withMessages(['entity_type' => 'Revisa los contactos asociados antes de convertir la organización en persona.']);
        }
        $normalizer = app(ContactNormalizer::class);
        if (filled($data['website_url'] ?? null) && ! $normalizer->url($data['website_url'])) {
            throw ValidationException::withMessages(['website_url' => 'Ingresá una dirección web válida.']);
        }
        if (filled($data['phone'] ?? null) && ! $normalizer->phone($data['phone'], $data['country'] ?? null)) {
            throw ValidationException::withMessages(['phone' => 'Revisá el teléfono y el país.']);
        }
        $data['email'] = $normalizer->email($data['email'] ?? null);
        $data['website_url'] = $normalizer->url($data['website_url'] ?? null);
        $data['domain'] = $normalizer->domain($data['website_url']);
        $data['phone_original'] = $data['phone'] ?? null;
        if ($lead && ($data['phone'] ?? null) === $lead->phone) {
            $data['phone_original'] = $lead->phone_original;
        }
        $data['phone'] = $normalizer->phone($data['phone'] ?? null, $data['country'] ?? null);
        $data['is_customer'] = $data['relationship_type'] === 'customer';
        $data['is_collaborator'] = $data['relationship_type'] === 'collaborator' || $request->boolean('is_collaborator');
        if (array_key_exists('estimated_value', $data)) {
            $data['estimated_value_minor'] = app(NotionValueParser::class)->minorFromDecimal($data['estimated_value']);
            unset($data['estimated_value']);
        }
        if (array_key_exists('estimated_currency', $data)) {
            $data['estimated_currency'] = filled($data['estimated_currency']) ? strtoupper($data['estimated_currency']) : null;
        }
        $data['tags'] = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['tags'] ?? '')))));

        return $data;
    }

    public function store(Request $request): mixed
    {
        $request->validate(['campaign_id' => 'nullable|exists:campaigns,id']);
        $data = $this->validateLead($request);
        $identity = app(LeadIdentityMatcher::class)->match($data);
        if ($identity['status'] === 'strong' || ($identity['status'] === 'possible' && ! $request->boolean('confirm_distinct'))) {
            throw ValidationException::withMessages(['name' => 'Existe una coincidencia con el registro #'.$identity['lead']->id.'. Revisa la cartera o confirma que se trata de una identidad distinta.']);
        }
        $lead = DB::transaction(function () use ($data, $request) {
            $lead = Lead::create($data + ['status' => 'NEW', 'acquired_at' => now()]);
            if ($request->filled('campaign_id')) {
                app(CampaignMembership::class)->attach($lead, Campaign::findOrFail($request->integer('campaign_id')));
            }
            $lead->sources()->create(['source' => $lead->source, 'observed_at' => now()]);
            app(LeadScorer::class)->score($lead);

            return $lead;
        });
        EnrichLead::dispatch($lead->id)->onQueue('sales');

        return redirect()->route('leads.show', $lead)->with('status', 'Registro comercial creado.');
    }

    public function update(Request $request, Lead $lead): mixed
    {
        $previousWebsite = $lead->website_url;
        $lead = app(LeadContactSynchronizer::class)->updateLead($lead, $this->validateLead($request, $lead));
        if ($previousWebsite !== $lead->website_url) {
            EnrichLead::dispatch($lead->id)->onQueue('sales');
        }
        app(LeadScorer::class)->score($lead);
        SystemLog::create(['type' => 'lead_edit', 'message' => 'Datos y notas actualizados.', 'context' => ['lead_id' => $lead->id]]);

        return back()->with('status', 'Datos actualizados.');
    }

    public function leadShow(Request $request, Lead $lead): mixed
    {
        $lead->load(['suggestedOffer', 'campaigns', 'signalRows', 'scoreComponents', 'contactAttempts.campaign', 'replies.contactAttempt', 'proposals', 'sources.importRun', 'statusHistories', 'possibleDuplicateOf', 'possibleDuplicates', 'contacts', 'services.proposal', 'offerScores.offer']);
        $request->validate(['history_campaign' => 'nullable|integer|exists:campaigns,id']);
        if ($request->filled('history_campaign')) {
            abort_unless($lead->campaigns->contains('id', $request->integer('history_campaign')), 422);
        }
        $timeline = collect();
        foreach ($lead->sources as $row) {
            $timeline->push(['at' => $row->observed_at ?? $row->created_at, 'title' => 'Fuente registrada', 'detail' => $row->source.' · '.$row->query, 'campaign_id' => $row->importRun?->campaign_id]);
        }
        foreach ($lead->contactAttempts as $row) {
            $timeline->push(['at' => $row->performed_at ?? $row->created_at, 'title' => $row->dry_run ? 'Simulación de contacto' : 'Contacto '.$row->step, 'detail' => $row->channel.' · '.$row->status, 'campaign_id' => $row->campaign_id, 'score_snapshot' => $row->score_snapshot]);
        }
        foreach ($lead->replies as $row) {
            $timeline->push(['at' => $row->received_at, 'title' => 'Respuesta: '.$row->category, 'detail' => $row->body, 'campaign_id' => $row->campaign_id]);
        }
        foreach ($lead->proposals as $row) {
            $timeline->push(['at' => $row->sent_at ?? $row->created_at, 'title' => 'Propuesta · versión '.$row->version, 'detail' => $row->service.' · '.$row->amount.' '.$row->currency.' · '.$row->result, 'campaign_id' => $row->campaign_id]);
        }
        foreach ($lead->statusHistories as $row) {
            $timeline->push(['at' => $row->changed_at, 'title' => Lead::statusLabel($row->to_status), 'detail' => $row->reason, 'campaign_id' => $row->campaign_id]);
        }
        foreach (SystemLog::where('context->lead_id', $lead->id)->where('type', 'lead_edit')->get() as $row) {
            $timeline->push(['at' => $row->created_at, 'title' => $row->message, 'detail' => '']);
        }

        $timeline = $timeline->map(function ($event) use ($lead) {
            $name = $lead->campaigns->firstWhere('id', $event['campaign_id'] ?? null)?->name;
            if ($name) {
                $event['detail'] = $name.' · '.$event['detail'];
            }

            return $event;
        })->when($request->filled('history_campaign'), fn ($events) => $events->where('campaign_id', $request->integer('history_campaign')));
        $merges = DB::table('lead_merges')->where('target_lead_id', $lead->id)->orderByDesc('id')->get();

        return view('sales.lead-detail', ['lead' => $lead, 'timeline' => $timeline->sortByDesc('at'), 'merges' => $merges, 'statuses' => Lead::STATUSES,
            'campaigns' => Campaign::where('status', 'active')->where('kill_switch', false)->orderBy('name')->get(),
            'offers' => Offer::where('active', true)->orWhereIn('id', $lead->campaigns->pluck('offer_id')->merge($lead->campaigns->pluck('pivot.offer_id'))->push($lead->suggested_offer_id)->filter())->orderBy('name')->get(),
            'currentScoreIds' => $lead->offerScores()->current()->pluck('id'),
            'templates' => MessageTemplate::where('active', true)->get(), 'categories' => Reply::CATEGORIES]);
    }

    public function bulkLeadAction(Request $request, LeadFilterService $filters, LeadPipeline $pipeline): mixed
    {
        $data = $request->validate([
            'action' => 'required|in:queue,change_offer,change_status,exclude,mark_review,export',
            'selected_ids' => 'required|array|min:1|max:2000', 'selected_ids.*' => 'integer|exists:leads,id',
            'offer_id' => 'required_if:action,change_offer|nullable|exists:offers,id',
            'status' => 'required_if:action,change_status|nullable|in:'.implode(',', Lead::STATUSES), 'reason' => 'nullable|string|max:255',
            'campaign' => 'nullable|exists:campaigns,id', 'view_filters' => 'nullable|array',
        ]);
        if ($request->has('view_filters')) {
            $filterData = $filters->fromRequest(new Request($request->input('view_filters')));
        } else {
            $filterData = $filters->fromRequest($request);
            unset($filterData['status'], $filterData['offer_id']);
        }
        if (isset($data['campaign'])) {
            $filterData['campaign'] = $data['campaign'];
        }
        $leads = $filters->query($filterData)->whereIn('leads.id', $data['selected_ids'])->get();
        if ($leads->count() !== count(array_unique($data['selected_ids']))) {
            return back()->withErrors('La selección no corresponde a esta vista.');
        }
        if ($data['action'] === 'export') {
            return redirect()->route('exports.csv', ['dataset' => 'leads', 'ids' => $leads->modelKeys()] + $filterData);
        }
        DB::transaction(function () use ($data, $leads, $pipeline) {
            foreach ($leads as $lead) {
                $campaign = in_array($data['action'], ['queue', 'change_status', 'change_offer']) ? app(CampaignMembership::class)->resolve($lead, $data['campaign'] ?? null) : null;
                match ($data['action']) {
                    'queue' => $pipeline->move($lead, 'QUEUED', 'bulk_queue', $campaign),
                    'change_status' => $pipeline->move($lead, $data['status'], $data['reason'] ?? 'Cambio masivo', $campaign),
                    'change_offer' => $this->changeOffer($lead, (int) $data['offer_id'], $campaign),
                    'mark_review' => $lead->update(['notes' => trim(($lead->notes ?? '')."\nRevisar manualmente.")]),
                    'exclude' => app(ExclusionService::class)->exclude($lead, $data['reason'] ?? 'Exclusión manual'),
                };
            }
        });

        return back()->with('status', $leads->count().' prospectos actualizados.');
    }

    private function changeOffer(Lead $lead, int $offerId, ?Campaign $campaign): void
    {
        $lead->update(['suggested_offer_id' => $offerId, 'offer_match_reason' => 'Selección manual.']);
        if ($campaign) {
            $lead->campaigns()->updateExistingPivot($campaign->id, ['offer_id' => $offerId]);
        }
        app(LeadScorer::class)->score($lead);
    }

    public function updateLeadStatus(Request $request, Lead $lead, LeadPipeline $pipeline): mixed
    {
        $data = $request->validate(['status' => 'required|in:'.implode(',', Lead::STATUSES), 'reason' => 'nullable|string|max:255', 'campaign_id' => 'nullable|exists:campaigns,id']);
        $campaign = app(CampaignMembership::class)->resolve($lead, $data['campaign_id'] ?? null);
        $pipeline->move($lead, $data['status'], $data['reason'] ?? 'Cambio manual', $campaign);

        return back()->with('status', 'Estado actualizado.');
    }

    public function saveView(Request $request, LeadFilterService $filters): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'campaign' => 'nullable|exists:campaigns,id']);
        SavedLeadView::updateOrCreate(['name' => $data['name'], 'scope' => isset($data['campaign']) ? 'campaign:'.$data['campaign'] : 'global'], ['filters' => $filters->fromRequest($request)]);

        return back()->with('status', 'Vista guardada.');
    }

    public function deleteView(SavedLeadView $savedLeadView): mixed
    {
        $savedLeadView->delete();

        return back()->with('status', 'Vista eliminada.');
    }

    public function storeSignal(Request $request, Lead $lead): mixed
    {
        $data = $request->validate(['type' => 'required|in:ads_active,clear_offer,observed_problem', 'evidence' => 'required|string|max:5000', 'evidence_url' => 'nullable|url:http,https|max:2048', 'evidence_kind' => 'required|in:observed,inferred', 'review_status' => 'required|in:confirmed,pending']);
        $lead->signalRows()->updateOrCreate(['type' => $data['type'], 'source' => 'manual'], $data + ['value' => 'true', 'detected_at' => now(), 'source_reference' => $data['evidence_url'] ?? 'manual:lead:'.$lead->id,
            'extraction_method' => 'human_review', 'confidence' => 1, 'expires_at' => null, 'rule_version' => 'human-v1', 'is_current' => true]);
        app(LeadScorer::class)->score($lead);

        return back()->with('status', 'Evidencia guardada.');
    }

    public function reviewSignal(Request $request, Lead $lead, Signal $signal): mixed
    {
        abort_unless($signal->lead_id === $lead->id, 404);
        $data = $request->validate(['review_status' => 'required|in:confirmed,rejected,pending']);
        $signal->update($data);
        app(LeadScorer::class)->score($lead);

        return back()->with('status', 'Señal revisada.');
    }

    public function confirmEmail(Lead $lead): mixed
    {
        $email = $lead->signalRows()->where('type', 'channel_email_visible')->where('review_status', 'confirmed')->where('is_current', true)->whereIn('evidence_kind', ['observed', 'inferred'])->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('confidence')->value('value');
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422);
        app(LeadContactSynchronizer::class)->updateLead($lead, ['email' => $email]);
        app(LeadScorer::class)->score($lead);

        return back()->with('status', 'Email incorporado.');
    }

    public function enrich(Lead $lead): mixed
    {
        EnrichLead::dispatch($lead->id, true)->onQueue('sales');

        return back()->with('status', 'Revisión en cola.');
    }

    public function attachCampaign(Request $request, Lead $lead): mixed
    {
        $data = $request->validate(['campaign_id' => 'required|exists:campaigns,id']);
        app(CampaignMembership::class)->attach($lead, Campaign::findOrFail($data['campaign_id']), true);

        return back()->with('status', 'Prospecto vinculado a la campaña.');
    }

    public function restoreContactability(Lead $lead, ExclusionService $exclusions): mixed
    {
        if ($exclusions->matches($lead->getAttributes())) {
            return back()->withErrors('Eliminá primero las exclusiones que coinciden con este prospecto.');
        }
        $lead->update(['excluded_at' => null, 'exclusion_reason' => null]);
        app(LeadPipeline::class)->move($lead, 'ENRICHED', 'Exclusión revisada manualmente');

        return back()->with('status', 'Prospecto habilitado para revisión. Seleccioná la campaña para enviarlo a cola.');
    }

    public function separateDuplicate(Lead $lead): mixed
    {
        $lead->update(['duplicate_status' => 'unique', 'duplicate_rule' => null, 'possible_duplicate_of_lead_id' => null]);

        return back()->with('status', 'Marcado como negocio distinto.');
    }
}
