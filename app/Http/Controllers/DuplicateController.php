<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadMergeService;
use Illuminate\Http\Request;

class DuplicateController extends Controller
{
    public function review(Request $request, Lead $lead, LeadMergeService $merges): mixed
    {
        $data = $request->validate(['other' => 'nullable|integer|exists:leads,id', 'main' => 'nullable|integer', 'q' => 'nullable|string|max:255']);
        $otherId = $data['other'] ?? $lead->possible_duplicate_of_lead_id ?? $lead->possibleDuplicates()->value('id');
        $other = $otherId ? Lead::findOrFail($otherId) : null;
        $target = $other && ($data['main'] ?? $other->id) == $other->id ? $other : $lead;
        $source = $other && $target->id === $lead->id ? $other : $lead;
        $plan = $other ? $merges->preview($target, $source) : null;

        return view('sales.duplicates', ['lead' => $lead, 'other' => $other, 'target' => $target, 'source' => $source, 'plan' => $plan,
            'candidates' => Lead::where('id', '!=', $lead->id)->when($data['q'] ?? null, fn ($query, $term) => $query->where('name', 'like', '%'.$term.'%'))->orderBy('name')->limit(100)->get()]);
    }

    public function merge(Request $request, LeadMergeService $merges): mixed
    {
        $data = $request->validate(['target_id' => 'required|integer|min:1', 'source_id' => 'required|integer|min:1|different:target_id',
            'plan_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'confirmation' => 'required|in:CONSOLIDAR',
            'fields' => 'nullable|array', 'fields.*' => 'in:target,source', 'campaigns' => 'nullable|array', 'campaigns.*' => 'in:target,source',
            'sequences' => 'nullable|array', 'sequences.*' => 'string|max:40', 'proposals' => 'nullable|array', 'proposals.*' => 'in:new_group,next_version',
            'assignments' => 'nullable|array', 'assignments.*' => 'string|max:40', 'primary_contact' => 'required|string|max:40']);
        $target = $merges->merge((int) $data['target_id'], (int) $data['source_id'], $data['plan_sha256'], array_intersect_key($data, array_flip(['fields', 'campaigns', 'sequences', 'proposals', 'assignments', 'primary_contact'])));

        return redirect()->route('leads.show', $target)->with('status', 'Fichas consolidadas. Se conservaron el historial, las relaciones y un respaldo previo.');
    }
}
