<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ClientService;
use App\Models\ContactAttempt;
use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\LeadContact;
use App\Models\LeadOfferScore;
use App\Models\LeadSource;
use App\Models\LeadStatusHistory;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use App\Models\ScoreComponent;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    public const DATASETS = [
        'leads' => Lead::class,
        'offer_scores' => LeadOfferScore::class,
        'sources' => LeadSource::class,
        'campaigns' => Campaign::class,
        'scoring' => ScoreComponent::class,
        'signals' => Signal::class,
        'contact_attempts' => ContactAttempt::class,
        'replies' => Reply::class,
        'pipeline' => LeadStatusHistory::class,
        'proposals' => Proposal::class,
        'offers' => Offer::class,
        'imports' => ImportRun::class,
        'contacts' => LeadContact::class,
        'services' => ClientService::class,
    ];

    public function __construct(private readonly LeadFilterService $leadFilters) {}

    public function csv(string $dataset, array $filters = []): StreamedResponse
    {
        abort_unless(isset(self::DATASETS[$dataset]), 404);

        return response()->streamDownload(function () use ($dataset, $filters) {
            $handle = fopen('php://output', 'w');
            $wroteHeader = false;
            $query = $this->datasetQuery($dataset, $filters);

            $query->chunk(200, function ($rows) use ($handle, &$wroteHeader) {
                foreach ($rows as $row) {
                    $data = $row->getAttributes();
                    if (! $wroteHeader) {
                        fputcsv($handle, array_keys($data), escape: '');
                        $wroteHeader = true;
                    }
                    CsvSerializer::row($handle, $data);
                }
            });

            fclose($handle);
        }, "{$dataset}.csv", ['Content-Type' => 'text/csv']);
    }

    public function json(array $leadFilters = []): array
    {
        $data = [];

        $selectedIds = $this->leadFilters->query($leadFilters)->pluck('leads.id')->all();
        foreach (self::DATASETS as $name => $class) {
            $data[$name] = $this->datasetQuery($name, $leadFilters, $selectedIds)->withoutEagerLoads()->get();
        }
        $ids = $selectedIds;
        $data['opportunities'] = DB::table('campaign_lead')->whereIn('lead_id', $ids)->when($leadFilters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->get();

        return [
            'exported_at' => now()->toISOString(),
            'data' => $data,
        ];
    }

    public function analyticsCsv(array $filters = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');
            CsvSerializer::row($handle, [
                'lead_id',
                'lead',
                'campaign',
                'source',
                'segment',
                'city',
                'country',
                'score',
                'channel',
                'first_contact_at',
                'replied',
                'reply_category',
                'interested',
                'proposal',
                'won',
                'lost',
                'days_to_reply',
                'days_to_proposal',
                'offer',
                'variant',
                'reply_attempt_id', 'reply_channel', 'reply_variant',
                'current_offer_score_known', 'current_offer_score', 'current_offer_score_version',
                'historical_score_known', 'historical_score', 'historical_score_version', 'historical_score_calculated_at',
                'historical_offer_id', 'historical_opportunity_id',
            ]);

            $this->leadFilters->query($filters, ['contactAttempts.campaign', 'replies', 'proposals', 'campaigns.offer', 'suggestedOffer', 'offerScores' => fn ($q) => $q->current()->with('offer')])->chunk(200, function ($leads) use ($handle, $filters) {
                $offers = Offer::whereIn('id', $leads->flatMap(fn ($lead) => $lead->campaigns->pluck('pivot.offer_id'))->filter()->unique())->get()->keyBy('id');
                foreach ($leads as $lead) {
                    $contexts = ($filters['campaign'] ?? null) ? $lead->campaigns->where('id', (int) $filters['campaign']) : $lead->campaigns;
                    if ($contexts->isEmpty()) {
                        $contexts = collect([null]);
                    }
                    foreach ($contexts as $context) {
                        $attempts = $lead->contactAttempts->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')
                            ->where('campaign_id', $context?->id);
                        if ($filters['channel'] ?? null) {
                            $attempts = $attempts->where('channel', $filters['channel']);
                        }
                        $firstContact = $attempts->sortBy('performed_at')->first();
                        $replies = $lead->replies->where('campaign_id', $context?->id);
                        if ($filters['channel'] ?? null) {
                            $replies = $replies->whereIn('contact_attempt_id', $attempts->modelKeys());
                        }
                        $reply = $replies->sortBy('received_at')->first();
                        $replyAttempt = $reply ? $lead->contactAttempts->firstWhere('id', $reply->contact_attempt_id) : null;
                        $proposal = $lead->proposals->where('campaign_id', $context?->id)->whereNotNull('sent_at')->sortBy('sent_at')->first();
                        $status = $context?->pivot->status ?? $lead->status;
                        $campaign = $context ?? $firstContact?->campaign;
                        $offer = $context ? $offers->get($context->pivot->offer_id) ?? $context->offer : $lead->suggestedOffer;

                        $currentScore = $lead->offerScores->firstWhere('offer_id', $offer?->id);
                        $currentKnown = $currentScore !== null;
                        $snapshot = $firstContact?->score_snapshot;
                        $historicalKnown = $firstContact && is_array($snapshot) && app(MetricsService::class)->validSnapshot($firstContact, $snapshot);
                        CsvSerializer::row($handle, [
                            $lead->id,
                            $lead->name,
                            $campaign?->name,
                            $lead->source,
                            $lead->segment,
                            $lead->city ?: $lead->location,
                            $lead->country,
                            $lead->score_total,
                            $firstContact?->channel,
                            $firstContact?->performed_at,
                            $reply ? 1 : 0,
                            $reply?->category,
                            in_array($status, ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'], true) ? 1 : 0,
                            $proposal ? 1 : 0,
                            $status === 'WON' ? 1 : 0,
                            $status === 'LOST' ? 1 : 0,
                            $firstContact && $reply ? $firstContact->performed_at->diffInDays($reply->received_at) : null,
                            $firstContact && $proposal?->sent_at ? $firstContact->performed_at->diffInDays($proposal->sent_at) : null,
                            $offer?->name,
                            $firstContact?->variant,
                            $replyAttempt?->id, $replyAttempt?->channel, $replyAttempt?->variant,
                            $currentKnown ? 1 : 0, $currentKnown ? $currentScore->total : null, $currentScore?->rule_version,
                            $historicalKnown ? 1 : 0, $historicalKnown ? $snapshot['total'] : null, $historicalKnown ? $snapshot['rule_version'] : null, $historicalKnown ? $snapshot['calculated_at'] : null,
                            $snapshot['offer_id'] ?? null, $snapshot['opportunity_id'] ?? null,
                        ]);
                    }
                }
            });

            fclose($handle);
        }, 'analytics_export.csv', ['Content-Type' => 'text/csv']);
    }

    public function phones(array $filters = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($filters) {
            $phones = [];

            $this->leadFilters->query($filters)
                ->whereNotNull('phone')
                ->chunk(200, function ($leads) use (&$phones) {
                    foreach ($leads as $lead) {
                        $phone = app(ContactNormalizer::class)->phone($lead->phone, $lead->country);

                        if ($phone) {
                            $phones[$phone] = true;
                        }
                    }
                });

            echo implode(',', array_keys($phones));
        }, 'telefonos.txt', ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function datasetQuery(string $dataset, array $filters, ?array $selectedIds = null): Builder
    {
        $class = self::DATASETS[$dataset];
        if ($dataset === 'leads') {
            return $selectedIds === null ? $this->leadFilters->query($filters) : Lead::whereIn('id', $selectedIds);
        }
        $query = $class::query();
        if ($filters === []) {
            return $query;
        }
        $ids = collect($selectedIds ?? $this->leadFilters->query($filters)->pluck('leads.id')->all());
        $campaign = $filters['campaign'] ?? null;
        if (in_array($dataset, ['sources', 'scoring', 'offer_scores', 'signals', 'contact_attempts', 'replies', 'pipeline', 'proposals', 'contacts', 'services'])) {
            $query->whereIn('lead_id', $ids);
            if ($dataset === 'offer_scores') {
                $query->when($filters['rule_version'] ?? null, fn ($q, $version) => $q->where('rule_version', $version));
                $query->when($filters['score_offer_id'] ?? null, fn ($q, $id) => $q->where('offer_id', $id));
                if ($campaign) {
                    $query->whereExists(fn ($q) => $q->selectRaw('1')->from('campaign_lead')->whereColumn('campaign_lead.lead_id', 'lead_offer_scores.lead_id')->whereColumn('campaign_lead.offer_id', 'lead_offer_scores.offer_id')->where('campaign_id', $campaign));
                }
            }
            if ($dataset === 'signals') {
                $query->when($filters['rule_version'] ?? null, fn ($q, $version) => $q->where('rule_version', $version))
                    ->when($filters['evidence_source'] ?? null, fn ($q, $source) => $q->where('source', $source))
                    ->when($filters['evidence_review'] ?? null, fn ($q, $review) => $q->where('review_status', $review));
            }
            if ($dataset === 'contact_attempts') {
                $query->when($filters['rule_version'] ?? null, fn ($q, $version) => $q->where('score_snapshot->rule_version', $version));
            }
            if ($campaign && in_array($dataset, ['contact_attempts', 'replies', 'pipeline', 'proposals'])) {
                $query->where('campaign_id', $campaign);
            }
            if ($campaign && $dataset === 'sources') {
                $query->whereHas('importRun', fn ($q) => $q->where('campaign_id', $campaign));
            }

            return $query;
        }
        $sourceIds = LeadSource::whereIn('lead_id', $ids)->whereNotNull('import_run_id')->pluck('import_run_id');
        $campaignIds = DB::table('campaign_lead')->whereIn('lead_id', $ids)->pluck('campaign_id')
            ->merge(ImportRun::whereIn('id', $sourceIds)->pluck('campaign_id'))
            ->merge(ContactAttempt::whereIn('lead_id', $ids)->pluck('campaign_id'))->unique();
        if ($campaign) {
            $campaignIds = $campaignIds->filter(fn ($id) => (int) $id === (int) $campaign);
        }
        if ($dataset === 'campaigns') {
            return $query->whereIn('id', $campaignIds);
        }
        if ($dataset === 'imports') {
            return $query->whereIn('id', $sourceIds)->when($campaign, fn ($q) => $q->where('campaign_id', $campaign));
        }
        $offerIds = Lead::whereIn('id', $ids)->pluck('suggested_offer_id')->merge(DB::table('campaign_lead')->whereIn('lead_id', $ids)->when($campaign, fn ($q) => $q->where('campaign_id', $campaign))->pluck('offer_id'))->merge(Campaign::whereIn('id', $campaignIds)->pluck('offer_id'))
            ->merge(ContactAttempt::whereIn('lead_id', $ids)->when($campaign, fn ($q) => $q->where('campaign_id', $campaign))->pluck('offer_id'))->filter()->unique();

        return $query->whereIn('id', $offerIds);
    }
}
