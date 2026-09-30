<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Exclusion;
use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Offer;
use App\Models\SystemLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadImporter
{
    public function __construct(
        private readonly LeadScorer $scorer,
        private readonly LeadPipeline $pipeline,
        private readonly LeadEnricher $enricher,
        private readonly OfferMatcher $offerMatcher,
    ) {}

    public function importCsv(UploadedFile|string $file, Campaign $campaign, array $meta = []): ImportRun
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        return DB::transaction(function () use ($path, $campaign, $meta) {
            $run = ImportRun::create([
                'campaign_id' => $campaign->id,
                'campaign_query_id' => $meta['campaign_query_id'] ?? null,
                'scrape_run_id' => $meta['scrape_run_id'] ?? null,
                'source' => 'google_maps',
                'query' => $meta['query'] ?? null,
                'segment' => $campaign->segment,
                'location' => $meta['location'] ?? null,
                'file_path' => $path,
                'started_at' => Carbon::now(),
            ]);

            $handle = fopen($path, 'r');
            $headers = $this->headers((array) fgetcsv($handle, escape: '\\'));
            $raw = $imported = $duplicates = $possibleDuplicates = $errors = 0;
            $maxRows = isset($meta['max_rows']) ? (int) $meta['max_rows'] : null;

            while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                $raw++;
                $record = $this->record($headers, $row);

                if ($maxRows && $raw > $maxRows) {
                    continue;
                }

                try {
                    $data = $this->normalize($record, $campaign);
                    $decision = $this->duplicateDecision($data);

                    if ($this->isExcluded($data)) {
                        $duplicates++;

                        continue;
                    }

                    if ($decision['status'] === 'strong_duplicate') {
                        $duplicates++;
                        SystemLog::create([
                            'type' => 'deduplication',
                            'message' => 'Lead descartado por duplicado fuerte.',
                            'context' => [
                                'rule' => $decision['rule'],
                                'matched_lead_id' => $decision['lead']?->id,
                                'row' => $record,
                            ],
                        ]);
                    } else {
                        if ($decision['status'] === 'possible_duplicate') {
                            $possibleDuplicates++;
                            $data['duplicate_status'] = 'possible_duplicate';
                            $data['duplicate_rule'] = $decision['rule'];
                            $data['possible_duplicate_of_lead_id'] = $decision['lead']?->id;
                        }

                        $lead = Lead::create($data);
                        $this->enricher->enrich($lead);
                        $this->scorer->score($lead);
                        $this->suggestOffer($lead, $campaign);
                        $lead->refresh();
                        $this->pipeline->move($lead, $lead->score_total >= 45 ? 'QUEUED' : 'ENRICHED', 'import_csv');
                        $imported++;

                        LeadSource::create([
                            'lead_id' => $lead->id,
                            'import_run_id' => $run->id,
                            'source' => 'google_maps',
                            'source_identifier' => $data['source_identifier'] ?? null,
                            'source_url' => $record['url'] ?? $record['link'] ?? null,
                            'query' => $meta['query'] ?? null,
                            'observed_at' => Carbon::now(),
                        ]);
                    }
                } catch (\Throwable $exception) {
                    $errors++;
                    SystemLog::create([
                        'type' => 'import_error',
                        'message' => $exception->getMessage(),
                        'context' => ['row' => $record],
                    ]);
                }
            }

            fclose($handle);

            $run->update([
                'raw_count' => $raw,
                'imported_count' => $imported,
                'duplicate_count' => $duplicates,
                'possible_duplicate_count' => $possibleDuplicates,
                'error_count' => $errors,
                'finished_at' => Carbon::now(),
            ]);

            return $run->refresh();
        });
    }

    private function headers(array $headers): array
    {
        return array_map(fn ($header) => Str::of((string) $header)->lower()->replace([' ', '-'], '_')->toString(), $headers);
    }

    private function record(array $headers, array $row): array
    {
        $record = [];

        foreach ($headers as $index => $header) {
            $record[$header] = trim((string) ($row[$index] ?? ''));
        }

        return $record;
    }

    private function normalize(array $record, Campaign $campaign): array
    {
        $website = $this->first($record, ['website', 'website_url', 'site', 'web']);
        $email = $this->first($record, ['email', 'emails']);
        $phone = $this->first($record, ['phone', 'phone_number', 'telefono', 'telephone']);

        return [
            'name' => $this->first($record, ['name', 'title', 'business_name', 'empresa']) ?: 'Sin nombre',
            'category' => $this->first($record, ['category', 'type', 'categoria']),
            'location' => $this->first($record, ['location', 'address', 'direccion']),
            'country' => $campaign->country,
            'language' => $campaign->language,
            'website_url' => $website,
            'domain' => $this->domain($website),
            'email' => $email ? mb_strtolower($email) : null,
            'phone' => $this->phone($phone),
            'source' => 'google_maps',
            'source_identifier' => $this->first($record, ['place_id', 'cid', 'google_id', 'id']),
            'segment' => $campaign->segment,
            'acquired_at' => Carbon::now(),
            'status' => 'NEW',
        ];
    }

    private function duplicateDecision(array $data): array
    {
        if ($data['source_identifier'] ?? null) {
            $lead = Lead::where('source_identifier', $data['source_identifier'])->first();

            if ($lead) {
                return ['status' => 'strong_duplicate', 'rule' => 'source_identifier', 'lead' => $lead];
            }
        }

        if ($data['email'] ?? null) {
            $lead = Lead::where('email', $data['email'])->first();

            if ($lead) {
                return ['status' => 'strong_duplicate', 'rule' => 'email', 'lead' => $lead];
            }
        }

        $domainMatch = ($data['domain'] ?? null) ? Lead::where('domain', $data['domain'])->get() : collect();

        foreach ($domainMatch as $lead) {
            if ($this->similar($data['name'] ?? '', $lead->name) && $this->similar($data['location'] ?? '', $lead->location ?? '')) {
                return ['status' => 'strong_duplicate', 'rule' => 'domain_name_location', 'lead' => $lead];
            }
        }

        if ($domainMatch->isNotEmpty()) {
            return ['status' => 'possible_duplicate', 'rule' => 'shared_domain', 'lead' => $domainMatch->first()];
        }

        if ($data['phone'] ?? null) {
            $lead = Lead::where('phone', $data['phone'])->first();

            if ($lead) {
                return ['status' => 'possible_duplicate', 'rule' => 'shared_phone', 'lead' => $lead];
            }
        }

        return ['status' => 'unique', 'rule' => null, 'lead' => null];
    }

    private function similar(string $a, string $b): bool
    {
        $left = $this->normalizeComparable($a);
        $right = $this->normalizeComparable($b);

        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        similar_text($left, $right, $percent);

        return $percent >= 88;
    }

    private function normalizeComparable(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function suggestOffer(Lead $lead, Campaign $campaign): void
    {
        $offer = $campaign->offer ?? $this->offerMatcher->match($lead) ?? Offer::where('active', true)->first();

        if ($offer) {
            $lead->update(['suggested_offer_id' => $offer->id]);
        }
    }

    private function isExcluded(array $data): bool
    {
        foreach ([
            'email' => $data['email'] ?? null,
            'domain' => $data['domain'] ?? null,
            'phone' => $data['phone'] ?? null,
            'source_identifier' => $data['source_identifier'] ?? null,
            'name' => $data['name'] ?? null,
        ] as $type => $value) {
            if ($value && Exclusion::where('type', $type)->where('value', $value)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function first(array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($record[$key] ?? null)) {
                return $record[$key];
            }
        }

        return null;
    }

    private function domain(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = parse_url(str_starts_with($url, 'http') ? $url : "https://{$url}", PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', mb_strtolower($host)) : null;
    }

    private function phone(?string $phone): ?string
    {
        $clean = preg_replace('/[^\d+]/', '', (string) $phone);

        return $clean ?: null;
    }
}
