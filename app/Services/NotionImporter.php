<?php

namespace App\Services;

use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use ZipArchive;

class NotionImporter
{
    public function __construct(private readonly NotionValueParser $values, private readonly NotionNotesSanitizer $sanitizer, private readonly LeadIdentityMatcher $identities, private readonly ContactNormalizer $normalizer) {}

    /** @param array<string, array<string, mixed>> $decisions
     * @return array{report: array<string, mixed>, records: array<int, array<string, mixed>>}
     */
    public function preview(string $path, array $decisions = []): array
    {
        [$csv, $markdowns, $fileHash] = $this->readArchive($path);
        $timezone = app(PreferencesService::class)->all()['timezone'];
        $records = [];
        $report = ['version' => 1, 'file_sha256' => $fileHash, 'timezone' => $timezone, 'read' => count($csv), 'new' => 0, 'existing' => 0,
            'possible_duplicates' => 0, 'ambiguous' => 0, 'ready' => 0, 'skipped' => 0, 'discarded_notes' => 0, 'unmatched_markdown' => [], 'rows' => []];
        $usedMarkdown = [];
        foreach ($csv as $index => $row) {
            $issues = [];
            $warnings = [];
            $candidates = $this->markdownCandidates($row, $markdowns);
            $explicitId = $this->notionId($row['ID'] ?? $row['Notion ID'] ?? $row['URL'] ?? '');
            if ($explicitId && isset($markdowns[$explicitId])) {
                $candidates = [$explicitId];
            }
            $id = $explicitId ?? (count($candidates) === 1 ? $candidates[0] : null);
            $key = $id ? 'notion:'.$id : 'notion:row:'.$fileHash.':'.($index + 1);
            $decision = $this->decision($decisions[$key] ?? []);
            if (isset($decision['markdown_id'])) {
                if (! in_array($decision['markdown_id'], $candidates, true)) {
                    throw new RuntimeException('La decisión de ficha Markdown no coincide con los candidatos del registro.');
                }
                $id = $decision['markdown_id'];
            }
            $markdown = $id ? ($markdowns[$id] ?? null) : null;
            if ($markdown) {
                $usedMarkdown[] = $id;
            }
            $name = trim($row['Nombre'] ?? '');
            $company = trim($row['Empresa'] ?? '');
            $organization = $company !== '' && $this->identity($company) !== $this->identity($name);
            $originalStatus = trim($row['Estado'] ?? '');
            if (! in_array($originalStatus, ['Socio', 'Perdido', 'Cerrado 💪', 'En desarrollo', ''], true)) {
                $issues[] = 'original_status_invalid';
                $originalStatus = '';
            }
            $pendingClassification = ! in_array($originalStatus, ['Socio', 'Perdido'], true);
            $priority = ['Alta' => 'high', 'Media' => 'medium', 'Baja' => 'low'][$row['Prioridad'] ?? ''] ?? null;
            $data = ['name' => $organization ? $company : $name, 'entity_type' => $organization ? 'organization' : 'person',
                'contact_person' => $organization ? $name : null, 'source' => 'notion', 'source_identifier' => $id, 'language' => 'es',
                'status' => $originalStatus === 'Perdido' ? 'LOST' : 'NEW', 'classification_pending' => $pendingClassification,
                'is_customer' => false, 'is_collaborator' => $originalStatus === 'Socio', 'manual_priority' => $priority,
                'phone_original' => filled($row['Teléfono'] ?? null) ? trim($row['Teléfono']) : null,
                'phone' => $this->values->phone($row['Teléfono'] ?? null), 'email' => null];
            if ($name === '' || mb_strlen($name) > 255 || mb_strlen($company) > 255 || $this->sanitizer->containsSensitive($name.' '.$company)) {
                $issues[] = 'identity_invalid';
            }
            if (filled($row['Prioridad'] ?? null) && $priority === null) {
                $issues[] = 'manual_priority_invalid';
            }
            if ($data['phone_original'] && ($this->sanitizer->containsSensitive($data['phone_original']) || mb_strlen($data['phone_original']) > 80)) {
                $issues[] = 'phone_original_omitted';
                $data['phone_original'] = null;
                $data['phone'] = null;
            }
            if ($data['phone_original'] && (! $data['phone'] || mb_strlen($data['phone_original']) > 80)) {
                $warnings[] = 'phone_not_normalized';
            }
            if (filled($row['Email'] ?? null)) {
                if (filter_var(trim($row['Email']), FILTER_VALIDATE_EMAIL)) {
                    $data['email'] = $this->normalizer->email($row['Email']);
                } else {
                    $issues[] = 'email_invalid';
                }
            }
            foreach (['Valor estimado' => 'estimated_value_minor', 'Añadido' => 'origin_created_at', 'Último contacto' => 'imported_last_contact_at', 'Proxima Entrega' => 'delivery_due_at', 'Próxima entrega' => 'delivery_due_at'] as $field => $target) {
                if (! array_key_exists($field, $row)) {
                    continue;
                }
                try {
                    $data[$target] = $target === 'estimated_value_minor' ? $this->values->money($row[$field]) : $this->values->date($row[$field], $timezone);
                } catch (\InvalidArgumentException $exception) {
                    $issues[] = $target.'_invalid';
                }
            }
            $data['estimated_currency'] = ($data['estimated_value_minor'] ?? null) !== null ? 'ARS' : null;
            $notes = $this->sanitizer->sanitize($markdown['content'] ?? '');
            $report['discarded_notes'] += $notes['discarded'];
            $source = LeadSource::where('import_key', $key)->first();
            $match = $source ? ['status' => 'strong', 'lead' => $source->lead, 'candidates' => [$source->lead_id], 'rule' => 'import_key'] : $this->identities->match($data);
            if ($match['status'] === 'possible') {
                $report['possible_duplicates']++;
            }
            $targetId = $source?->lead_id ?? $match['lead']?->id;
            $action = $decision['action'] ?? 'import';
            $targetKey = $decision['match_key'] ?? null;
            if ($source) {
                $action = 'unchanged';
            } elseif ($action === 'match' && ! $targetKey) {
                if (! Lead::whereKey($decision['lead_id'] ?? 0)->exists()) {
                    throw new RuntimeException('La decisión referencia un registro comercial inexistente.');
                }
                $targetId = (int) $decision['lead_id'];
            } elseif ($action === 'new') {
                if ($match['status'] === 'strong') {
                    throw new RuntimeException('No se puede crear otra identidad cuando existe una coincidencia confiable.');
                }
                $targetId = null;
            }
            if (array_key_exists('is_customer', $decision) || array_key_exists('is_collaborator', $decision)) {
                $data['is_customer'] = $decision['is_customer'] ?? $data['is_customer'];
                $data['is_collaborator'] = $decision['is_collaborator'] ?? $data['is_collaborator'];
                $data['classification_pending'] = false;
                $pendingClassification = false;
            }
            if (isset($decision['status'])) {
                $data['status'] = $decision['status'];
            }
            if (isset($decision['entity_type'])) {
                $data['entity_type'] = $decision['entity_type'];
            }
            $ambiguities = [];
            if ($pendingClassification) {
                $ambiguities[] = 'classification_required';
            }
            if (count($candidates) > 1 && ! isset($decision['markdown_id'])) {
                $ambiguities[] = 'markdown_ambiguous';
            }
            if ($match['status'] === 'possible' && ! in_array($action, ['match', 'new', 'skip'])) {
                $ambiguities[] = 'identity_required';
            }
            if ($issues !== [] && ! ($decision['omit_invalid'] ?? false)) {
                $ambiguities[] = 'invalid_fields_require_review';
            }
            if (in_array('identity_invalid', $issues, true)) {
                $ambiguities[] = 'identity_invalid';
            }
            $records[] = ['key' => $key, 'notion_id' => $id, 'data' => $data, 'notes' => $notes['notes'], 'original_status' => $originalStatus,
                'action' => $action, 'target_id' => $targetId, 'target_key' => $targetKey, 'contact_name' => $name, 'ambiguities' => $ambiguities, 'errors' => $issues, 'warnings' => $warnings,
                'candidates' => $match['candidates'], 'markdown_candidates' => $candidates];
        }
        if (array_diff(array_keys($decisions), array_column($records, 'key')) !== []) {
            throw new RuntimeException('El archivo de decisiones referencia registros que no están en este export.');
        }
        foreach ($records as $index => &$record) {
            if (count(array_filter($records, fn ($row) => $row['key'] === $record['key'])) > 1) {
                $record['ambiguities'][] = 'duplicate_notion_id';
            }
            $sameExport = [];
            foreach ($records as $otherIndex => $other) {
                if ($index !== $otherIndex && $record['key'] !== $other['key'] && $this->sharedExportIdentity($record['data'], $other['data'])) {
                    $sameExport[] = $other['key'];
                }
            }
            if ($sameExport !== [] && ! in_array($record['action'], ['new', 'match', 'skip', 'unchanged'])) {
                $record['ambiguities'][] = 'shared_identity_in_export';
            }
            $record['ready'] = $record['ambiguities'] === [] && ! in_array($record['action'], ['skip', 'unchanged']);
            if ($record['action'] === 'unchanged') {
                $report['existing']++;
            } elseif ($record['action'] === 'skip') {
                $report['skipped']++;
            } else {
                $report[$record['target_id'] || $record['target_key'] ? 'existing' : 'new']++;
                $report['ambiguous'] += $record['ambiguities'] !== [] ? 1 : 0;
                $report['ready'] += $record['ready'] ? 1 : 0;
            }
            $report['rows'][] = ['row' => $index + 1, 'key' => $record['key'], 'notion_id' => $record['notion_id'], 'action' => $record['action'],
                'lead_id' => $record['target_id'], 'match_key' => $record['target_key'], 'candidate_lead_ids' => $record['candidates'], 'markdown_candidates' => $record['markdown_candidates'],
                'duplicate_export_keys' => $sameExport, 'identity' => in_array('identity_invalid', $record['errors'], true) ? '[Omitida]' : $record['data']['name'],
                'original_status' => in_array($record['original_status'], ['Socio', 'Perdido', 'Cerrado 💪', 'En desarrollo', ''], true) ? $record['original_status'] : '[No reconocido]',
                'classification_pending' => $record['data']['classification_pending'],
                'ready' => $record['ready'], 'errors' => $record['errors'], 'warnings' => $record['warnings'], 'review' => $record['ambiguities'],
                'omitted_fields' => ['Botón de contacto', 'Titular de la cuenta'], 'notes_discarded' => $record['notes'] === null && $record['notion_id'] && isset($markdowns[$record['notion_id']]) ? $this->sanitizer->sanitize($markdowns[$record['notion_id']]['content'])['discarded'] : 0];
        }
        unset($record);
        foreach ($records as $index => &$record) {
            if (! $record['target_key'] || ! $record['ready']) {
                continue;
            }
            $target = collect($records)->firstWhere('key', $record['target_key']);
            if (! $target || $target['key'] === $record['key'] || $target['target_key']) {
                throw new RuntimeException('match_key debe identificar un registro principal distinto, sin referencias encadenadas.');
            }
            if (! $target['ready'] && $target['action'] !== 'unchanged') {
                $record['ambiguities'][] = 'target_requires_review';
                $record['ready'] = false;
                $report['rows'][$index]['review'] = $record['ambiguities'];
                $report['rows'][$index]['ready'] = false;
                $report['ready']--;
                $report['ambiguous']++;
            }
        }
        unset($record);
        foreach (array_diff(array_keys($markdowns), $usedMarkdown) as $id) {
            $report['unmatched_markdown'][] = $id;
        }
        $report['state_sha256'] = $this->stateFingerprint();
        $report['plan_sha256'] = hash('sha256', json_encode([$report, $decisions], JSON_THROW_ON_ERROR));

        return compact('report', 'records');
    }

