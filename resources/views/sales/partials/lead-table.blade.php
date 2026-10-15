@php
    $contextCampaign = $campaign ?? ($campaigns ?? collect())->firstWhere('id', $filters['campaign'] ?? null);
@endphp
<section data-infinite-list="leads">
<div class="section-head"><div><h2>{{ $tableTitle ?? 'Prospectos' }}</h2><p class="muted">Seleccioná registros para organizar la próxima acción.</p></div><span class="table-caption">{{ method_exists($leads,'total') ? $leads->total() : $leads->count() }} registros</span></div>
@if($leads->isEmpty())
    @if(count(array_diff(array_keys($filters ?? []),['campaign','sort']))>0)
        @include('sales.partials.empty-state',['emptyIcon'=>'search','emptyTitle'=>'No encontramos coincidencias','emptyText'=>'Probá otra búsqueda o quitá algunos filtros para ampliar los resultados.','emptyAction'=>'Limpiar filtros','emptyUrl'=>!empty($campaign) ? route('campaigns.show',['campaign'=>$campaign->id,'tab'=>'leads']) : route('leads')])
    @else
        @include('sales.partials.empty-state',['emptyIcon'=>'people','emptyTitle'=>'Tu base de contactos empieza acá','emptyText'=>'Agregá un prospecto o importá un archivo para reunir la información de tus contactos.','emptyAction'=>'Agregar prospecto','emptyUrl'=>route('leads',['create'=>1,'campaign'=>$contextCampaign?->id])])
    @endif
