<?php

namespace App\Services;

use App\Models\ContactAttempt;
use App\Models\Offer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScoreSnapshotService
{
    public function __construct(private readonly LeadScorer $scorer) {}

    /** Capture local knowledge before SMTP or at manual registration; never rebuild old scores. */
    public function capture(ContactAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $stored = ContactAttempt::lockForUpdate()->findOrFail($attempt->id);
            if ($stored->score_snapshot !== null || $stored->dry_run || ! in_array($stored->status, ['sending', 'sent'], true)
                || ($stored->status === 'sent' && $stored->performed_at === null)) {
                return;
            }
            $recordedAt = now()->startOfSecond();
            $asOf = $stored->performed_at?->copy() ?? $recordedAt->copy();
            $lead = $stored->lead;
            $opportunity = DB::table('campaign_lead')->where('lead_id', $lead->id)->where('campaign_id', $stored->campaign_id)->first();
            $offerId = $stored->offer_id ?? $opportunity?->offer_id ?? $stored->campaign?->offer_id;
            $offer = $offerId ? Offer::find($offerId) : null;
            $snapshot = ['known' => false, 'lead_id' => $lead->id, 'campaign_id' => $stored->campaign_id, 'opportunity_id' => $opportunity?->id,
                'offer_id' => $offerId, 'total' => null, 'dimensions' => [], 'confidence' => 0, 'coverage' => 0,
                'explanation' => [], 'evidence_ids' => [], 'evidence' => [], 'rule_version' => null, 'calculated_at' => null,
                'recorded_at' => $recordedAt->toIso8601String(), 'as_of' => $asOf->toIso8601String(), 'reason' => null];
            $evaluation = null;
            if (! $offer) {
                $snapshot['reason'] = 'La oportunidad no conserva una oferta evaluable.';
            } elseif ($asOf->greaterThan($recordedAt)) {
                $snapshot['reason'] = 'La fecha futura no representa un contacto realizado.';
            } elseif ($stored->updated_at->copy()->startOfSecond()->lessThan($recordedAt)) {
                $snapshot['reason'] = 'Contacto histórico sin puntuación capturada en el momento de su registro.';
            } elseif ($asOf->lessThan($recordedAt)) {
                $row = $this->scorer->forOffer($lead, $offer);
                if ($row && $row->calculated_at->lessThanOrEqualTo($asOf)) {
                    $evaluation = $row->only(['total', 'dimensions', 'confidence', 'coverage', 'explanation', 'rule_version', 'calculated_at']);
                } else {
                    $snapshot['reason'] = 'No existe un cálculo conservado anterior al contacto. No se reconstruyen puntuaciones históricas.';
                }
            } else {
                $evaluation = $this->scorer->evaluateOffer($lead, $offer);
            }
            if ($evaluation !== null) {
                $evidence = $evaluation['explanation']['evidence'] ?? [];
                $futureEvidence = collect($evidence)->contains(fn (array $item): bool => filled($item['detected_at'] ?? null) && Carbon::parse($item['detected_at'])->startOfSecond()->greaterThan($asOf));
                if ($futureEvidence || $evaluation['confidence'] <= 0 || $evaluation['coverage'] <= 0) {
                    $snapshot['reason'] = $futureEvidence ? 'La evidencia disponible se observó después del contacto.' : 'Evidencia insuficiente para una puntuación conocida.';
                } else {
                    $explanation = $evaluation['explanation'];
                    unset($explanation['input_state'], $explanation['offer_state'], $explanation['signal_state'], $explanation['rule_state']);
                    $snapshot = array_replace($snapshot, ['known' => true, 'total' => $evaluation['total'], 'dimensions' => $evaluation['dimensions'],
                        'confidence' => $evaluation['confidence'], 'coverage' => $evaluation['coverage'], 'explanation' => $explanation,
                        'evidence_ids' => $explanation['evidence_ids'] ?? [], 'evidence' => $evidence, 'rule_version' => $evaluation['rule_version'],
                        'calculated_at' => Carbon::parse($evaluation['calculated_at'])->toIso8601String()]);
                }
            }
            $stored->update(['score_snapshot' => $snapshot]);
            $attempt->refresh();
        });
    }
}
