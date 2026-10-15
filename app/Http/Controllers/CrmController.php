<?php

namespace App\Http\Controllers;

use App\Models\ClientService;
use App\Models\Lead;
use App\Models\LeadContact;
use App\Models\Proposal;
use App\Services\ContactNormalizer;
use App\Services\LeadContactSynchronizer;
use App\Services\NotionValueParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CrmController extends Controller
{
    public function services(Request $request): mixed
    {
        $filters = $request->validate(['q' => 'nullable|string|max:255', 'status' => ['nullable', Rule::in(array_keys(ClientService::STATUSES))],
            'type' => ['nullable', Rule::in(array_keys(ClientService::TYPES))], 'renewals' => 'nullable|boolean', 'until' => 'nullable|date_format:Y-m-d']);
        $query = ClientService::with(['lead', 'proposal'])->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($nested) => $nested->where('name', 'like', '%'.$term.'%')->orWhereHas('lead', fn ($lead) => $lead->where('name', 'like', '%'.$term.'%'))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        if ($request->boolean('renewals')) {
            $query->whereNotNull('renews_on')->whereNotIn('status', ['completed', 'cancelled'])->whereDate('renews_on', '<=', $filters['until'] ?? now()->addDays(30)->toDateString());
        }

        return view('sales.services', ['services' => $query->orderByRaw('renews_on is null')->orderBy('renews_on')->orderByDesc('id')->paginate(25)->withQueryString(), 'filters' => $filters]);
    }

    public function storeContact(Request $request, Lead $lead): mixed
    {
        $this->saveContact($request, $lead);

        return back()->with('status', 'Contacto añadido.');
    }

    public function updateContact(Request $request, Lead $lead, LeadContact $contact): mixed
    {
        abort_unless($contact->lead_id === $lead->id, 404);
        $this->saveContact($request, $lead, $contact);

        return back()->with('status', 'Contacto actualizado.');
    }

    private function saveContact(Request $request, Lead $lead, ?LeadContact $contact = null): void
    {
        if ($lead->entity_type !== 'organization') {
            throw ValidationException::withMessages(['contact' => 'Una persona utiliza sus propios datos de contacto.']);
        }
        $data = $request->validate(['name' => 'required|string|max:255', 'role' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255',
            'phone_original' => 'nullable|string|max:80', 'is_primary' => 'sometimes|boolean']);
        $data['phone'] = app(NotionValueParser::class)->phone($data['phone_original'] ?? null);
        $data['email'] = app(ContactNormalizer::class)->email($data['email'] ?? null);
        $data['is_primary'] = $request->boolean('is_primary');
        DB::transaction(function () use ($lead, $contact, $data): void {
            Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($data['is_primary']) {
                $lead->contacts()->update(['is_primary' => false]);
            }
            if ($contact) {
                $contact->update($data);
            } else {
                $lead->contacts()->create($data);
            }
            if ($data['is_primary']) {
                app(LeadContactSynchronizer::class)->updateLead($lead, ['contact_person' => $data['name'], 'contact_role' => $data['role'] ?? null, 'phone' => $data['phone'], 'phone_original' => $data['phone_original'] ?? null, 'email' => $data['email']]);
            }
        });
    }

    public function deleteContact(Lead $lead, LeadContact $contact): mixed
    {
        abort_unless($contact->lead_id === $lead->id, 404);
        $contact->delete();

        return back()->with('status', 'Contacto eliminado. Los datos principales se conservan en la ficha.');
    }

    public function storeService(Request $request, Lead $lead): mixed
    {
        $lead->services()->create($this->serviceData($request, $lead));

        return back()->with('status', 'Servicio añadido.');
    }

    public function updateService(Request $request, Lead $lead, ClientService $service): mixed
    {
        abort_unless($service->lead_id === $lead->id, 404);
        $service->update($this->serviceData($request, $lead));

        return back()->with('status', 'Servicio actualizado.');
    }

    private function serviceData(Request $request, Lead $lead): array
    {
        if (! $lead->is_customer) {
            throw ValidationException::withMessages(['service' => 'Clasifica este registro como cliente antes de añadir servicios.']);
        }
        $data = $request->validate(['name' => 'required|string|max:255', 'type' => ['required', Rule::in(array_keys(ClientService::TYPES))],
            'status' => ['required', Rule::in(array_keys(ClientService::STATUSES))], 'amount' => ['nullable', 'string', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'],
            'currency' => 'nullable|required_with:amount|alpha:ascii|size:3', 'starts_on' => 'nullable|date_format:Y-m-d',
            'ends_on' => $request->filled('starts_on') ? 'nullable|date_format:Y-m-d|after_or_equal:starts_on' : 'nullable|date_format:Y-m-d', 'renews_on' => 'nullable|date_format:Y-m-d',
            'reference_url' => 'nullable|url:http,https|max:2048', 'notes' => 'nullable|string|max:20000', 'proposal_id' => 'nullable|integer|exists:proposals,id']);
        if (filled($data['proposal_id'] ?? null) && ! Proposal::whereKey($data['proposal_id'])->where('lead_id', $lead->id)->exists()) {
            throw ValidationException::withMessages(['proposal_id' => 'La propuesta debe pertenecer al mismo cliente.']);
        }
        $data['amount_minor'] = app(NotionValueParser::class)->minorFromDecimal(isset($data['amount']) ? (string) $data['amount'] : null);
        $data['currency'] = filled($data['currency'] ?? null) ? strtoupper($data['currency']) : null;
        unset($data['amount']);

        return $data;
    }

    public function deleteService(Lead $lead, ClientService $service): mixed
    {
        abort_unless($service->lead_id === $lead->id, 404);
        $service->delete();

        return back()->with('status', 'Servicio eliminado.');
    }
}
