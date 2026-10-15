<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/** The main fields on Lead are the source of truth; a primary contact is its projection. */
class LeadContactSynchronizer
{
    public function updateLead(Lead $lead, array $data): Lead
    {
        return DB::transaction(function () use ($lead, $data) {
            $lead = Lead::lockForUpdate()->findOrFail($lead->id);
            $lead->update($data);
            $primary = $lead->contacts()->where('is_primary', true)->first();
            if ($primary) {
                $primary->update(['name' => $lead->contact_person ?: $primary->name, 'role' => $lead->contact_role,
                    'email' => $lead->email, 'phone' => $lead->phone, 'phone_original' => $lead->phone_original ?? $lead->phone]);
            }

            return $lead;
        });
    }
}
