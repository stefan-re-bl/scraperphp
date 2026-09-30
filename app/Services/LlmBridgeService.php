<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LlmBridgeRun;
use App\Models\LlmSuggestion;
use Illuminate\Support\Facades\Validator;

class LlmBridgeService
{
    public function createPrompt(string $useCase, array $leadIds): LlmBridgeRun
    {
        $leads = Lead::whereIn('id', $leadIds)->get();
        $context = $leads->map(fn (Lead $lead) => [
            'lead_id' => $lead->id,
            'name' => $lead->name,
            'category' => $lead->category,
            'location' => $lead->location,
            'website' => $lead->website_url,
            'signals' => $lead->signals,
            'score' => $lead->score_total,
            'status' => $lead->status,
        ])->values();

        $prompt = "Analiza estos leads para el caso de uso: {$useCase}.\n";
        $prompt .= "No inventes datos. Usa solo evidencia del JSON.\n";
        $prompt .= 'Devuelve JSON con esta forma: ';
        $prompt .= '{"suggestions":[{"lead_id":1,"type":"pain_hypothesis","payload":{"summary":"...","evidence":"..."}}]}';
        $prompt .= "\nLeads:\n".$context->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return LlmBridgeRun::create([
            'use_case' => $useCase,
            'status' => 'prompt_ready',
            'prompt' => $prompt,
            'lead_ids' => $leadIds,
        ]);
    }

    public function importJson(LlmBridgeRun $run, string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $run->update(['status' => 'invalid', 'imported_json' => $json, 'validation_errors' => 'JSON inválido.']);

            return ['ok' => false, 'errors' => ['JSON inválido.']];
        }

        $validator = Validator::make($decoded, [
            'suggestions' => ['required', 'array', 'max:200'],
            'suggestions.*.lead_id' => ['required', 'integer', 'exists:leads,id'],
            'suggestions.*.type' => ['required', 'string', 'max:80'],
            'suggestions.*.payload' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->all();
            $run->update(['status' => 'invalid', 'imported_json' => $json, 'validation_errors' => implode("\n", $errors)]);

            return ['ok' => false, 'errors' => $errors];
        }

        foreach ($decoded['suggestions'] as $suggestion) {
            if (! in_array($suggestion['lead_id'], $run->lead_ids ?? [], true)) {
                continue;
            }

            LlmSuggestion::updateOrCreate([
                'llm_bridge_run_id' => $run->id,
                'lead_id' => $suggestion['lead_id'],
                'type' => $suggestion['type'],
            ], [
                'payload' => $suggestion['payload'],
                'status' => 'AI_SUGGESTION',
            ]);
        }

        $run->update(['status' => 'imported', 'imported_json' => $json, 'validation_errors' => null]);

        return ['ok' => true, 'errors' => []];
    }
}
