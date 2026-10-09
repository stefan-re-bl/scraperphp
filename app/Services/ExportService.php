<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\ImportRun;
use App\Models\Lead;
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
    ];

    public function __construct(private readonly LeadFilterService $leadFilters) {}

    public function csv(string $dataset, array $filters = []): StreamedResponse
    {
        abort_unless(isset(self::DATASETS[$dataset]), 404);

        $class = self::DATASETS[$dataset];

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
                    fputcsv($handle, array_map(fn ($value) => is_string($value) && ! is_numeric($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $data), escape: '');
                }
            });

            fclose($handle);
        }, "{$dataset}.csv", ['Content-Type' => 'text/csv']);
    }

    public function json(array $leadFilters = []): array
    {
        $data = [];

        foreach (self::DATASETS as $name => $class) {
            $data[$name] = $this->datasetQuery($name, $leadFilters)->withoutEagerLoads()->get();
        }
        $ids = $this->leadFilters->query($leadFilters)->pluck('leads.id');
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
            fputcsv($handle, [
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
            ]);

            $this->leadFilters->query($filters)->chunk(200, function ($leads) use ($handle, $filters) {
                foreach ($leads as $lead) {
                    $attempts = $lead->contactAttempts->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at');
                    if ($filters['campaign'] ?? null) {
                        $attempts = $attempts->where('campaign_id', $filters['campaign']);
                    }
                    $firstContact = $attempts->sortBy('performed_at')->first();
                    $replies = ($filters['campaign'] ?? null) ? $lead->replies->where('campaign_id', $filters['campaign']) : $lead->replies;
                    $reply = $replies->sortBy('received_at')->first();
                    $proposals = ($filters['campaign'] ?? null) ? $lead->proposals->where('campaign_id', $filters['campaign']) : $lead->proposals;
                    $proposal = $proposals->whereNotNull('sent_at')->sortBy('sent_at')->first();
                    $status = ($filters['campaign'] ?? null) ? (DB::table('campaign_lead')->where('lead_id', $lead->id)->where('campaign_id', $filters['campaign'])->value('status') ?? $lead->status) : $lead->status;
                    $campaign = $firstContact ? DB::table('campaigns')->find($firstContact->campaign_id) : null;

                    fputcsv($handle, [
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
                        $lead->suggestedOffer?->name,
                        $firstContact?->variant,
                    ]);
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

    private function datasetQuery(string $dataset, array $filters): Builder
    {
        $class = self::DATASETS[$dataset];
        if ($dataset === 'leads') {
            return $this->leadFilters->query($filters);
        }
        $query = $class::query();
        if ($filters === []) {
            return $query;
        }
        $ids = $this->leadFilters->query($filters)->pluck('leads.id');
        $campaign = $filters['campaign'] ?? null;
        if (in_array($dataset, ['sources', 'scoring', 'signals', 'contact_attempts', 'replies', 'pipeline', 'proposals'])) {
            $query->whereIn('lead_id', $ids);
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
        $offerIds = Lead::whereIn('id', $ids)->pluck('suggested_offer_id')->merge(Campaign::whereIn('id', $campaignIds)->pluck('offer_id'))
            ->merge(ContactAttempt::whereIn('lead_id', $ids)->when($campaign, fn ($q) => $q->where('campaign_id', $campaign))->pluck('offer_id'))->filter()->unique();

        return $query->whereIn('id', $offerIds);
    }
}
