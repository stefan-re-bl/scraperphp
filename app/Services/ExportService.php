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

        return response()->streamDownload(function () use ($class, $dataset, $filters) {
            $handle = fopen('php://output', 'w');
            $wroteHeader = false;
            $query = $dataset === 'leads' ? $this->leadFilters->query($filters) : $class::query();

            $query->chunk(200, function ($rows) use ($handle, &$wroteHeader) {
                foreach ($rows as $row) {
                    $data = $row->getAttributes();
                    if (! $wroteHeader) {
                        fputcsv($handle, array_keys($data));
                        $wroteHeader = true;
                    }
                    fputcsv($handle, $data);
                }
            });

            fclose($handle);
        }, "{$dataset}.csv", ['Content-Type' => 'text/csv']);
    }

    public function json(array $leadFilters = []): array
    {
        $data = [];

        foreach (self::DATASETS as $name => $class) {
            $data[$name] = $name === 'leads'
                ? $this->leadFilters->query($leadFilters)->get()
                : $class::all();
        }

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

            $this->leadFilters->query($filters)->chunk(200, function ($leads) use ($handle) {
                foreach ($leads as $lead) {
                    $firstContact = $lead->contactAttempts->whereNotNull('performed_at')->sortBy('performed_at')->first();
                    $reply = $lead->replies->sortBy('received_at')->first();
                    $proposal = $lead->proposals->sortBy('sent_at')->first();
                    $campaign = $firstContact ? DB::table('campaigns')->find($firstContact->campaign_id) : null;

                    fputcsv($handle, [
                        $lead->id,
                        $lead->name,
                        $campaign?->name,
                        $lead->source,
                        $lead->segment,
                        $lead->location,
                        $lead->country,
                        $lead->score_total,
                        $firstContact?->channel,
                        $firstContact?->performed_at,
                        $reply ? 1 : 0,
                        $reply?->category,
                        in_array($lead->status, ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON'], true) ? 1 : 0,
                        $proposal ? 1 : 0,
                        $lead->status === 'WON' ? 1 : 0,
                        $lead->status === 'LOST' ? 1 : 0,
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
                        $phone = $this->argentinaPhone($lead->phone);

                        if ($phone) {
                            $phones[$phone] = true;
                        }
                    }
                });

            echo implode(',', array_keys($phones));
        }, 'telefonos.txt', ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function argentinaPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        while (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        if (str_starts_with($digits, '54')) {
            $normalized = $digits;
        } else {
            $normalized = '54'.$digits;
        }

        if (! preg_match('/^54\d{8,13}$/', $normalized)) {
            return null;
        }

        return '+'.$normalized;
    }
}
