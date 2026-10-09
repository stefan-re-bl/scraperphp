<?php

namespace App\Services;

use App\Jobs\EnrichLead;
use App\Jobs\ImportLeadFile;
use App\Models\Campaign;
use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\ScrapeRun;
use App\Models\SystemLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadImporter
{
    public const FIELDS = ['name', 'category', 'location', 'city', 'website', 'email', 'phone', 'place_id', 'contact_person', 'contact_role', 'tags'];

    public function __construct(private readonly LeadScorer $scorer, private readonly LeadPipeline $pipeline, private readonly OfferMatcher $matcher, private readonly ContactNormalizer $normalizer, private readonly CampaignMembership $memberships, private readonly ExclusionService $exclusions) {}

    public function preview(string $path, array $mapping = []): array
    {
        [$handle, $headers, $delimiter] = $this->open($path);
        $rows = [];
        while (count($rows) < 5 && ($row = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
            $rows[] = $this->record($headers, $row, $mapping);
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    public function enqueue(UploadedFile $file, Campaign $campaign, array $meta = []): ImportRun
    {
        $path = $file->store('imports');

        return $this->enqueuePath(storage_path('app/private/'.$path), $campaign, $meta);
    }

    public function enqueuePath(string $path, Campaign $campaign, array $meta = []): ImportRun
    {
        unset($meta['csv']);
        $this->preview($path, $meta['mapping'] ?? []);
        if ($campaign->kill_switch || $campaign->status === 'paused') {
            throw ValidationException::withMessages(['campaign_id' => 'La campaña está pausada.']);
        }
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => $meta['source'] ?? $campaign->source,
            'query' => $meta['query'] ?? null, 'location' => $meta['location'] ?? null, 'segment' => $campaign->segment,
            'file_path' => $path, 'mapping' => $meta['mapping'] ?? [], 'raw_metadata' => $meta, 'status' => 'queued']);
        ImportLeadFile::dispatch($run->id)->onQueue('sales');

        return $run->refresh();
    }

    public function importCsv(UploadedFile|string $file, Campaign $campaign, array $meta = []): ImportRun
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $run = isset($meta['run_id']) ? ImportRun::findOrFail($meta['run_id']) : ImportRun::create([
            'campaign_id' => $campaign->id, 'campaign_query_id' => $meta['campaign_query_id'] ?? null,
            'scrape_run_id' => $meta['scrape_run_id'] ?? null, 'source' => $meta['source'] ?? $campaign->source,
            'query' => $meta['query'] ?? null, 'location' => $meta['location'] ?? null,
            'segment' => $campaign->segment, 'file_path' => $path, 'mapping' => $meta['mapping'] ?? [], 'status' => 'queued',
        ]);
        if ($run->status === 'completed') {
            return $run;
        }
        $token = Str::uuid()->toString();
        $claimed = ImportRun::whereKey($run->id)->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(15)))
            ->update(['status' => 'processing', 'claim_token' => $token, 'claimed_at' => now(), 'started_at' => $run->started_at ?? now(), 'error_message' => null]);
        if (! $claimed) {
            return $run->refresh();
        }
        $run->refresh();
        $handle = null;
        try {
            if (! is_file($path)) {
                throw new \RuntimeException('No se encontró el archivo importado.');
            }
            $hash = hash_file('sha256', $path);
            if ($run->file_hash && $run->file_hash !== $hash) {
                throw new \RuntimeException('El archivo cambió desde la importación anterior. Importalo como un archivo nuevo.');
            }
            $run->update(['file_hash' => $hash]);
            [$handle, $headers, $delimiter] = $this->open($path);
            $counts = $run->only(['raw_count', 'imported_count', 'duplicate_count', 'possible_duplicate_count', 'excluded_count', 'error_count']);
            $rowNumber = 0;
            $cancelled = false;
            if ($run->processed_rows > 0) {
                foreach (Lead::whereNull('enriched_at')->whereHas('sources', fn ($q) => $q->where('import_run_id', $run->id))->pluck('id') as $leadId) {
                    EnrichLead::dispatch($leadId)->onQueue('sales');
                }
            }
            while (($row = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $rowNumber++;
                if ($rowNumber <= $run->processed_rows) {
                    continue;
                }
                if ($campaign->fresh()->kill_switch || $campaign->fresh()->status === 'paused'
                    || (isset($meta['cancel_run_id']) && ScrapeRun::whereKey($meta['cancel_run_id'])->whereNotNull('cancel_requested_at')->exists())) {
                    $cancelled = true;
                    break;
                }
                $record = $this->record($headers, $row, $meta['mapping'] ?? $run->mapping ?? []);
                $result = DB::transaction(function () use ($record, $campaign, $run, $meta, $rowNumber, &$counts, $token) {
                    if (! ImportRun::whereKey($run->id)->where('claim_token', $token)->exists()) {
                        throw new \RuntimeException('La importación está siendo procesada por otra tarea.');
                    }
                    $result = ['kind' => 'skipped'];
                    if (! isset($meta['max_rows']) || $rowNumber <= $meta['max_rows']) {
                        try {
                            $result = DB::transaction(function () use ($record, $campaign, $run, $meta) {
                                $data = $this->normalize($record, $campaign, $run->source);
                                if ($this->exclusions->matches($data)) {
                                    return ['kind' => 'excluded'];
                                }
                                $duplicate = $this->duplicate($data);
                                if ($duplicate['status'] === 'strong') {
                                    $lead = $duplicate['lead'];
                                } else {
                                    $lead = Lead::create($data + ['duplicate_status' => $duplicate['status'] === 'possible' ? 'possible_duplicate' : 'unique',
                                        'duplicate_rule' => $duplicate['rule'], 'possible_duplicate_of_lead_id' => $duplicate['lead']?->id]);
                                    $offer = $campaign->offer ?? $this->matcher->match($lead);
                                    $lead->update(['suggested_offer_id' => $offer?->id, 'offer_match_reason' => $campaign->offer ? 'Oferta definida para esta campaña.' : $lead->offer_match_reason]);
                                    $this->scorer->score($lead);
                                    $this->pipeline->move($lead, $lead->score_total >= 45 && $lead->contactability_score > 0 ? 'QUEUED' : 'ENRICHED', 'import_csv');
                                }
                                $this->memberships->attach($lead, $campaign);
                                LeadSource::firstOrCreate(['lead_id' => $lead->id, 'import_run_id' => $run->id], [
                                    'source' => $run->source, 'source_identifier' => $data['source_identifier'], 'source_url' => $record['url'] ?? $record['link'] ?? null,
                                    'query' => $meta['query'] ?? null, 'observed_at' => now(),
                                ]);

                                return ['kind' => $duplicate['status'], 'lead_id' => $lead->id];
                            });
                        } catch (ValidationException $exception) {
                            $result = ['kind' => 'error'];
                            SystemLog::create(['type' => 'import_error', 'message' => $exception->getMessage(), 'context' => ['import_run_id' => $run->id, 'row_number' => $rowNumber, 'row' => $record]]);
                        }
                    }
                    $counts['raw_count'] = $rowNumber;
                    match ($result['kind']) {
                        'strong' => $counts['duplicate_count']++, 'excluded' => $counts['excluded_count']++,
                        'unique', 'possible' => $counts['imported_count']++, 'error' => $counts['error_count']++, default => null,
                    };
                    if ($result['kind'] === 'possible') {
                        $counts['possible_duplicate_count']++;
                    }
                    $run->update($counts + ['processed_rows' => $rowNumber, 'claimed_at' => now()]);

                    return $result;
                });
                if (in_array($result['kind'], ['unique', 'possible'])) {
                    EnrichLead::dispatch($result['lead_id'])->onQueue('sales');
                }
            }
            $run->update($counts + ['status' => $cancelled ? 'cancelled' : 'completed', 'finished_at' => now()]);
        } catch (\Throwable $exception) {
            $run->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            throw $exception;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            ImportRun::whereKey($run->id)->where('claim_token', $token)->update(['claim_token' => null, 'claimed_at' => null]);
        }

        return $run->refresh();
    }

    private function open(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['csv' => 'No se pudo leer el archivo.']);
        }
        $line = fgets($handle);
        if ($line === false || trim($line) === '') {
            fclose($handle);
            throw ValidationException::withMessages(['csv' => 'El archivo está vacío.']);
        }
        $delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
        $headers = array_map(fn ($value) => Str::of(ltrim((string) $value, "\xEF\xBB\xBF"))->trim()->lower()->replace([' ', '-'], '_')->toString(), str_getcsv($line, separator: $delimiter, escape: ''));

        return [$handle, $headers, $delimiter];
    }

    private function record(array $headers, array $row, array $mapping): array
    {
        $record = [];
        foreach ($headers as $i => $header) {
            $record[$header] = trim((string) ($row[$i] ?? ''));
        }
        foreach ($mapping as $field => $column) {
            if (in_array($field, self::FIELDS) && filled($column)) {
                $record[$field] = $record[$column] ?? '';
            }
        }

        return $record;
    }

    private function normalize(array $record, Campaign $campaign, string $source): array
    {
        $website = $this->normalizer->url($this->first($record, ['website', 'website_url', 'site', 'web']));
        $name = $this->first($record, ['name', 'title', 'business_name', 'empresa']);
        if (! $name) {
            throw ValidationException::withMessages(['name' => 'Falta el nombre del negocio.']);
        }

        return ['name' => $name, 'category' => $this->first($record, ['category', 'type', 'categoria']),
            'location' => $this->first($record, ['location', 'address', 'direccion']), 'city' => $record['city'] ?? $campaign->city,
            'country' => $campaign->country, 'language' => $campaign->language, 'website_url' => $website,
            'domain' => $this->normalizer->domain($website), 'email' => $this->normalizer->email($this->first($record, ['email', 'emails'])),
            'phone' => $this->normalizer->phone($this->first($record, ['phone', 'phone_number', 'telefono', 'telephone']), $campaign->country),
            'contact_person' => $record['contact_person'] ?? null, 'contact_role' => $record['contact_role'] ?? null,
            'tags' => array_values(array_filter(array_map('trim', explode(',', $record['tags'] ?? '')))),
            'source' => $source, 'source_identifier' => $this->first($record, ['place_id', 'cid', 'google_id', 'id']),
            'segment' => $campaign->segment, 'acquired_at' => now(), 'status' => 'NEW'];
    }

    private function duplicate(array $data): array
    {
        $strong = ! empty($data['source_identifier']) ? Lead::where('source', $data['source'])->where('source_identifier', $data['source_identifier'])->first() : null;
        if ($strong) {
            return ['status' => 'strong', 'rule' => 'source_identifier', 'lead' => $strong];
        }
        $candidates = Lead::where(function ($q) use ($data) {
            foreach (['domain', 'email', 'phone'] as $field) {
                if (filled($data[$field])) {
                    $q->orWhere($field, $data[$field]);
                }
            }
            $q->orWhere('name', $data['name']);
        })->get();
        foreach ($candidates as $lead) {
            similar_text(Str::ascii(mb_strtolower($lead->name)), Str::ascii(mb_strtolower($data['name'])), $name);
            similar_text(Str::ascii(mb_strtolower($lead->location ?? '')), Str::ascii(mb_strtolower($data['location'] ?? '')), $location);
            if ($name >= 88 && $location >= 88 && filled($data['location'])) {
                return ['status' => 'strong', 'rule' => 'identity_location', 'lead' => $lead];
            }
        }
        $match = $candidates->first(fn ($lead) => collect(['domain', 'email', 'phone'])->contains(fn ($field) => filled($data[$field]) && $data[$field] === $lead->$field));

        return ['status' => $match ? 'possible' : 'unique', 'rule' => $match ? (filled($data['domain']) && $match->domain === $data['domain'] ? 'shared_domain' : (filled($data['phone']) && $match->phone === $data['phone'] ? 'shared_phone' : 'shared_email')) : null, 'lead' => $match];
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
}