    /** @param array<string, mixed> $plan */
    public function apply(array $plan): array
    {
        return DB::transaction(function () use ($plan): array {
            $run = ImportRun::create(['source' => 'notion', 'campaign_id' => null, 'status' => 'processing', 'file_hash' => $plan['report']['file_sha256'],
                'raw_count' => $plan['report']['read'], 'started_at' => now(), 'raw_metadata' => ['plan_sha256' => $plan['report']['plan_sha256']]]);
            if (! hash_equals($plan['report']['state_sha256'], $this->stateFingerprint())) {
                throw new RuntimeException('La cartera cambió antes de iniciar la transacción. Genera otra vista previa.');
            }
            $created = 0;
            $matched = 0;
            $records = $plan['records'];
            usort($records, fn ($left, $right) => (bool) $left['target_key'] <=> (bool) $right['target_key']);
            foreach ($records as $record) {
                if (! $record['ready'] || LeadSource::where('import_key', $record['key'])->exists()) {
                    continue;
                }
                $data = $record['data'];
                if ($record['target_key']) {
                    $record['target_id'] = LeadSource::where('import_key', $record['target_key'])->value('lead_id');
                    if (! $record['target_id']) {
                        throw new RuntimeException('No se pudo resolver el registro principal aprobado.');
                    }
                }
                if ($record['target_id']) {
                    $lead = Lead::lockForUpdate()->findOrFail($record['target_id']);
                    $safeFill = [];
                    $sameContact = blank($lead->contact_person) || $this->identity($lead->contact_person) === $this->identity($record['contact_name']);
                    foreach (['phone', 'phone_original', 'email', 'contact_person', 'origin_created_at', 'imported_last_contact_at', 'delivery_due_at'] as $field) {
                        if (! $sameContact && in_array($field, ['phone', 'phone_original', 'email', 'contact_person'], true)) {
                            continue;
                        }
                        if ($lead->$field === null && isset($data[$field])) {
                            $safeFill[$field] = $data[$field];
                        }
                    }
                    if ($lead->estimated_value_minor === null && $lead->estimated_currency === null && isset($data['estimated_value_minor'])) {
                        $safeFill['estimated_value_minor'] = $data['estimated_value_minor'];
                        $safeFill['estimated_currency'] = $data['estimated_currency'];
                    }
                    $lead->fill($safeFill);
                    $matched++;
                } else {
                    $lead = Lead::create($data + ['acquired_at' => now()]);
                    $created++;
                }
                if ($record['notes']) {
                    $lead->notes = trim(($lead->notes ?? '')."\n\n[Notion ".($record['notion_id'] ?? $record['key'])."]\n".$record['notes']);
                }
                $lead = app(LeadContactSynchronizer::class)->updateLead($lead, $lead->getDirty());
                if ($lead->entity_type === 'organization' && filled($record['contact_name']) && $this->identity($record['contact_name']) !== $this->identity($lead->name)) {
                    $lead->contacts()->firstOrCreate(['import_key' => $record['key']], ['name' => $record['contact_name'], 'phone' => $data['phone'],
                        'phone_original' => $data['phone_original'], 'email' => $data['email'], 'is_primary' => ! $record['target_id']]);
                }
                $lead->sources()->create(['source' => 'notion', 'source_identifier' => $record['notion_id'], 'import_key' => $record['key'],
                    'import_run_id' => $run->id, 'observed_at' => now(), 'metadata' => ['original_status' => $record['original_status'],
                        'classification_pending' => $data['classification_pending'], 'errors' => $record['errors'], 'warnings' => $record['warnings'],
                        'notes_sha256' => $record['notes'] ? hash('sha256', $record['notes']) : null]]);
            }
            $run->update(['status' => 'completed', 'finished_at' => now(), 'imported_count' => $created, 'duplicate_count' => $matched, 'processed_rows' => $plan['report']['read']]);

            return ['run_id' => $run->id, 'created' => $created, 'matched' => $matched, 'pending' => $plan['report']['ambiguous']];
        });
    }

