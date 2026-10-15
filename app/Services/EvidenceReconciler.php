<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Signal;
use Illuminate\Support\Facades\DB;

class EvidenceReconciler
{
    /** Stable logical observations retain human review; superseded data stays archived. */
    public function apply(Lead $lead, array $observations, array $managedSources): void
    {
        DB::transaction(function () use ($lead, $observations, $managedSources): void {
            $existing = $lead->signalRows()->whereIn('source', $managedSources)->where('source', '!=', 'manual')->lockForUpdate()->get();
            $keys = [];
            foreach ($observations as $observation) {
                if ($observation['source'] === 'manual') {
                    continue;
                }
                $reference = $observation['source_reference'] ?? $observation['source'];
                $key = hash('sha256', $observation['source'].'|'.$reference.'|'.$observation['type']);
                $keys[] = $key;
                $attributes = $observation + ['detected_at' => now(), 'rule_version' => config('enrichment.rule_version', 'enrichment-v1'),
                    'expires_at' => now()->addDays(max(1, (int) config('enrichment.evidence_ttl_days', 30))), 'metadata' => [], 'evidence_url' => null];
                $attributes['evidence_key'] = $key;
                $attributes['is_current'] = true;
                $attributes['confidence'] = max(0, min(1, (float) ($attributes['confidence'] ?? 0.5)));
                $attributes['review_status'] = ($attributes['evidence_kind'] ?? 'unknown') === 'observed' ? 'confirmed' : 'pending';
                $byKey = $existing->where('evidence_key', $key);
                $candidates = $byKey->where('is_current', true);
                if ($candidates->isEmpty()) {
                    $candidates = $byKey->take(1);
                }
                if ($candidates->isEmpty()) {
                    $candidates = $existing->filter(fn (Signal $legacy): bool => $legacy->evidence_key === null
                        && $legacy->source === $observation['source'] && $legacy->type === $observation['type']
                        && ($legacy->evidence_url === null || rtrim($legacy->evidence_url, '/') === rtrim($reference, '/')))->take(1);
                }
                $row = $candidates->first();
                if ($row) {
                    $unchanged = $this->sameObservation($row, $attributes);
                    $coherent = $candidates->every(fn (Signal $candidate): bool => $this->sameObservation($candidate, $attributes))
                        && $candidates->pluck('review_status')->unique()->count() === 1;
                    $attributes['review_status'] = $unchanged && $coherent ? $row->review_status : 'pending';
                    if (! $unchanged) {
                        $history = $row->metadata['observation_history'] ?? [];
                        $history[] = $row->only(['value', 'evidence', 'source_reference', 'evidence_kind', 'extraction_method', 'confidence', 'rule_version', 'detected_at', 'review_status']);
                        $attributes['metadata']['observation_history'] = array_slice($history, -5);
                    } elseif (isset($row->metadata['observation_history'])) {
                        $attributes['metadata']['observation_history'] = $row->metadata['observation_history'];
                    }
                    $row->update($attributes);
                    foreach ($candidates as $duplicate) {
                        if ($duplicate->id !== $row->id) {
                            $duplicate->update(['is_current' => false]);
                        }
                    }
                } else {
                    $lead->signalRows()->create($attributes);
                }
            }
            foreach ($existing as $row) {
                if (! in_array($row->fresh()->evidence_key, $keys, true)) {
                    $row->update(['is_current' => false]);
                }
            }
        });
    }

    private function sameObservation(Signal $row, array $attributes): bool
    {
        foreach (['value', 'evidence', 'source_reference', 'evidence_kind', 'extraction_method', 'rule_version'] as $field) {
            if ($row->{$field} !== ($attributes[$field] ?? null)) {
                return false;
            }
        }

        return ($row->metadata['normalized_values'] ?? null) === ($attributes['metadata']['normalized_values'] ?? null);
    }
}
