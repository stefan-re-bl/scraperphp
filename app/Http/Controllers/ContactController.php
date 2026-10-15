<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use App\Services\CampaignMembership;
use App\Services\ContactNormalizer;
use App\Services\ContactWorkflow;
use App\Services\EmailContactService;
use App\Services\ExclusionService;
use App\Services\ExperimentService;
use App\Services\FollowUpService;
use App\Services\LeadPipeline;
use App\Services\TemplateRenderer;
use App\Services\WhatsAppMessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactController extends Controller
{
    public function queue(Request $request, TemplateRenderer $renderer, WhatsAppMessageService $whatsapp): mixed
    {
        $request->validate(['campaign' => 'nullable|exists:campaigns,id', 'lead' => 'nullable|exists:leads,id', 'template' => 'nullable|exists:message_templates,id', 'channel' => 'nullable|in:email,phone,whatsapp,manual']);
        $campaign = $request->filled('campaign') ? Campaign::findOrFail($request->integer('campaign')) : null;
        $query = app(CampaignMembership::class)->ready($campaign);
        if ($request->filled('lead')) {
            $query->whereKey($request->integer('lead'));
        }
        $skipped = array_filter(array_map('intval', explode(',', (string) $request->query('skip', ''))));
        if ($skipped) {
            $query->whereNotIn('leads.id', $skipped);
        }
        $leads = $query->with(['campaigns.offer', 'offerScores.offer', 'contactAttempts', 'suggestedOffer', 'signalRows', 'scoreComponents'])->orderByRaw('case when next_action_at is null then 1 else 0 end')->orderBy('next_action_at')->orderByRaw('resolved_score is null')->orderByDesc('resolved_score')->orderByDesc('leads.id')->paginate(1)->withQueryString();
        $prepared = [];
        foreach ($leads as $lead) {
            $context = $campaign ?? $lead->campaigns->firstWhere('id', (int) $lead->queue_campaign_id) ?? $lead->campaigns->first(fn ($c) => ! $c->kill_switch && $c->status === 'active'
                && in_array($c->pivot->status, ['QUEUED', 'CONTACTED']) && (! $c->pivot->next_action_at || Carbon::parse($c->pivot->next_action_at)->isPast())
                && ($c->pivot->status === 'QUEUED' || $lead->contactAttempts->contains(fn ($a) => $a->campaign_id === $c->id && $a->status === 'planned' && (! $a->due_at || $a->due_at->isPast()))));
            $offer = app(CampaignMembership::class)->offer($lead, $context);
            $pending = $lead->contactAttempts->where('campaign_id', $context?->id)->where('status', 'planned')->sortBy('due_at')->first();
            $step = $pending?->step ?? 'initial';
            $channel = $request->query('channel', $pending?->channel ?? ($context?->channel === 'manual' ? 'email' : ($context?->channel ?? 'email')));
            $template = $request->filled('template') ? MessageTemplate::where('active', true)->where('channel', $channel)->where('stage', $step)->where('language', $lead->language)->where(fn ($q) => $q->whereNull('segment')->orWhere('segment', $lead->segment))->where(fn ($q) => $q->whereNull('offer_id')->orWhere('offer_id', $offer?->id))->find($request->integer('template')) : $renderer->select($lead, $channel, $step, $offer?->id);
            $variant = app(ExperimentService::class)->assigned($lead, $context);
            $variantTemplate = $variant ? app(ExperimentService::class)->template($variant) : null;
            if (! $request->filled('template') && $variantTemplate && $variantTemplate->active && $variantTemplate->channel === $channel && $variantTemplate->stage === $step
                && $variantTemplate->language === $lead->language && (! $variantTemplate->segment || $variantTemplate->segment === $lead->segment)
                && (! $variantTemplate->offer_id || $variantTemplate->offer_id === $offer?->id)) {
                $template = $variantTemplate;
            }
            $message = $template ? $renderer->render($template, $lead, $offer) : ($channel === 'whatsapp' ? $renderer->renderOffer($lead, $offer) : ['subject' => '', 'body' => '', 'missing' => []]);
            $prepared[$lead->id] = ['campaign' => $context, 'pending' => $pending, 'step' => $step, 'channel' => $channel, 'template' => $template,
                'variant' => $variant, 'offer' => $offer, 'message' => $message, 'whatsapp_phone' => app(ContactNormalizer::class)->whatsappPhone($lead->phone, $lead->country),
                'whatsapp_url' => $channel === 'whatsapp' ? $whatsapp->url($lead, $message['body']) : null];
        }

        return view('sales.queue', ['campaign' => $campaign, 'leads' => $leads, 'prepared' => $prepared, 'campaigns' => Campaign::where('status', 'active')->where('kill_switch', false)->get(), 'templates' => MessageTemplate::where('active', true)->get()]);
    }

    public function whatsapp(Request $request, Lead $lead, WhatsAppMessageService $whatsapp, CampaignMembership $memberships, ExclusionService $exclusions): mixed
    {
        $data = $request->validate(['campaign_id' => 'nullable|integer|exists:campaigns,id']);
        $campaign = isset($data['campaign_id']) ? Campaign::findOrFail($data['campaign_id']) : null;
        if ($campaign) {
            abort_unless($memberships->query($campaign)->whereKey($lead->id)->exists(), 404);
        } else {
            if ($lead->campaigns()->count() > 1) {
                throw ValidationException::withMessages(['campaign_id' => 'Seleccioná una campaña para preparar el mensaje.']);
            }
            $campaign = $lead->campaigns()->first();
        }
        $exclusions->assertContactable($lead, $campaign);
        if (in_array($memberships->status($lead, $campaign), ['PAUSED', 'WON', 'LOST'], true)) {
            throw ValidationException::withMessages(['lead_id' => 'La oportunidad está pausada o cerrada.']);
        }
        $pending = $lead->contactAttempts()->where('campaign_id', $campaign?->id)->where('status', 'planned')->orderBy('due_at')->first();
        $prepared = $whatsapp->prepare($lead, $campaign, $pending?->step ?? 'initial');

        return response()->json(['body' => $prepared['message']['body'], 'missing' => $prepared['message']['missing'],
            'phone' => $prepared['phone'], 'url' => $prepared['url'], 'offer_id' => $prepared['offer']?->id,
            'offer_name' => $prepared['offer']?->name, 'template_id' => $prepared['template']?->id, 'campaign_id' => $campaign?->id,
            'step' => $pending?->step ?? 'initial', 'contact_attempt_id' => $pending?->id]);
    }

    public function storeContactAttempt(Request $request, ContactWorkflow $workflow): mixed
    {
        $data = $request->validate(['lead_id' => 'required|exists:leads,id', 'campaign_id' => 'required|exists:campaigns,id', 'channel' => 'required|in:email,phone,whatsapp,manual',
            'variant' => 'nullable|string|max:255', 'preparation_seconds' => 'nullable|integer|min:0|max:86400', 'step' => 'required|in:initial,follow_up_1,follow_up_2,closed', 'planned_at' => 'nullable|date',
            'performed_at' => 'nullable|date|before_or_equal:now', 'message_snapshot' => 'nullable|string|max:20000', 'result' => 'nullable|string|max:255',
            'message_template_id' => 'nullable|exists:message_templates,id', 'subject' => 'nullable|string|max:255', 'contact_attempt_id' => 'nullable|exists:contact_attempts,id']);
        $workflow->record($data);
        if ($request->boolean('next')) {
            return redirect()->route('queue', ['campaign' => $data['campaign_id']])->with('status', 'Contacto registrado.');
        }

        return back()->with('status', 'Contacto registrado.');
    }

    public function reschedule(Request $request, ContactAttempt $contactAttempt, ContactWorkflow $workflow): mixed
    {
        $data = $request->validate(['due_at' => 'required|date|after:now']);
        $workflow->reschedule($contactAttempt, $data['due_at']);

        return back()->with('status', 'Seguimiento reprogramado.');
    }

    public function snooze(Request $request, Lead $lead): mixed
    {
        $data = $request->validate(['due_at' => 'required|date|after:now', 'campaign_id' => 'nullable|exists:campaigns,id']);
        $campaign = app(CampaignMembership::class)->resolve($lead, $data['campaign_id'] ?? null);
        app(ExclusionService::class)->assertContactable($lead, $campaign);
        $pending = $lead->contactAttempts()->where('status', 'planned')->when($campaign, fn ($q) => $q->where('campaign_id', $campaign->id))->first();
        if ($pending) {
            app(ContactWorkflow::class)->reschedule($pending, $data['due_at']);
        } else {
            app(CampaignMembership::class)->nextAction($lead, $campaign, $data['due_at']);
        }

        return back()->with('status', 'Próxima acción pospuesta.');
    }

    public function sendEmail(Request $request, EmailContactService $email): mixed
    {
        $data = $request->validate(['lead_id' => 'required|exists:leads,id', 'campaign_id' => 'required|exists:campaigns,id', 'message_template_id' => 'required|exists:message_templates,id', 'dry_run' => 'nullable|boolean']);
        $attempt = $email->sendInitial(Lead::findOrFail($data['lead_id']), Campaign::findOrFail($data['campaign_id']), MessageTemplate::findOrFail($data['message_template_id']), $request->boolean('dry_run', true));

        return back()->with('status', $attempt->dry_run ? 'Simulación registrada; el prospecto conserva su estado.' : ($attempt->result === 'sent_requires_review' ? 'Email enviado; revisá el estado de la oportunidad.' : 'Email enviado.'));
    }

    public function replies(Request $request): mixed
    {
        return view('sales.replies', ['leads' => Lead::orderBy('name')->get(), 'attempts' => ContactAttempt::where('status', 'sent')->where('dry_run', false)->latest()->get(),
            'categories' => Reply::CATEGORIES, 'campaigns' => Campaign::get(),
            'replies' => Reply::with('lead')->when($request->boolean('pending'), fn ($q) => $q->where('requires_human', true))->latest()->orderByDesc('id')->paginate(50)->withQueryString()]);
    }

    public function storeReply(Request $request): mixed
    {
        $data = $request->validate(['lead_id' => 'required|exists:leads,id', 'campaign_id' => 'nullable|exists:campaigns,id', 'contact_attempt_id' => 'nullable|exists:contact_attempts,id',
            'category' => 'required|in:'.implode(',', Reply::CATEGORIES), 'body' => 'nullable|string|max:20000', 'received_at' => 'required|date', 'objection_type' => 'nullable|string|max:255', 'objection_notes' => 'nullable|string|max:10000']);
        $lead = Lead::findOrFail($data['lead_id']);
        $attempt = isset($data['contact_attempt_id']) ? ContactAttempt::where('lead_id', $lead->id)->findOrFail($data['contact_attempt_id']) : null;
        $campaign = app(CampaignMembership::class)->resolve($lead, $data['campaign_id'] ?? $attempt?->campaign_id);
        if ($attempt && $campaign && $attempt->campaign_id !== $campaign->id) {
            return back()->withErrors('El contacto pertenece a otra campaña.');
        }
        $data['campaign_id'] = $campaign?->id;
        DB::transaction(function () use ($data, $lead, $campaign) {
            Reply::create($data + ['requires_human' => true]);
            app(FollowUpService::class)->pauseForReply($lead, $campaign?->id);
            app(CampaignMembership::class)->nextAction($lead, $campaign, null);
            app(LeadPipeline::class)->move($lead, match ($data['category']) {
                'interested', 'meeting', 'information_request', 'price_question' => 'INTERESTED', 'proposal' => 'PROPOSAL',
                'won' => 'WON', 'lost', 'negative' => 'LOST', default => 'REPLIED',
            }, 'reply_recorded', $campaign);
        });

        return back()->with('status', 'Respuesta registrada. Seguimientos pausados.');
    }

    public function resolveReply(Reply $reply): mixed
    {
        $reply->update(['requires_human' => false]);

        return back()->with('status', 'Respuesta marcada como atendida.');
    }

    public function proposals(): mixed
    {
        return view('sales.proposals', ['leads' => Lead::with('campaigns')->orderBy('name')->get(), 'campaigns' => Campaign::get(),
            'offers' => Offer::orderBy('name')->get(), 'proposals' => Proposal::with(['lead.campaigns', 'offer'])->latest()->orderByDesc('id')->paginate(50)->withQueryString()]);
    }

    private function proposalData(Request $request, ?Proposal $proposal = null): array
    {
        $data = $request->validate(['lead_id' => 'required|exists:leads,id', 'campaign_id' => 'nullable|exists:campaigns,id', 'offer_id' => 'sometimes|nullable|integer|exists:offers,id', 'service' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric|min:0', 'currency' => 'required|string|size:3', 'version' => 'required|integer|min:1',
            'sent_at' => 'nullable|date', 'response' => 'nullable|string|max:10000', 'objections' => 'nullable|string|max:10000', 'modifications' => 'nullable|string|max:10000', 'result' => 'nullable|in:pending,won,lost'],
            ['offer_id.exists' => 'La oferta seleccionada ya no está disponible.']);
        if ($proposal && ($proposal->lead_id !== (int) $data['lead_id'] || ($data['campaign_id'] ?? $proposal->campaign_id) != $proposal->campaign_id)) {
            abort(422);
        }
        $lead = Lead::findOrFail($data['lead_id']);
        $campaign = app(CampaignMembership::class)->resolve($lead, $data['campaign_id'] ?? $proposal?->campaign_id);
        $data['campaign_id'] = $campaign?->id;
        $offer = array_key_exists('offer_id', $data) ? ($data['offer_id'] ? Offer::findOrFail($data['offer_id']) : null)
            : ($proposal?->offer ?? app(CampaignMembership::class)->offer($lead, $campaign));
        $data['offer_id'] = $offer?->id;
        $data['service'] = trim($data['service'] ?? '') ?: $offer?->name;
        if (! filled($data['service'])) {
            throw ValidationException::withMessages(['service' => 'Elegí una oferta o escribí el servicio de la propuesta.']);
        }
        $data['currency'] = strtoupper($data['currency']);
        $group = $proposal?->group_key ?? Proposal::where('lead_id', $lead->id)->where('campaign_id', $campaign?->id)->where('offer_id', $data['offer_id'])->where('service', $data['service'])->value('group_key') ?? Str::uuid()->toString();
        if (Proposal::where('group_key', $group)->where('version', $data['version'])->when($proposal, fn ($q) => $q->where('id', '!=', $proposal->id))->exists()) {
            throw ValidationException::withMessages(['version' => 'Esa versión ya existe. Editá la propuesta o creá una versión nueva.']);
        }

        return $data + ['group_key' => $group];
    }

    public function storeProposal(Request $request): mixed
    {
        return DB::transaction(fn () => $this->saveProposal($this->proposalData($request)));
    }

    public function updateProposal(Request $request, Proposal $proposal): mixed
    {
        return DB::transaction(fn () => $this->saveProposal($this->proposalData($request, $proposal), $proposal));
    }

    private function saveProposal(array $data, ?Proposal $proposal = null): mixed
    {
        DB::transaction(function () use ($data, $proposal) {
            if ($proposal) {
                $proposal->update($data);
            } else {
                Proposal::create($data);
            }
            $lead = Lead::findOrFail($data['lead_id']);
            app(LeadPipeline::class)->move($lead, match ($data['result'] ?? 'pending') {
                'won' => 'WON', 'lost' => 'LOST', default => 'PROPOSAL'
            }, 'proposal_recorded', $data['campaign_id'] ? Campaign::findOrFail($data['campaign_id']) : null);
        });

        return back()->with('status', 'Propuesta guardada.');
    }

    public function pipeline(Request $request, ?Campaign $campaign = null): mixed
    {
        $query = $campaign ? app(CampaignMembership::class)->query($campaign) : Lead::query();
        $leads = $query->with(['campaigns.offer', 'suggestedOffer', 'offerScores.offer'])->orderByDesc('updated_at')->get();
        $offers = Offer::whereIn('id', $leads->flatMap(fn ($lead) => $lead->campaigns->pluck('pivot.offer_id'))->filter()->unique())->get()->keyBy('id');
        $opportunities = collect();
        foreach ($leads as $lead) {
            $contexts = $campaign ? $lead->campaigns->where('id', $campaign->id) : $lead->campaigns;
            foreach ($contexts as $context) {
                $opportunity = clone $lead;
                $opportunity->status = $context->pivot->status;
                $opportunity->setRelation('opportunity_campaign', $context);
                $opportunity->setRelation('opportunity_offer', $offers->get($context->pivot->offer_id) ?? $context->offer ?? $lead->suggestedOffer);
                $opportunities->push($opportunity);
            }
            if ($contexts->isEmpty() && (! $campaign || $lead->campaigns->isEmpty())) {
                $lead->setRelation('opportunity_campaign', $campaign);
                $lead->setRelation('opportunity_offer', $lead->suggestedOffer);
                $opportunities->push($lead);
            }
        }
        $leads = $opportunities;
        $columns = ['REPLIED', 'INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'];

        return view('sales.pipeline', ['campaign' => $campaign, 'columns' => $columns, 'leadsByStatus' => $leads->whereIn('status', $columns)->groupBy('status'), 'statuses' => Lead::STATUSES]);
    }
}
