<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadMergeService
{
    public const FIELDS = ['name', 'entity_type', 'status', 'next_action_at', 'category', 'segment', 'location', 'city', 'country', 'language', 'website_url', 'domain', 'manual_priority', 'estimated_value_minor', 'estimated_currency', 'origin_created_at', 'imported_last_contact_at', 'delivery_due_at', 'suggested_offer_id'];

    private const RELATIONS = ['lead_sources', 'lead_contacts', 'client_services', 'contact_attempts', 'replies', 'proposals', 'lead_status_histories', 'signals', 'score_components', 'llm_suggestions', 'campaign_lead', 'lead_experiment_variant', 'lead_offer_scores', 'enrichment_source_states'];

    public function preview(Lead $target, Lead $source): array
    {
        if ($target->id === $source->id) {
            throw ValidationException::withMessages(['other' => 'Selecciona dos fichas diferentes.']);
        }
        $target->refresh();
        $source->refresh();
        $snapshot = $this->snapshot($target->id, $source->id);
        $fields = [];
        $result = $target->getAttributes();
        foreach (self::FIELDS as $field) {
            $left = $target->getRawOriginal($field);
            $right = $source->getRawOriginal($field);
            if (blank($left) && filled($right)) {
                $result[$field] = $right;
            } elseif (filled($left) && filled($right) && (string) $left !== (string) $right) {
                $fields[$field] = ['target' => $left, 'source' => $right];
            }
        }
        $campaigns = collect($snapshot['campaign_lead'])->groupBy('campaign_id')->filter(fn ($rows) => $rows->count() > 1)->map(fn ($rows) => [
            'target' => $rows->firstWhere('lead_id', $target->id), 'source' => $rows->firstWhere('lead_id', $source->id),
        ])->all();
        $sequences = collect($snapshot['contact_attempts'])->where('status', 'planned')->groupBy('campaign_id')->filter(fn ($rows) => $rows->pluck('lead_id')->unique()->count() > 1)->map(fn ($rows) => $rows->all())->all();
        $proposals = [];
        foreach ($snapshot['proposals'] as $row) {
            if ($row['lead_id'] === $source->id && $row['group_key'] && collect($snapshot['proposals'])->contains(fn ($other) => $other['lead_id'] === $target->id && $other['group_key'] === $row['group_key'] && $other['version'] === $row['version'])) {
                $proposals[$row['id']] = $row;
            }
        }
        $assignments = collect($snapshot['lead_experiment_variant'])->map(function ($row) {
            $row['experiment_id'] = DB::table('experiment_variants')->where('id', $row['experiment_variant_id'])->value('experiment_id');

            return $row;
        })->groupBy('experiment_id')->filter(fn ($rows) => $rows->pluck('experiment_variant_id')->unique()->count() > 1)->map(fn ($rows) => $rows->all())->all();

        return ['target_id' => $target->id, 'source_id' => $source->id, 'fields' => $fields, 'result' => $result,
            'campaigns' => $campaigns, 'sequences' => $sequences, 'proposals' => $proposals, 'assignments' => $assignments,
            'contacts' => $snapshot['lead_contacts'], 'counts' => collect(self::RELATIONS)->mapWithKeys(fn ($table) => [$table => count($snapshot[$table])])->all(),
            'snapshot' => $snapshot, 'plan_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    public function merge(int $targetId, int $sourceId, string $approvedHash, array $decisions): Lead
    {
        $previous = DB::table('lead_merges')->where('source_lead_id', $sourceId)->first();
        if ($previous) {
            if ((int) $previous->target_lead_id !== $targetId || ! hash_equals($previous->plan_sha256, $approvedHash)) {
                throw ValidationException::withMessages(['confirmation' => 'La ficha ya se consolidó con otra revisión.']);
            }

            return Lead::findOrFail($targetId);
        }
        $plan = $this->preview(Lead::findOrFail($targetId), Lead::findOrFail($sourceId));
        $this->assertApproved($plan, $approvedHash, $decisions);
        $this->assertIdle($targetId, $sourceId);
        $backup = app(BackupService::class)->create();
        $backupHash = app(BackupService::class)->verify($backup)['sha256'];

        return DB::transaction(function () use ($targetId, $sourceId, $approvedHash, $decisions, $backup, $backupHash) {
            $target = Lead::lockForUpdate()->findOrFail($targetId);
            $source = Lead::lockForUpdate()->findOrFail($sourceId);
            DB::table('leads')->where('id', $targetId)->update(['updated_at' => $target->getRawOriginal('updated_at')]);
            $plan = $this->preview($target, $source);
            $this->assertApproved($plan, $approvedHash, $decisions);
            $this->assertIdle($targetId, $sourceId);
            $data = array_intersect_key($plan['result'], array_flip(self::FIELDS));
            foreach ($plan['fields'] as $field => $values) {
                $data[$field] = $values[$decisions['fields'][$field]];
            }
            if ($data['entity_type'] === 'person' && $plan['contacts'] !== []) {
                throw ValidationException::withMessages(['fields.entity_type' => 'Los contactos asociados requieren conservar una organización.']);
            }
            $data += ['is_customer' => $target->is_customer || $source->is_customer, 'is_collaborator' => $target->is_collaborator || $source->is_collaborator,
                'classification_pending' => $target->classification_pending || $source->classification_pending,
                'tags' => array_values(array_unique(array_merge($target->tags ?? [], $source->tags ?? []))),
                'notes' => trim(($target->notes ?? '').($source->notes ? "\n\n[Consolidado desde ficha #{$sourceId}]\n".$source->notes : '')),
                'duplicate_status' => 'unique', 'duplicate_rule' => null, 'possible_duplicate_of_lead_id' => null];
            if ($source->excluded_at && ! $target->excluded_at) {
                $data['excluded_at'] = $source->excluded_at;
                $data['exclusion_reason'] = $source->exclusion_reason;
            }
            $target->fill($data)->save();
            if ($source->source_identifier && ! $source->sources()->where('source', $source->source)->where('source_identifier', $source->source_identifier)->exists()) {
                $target->sources()->create(['source' => $source->source, 'source_identifier' => $source->source_identifier, 'observed_at' => $source->acquired_at ?? now(), 'metadata' => ['merged_from_lead_id' => $sourceId]]);
            }
            $this->moveContacts($plan, $target, $source, $decisions);
            $this->moveCampaigns($targetId, $sourceId, $decisions);
            $this->moveProposals($plan, $sourceId, $decisions);
            $this->moveAssignments($plan, $targetId, $sourceId, $decisions);
            foreach (array_diff(self::RELATIONS, ['lead_contacts', 'campaign_lead', 'lead_experiment_variant', 'lead_offer_scores', 'enrichment_source_states']) as $table) {
                DB::table($table)->where('lead_id', $sourceId)->update(['lead_id' => $targetId]);
            }
            foreach ($plan['sequences'] as $campaignId => $rows) {
                $selected = $decisions['sequences'][$campaignId];
                $preserved = $selected === 'pause_all' ? [] : array_column(array_filter($rows, fn ($row) => $row['lead_id'] === (int) substr($selected, 5)), 'id');
                DB::table('contact_attempts')->whereIn('id', array_column($rows, 'id'))->when($preserved !== [], fn ($q) => $q->whereNotIn('id', $preserved))->update(['status' => 'paused', 'result' => 'merge_requires_review']);
            }
            $closed = DB::table('campaign_lead')->where('lead_id', $targetId)->whereNotIn('status', ['QUEUED', 'CONTACTED'])->pluck('campaign_id');
            DB::table('contact_attempts')->where('lead_id', $targetId)->where('status', 'planned')->whereIn('campaign_id', $closed)->update(['status' => 'paused', 'result' => 'merge_requires_review']);
            if ($target->excluded_at) {
                DB::table('contact_attempts')->where('lead_id', $targetId)->where('status', 'planned')->update(['status' => 'paused', 'result' => 'merge_excluded']);
            }
            DB::table('leads')->where('possible_duplicate_of_lead_id', $sourceId)->where('id', '!=', $targetId)->update(['possible_duplicate_of_lead_id' => $targetId]);
            foreach (DB::table('llm_bridge_runs')->get() as $run) {
                $ids = json_decode($run->lead_ids ?? '[]', true);
                if (in_array($sourceId, $ids, true)) {
                    DB::table('llm_bridge_runs')->where('id', $run->id)->update(['lead_ids' => json_encode(array_values(array_unique(array_map(fn ($id) => $id === $sourceId ? $targetId : $id, $ids))))]);
                }
            }
            foreach (DB::table('system_logs')->where('context->lead_id', $sourceId)->get() as $log) {
                $context = json_decode($log->context, true);
                $context['lead_id'] = $targetId;
                $context['merged_from_lead_id'] = $sourceId;
                DB::table('system_logs')->where('id', $log->id)->update(['context' => json_encode($context, JSON_THROW_ON_ERROR)]);
            }
            foreach (DB::table('saved_lead_views')->get() as $view) {
                $filters = json_decode($view->filters, true);
                if (isset($filters['ids'])) {
                    $ids = is_array($filters['ids']) ? $filters['ids'] : explode(',', (string) $filters['ids']);
                    if (in_array($sourceId, array_map('intval', $ids), true)) {
                        $filters['ids'] = array_values(array_unique(array_map(fn ($id) => (int) $id === $sourceId ? $targetId : (int) $id, $ids)));
                        DB::table('saved_lead_views')->where('id', $view->id)->update(['filters' => json_encode($filters, JSON_THROW_ON_ERROR)]);
                    }
                }
            }
            DB::table('lead_merges')->where('target_lead_id', $sourceId)->update(['target_lead_id' => $targetId]);
            DB::table('lead_merges')->insert(['source_lead_id' => $sourceId, 'target_lead_id' => $targetId, 'plan_sha256' => $approvedHash,
                'original_records' => json_encode($plan['snapshot'], JSON_THROW_ON_ERROR), 'decisions' => json_encode($decisions, JSON_THROW_ON_ERROR),
                'backup_path' => $backup, 'backup_sha256' => $backupHash, 'created_at' => now(), 'updated_at' => now()]);
            $source->delete();
            app(LeadScorer::class)->score($target);
            $memberships = DB::table('campaign_lead')->where('lead_id', $targetId);
            $dueAt = $memberships->exists() ? $memberships->whereNotIn('status', ['PAUSED', 'WON', 'LOST'])->min('next_action_at') : $target->next_action_at?->toDateTimeString();
            app(CampaignMembership::class)->nextAction($target, null, $dueAt);
            if (DB::select('PRAGMA foreign_key_check') !== []) {
                throw new \RuntimeException('La consolidación no pasó la comprobación de relaciones.');
            }

            return $target->refresh();
        });
    }

    private function snapshot(int $targetId, int $sourceId): array
    {
        $snapshot = ['leads' => DB::table('leads')->whereIn('id', [$targetId, $sourceId])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
        foreach (self::RELATIONS as $table) {
            $snapshot[$table] = DB::table($table)->whereIn('lead_id', [$targetId, $sourceId])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $snapshot['duplicate_references'] = DB::table('leads')->whereIn('possible_duplicate_of_lead_id', [$targetId, $sourceId])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $snapshot['system_logs'] = DB::table('system_logs')->whereIn('context->lead_id', [$targetId, $sourceId])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        return $snapshot;
    }

    private function assertApproved(array $plan, string $hash, array $decisions): void
    {
        if (! hash_equals($plan['plan_sha256'], $hash)) {
            throw ValidationException::withMessages(['confirmation' => 'Las fichas cambiaron. Revisa de nuevo la comparación.']);
        }
        foreach (['fields', 'campaigns', 'sequences', 'proposals', 'assignments'] as $section) {
            foreach ($plan[$section] as $key => $value) {
                $allowed = match ($section) {
                    'fields', 'campaigns' => ['target', 'source'],
                    'sequences' => ['pause_all', ...array_map(fn ($row) => 'lead:'.$row['lead_id'], $value)],
                    'proposals' => ['new_group', 'next_version'],
                    'assignments' => array_map(fn ($row) => (string) $row['experiment_variant_id'], $value),
                };
                if (! in_array((string) ($decisions[$section][$key] ?? ''), $allowed, true)) {
                    throw ValidationException::withMessages([$section.'.'.$key => 'Resuelve cada diferencia antes de consolidar.']);
                }
            }
        }
        if (! in_array((string) ($decisions['primary_contact'] ?? ''), ['target', 'source', ...array_map(fn ($row) => (string) $row['id'], $plan['contacts'])], true)) {
            throw ValidationException::withMessages(['primary_contact' => 'Elige el contacto principal de una de las fichas.']);
        }
    }

    private function assertIdle(int $targetId, int $sourceId): void
    {
        if (DB::table('jobs')->whereNotNull('reserved_at')->exists() || DB::table('scrape_runs')->where('status', 'running')->exists()
            || DB::table('import_runs')->where('status', 'processing')->exists()
            || DB::table('contact_attempts')->whereIn('lead_id', [$targetId, $sourceId])->where('status', 'sending')->exists()) {
            throw ValidationException::withMessages(['confirmation' => 'Espera a que terminen las tareas antes de consolidar.']);
        }
    }

    private function moveContacts(array $plan, Lead $target, Lead $source, array $decisions): void
    {
        foreach ($source->contacts()->get() as $contact) {
            if ($contact->import_key && $target->contacts()->where('import_key', $contact->import_key)->exists()) {
                $contact->import_key = 'merge:'.$source->id.':'.$contact->id;
            }
            $contact->lead_id = $target->id;
            $contact->is_primary = false;
            $contact->save();
        }
        $side = $decisions['primary_contact'];
        if ($target->entity_type !== 'organization') {
            $chosen = collect($plan['snapshot']['leads'])->firstWhere('id', $side === 'source' ? $source->id : $target->id);
            $target->update(array_intersect_key($chosen, array_flip(['email', 'phone', 'phone_original', 'contact_person', 'contact_role'])));

            return;
        }
        $projected = [];
        foreach (['target' => collect($plan['snapshot']['leads'])->firstWhere('id', $target->id), 'source' => $source->getAttributes()] as $key => $row) {
            if (filled($row['email']) || filled($row['phone']) || filled($row['contact_person'])) {
                $projection = ['name' => $row['contact_person'] ?: $row['name'], 'email' => $row['email'], 'phone' => $row['phone'], 'phone_original' => $row['phone_original'], 'role' => $row['contact_role']];
                $importKey = 'merge-projection:'.$row['id'].':'.hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR));
                $existing = $target->contacts()->where('import_key', $importKey)->first();
                if ($existing && $existing->only(array_keys($projection)) !== $projection) {
                    $importKey .= ':'.Str::uuid()->toString();
                }
                $projected[$key] = $target->contacts()->firstOrCreate(['import_key' => $importKey], $projection + ['is_primary' => false]);
            }
        }
        $primary = in_array($side, ['target', 'source'], true) ? ($projected[$side] ?? null) : $target->contacts()->find($side);
        $target->contacts()->update(['is_primary' => false]);
        if ($primary) {
            $primary->update(['is_primary' => true]);
            $target->update(['contact_person' => $primary->name, 'contact_role' => $primary->role, 'email' => $primary->email, 'phone' => $primary->phone, 'phone_original' => $primary->phone_original]);
        } elseif (in_array($side, ['target', 'source'], true)) {
            $row = collect($plan['snapshot']['leads'])->firstWhere('id', $side === 'source' ? $source->id : $target->id);
            $target->update(array_intersect_key($row, array_flip(['email', 'phone', 'phone_original', 'contact_person', 'contact_role'])));
        }
    }

    private function moveCampaigns(int $targetId, int $sourceId, array $decisions): void
    {
        foreach (DB::table('campaign_lead')->where('lead_id', $sourceId)->get() as $membership) {
            $existing = DB::table('campaign_lead')->where('lead_id', $targetId)->where('campaign_id', $membership->campaign_id)->first();
            if (! $existing) {
                DB::table('campaign_lead')->where('id', $membership->id)->update(['lead_id' => $targetId]);
            } else {
                if ($decisions['campaigns'][$membership->campaign_id] === 'source') {
                    $data = (array) $membership;
                    unset($data['id'], $data['lead_id'], $data['campaign_id']);
                    DB::table('campaign_lead')->where('id', $existing->id)->update($data);
                }
                DB::table('campaign_lead')->where('id', $membership->id)->delete();
            }
        }
    }

    private function moveProposals(array $plan, int $sourceId, array $decisions): void
    {
        foreach ($plan['proposals'] as $id => $row) {
            $data = ['modifications' => trim(($row['modifications'] ?? '')."\nConsolidación: grupo {$row['group_key']}, versión original {$row['version']}, ficha #{$sourceId}.")];
            if ($decisions['proposals'][$id] === 'new_group') {
                $data['group_key'] = Str::uuid()->toString();
            } else {
                $data['version'] = DB::table('proposals')->where('group_key', $row['group_key'])->max('version') + 1;
            }
            DB::table('proposals')->where('id', $id)->update($data);
        }
    }

    private function moveAssignments(array $plan, int $targetId, int $sourceId, array $decisions): void
    {
        foreach ($plan['assignments'] as $experimentId => $rows) {
            DB::table('lead_experiment_variant')->whereIn('id', array_column($rows, 'id'))->where('experiment_variant_id', '!=', (int) $decisions['assignments'][$experimentId])->delete();
        }
        foreach (DB::table('lead_experiment_variant')->where('lead_id', $sourceId)->get() as $assignment) {
            $same = DB::table('lead_experiment_variant')->where('lead_id', $targetId)->where('experiment_variant_id', $assignment->experiment_variant_id)->exists();
            if ($same) {
                DB::table('lead_experiment_variant')->where('id', $assignment->id)->delete();
            } else {
                DB::table('lead_experiment_variant')->where('id', $assignment->id)->update(['lead_id' => $targetId]);
            }
        }
    }
}
