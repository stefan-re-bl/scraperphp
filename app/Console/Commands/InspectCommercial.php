<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\ImportRun;
use App\Models\LeadOfferScore;
use App\Models\ScrapeRun;
use App\Models\Signal;
use App\Services\CommercialWorkService;
use App\Services\CsvSerializer;
use App\Services\ExportService;
use App\Services\LeadFilterService;
use App\Services\MetricsService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InspectCommercial extends Command
{
    protected $signature = 'sales:inspect {resource : leads|campaigns|opportunities|runs|actions|metrics|scores|evidence|export}
        {--filters= : JSON object with the same lead filters as the web interface}
        {--campaign= : Campaign ID} {--ids= : Comma-separated lead IDs}
        {--limit=100 : Maximum rows, 1-2000} {--dimension=campaign : Metric dimension}
        {--rule-version= : Historical scoring rule version} {--dataset=leads : Export dataset} {--output= : Explicit path for a new CSV file}';

    protected $description = 'Consult commercial records with structured JSON; no contacts are sent';

    public function handle(LeadFilterService $leadFilters, MetricsService $metrics, CommercialWorkService $work, ExportService $exports): int
    {
        try {
            $raw = $this->option('filters');
            $filters = $raw === null ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($filters) || ($filters !== [] && array_is_list($filters))) {
                throw new \InvalidArgumentException('--filters debe ser un objeto JSON.');
            }
            if ($this->option('campaign') !== null) {
                $filters['campaign'] = $this->option('campaign');
            }
            if ($this->option('ids') !== null) {
                $filters['ids'] = $this->option('ids');
            }
            if ($this->option('rule-version') !== null) {
                $filters['rule_version'] = $this->option('rule-version');
            }
            $rules = ['campaign' => 'sometimes|integer|min:1|exists:campaigns,id', 'from' => 'sometimes|date', 'to' => 'sometimes|date|after_or_equal:from',
                'acquired_from' => 'sometimes|date', 'acquired_to' => 'sometimes|date', 'min_score' => 'sometimes|integer|min:0', 'max_score' => 'sometimes|integer|min:0',
                'q' => 'sometimes|string|max:255', 'status' => 'sometimes|string', 'offer_id' => 'sometimes|integer|min:1', 'score_offer_id' => 'sometimes|integer|min:1|exists:offers,id',
                'rule_version' => 'sometimes|string|max:255', 'evidence_source' => 'sometimes|string|max:100', 'evidence_review' => 'sometimes|in:pending,confirmed,rejected', 'channel' => 'sometimes|in:email,phone,whatsapp,manual',
                'relationship_type' => 'sometimes|in:customer,collaborator,prospect'];
            foreach (['segment', 'city', 'country', 'source', 'language', 'signal', 'variant', 'manual_priority', 'entity_type', 'tag', 'sort'] as $key) {
                $rules[$key] = 'sometimes|string|max:255';
            }
            foreach (['due', 'classification_pending', 'has_email', 'has_phone', 'has_web', 'possible_duplicate', 'contacted', 'replied', 'interested', 'proposal', 'won', 'lost'] as $key) {
                $rules[$key] = 'sometimes|boolean';
            }
            if (array_diff(array_keys($filters), array_merge(array_keys($rules), ['ids'])) !== []) {
                throw new \InvalidArgumentException('Filtro desconocido. Usa los filtros comerciales documentados.');
            }
            Validator::make($filters, $rules)->validate();
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if (! $limit || $limit < 1 || $limit > 2000) {
                throw new \InvalidArgumentException('--limit debe estar entre 1 y 2000.');
            }
            $resource = $this->argument('resource');
            if (in_array($resource, ['runs', 'campaigns'], true) && array_diff(array_keys($filters), ['campaign']) !== []) {
                throw new \InvalidArgumentException('Este recurso solo admite el filtro campaign.');
            }
            if ($resource === 'opportunities' && ! isset($filters['campaign']) && array_intersect(array_keys($filters), ['status', 'due', 'offer_id', 'channel', 'contacted', 'replied', 'interested', 'proposal', 'won', 'lost']) !== []) {
                throw new \InvalidArgumentException('Para filtrar estados o actividad de oportunidades indica --campaign.');
            }
            if ($this->option('output') !== null && $resource !== 'export') {
                throw new \InvalidArgumentException('--output solo es válido con export.');
            }
            $selection = array_diff_key($filters, array_flip(['rule_version', 'evidence_source', 'evidence_review']));
            if ($resource === 'scores') {
                unset($selection['offer_id']);
            }
            $query = $leadFilters->query($selection);
            $data = match ($resource) {
                'leads' => $query->limit($limit)->get()->toArray(),
                'campaigns' => Campaign::when($filters['campaign'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('id')->limit($limit)->get()->toArray(),
                'opportunities' => DB::table('campaign_lead')->whereIn('lead_id', $query->select('leads.id'))->when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->orderBy('id')->limit($limit)->get()->toArray(),
                'runs' => [
                    'scraping' => ScrapeRun::when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->latest('id')->limit($limit)->get(['id', 'campaign_id', 'status', 'dry_run', 'raw_count', 'imported_count', 'duplicate_count', 'error_count', 'started_at', 'finished_at'])->toArray(),
                    'imports' => ImportRun::when($filters['campaign'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))->latest('id')->limit($limit)->get(['id', 'campaign_id', 'status', 'imported_count', 'duplicate_count', 'error_count', 'created_at', 'updated_at'])->toArray(),
                ],
                'actions' => $work->actions(isset($filters['campaign']) ? (int) $filters['campaign'] : null, $limit, $filters),
                'metrics' => ['funnel' => $metrics->funnel($filters), 'segments' => $metrics->segmented($this->validDimension(), $filters), 'evaluation' => $metrics->scoreEvaluation($filters)],
                'scores' => $this->scores($query, $filters, $limit),
                'evidence' => Signal::whereIn('lead_id', $query->select('leads.id'))
                    ->when($filters['evidence_source'] ?? null, fn ($q, $source) => $q->where('source', $source))
                    ->when($filters['evidence_review'] ?? null, fn ($q, $review) => $q->where('review_status', $review))
                    ->when($filters['rule_version'] ?? null, fn ($q, $version) => $q->where('rule_version', $version))
                    ->orderBy('lead_id')->orderBy('id')->limit($limit)->get()->toArray(),
                'export' => $this->export($exports, $filters, $limit),
                default => throw new \InvalidArgumentException('Recurso desconocido. Usa leads, campaigns, opportunities, runs, actions, metrics, scores, evidence o export.'),
            };
            $this->line(json_encode(['ok' => true, 'resource' => $resource, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\JsonException|\InvalidArgumentException|ValidationException $exception) {
            $this->line(json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return self::INVALID;
        } catch (\Throwable $exception) {
            report($exception);
            $this->line(json_encode(['ok' => false, 'error' => 'No se pudo completar la consulta comercial. Revisa el diagnóstico local.'], JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }

    private function scores(Builder $leadQuery, array $filters, int $limit): array
    {
        $rows = LeadOfferScore::whereIn('lead_id', $leadQuery->select('leads.id'))->with('offer')
            ->when($filters['score_offer_id'] ?? $filters['offer_id'] ?? null, fn ($q, $id) => $q->where('offer_id', $id))
            ->when($filters['rule_version'] ?? null, fn ($q, $version) => $q->where('rule_version', $version))
            ->orderBy('lead_id')->orderBy('offer_id')->limit($limit)->get();
        $freshIds = LeadOfferScore::query()->current()->whereIn('id', $rows->modelKeys())->pluck('id');

        return $rows->map(fn ($score) => [...$score->toArray(), 'fresh' => $freshIds->contains($score->id)])->all();
    }

    private function validDimension(): string
    {
        $dimension = $this->option('dimension');
        if (! in_array($dimension, ['campaign', 'channel', 'offer', 'segment', 'city', 'country', 'source', 'language', 'variant'], true)) {
            throw new \InvalidArgumentException('Dimensión de métricas inválida.');
        }

        return $dimension;
    }

    private function export(ExportService $exports, array $filters, int $limit): array
    {
        $dataset = $this->option('dataset');
        if (! isset(ExportService::DATASETS[$dataset])) {
            throw new \InvalidArgumentException('Dataset inválido.');
        }
        if ($this->option('output') === null) {
            return ['dataset' => $dataset, 'rows' => $exports->datasetQuery($dataset, $filters)->withoutEagerLoads()->limit($limit)->get()->toArray()];
        }
        $path = $this->option('output');
        if (str_contains($path, '://') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('--output debe ser una ruta local.');
        }
        // Exclusive creation preserves existing exports and prevents accidental replacement.
        $handle = @fopen($path, 'x');
        if (! $handle) {
            throw new \InvalidArgumentException('No se puede crear el archivo; la ruta debe ser nueva y escribible.');
        }
        $count = 0;
        try {
            // Reuse the web serializer and incremental query without capturing the file in memory.
            $exports->datasetQuery($dataset, $filters)->chunk(200, function ($rows) use ($handle, &$count, $limit) {
                foreach ($rows as $row) {
                    if ($count >= $limit) {
                        return false;
                    }
                    if ($count === 0) {
                        CsvSerializer::row($handle, array_keys($row->getAttributes()));
                    }
                    CsvSerializer::row($handle, array_values($row->getAttributes()));
                    $count++;
                }
            });
        } finally {
            fclose($handle);
        }

        return ['dataset' => $dataset, 'output' => realpath($path), 'rows' => $count];
    }
}
