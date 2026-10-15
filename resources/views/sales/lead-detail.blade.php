@extends('layouts.app')
@section('content')
@php($leadOfferContext = app(App\Services\CampaignMembership::class)->offerContext($lead))
<div class="page-head"><div><span class="eyebrow"><a href="{{ route('leads') }}">Cartera</a> / Ficha del contacto</span></div><button type="button" class="light" data-drawer-open="lead-edit">@include('sales.partials.icon',['name'=>'edit']) Editar datos y notas</button></div>
<div class="lead-header"><span class="avatar">{{ mb_strtoupper(mb_substr($lead->name,0,1)) }}</span><div class="lead-header-content"><h1>{{ $lead->name }}</h1><p>{{ $lead->category ?: 'Sin categoría' }}@if($lead->city || $lead->location) · {{ $lead->city ?: $lead->location }} @endif @if($lead->country) · {{ $lead->country }} @endif</p><div class="lead-tags">@include('sales.partials.status-badge',['status'=>$lead->excluded_at ? 'excluded' : $lead->status])<span class="lead-relation-pill">{{ $lead->relationshipLabel() }}</span>@foreach($lead->tags ?? [] as $tag)<span class="badge">{{ $tag }}</span>@endforeach</div><div class="lead-contact-summary">@if($lead->contact_person)<span>@include('sales.partials.icon',['name'=>'people']) {{ $lead->contact_person }}@if($lead->contact_role) · {{ $lead->contact_role }} @endif</span>@endif @if($lead->email)<span>@include('sales.partials.icon',['name'=>'mail']) {{ $lead->email }}</span>@endif @if($lead->phone)<span>@include('sales.partials.icon',['name'=>'phone']) {{ $lead->phone }}</span>@endif</div></div><a class="button" href="{{ route('queue',['lead'=>$lead->id]) }}">Abrir cola enfocada @include('sales.partials.icon',['name'=>'arrow'])</a></div>
@if($lead->excluded_at)<div class="error"><strong>Este prospecto está excluido</strong><p>{{ $lead->exclusion_reason }}</p><p>Eliminá las coincidencias en <a href="{{ route('exclusions') }}">Exclusiones</a> antes de confirmar la revisión.</p><form method="post" action="{{ route('leads.restore-contactability',$lead) }}">@csrf<button class="danger">Confirmar revisión y habilitar</button></form></div>@endif
@if($lead->duplicate_status==='possible_duplicate')<section><div class="section-head"><h2>Posible duplicado</h2><a href="{{ route('leads.duplicates.review',$lead) }}" class="button light">Comparar y resolver</a><span class="badge warn">Necesita revisión</span></div><p class="muted">Hay una posible relación con @if($lead->possibleDuplicateOf)<a href="{{ route('leads.show',$lead->possibleDuplicateOf) }}">{{ $lead->possibleDuplicateOf->name }}</a>@else otro prospecto @endif. Revisá los registros antes de contactar.</p><form method="post" action="{{ route('leads.distinct',$lead) }}">@csrf<button class="secondary">Confirmar que son negocios distintos</button></form></section>@endif
<div class="tabs"><a href="#lead-activity" class="active">Actividad</a><a href="#lead-evidence">Evidencia</a><a href="#lead-opportunities">Oportunidades</a></div>
<div class="two-col lead-detail-grid"><div>
<section id="lead-activity"><div class="section-head"><div><h2>Historial de actividad</h2><p class="muted">Contactos, respuestas y avances en orden cronológico.</p></div><span class="badge">{{ $timeline->count() }} {{ $timeline->count()===1 ? 'evento' : 'eventos' }}</span></div><form method="get" action="{{ route('leads.show',$lead) }}"><label>Actividad de campaña<select name="history_campaign"><option value="">Toda la actividad</option>@foreach($lead->campaigns as $historyCampaign)<option value="{{ $historyCampaign->id }}" @selected(request('history_campaign')==$historyCampaign->id)>{{ $historyCampaign->name }}</option>@endforeach</select></label><button class="secondary">Filtrar historial</button></form><div class="timeline">@forelse($timeline as $event)<div class="timeline-item"><span class="timeline-symbol">@include('sales.partials.icon',['name'=>'clock'])</span><strong>{{ $event['title'] }}</strong><div class="muted">@dateDisplay($event['at'])</div><div>{{ $event['detail'] }}</div>@if(array_key_exists('score_snapshot', $event))<details><summary>Puntuación al contactar</summary>@if(($event['score_snapshot']['known'] ?? false) && isset($event['score_snapshot']['total']))<p>{{ $event['score_snapshot']['total'] }}/100 · versión {{ $event['score_snapshot']['rule_version'] ?? 'sin versión' }}</p><p class="muted">Estado conservado al registrar el contacto; no cambia al volver a puntuar.</p>@else<p class="muted">Desconocida. No se reconstruye con datos posteriores al contacto.</p>@endif</details>@endif</div>@empty @include('sales.partials.empty-state',['emptyIcon'=>'clock','emptyTitle'=>'Todavía no hay actividad','emptyText'=>'Los contactos, respuestas y cambios que registres aparecerán acá.']) @endforelse</div></section>
<section><div class="section-head"><h2>Datos del contacto</h2><button type="button" class="icon-button" data-drawer-open="lead-edit" aria-label="Editar identidad">@include('sales.partials.icon',['name'=>'edit'])</button></div><dl class="identity-list"><div><dt>Email</dt><dd>{{ $lead->email ?? 'Sin registrar' }}</dd></div><div><dt>Teléfono</dt><dd>{{ $lead->phone ?? 'Sin registrar' }}</dd></div><div><dt>Sitio web</dt><dd>@if($lead->website_url)<a href="{{ $lead->website_url }}" target="_blank" rel="noreferrer">{{ $lead->domain ?: $lead->website_url }}</a>@else Sin registrar @endif</dd></div><div><dt>Fuente</dt><dd>{{ ['manual'=>'Manual','google_maps'=>'Google Maps','csv_manual'=>'CSV','referral'=>'Referido','web'=>'Web'][$lead->source] ?? $lead->source }}</dd></div><div><dt>Dirección</dt><dd>{{ $lead->location ?: 'Sin registrar' }}</dd></div><div><dt>Persona de contacto</dt><dd>{{ $lead->contact_person ?: 'Sin registrar' }}</dd></div></dl><div class="contact-pills">@if($lead->email)<button type="button" class="light" data-copy="{{ $lead->email }}">@include('sales.partials.icon',['name'=>'copy']) Copiar email</button><a class="button light" href="mailto:{{ $lead->email }}">Abrir email</a>@endif @if($lead->phone)<button type="button" class="light" data-copy="{{ $lead->phone }}">Copiar teléfono</button><button type="button" class="light" data-drawer-open="whatsapp-compose" @disabled($lead->excluded_at)>@include('sales.partials.icon', ['name'=>'reply']) WhatsApp</button>@endif @if($lead->website_url)<a class="button light" href="{{ $lead->website_url }}" target="_blank" rel="noreferrer">Abrir sitio</a>@endif</div>@if($lead->notes)<div class="panel-note"><strong>Notas</strong><p style="margin:5px 0 0;white-space:pre-wrap">{{ $lead->notes }}</p></div>@endif</section>
<section id="lead-evidence"><div class="section-head"><div><h2>Evidencia comercial</h2><p class="muted">Separá lo observado de lo que todavía necesita revisión.</p></div></div>@forelse($lead->signalRows->filter(fn ($row) => $row->is_current || $row->source === 'manual') as $signal)<div @class(['card','evidence-card','pending'=>$signal->review_status==='pending','rejected'=>$signal->review_status==='rejected'])><div class="section-head" style="margin-bottom:8px"><strong>{{ App\Models\Signal::label($signal->type) }}</strong>@include('sales.partials.status-badge',['status'=>$signal->review_status])</div><p style="font-size:12px;margin:0">{{ $signal->evidence }}</p><div class="muted">{{ ['observed'=>'Hecho observado','inferred'=>'Inferencia respaldada','unknown'=>'Dato desconocido','contradiction'=>'Contradicción','conflict'=>'Conflicto de identidad'][$signal->evidence_kind] ?? 'Pendiente de clasificar' }} · {{ ['website'=>'Sitio oficial','google_maps'=>'Google Maps','osm'=>'OpenStreetMap','crm'=>'CRM','dns_http'=>'DNS y HTTP','dns'=>'DNS','http'=>'HTTP','manual'=>'Revisión humana','import'=>'Importación','system'=>'Sistema'][$signal->source] ?? $signal->source }} · @dateDisplay($signal->detected_at) @if($signal->expires_at && $signal->expires_at->isPast()) · Vigencia vencida @endif @if($signal->evidence_url) · <a href="{{ $signal->evidence_url }}" target="_blank" rel="noreferrer">Ver evidencia</a>@endif</div>@if($signal->source==='osm')<p class="form-help"><a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">© OpenStreetMap contributors · ODbL</a></p>@endif<form method="post" action="{{ route('leads.signals.review',[$lead,$signal]) }}">@csrf @method('PATCH')<select name="review_status" aria-label="Revisión de {{ App\Models\Signal::label($signal->type) }}"><option value="confirmed" @selected($signal->review_status==='confirmed')>Confirmar</option><option value="pending" @selected($signal->review_status==='pending')>Dejar pendiente</option><option value="rejected" @selected($signal->review_status==='rejected')>Descartar</option></select><button class="secondary">Guardar revisión</button></form></div>@empty @include('sales.partials.empty-state',['emptyIcon'=>'tag','emptyTitle'=>'No hay evidencia registrada','emptyText'=>'Agregá lo observado o solicitá una revisión del sitio desde las acciones de esta ficha.']) @endforelse</section>
<section id="lead-opportunities"><div class="section-head"><h2>Oportunidades por campaña</h2><span class="badge">{{ $lead->campaigns->count() }}</span></div>@forelse($lead->campaigns as $membership)<div class="card opportunity-card"><div><strong><a href="{{ route('campaigns.show',$membership) }}">{{ $membership->name }}</a></strong><div style="margin-top:7px">@include('sales.partials.status-badge',['status'=>$membership->pivot->status])</div></div>@if($lead->is_customer && !$membership->pivot->contact_enabled)<form method="post" action="{{ route('leads.campaigns.store',$lead) }}" data-confirm="¿Habilitar contacto para esta oportunidad del cliente?">@csrf<input type="hidden" name="campaign_id" value="{{ $membership->id }}"><button class="small secondary">Habilitar contacto de esta oportunidad</button></form>@endif<form method="post" action="{{ route('leads.status',$lead) }}">@csrf<input type="hidden" name="campaign_id" value="{{ $membership->id }}"><input type="hidden" name="status" value="QUEUED"><input type="hidden" name="reason" value="Cola desde ficha"><button class="small secondary">Enviar a cola</button><a class="button small light" href="{{ route('queue',['campaign'=>$membership->id,'lead'=>$lead->id]) }}">Abrir trabajo</a></form></div>@empty<p class="muted">Vinculá una campaña para organizar esta oportunidad.</p>@endforelse
<details style="margin-top:18px"><summary>Vincular otra campaña</summary><form method="post" action="{{ route('leads.campaigns.store',$lead) }}">@csrf<input type="hidden" name="lead_id" value="{{ $lead->id }}"><label>Campaña<select name="campaign_id" required><option value="">Seleccionar campaña</option>@foreach($campaigns->whereNotIn('id',$lead->campaigns->modelKeys()) as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select></label><button class="secondary">Vincular campaña</button></form></details></section>
</div><div>
<div class="next-action"><span class="eyebrow">Próxima acción</span>@if($lead->excluded_at)<strong>Revisar la exclusión</strong><p>Este contacto necesita una revisión antes de habilitarlo.</p>@elseif($lead->next_action_at)<strong>@dateDisplay($lead->next_action_at)</strong><p>El contacto aparecerá en la cola cuando corresponda.</p>@else<strong>Definí el próximo paso</strong><p>Podés registrar un contacto o revisar una oportunidad.</p>@endif</div>
@include('sales.partials.offer-scores')
<section><details><summary>Puntaje general de compatibilidad</summary><div class="section-head"><span class="metric" style="margin:0">{{ $lead->score_total }}</span><span class="badge">{{ $lead->score_total>=60 ? 'Scoring alto' : ($lead->score_total>=35 ? 'Scoring medio' : 'Scoring bajo') }}</span></div><div class="grid diagnosis-grid"><div class="kpi"><span class="muted">Datos</span><div style="font-weight:650;margin-top:4px">{{ $lead->data_quality_score }}</div></div><div class="kpi"><span class="muted">Contacto</span><div style="font-weight:650;margin-top:4px">{{ $lead->contactability_score }}</div></div><div class="kpi"><span class="muted">Encaje</span><div style="font-weight:650;margin-top:4px">{{ $lead->offer_fit_score }}</div></div></div><p class="form-help" style="margin-top:14px">Oferta sugerida: <strong>{{ $lead->suggestedOffer?->name ?? 'Sin oferta' }}</strong></p><p class="form-help">{{ $lead->offer_match_reason }}</p><details style="margin-top:16px"><summary>Ver componentes del scoring</summary><table class="compact-table" data-static-table><tr><th>Componente</th><th>Puntos</th></tr>@foreach($lead->scoreComponents as $component)<tr><td>{{ App\Services\LeadScorer::DEFAULTS[$component->component][2] ?? str_replace('_',' ',$component->component) }}<div class="muted">{{ $component->evidence }}</div></td><td>{{ $component->points }}</td></tr>@endforeach</table></details></details></section>
<div class="action-forms">
        <section><details><summary>@include('sales.partials.icon',['name'=>'tag']) Evidencia comercial</summary><form method="post" action="{{ route('leads.signals.store', $lead) }}">@csrf
        <label>Señal<select name="type"><option value="ads_active">Invierte en captación</option><option value="clear_offer">Oferta clara</option><option value="observed_problem">Problema observado</option></select></label>
        <label>Evidencia<textarea name="evidence" required></textarea></label><label>Enlace<input type="url" name="evidence_url"></label>
        <label>Tipo<select name="evidence_kind"><option value="observed">Observada</option><option value="inferred">Inferida</option></select></label>
        <label>Revisión<select name="review_status"><option value="confirmed">Confirmada</option><option value="pending">Pendiente</option></select></label><button>Guardar evidencia</button></form>
        @if ($lead->signalRows->contains('type', 'channel_email_visible'))<form method="post" action="{{ route('leads.email.confirm', $lead) }}">@csrf<button class="secondary">Usar email detectado en la web</button></form>@endif
        <form method="post" action="{{ route('leads.enrich', $lead) }}">@csrf<button class="secondary">Volver a revisar sitio</button></form></details></section>
        <section>
            <details><summary>@include('sales.partials.icon',['name'=>'settings']) Acciones</summary>
            <form method="post" action="/leads/{{ $lead->id }}/status">
                @csrf
                <label>Estado
                    <select name="status">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($lead->status === $status)>{{ App\Models\Lead::statusLabel($status) }}</option>
                        @endforeach
                    </select>
                </label>
                @include('sales.partials.campaign-select', ['selectedCampaignId' => null])
                <label>Motivo<input name="reason" value="Cambio manual"></label>
                <button>Actualizar estado</button>
            </form>
        </details></section>

        <section>
            <details><summary>@include('sales.partials.icon',['name'=>'phone']) Registrar contacto</summary>
            <form method="post" action="{{ route('contact-attempts.store') }}" data-contact-record>
                <input type="hidden" name="message_template_id"><input type="hidden" name="contact_attempt_id">
                @csrf
                <input type="hidden" name="lead_id" value="{{ $lead->id }}">
                <div class="grid">
                    <label>Campaña
                        <select name="campaign_id" required>
                            @foreach ($campaigns as $campaign)
                                <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Canal<input name="channel" value="email" required></label>
                    <label>Paso
                        <select name="step"><option value="initial">Contacto inicial</option><option value="follow_up_1">Seguimiento 1</option><option value="follow_up_2">Seguimiento 2</option><option value="closed">Otro contacto</option></select>
                    </label>
                    <label>Fecha realizada<input type="datetime-local" name="performed_at" step="1" value="{{ now()->timezone($preferences['timezone'])->format('Y-m-d\TH:i:s') }}"></label>
                </div>
                <label>Mensaje<textarea name="message_snapshot"></textarea></label>
                <button>Registrar contacto</button>
            </form>
        </details></section>

        <section>
            <details><summary>@include('sales.partials.icon',['name'=>'reply']) Registrar respuesta</summary>
            <form method="post" action="/replies">
                @csrf
                <input type="hidden" name="lead_id" value="{{ $lead->id }}">
                @include('sales.partials.campaign-select', ['selectedCampaignId' => null])
                <label>Categoría
                    <select name="category">
                        @foreach ($categories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Fecha<input type="datetime-local" name="received_at" value="{{ now()->timezone($preferences['timezone'])->format('Y-m-d\TH:i:s') }}" required></label>
                <label>Texto<textarea name="body"></textarea></label>
                <button>Registrar respuesta</button>
            </form>
        </details></section>

        <section>
            <details><summary>@include('sales.partials.icon',['name'=>'proposal']) Registrar propuesta</summary>
            <form method="post" action="{{ route('proposals.store') }}" data-proposal-fields data-existing-proposal="0">
                @csrf
                <input type="hidden" name="lead_id" value="{{ $lead->id }}" data-offer-context="{{ json_encode($leadOfferContext) }}">
                @include('sales.partials.campaign-select', ['selectedCampaignId' => $lead->campaigns->count() === 1 ? $lead->campaigns->first()->id : null])
                @include('sales.partials.proposal-offer', ['editingProposal' => null, 'defaultOfferId' => $leadOfferContext['default'] ?? null])
                <label>Servicio<input name="service" value="{{ $offers->firstWhere('id', $leadOfferContext['default'] ?? null)?->name }}" data-proposal-service required></label>
                <p class="form-help">Podés adaptar el servicio para este presupuesto. <a href="{{ route('offers', ['create' => 1]) }}">Crear una oferta</a></p>
                <div class="grid">
                    <label>Importe<input type="number" step="0.01" name="amount"></label>
                    <label>Moneda<input name="currency" value="{{ $preferences['currency'] }}" maxlength="3" required></label>
                    <label>Versión<input type="number" name="version" value="1" min="1" required></label>
                    <label>Resultado
                        <select name="result"><option value="pending">Pendiente</option><option value="won">Ganada</option><option value="lost">Perdida</option></select>
                    </label>
                </div>
                <label>Fecha<input type="datetime-local" name="sent_at" value="{{ now()->timezone($preferences['timezone'])->format('Y-m-d\TH:i:s') }}"></label>
                <button>Registrar propuesta</button>
            </form>
        </details></section>
</div></div></div>
@if($merges->isNotEmpty())<section><h2>Historial de consolidaciones</h2>@foreach($merges as $merge)<details><summary>Ficha #{{ $merge->source_lead_id }} consolidada el {{ $merge->created_at }}</summary><p>Se conserva un respaldo previo y las decisiones de revisión. Los datos originales permanecen en el registro de consolidación.</p>@php($original = json_decode($merge->original_records,true))<table><thead><tr><th>Ficha original</th><th>Nombre</th><th>Contacto</th><th>Notas originales</th></tr></thead><tbody>@foreach($original['leads'] as $originalLead)<tr><td>#{{ $originalLead['id'] }}</td><td>{{ $originalLead['name'] }}</td><td>{{ $originalLead['email'] }} · {{ $originalLead['phone_original'] }}</td><td>{{ $originalLead['notes'] }}</td></tr>@endforeach</tbody></table><p>Decisiones: {{ $merge->decisions }}</p></details>@endforeach</section>@endif
@include('sales.partials.crm-detail')
@include('sales.partials.crm-drawers')
@component('sales.partials.drawer',['drawerId'=>'lead-edit','drawerTitle'=>'Editar prospecto','drawerEyebrow'=>$lead->name])
<form method="post" action="{{ route('leads.update',$lead) }}">@csrf @method('PUT') @include('sales.partials.lead-form',['editingLead'=>$lead])<div class="form-footer"><button type="button" class="secondary" data-drawer-close>Cancelar</button><button>Guardar datos</button></div></form>
@endcomponent
@if($lead->phone) @include('sales.partials.whatsapp-composer') @endif
@endsection