    /** @return array{0: array<int, array<string, string>>, 1: array<string, array<string, mixed>>, 2: string} */
    private function readArchive(string $path): array
    {
        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP de Notion.');
        }
        try {
            $csvFiles = [];
            $markdowns = [];
            $totalSize = 0;
            if ($zip->numFiles > 500) {
                throw new RuntimeException('El ZIP supera el límite de archivos.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = rawurldecode($stat['name']);
                $totalSize += $stat['size'];
                if ($stat['size'] > 5 * 1024 * 1024 || $totalSize > 50 * 1024 * 1024 || preg_match('~(?:^/|^[A-Za-z]:|\\\\|(?:^|/)\.\.(?:/|$)|\x00)~', $name)) {
                    throw new RuntimeException('El ZIP contiene una ruta insegura o excede los límites de tamaño.');
                }
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('El ZIP contiene un enlace no permitido.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($extension, ['md', 'csv'])) {
                    throw new RuntimeException('El ZIP contiene un formato no permitido.');
                }
                $content = $zip->getFromIndex($index);
                if ($content === false || ! mb_check_encoding($content, 'UTF-8')) {
                    throw new RuntimeException('No se pudo leer un archivo UTF-8 del ZIP.');
                }
                $content = ltrim($content, "\xEF\xBB\xBF");
                if ($extension === 'csv') {
                    $csvFiles[$name] = $content;
                } elseif (preg_match('/([a-f0-9]{32})\.md$/i', $name, $match)) {
                    $id = strtolower($match[1]);
                    if (isset($markdowns[$id])) {
                        throw new RuntimeException('El ZIP repite un identificador de Notion.');
                    }
                    $markdowns[$id] = $this->markdown($content);
                } else {
                    throw new RuntimeException('Una ficha Markdown no tiene un identificador de Notion válido.');
                }
            }
            $candidates = [];
            foreach ($csvFiles as $name => $content) {
                $handle = fopen('php://memory', 'r+');
                fwrite($handle, $content);
                rewind($handle);
                $headers = fgetcsv($handle, escape: '');
                if ($headers === false || ! in_array('Nombre', $headers) || ! in_array('Estado', $headers)) {
                    fclose($handle);

                    continue;
                }
                $rows = [];
                while (($values = fgetcsv($handle, escape: '')) !== false) {
                    if ($values === [null]) {
                        continue;
                    }
                    if (count($values) !== count($headers) || count($rows) >= 5000) {
                        fclose($handle);
                        throw new RuntimeException('El CSV contiene una fila inválida o demasiados registros.');
                    }
                    $rows[] = array_combine($headers, $values);
                }
                fclose($handle);
                $candidates[] = ['rows' => $rows, 'all' => str_ends_with($name, '_all.csv'), 'columns' => count($headers)];
            }
            usort($candidates, fn ($a, $b) => [$b['columns'], $b['all']] <=> [$a['columns'], $a['all']]);
            if ($candidates === [] || (count($candidates) > 1 && $candidates[0]['all'] === $candidates[1]['all'] && $candidates[0]['columns'] === $candidates[1]['columns'])) {
                throw new RuntimeException('No se pudo identificar un único CSV principal.');
            }

            return [$candidates[0]['rows'], $markdowns, hash_file('sha256', $path)];
        } finally {
            $zip->close();
        }
    }

