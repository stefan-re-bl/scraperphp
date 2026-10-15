<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Exclusion;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExclusionService
{
    public function __construct(private readonly ContactNormalizer $normalizer) {}

    public function matches(array $data): bool
    {
        foreach (['email', 'domain', 'phone', 'name', 'source_identifier'] as $type) {
            if (filled($data[$type] ?? null) && Exclusion::where('type', $type)->where('value', $this->normalizer->exclusion($type, $data[$type]))->exists()) {
                return true;
            }
        }

        return false;
    }

    public function assertContactable(Lead $lead, ?Campaign $campaign = null): void
    {
        if ($lead->duplicate_status === 'possible_duplicate') {
            throw ValidationException::withMessages(['lead_id' => 'Resuelve la posible duplicación antes de contactar este prospecto.']);
        }
        app(CampaignMembership::class)->assertContactEnabled($lead, $campaign);
        if ($lead->excluded_at || $this->matches($lead->getAttributes())) {
            throw ValidationException::withMessages(['lead_id' => 'Este prospecto está excluido.']);
        }
        if ($campaign && ($campaign->kill_switch || $campaign->status === 'paused')) {
            throw ValidationException::withMessages(['campaign_id' => 'La campaña está pausada.']);
        }
    }

    public function exclude(Lead $lead, string $reason, bool $register = true): void
    {
        DB::transaction(function () use ($lead, $reason, $register) {
            if ($register) {
                foreach (['email', 'domain', 'phone', 'source_identifier'] as $type) {
                    if ($lead->$type) {
                        $this->store($type, $lead->$type, $reason);
                    }
                }
            }
            $lead->contactAttempts()->whereIn('status', ['planned', 'paused'])->update(['status' => 'cancelled', 'result' => 'excluded']);
            app(LeadPipeline::class)->move($lead, 'PAUSED', $reason);
            $lead->update(['excluded_at' => now(), 'exclusion_reason' => $reason, 'next_action_at' => null]);
            DB::table('campaign_lead')->where('lead_id', $lead->id)->update(['status' => 'PAUSED', 'next_action_at' => null, 'updated_at' => now()]);
            $lead->contactAttempts()->where('status', 'planned')->update(['status' => 'cancelled', 'result' => 'excluded']);
        });
    }

    public function store(string $type, string $value, ?string $reason): Exclusion
    {
        $value = $this->normalizer->exclusion($type, $value);
        $exclusion = Exclusion::updateOrCreate(['type' => $type, 'value' => $value], ['reason' => $reason]);
        Lead::whereNotNull($type)->chunkById(200, function ($leads) use ($type, $value, $reason) {
            foreach ($leads as $lead) {
                if ($this->normalizer->exclusion($type, (string) $lead->$type) === $value) {
                    $this->exclude($lead, $reason ?: 'Exclusión registrada', false);
                }
            }
        });

        return $exclusion;
    }
}
