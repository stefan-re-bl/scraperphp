<?php

namespace App\Services;

use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class ExperimentService
{
    public function assign(Lead $lead, Experiment $experiment): ?ExperimentVariant
    {
        $variants = $experiment->variants()->get();

        if ($variants->isEmpty()) {
            return null;
        }

        $existing = DB::table('lead_experiment_variant')
            ->where('lead_id', $lead->id)
            ->whereIn('experiment_variant_id', $variants->pluck('id'))
            ->first();

        if ($existing) {
            return ExperimentVariant::find($existing->experiment_variant_id);
        }

        $variant = $variants->sortBy(function ($variant) {
            return DB::table('lead_experiment_variant')
                ->where('experiment_variant_id', $variant->id)
                ->count();
        })->first();

        DB::table('lead_experiment_variant')->insert([
            'lead_id' => $lead->id,
            'experiment_variant_id' => $variant->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $variant;
    }
}