    private function stateFingerprint(): string
    {
        $state = [Lead::orderBy('id')->get()->map(fn (Lead $lead) => $lead->getAttributes())->all(),
            LeadSource::orderBy('id')->get()->map(fn (LeadSource $source) => $source->getAttributes())->all(),
            DB::table('lead_contacts')->orderBy('id')->get()->all(), DB::table('client_services')->orderBy('id')->get()->all()];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function markdown(string $content): array
    {
        $fields = [];
        $title = '';
        foreach (explode("\n", str_replace("\r", '', $content)) as $line) {
            if ($title === '' && preg_match('/^# (.+)$/u', $line, $match)) {
                $title = trim($match[1]);

                continue;
            }
            if (preg_match('/^(Añadido|Empresa|Teléfono|Email):\s*(.*)$/u', $line, $match)) {
                $fields[$match[1]] = trim($match[2]);
            }
        }

        return ['title' => $title, 'fields' => $fields, 'content' => $content];
    }

    private function markdownCandidates(array $row, array $markdowns): array
    {
        $matches = [];
        foreach ($markdowns as $id => $markdown) {
            if ($this->identity($row['Nombre'] ?? '') !== $this->identity($markdown['title'])) {
                continue;
            }
            $matchesRow = true;
            foreach (['Empresa', 'Añadido', 'Teléfono', 'Email'] as $field) {
                if (filled($row[$field] ?? null) && (! isset($markdown['fields'][$field]) || $this->identity($row[$field]) !== $this->identity($markdown['fields'][$field]))) {
                    $matchesRow = false;
                }
            }
            if ($matchesRow) {
                $matches[] = $id;
            }
        }

        return $matches;
    }

    private function notionId(string $value): ?string
    {
        $value = str_replace('-', '', $value);

        return preg_match('/([a-f0-9]{32})(?:[?#].*)?$/i', $value, $match) ? strtolower($match[1]) : null;
    }

    private function identity(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }

    private function sharedExportIdentity(array $left, array $right): bool
    {
        if ($this->identity($left['name']) === $this->identity($right['name'])) {
            return true;
        }
        foreach (['email', 'phone', 'phone_original'] as $field) {
            if ($field !== 'email' && ! $this->normalizer->hasComparablePhone($left[$field] ?? null)) {
                continue;
            }
            if (filled($left[$field] ?? null) && $left[$field] === ($right[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function decision(array $decision): array
    {
        $validator = Validator::make($decision, ['action' => ['sometimes', Rule::in(['import', 'new', 'match', 'skip'])],
            'lead_id' => 'sometimes|integer|min:1', 'match_key' => 'sometimes|string|max:150', 'markdown_id' => 'sometimes|regex:/^[a-f0-9]{32}$/D',
            'is_customer' => 'sometimes|boolean', 'is_collaborator' => 'sometimes|boolean',
            'status' => ['sometimes', Rule::in(['NEW', 'LOST', 'PAUSED'])], 'entity_type' => 'sometimes|in:person,organization', 'omit_invalid' => 'sometimes|boolean']);
        if ($validator->fails() || array_diff(array_keys($decision), ['action', 'lead_id', 'match_key', 'markdown_id', 'is_customer', 'is_collaborator', 'status', 'entity_type', 'omit_invalid']) !== []) {
            throw new RuntimeException('El archivo de decisiones contiene una instrucción inválida.');
        }

        if (($decision['action'] ?? '') === 'match' && (isset($decision['lead_id']) === isset($decision['match_key']))) {
            throw new RuntimeException('Una coincidencia requiere exactamente lead_id o match_key.');
        }
        if (isset($decision['match_key']) && ($decision['action'] ?? '') !== 'match') {
            throw new RuntimeException('match_key solo se admite con la acción match.');
        }

        return $validator->validated();
    }
}
