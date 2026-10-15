<section><h2>Relación comercial</h2><div class="grid">
<div><span class="muted">Identidad</span><p>{{ $lead->entity_type === 'person' ? 'Persona' : 'Organización' }} · {{ $lead->relationshipLabel() }}</p></div>
<div><span class="muted">Prioridad manual</span><p>{{ ['high'=>'Alta', 'medium'=>'Media', 'low'=>'Baja'][$lead->manual_priority] ?? 'Sin definir' }}</p></div>
<div><span class="muted">Valor estimado de oportunidad</span><p>{{ $lead->estimated_value !== null ? $lead->estimated_value.' '.$lead->estimated_currency : 'Sin definir' }}</p></div>
</div>
@if($lead->classification_pending)<p class="panel-note">Clasificación pendiente. Revisa la relación comercial antes de continuar la prospección.</p>@endif
@if($lead->phone_original && $lead->phone_original !== $lead->phone)<p class="form-help">Teléfono original: {{ $lead->phone_original }}</p>@endif
@if($lead->origin_created_at)<p class="form-help">Fecha de origen: @dateDisplay($lead->origin_created_at)</p>@endif
@if($lead->imported_last_contact_at)<p class="form-help">Último contacto declarado en la importación: @dateDisplay($lead->imported_last_contact_at)</p>@endif
@if($lead->delivery_due_at)<p class="form-help">Próxima entrega: @dateDisplay($lead->delivery_due_at)</p>@endif
@foreach($lead->sources->where('source', 'notion') as $source)<p class="form-help">Notion: {{ $source->source_identifier ?? $source->import_key }} · estado de origen: {{ $source->metadata['original_status'] ?? 'Sin definir' }}</p>@endforeach
</section>
@if($lead->entity_type === 'organization')
<section><div class="section-head"><h2>Contactos asociados</h2><button type="button" class="light" data-drawer-open="contact-create">Añadir contacto</button></div>
@forelse($lead->contacts as $contact)
<div class="panel-note"><strong>{{ $contact->name }}</strong> {{ $contact->is_primary ? '· Principal' : '' }}<p>{{ $contact->role }} · {{ $contact->email }} · {{ $contact->phone_original }}</p>
<div class="actions"><button type="button" class="light" data-drawer-open="contact-edit-{{ $contact->id }}">Editar</button><form method="post" action="{{ route('leads.contacts.delete', [$lead, $contact]) }}" data-confirm="¿Eliminar este contacto asociado?">@csrf @method('DELETE')<button class="light">Eliminar</button></form></div></div>
@empty<p class="muted">Los datos principales de la ficha se conservan. Puedes añadir otros contactos de la organización.</p>@endforelse
</section>
@endif
<section><div class="section-head"><h2>Servicios contratados</h2>@if($lead->is_customer)<button type="button" class="light" data-drawer-open="service-create">Añadir servicio</button>@endif</div>
@forelse($lead->services as $service)
<div class="panel-note"><strong>{{ $service->name }}</strong><p>{{ App\Models\ClientService::TYPES[$service->type] }} · {{ App\Models\ClientService::STATUSES[$service->status] }} · {{ $service->amount !== null ? $service->amount.' '.$service->currency : 'Importe sin definir' }}</p>
@if($service->starts_on || $service->ends_on)<p>Inicio: {{ $service->starts_on?->format('d/m/Y') ?? 'Sin definir' }} · Finalización: {{ $service->ends_on?->format('d/m/Y') ?? 'Sin definir' }}</p>@endif
@if($service->renews_on)<p>Renovación: {{ $service->renews_on->format('d/m/Y') }}</p>@endif
@if($service->proposal)<p>Propuesta: {{ $service->proposal->service }} · versión {{ $service->proposal->version }}</p>@endif
@if($service->reference_url)<p><a href="{{ $service->reference_url }}" target="_blank" rel="noopener noreferrer">Referencia del servicio</a></p>@endif
@if($service->notes)<p style="white-space:pre-wrap">{{ $service->notes }}</p>@endif
<div class="actions"><button type="button" class="light" data-drawer-open="service-edit-{{ $service->id }}">Editar</button><form method="post" action="{{ route('leads.services.delete', [$lead, $service]) }}" data-confirm="¿Eliminar este servicio y su registro? Para conservarlo, cambia su estado a Cancelado.">@csrf @method('DELETE')<button class="light">Eliminar</button></form></div></div>
@empty<p class="muted">Sin servicios registrados.@if(!$lead->is_customer) Clasifica este registro como cliente para añadir servicios.@endif</p>@endforelse
<a href="{{ route('services', ['renewals'=>1]) }}">Consultar próximas renovaciones</a>
</section>