@else
<form method="post" action="{{ route('leads.bulk') }}" data-bulk-form>@csrf
    <input type="hidden" name="view_filters[sort]" value="{{ $filterQuery['sort'] ?? 'score' }}">
    @foreach(($filterQuery ?? []) as $key=>$value) @if(!in_array($key,['ids','page','action']) && !is_array($value))<input type="hidden" name="view_filters[{{ $key }}]" value="{{ $value }}">@endif @endforeach
    @if($contextCampaign)<input type="hidden" name="campaign" value="{{ $contextCampaign->id }}">@endif
    <div class="bulk-toolbar"><span data-selection-count aria-live="polite">0 seleccionados</span><button type="button" class="secondary" data-selection-clear>Limpiar selección</button><label class="sr-only" for="bulk-action">Acción para la selección</label><select id="bulk-action" name="action" data-bulk-action required><option value="queue">Enviar a cola</option><option value="change_offer">Cambiar oferta</option><option value="change_status">Cambiar estado</option><option value="mark_review">Marcar para revisión</option><option value="export">Exportar seleccionados</option><option value="exclude">Excluir</option></select>
    <select name="offer_id" data-action-field="change_offer" aria-label="Nueva oferta"><option value="">Elegir oferta</option>@foreach($offers as $offer)<option value="{{ $offer->id }}">{{ $offer->name }}</option>@endforeach</select><select name="status" data-action-field="change_status" aria-label="Nuevo estado"><option value="">Elegir estado</option>@foreach($statuses as $status)<option value="{{ $status }}">{{ App\Models\Lead::statusLabel($status) }}</option>@endforeach</select><input name="reason" placeholder="Motivo (opcional)" aria-label="Motivo de la acción"><button data-selection-submit>Aplicar</button></div>
    <div class="table-wrap"><table class="responsive-table lead-table"><thead><tr><th><input type="checkbox" data-select-page aria-label="Seleccionar todos los registros cargados"></th><th>Prospecto</th><th>Contacto</th><th>Scoring / prioridad manual</th><th>Estado</th><th>Oferta</th><th>Señales</th><th data-column="activity">Actividad</th><th>Acciones</th></tr></thead><tbody data-infinite-items>
    @foreach($leads as $lead)
    @php
        $membership = $contextCampaign ? $lead->campaigns->firstWhere('id',$contextCampaign->id) : null;
        $viewStatus = $membership?->pivot->status ?? $lead->status;
        $scoreOfferId = $contextCampaign ? ($membership?->pivot->offer_id ?? $contextCampaign->offer_id) : ($filters['score_offer_id'] ?? null);
        $offerScore = $scoreOfferId ? $lead->offerScores->firstWhere('offer_id', (int) $scoreOfferId) : null;
        $viewScore = $lead->score_total;
        if (array_key_exists('resolved_score', $lead->getAttributes())) {
            $viewScore = $lead->resolved_score;
            $scoreOfferId = $lead->resolved_score_offer_id;
        } elseif ($scoreOfferId) {
            $viewScore = $offerScore?->isFresh() ? $offerScore->total : null;
        }
        $viewOffer = $contextCampaign ? ($offers->firstWhere('id',$membership?->pivot->offer_id ?? $contextCampaign->offer_id) ?? $contextCampaign->offer) : $lead->suggestedOffer;
    @endphp
    <tr data-infinite-item="{{ $lead->id }}"><td class="cell-select"><input type="checkbox" name="selected_ids[]" value="{{ $lead->id }}" aria-label="Seleccionar {{ $lead->name }}"></td><td class="cell-name"><strong><a href="{{ route('leads.show',$lead) }}">{{ $lead->name }}</a></strong><div class="muted">{{ $lead->category ?: 'Sin categoría' }}</div><div class="muted">{{ $lead->city ?: $lead->location ?: 'Sin ubicación' }}@if($lead->country) · {{ $lead->country }} @endif</div>@if($lead->duplicate_status==='possible_duplicate')<span class="badge warn">Revisar duplicado</span>@endif</td>
    <td class="cell-contact">@if($lead->email)<div class="contact-line">@include('sales.partials.icon',['name'=>'mail'])<a href="mailto:{{ $lead->email }}">{{ $lead->email }}</a></div>@endif @if($lead->phone)<div class="contact-line">@include('sales.partials.icon',['name'=>'phone'])<a href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a></div>@endif @if($lead->website_url)<div class="contact-line">@include('sales.partials.icon',['name'=>'web'])<a href="{{ $lead->website_url }}" target="_blank" rel="noreferrer">Ver sitio</a></div>@endif @if(!$lead->email && !$lead->phone)<span class="muted">Contacto por completar</span>@endif</td>
    <td><span @class(['badge','score-badge','good'=>$viewScore!==null && $viewScore>=60,'warn'=>$viewScore===null || ($offerScore && $offerScore->confidence<0.5) || ($viewScore>=35 && $viewScore<60)])>{{ $viewScore ?? 'Sin evaluar' }}</span><span class="priority-caption">{{ $scoreOfferId ? 'Por oferta' : 'General' }}@if($offerScore && $offerScore->confidence<0.5) · Evidencia insuficiente @endif</span><span class="priority-caption">{{ ['high'=>'Alta','medium'=>'Media','low'=>'Baja'][$lead->manual_priority] ?? 'Sin prioridad manual' }}</span></td><td>@include('sales.partials.status-badge',['status'=>$lead->excluded_at ? 'excluded' : $viewStatus])</td><td>{{ $viewOffer?->name ?? 'Sin oferta' }}</td>
    <td class="cell-signals"><div class="table-signals">@forelse($lead->signalRows->where('review_status','!=','rejected')->take(2) as $signal)<span class="badge">{{ App\Models\Signal::label($signal->type) }}</span>@empty<span class="muted">Sin señales</span>@endforelse</div></td><td class="cell-date">@dateDisplay($lead->updated_at)</td>
    <td class="cell-actions"><div class="row-actions"><a class="button small light" href="{{ route('leads.show',$lead) }}">Ficha</a><details class="action-menu"><summary aria-label="Más acciones para {{ $lead->name }}">@include('sales.partials.icon',['name'=>'more'])</summary><div class="action-menu-list"><a href="{{ route('queue',['lead'=>$lead->id,'campaign'=>$contextCampaign?->id]) }}">@include('sales.partials.icon',['name'=>'queue']) Abrir en la cola</a>@if($lead->email)<button type="button" data-copy="{{ $lead->email }}">@include('sales.partials.icon',['name'=>'copy']) Copiar email</button>@endif @if($lead->phone)<button type="button" data-copy="{{ $lead->phone }}">@include('sales.partials.icon',['name'=>'copy']) Copiar teléfono</button>@endif</div></details></div></td></tr>
    @endforeach
    </tbody></table></div>
</form>
@if(method_exists($leads,'links')){{ $leads->links() }}@endif
@endif
</section>
