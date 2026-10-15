<?php

namespace App\Services;

use App\Jobs\EnrichLead;
use App\Jobs\ImportLeadFile;
use App\Models\Campaign;
use App\Models\ImportRowIssue;
use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\ScrapeRun;
use App\Models\SystemLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadImporter
{
    public const CLAIM_SECONDS = 900;

    public const FIELDS = ['name', 'category', 'location', 'city', 'website', 'email', 'phone', 'place_id', 'contact_person', 'contact_role', 'tags', 'latitude', 'longitude'];

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
        try {
            return $this->processCsv($file, $campaign, $meta);
        } catch (\Throwable $exception) {
            throw AcquisitionFailure::publicException($exception);
        }
    }

    private function processCsv(UploadedFile|string $file, Campaign $campaign, array $meta): ImportRun
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $run = isset($meta['run_id']) ? ImportRun::findOrFail($meta['run_id']) : ImportRun::create([
            'campaign_id' => $campaign->id, 'campaign_query_id' => $meta['campaign_query_id'] ?? null,
            'scrape_run_id' => $meta['scrape_run_id'] ?? null, 'source' => $meta['source'] ?? $campaign->source,
            'query' => $meta['query'] ?? null, 'location' => $meta['location'] ?? null,
            'segment' => $campaign->segment, 'file_path' => $path, 'mapping' => $meta['mapping'] ?? [], 'status' => 'queued',
        ]);
        if (in_array($run->status, ['completed', 'cancelled'])) {
            return $run;
        }
        $token = Str::uuid()->toString();
        $claimed = ImportRun::whereKey($run->id)->whereNotIn('status', ['completed', 'cancelled'])->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::CLAIM_SECONDS)))
            ->update(['status' => 'processing', 'claim_token' => $token, 'claimed_at' => now(), 'started_at' => $run->started_at ?? now(), 'error_message' => null]);
        if (! $claimed) {
            return $run->refresh();
        }
        $run->refresh();
        $handle = null;
        try {
            if ($campaign->fresh()->kill_switch || $campaign->fresh()->status === 'paused') {
                $this->ownedUpdate($run, $token, ['status' => 'cancelled', 'finished_at' => now()]);

                return $run->refresh();
            }
            if (! is_file($path)) {
                throw new \RuntimeException('No se encontró el archivo importado.');
            }
            $hash = hash_file('sha256', $path);
            if ($run->file_hash && $run->file_hash !== $hash) {
                throw new \RuntimeException('El archivo cambió desde la importación anterior. Importalo como un archivo nuevo.');
            }
            $this->ownedUpdate($run, $token, ['file_hash' => $hash]);
            [$handle, $headers, $delimiter] = $this->open($path);
            $this->validateMapping($headers, $meta['mapping'] ?? $run->mapping ?? []);
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
                $result = DB::transaction(function () use ($record, $campaign, $run, $meta, $rowNumber, &$counts, $token, $headers, $row) {
                    if (! ImportRun::whereKey($run->id)->where('claim_token', $token)->exists()) {
                        throw new AcquisitionClaimLost('La reserva de importación pertenece a otra tarea.');
                    }
                    $result = ['kind' => 'skipped'];
                    if (! isset($meta['max_rows']) || $rowNumber <= $meta['max_rows']) {
                        try {
                            $result = DB::transaction(function () use ($record, $campaign, $run, $meta, $headers, $row) {
                                $this->validateRow($headers, $row, $record);
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
                                    $this->pipeline->move($lead, $duplicate['status'] !== 'possible' && $lead->score_total >= 45 && $lead->contactability_score > 0 ? 'QUEUED' : 'ENRICHED', 'import_csv');
                                }
                                if (! $lead->is_customer && ! $lead->classification_pending) {
                                    $this->memberships->attach($lead, $campaign);
                                }
                                LeadSource::firstOrCreate(['lead_id' => $lead->id, 'source' => $run->source, 'source_identifier' => $data['source_identifier'], 'import_run_id' => $lead->is_customer || $lead->classification_pending ? null : $run->id], [
                                    'source' => $run->source, 'source_identifier' => $data['source_identifier'], 'source_url' => $record['url'] ?? $record['link'] ?? null,
                                    'query' => $meta['query'] ?? null, 'observed_at' => now(),
                                    'metadata' => ['observed_import_run_id' => $run->id] + $this->coordinates($record),
                                ]);

                                return ['kind' => $duplicate['status'], 'lead_id' => $lead->id];
                            });
                        } catch (ValidationException $exception) {
                            $result = ['kind' => 'error'];
                            ImportRowIssue::updateOrCreate(['import_run_id' => $run->id, 'row_number' => $rowNumber], ['errors' => $exception->errors()]);
                            SystemLog::create(['type' => 'import_error', 'message' => 'Fila rechazada durante la importación.', 'context' => ['import_run_id' => $run->id, 'row_number' => $rowNumber, 'fields' => array_keys($exception->errors()), 'reasons' => $exception->errors()]]);
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
                    $this->ownedUpdate($run, $token, $counts + ['processed_rows' => $rowNumber, 'claimed_at' => now()]);

                    return $result;
                });
                if (in_array($result['kind'], ['unique', 'possible'])) {
                    EnrichLead::dispatch($result['lead_id'])->onQueue('sales');
                }
            }
            $this->ownedUpdate($run, $token, $counts + ['status' => $cancelled ? 'cancelled' : 'completed', 'finished_at' => now()]);
        } catch (AcquisitionClaimLost) {
            // A replacement worker owns all further changes, including terminal state.
            return $run->refresh();
        } catch (\Throwable $exception) {
            $exception = AcquisitionFailure::publicException($exception);
            ImportRun::whereKey($run->id)->where('claim_token', $token)->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            throw $exception;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            ImportRun::whereKey($run->id)->where('claim_token', $token)->update(['claim_token' => null, 'claimed_at' => null]);
        }

        return $run->refresh();
    }

    private function ownedUpdate(ImportRun $run, string $token, array $values): void
    {
        if (ImportRun::whereKey($run->id)->where('claim_token', $token)->update($values) !== 1) {
            throw new AcquisitionClaimLost('La reserva de importación pertenece a otra tarea.');
        }
    }

    public function retryDelay(ImportRun $run): int
    {
        return $run->claimed_at ? max(1, (int) now()->diffInSeconds($run->claimed_at->copy()->addSeconds(self::CLAIM_SECONDS), false) + 1) : 1;
    }

    /** Scan incrementally using exactly the parser and normalizer used for import. */
    public function analyze(string $path, Campaign $campaign, array $mapping = [], ?string $source = null): array
    {
        [$handle, $headers, $delimiter] = $this->open($path);
        try {
            $this->validateMapping($headers, $mapping);
        } catch (ValidationException $exception) {
            fclose($handle);
            throw $exception;
        }
        $summary = ['rows' => 0, 'valid' => 0, 'invalid' => 0, 'duplicates' => 0, 'possible_duplicates' => 0, 'excluded' => 0,
            'recognized' => [], 'missing' => [], 'issues' => [], 'warnings' => [], 'mapping' => $mapping];
        $aliases = ['name' => ['name', 'title', 'business_name', 'empresa'], 'email' => ['email', 'emails'],
            'phone' => ['phone', 'phone_number', 'telefono', 'telephone'], 'website' => ['website', 'website_url', 'site', 'web'],
            'location' => ['location', 'address', 'direccion'], 'category' => ['category', 'type', 'categoria'],
            'place_id' => ['place_id', 'cid', 'google_id', 'id'], 'latitude' => ['latitude', 'lat'], 'longitude' => ['longitude', 'lng', 'lon']];
        foreach (self::FIELDS as $field) {
            $columns = filled($mapping[$field] ?? null) ? [$mapping[$field]] : ($aliases[$field] ?? [$field]);
            if (array_intersect($headers, $columns)) {
                $summary['recognized'][] = $field;
            } else {
                $summary['missing'][] = $field;
            }
        }
        $seen = [];
        try {
            while (($row = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $number = ++$summary['rows'];
                $record = $this->record($headers, $row, $mapping);
                try {
                    $this->validateRow($headers, $row, $record);
                    $data = $this->normalize($record, $campaign, $source ?? $campaign->source);
                    $summary['valid']++;
                    if ($this->exclusions->matches($data)) {
                        $summary['excluded']++;

                        continue;
                    }
                    $match = $this->duplicate($data);
                    $fingerprint = hash('sha256', json_encode(array_intersect_key($data, array_flip(['name', 'domain', 'email', 'phone', 'location', 'city', 'source_identifier']))));
                    $external = filled($data['source_identifier']) ? $data['source'].':'.$data['source_identifier'] : null;
                    if ($match['status'] === 'strong' || isset($seen['row:'.$fingerprint]) || ($external && isset($seen['external:'.$external]))) {
                        $summary['duplicates']++;
                    } elseif ($match['status'] === 'possible') {
                        $summary['possible_duplicates']++;
                    } elseif ((filled($data['domain']) && isset($seen['domain:'.$data['domain']])) || (filled($data['email']) && isset($seen['email:'.$data['email']]))) {
                        $summary['possible_duplicates']++;
                    }
                    $seen['row:'.$fingerprint] = true;
                    foreach (['domain', 'email'] as $field) {
                        if (filled($data[$field])) {
                            $seen[$field.':'.$data[$field]] = true;
                        }
                    }
                    if ($external) {
                        $seen['external:'.$external] = true;
                    }
                } catch (ValidationException $exception) {
                    $summary['invalid']++;
                    if (count($summary['issues']) < 500) {
                        $summary['issues'][] = ['row' => $number, 'errors' => $exception->errors()];
                    }
                }
            }
        } finally {
            fclose($handle);
        }
        if ($summary['possible_duplicates']) {
            $summary['warnings'][] = 'Los posibles duplicados quedarán pendientes de revisión y bloqueados para contacto.';
        }
        if ($summary['invalid'] > count($summary['issues'])) {
            $summary['warnings'][] = 'Se muestran los primeros 500 errores. El resultado final incluye todas las filas rechazadas.';
        }
        $summary['file_hash'] = hash_file('sha256', $path);
        $summary['analysis_hash'] = hash('sha256', $summary['file_hash'].json_encode($mapping));

        return $summary;
    }

    private function validateMapping(array $headers, array $mapping): void
    {
        foreach ($mapping as $field => $column) {
            if (filled($column) && (! in_array($field, self::FIELDS, true) || ! in_array($column, $headers, true))) {
                throw ValidationException::withMessages(['mapping' => 'La asignación contiene una columna o un campo desconocido.']);
            }
        }
    }

    private function validateRow(array $headers, array $row, array $record): void
    {
        if (count($headers) !== count($row)) {
            throw ValidationException::withMessages(['columns' => 'La cantidad de columnas de la fila no coincide con la cabecera.']);
        }
        $email = $this->first($record, ['email', 'emails']);
        $website = $this->first($record, ['website', 'website_url', 'site', 'web']);
        if ($email && ! $this->normalizer->email($email)) {
            throw ValidationException::withMessages(['email' => 'El email no contiene una dirección válida.']);
        }
        if ($website && ! $this->normalizer->url($website)) {
            throw ValidationException::withMessages(['website' => 'La dirección del sitio no es válida.']);
        }
    }

    public function rejectedRows(ImportRun $run): \Generator
    {
        if (! is_file($run->file_path) || ($run->file_hash && hash_file('sha256', $run->file_path) !== $run->file_hash)) {
            throw new \RuntimeException('El archivo original no está disponible o cambió.');
        }
        [$handle, $headers, $delimiter] = $this->open($run->file_path);
        try {
            yield ['fila', 'motivos', ...$headers];
            $number = 0;
            $issues = ImportRowIssue::where('import_run_id', $run->id)->orderBy('row_number')->cursor()->getIterator();
            $issues->rewind();
            while (($row = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $number++;
                if ($issues->valid() && $issues->current()->row_number === $number) {
                    yield [$number, implode(' | ', Arr::flatten($issues->current()->errors)), ...$row];
                    $issues->next();
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function open(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['csv' => 'No se pudo leer el archivo.']);
        }
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

        if (count($headers) !== count(array_unique($headers)) || in_array('', $headers, true)) {
            fclose($handle);
            throw ValidationException::withMessages(['csv' => 'Las columnas deben tener nombres únicos y no vacíos.']);
        }

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
            'phone_original' => $this->first($record, ['phone', 'phone_number', 'telefono', 'telephone']),
            'contact_person' => $record['contact_person'] ?? null, 'contact_role' => $record['contact_role'] ?? null,
            'tags' => array_values(array_filter(array_map('trim', explode(',', $record['tags'] ?? '')))),
            'source' => $source, 'source_identifier' => $this->first($record, ['place_id', 'cid', 'google_id', 'id']),
            'segment' => $campaign->segment, 'acquired_at' => now(), 'status' => 'NEW'];
    }

    /** @return array{coordinates?: array{latitude: float, longitude: float}} */
    private function coordinates(array $record): array
    {
        $latitude = $this->first($record, ['latitude', 'lat']);
        $longitude = $this->first($record, ['longitude', 'lng', 'lon']);
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [];
        }
        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return [];
        }

        return ['coordinates' => ['latitude' => $latitude, 'longitude' => $longitude]];
    }

    private function duplicate(array $data): array
    {
        return app(LeadIdentityMatcher::class)->match($data);
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
