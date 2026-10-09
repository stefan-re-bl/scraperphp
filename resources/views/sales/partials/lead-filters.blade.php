<section class="filter-panel">
<form method="get" action="{{ $filterAction ?? route('leads') }}">
    @foreach(($fixedFilters ?? []) as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
    <div class="quick-filter"><label><span class="sr-only">Búsqueda rápida</span><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar por nombre, email, teléfono o notas"></label><button>@include('sales.partials.icon',['name'=>'search']) Buscar</button><a class="button light" href="{{ ($filterAction ?? route('leads')).(!empty($fixedFilters) ? '?'.http_build_query($fixedFilters) : '') }}">Limpiar</a><details class="action-menu"><summary aria-label="Exportar esta vista">@include('sales.partials.icon',['name'=>'download']) Exportar</summary><div class="action-menu-list"><a href="{{ route('exports.csv',['dataset'=>'leads']+($filterQuery ?? [])) }}">CSV de esta vista</a><a href="{{ route('exports.json',$filterQuery ?? []) }}">JSON de esta vista</a><a href="{{ route('exports.phones',$filterQuery ?? []) }}">Teléfonos</a><a href="{{ route('exports.analytics',$filterQuery ?? []) }}">Informe de actividad</a></div></details></div>
    <details class="filter-advanced" @if(count($filters)>2) open @endif><summary>@include('sales.partials.icon',['name'=>'filter']) Filtros avanzados</summary><div class="filters">
<label>Relación<select name="relationship_type"><option value="">Todas</option>@foreach (['prospect'=>'Cliente potencial','collaborator'=>'Colaborador','customer'=>'Cliente'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['relationship_type'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>Etiqueta<input name="tag" value="{{ $filters['tag'] ?? '' }}"></label>
            @if(empty($campaign))<label>Campaña
                <select name="campaign">
                    <option value="">Todas</option>
                    @foreach ($campaigns as $campaignOption)
                        <option value="{{ $campaignOption->id }}" @selected(($filters['campaign'] ?? '') == $campaignOption->id)>{{ $campaignOption->name }}</option>
                    @endforeach
                </select>
            </label>
            @endif
            <label>Estado
                <select name="status">
                    <option value="">Todos</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ App\Models\Lead::statusLabel($status) }}</option>
                    @endforeach
                </select>
            </label>
            <label>Segmento<input name="segment" value="{{ $filters['segment'] ?? '' }}"></label>
            <label>Ciudad<input name="city" value="{{ $filters['city'] ?? '' }}"></label>
            <label>País<input name="country" value="{{ $filters['country'] ?? '' }}"></label>
            <label>Prioridad mínima<input type="number" name="min_score" value="{{ $filters['min_score'] ?? '' }}"></label>
            <label>Prioridad máxima<input type="number" name="max_score" value="{{ $filters['max_score'] ?? '' }}"></label>
            <label>Oferta
                <select name="offer_id">
                    <option value="">Todas</option>
                    @foreach ($offers as $offer)
                        <option value="{{ $offer->id }}" @selected(($filters['offer_id'] ?? '') == $offer->id)>{{ $offer->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Fuente
                <select name="source">
                    <option value="">Todas</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ $source }}</option>
                    @endforeach
                </select>
            </label>
            <label>Canal<input name="channel" value="{{ $filters['channel'] ?? '' }}"></label>
            <label>Idioma<input name="language" value="{{ $filters['language'] ?? '' }}"></label>
            <label>Email
                <select name="has_email">
                    <option value="">Cualquiera</option>
                    <option value="1" @selected(($filters['has_email'] ?? '') === '1')>Tiene email</option>
                    <option value="0" @selected(($filters['has_email'] ?? '') === '0')>Sin email</option>
                </select>
            </label>
            <label>Teléfono
                <select name="has_phone">
                    <option value="">Cualquiera</option>
                    <option value="1" @selected(($filters['has_phone'] ?? '') === '1')>Tiene teléfono</option>
                    <option value="0" @selected(($filters['has_phone'] ?? '') === '0')>Sin teléfono</option>
                </select>
            </label>
            <label>Web
                <select name="has_web">
                    <option value="">Cualquiera</option>
                    <option value="1" @selected(($filters['has_web'] ?? '') === '1')>Tiene web</option>
                    <option value="0" @selected(($filters['has_web'] ?? '') === '0')>Sin web</option>
                </select>
            </label>
            <label>Señal
                <select name="signal">
                    <option value="">Todas</option>
                    @foreach ($signals as $signal)
                        <option value="{{ $signal }}" @selected(($filters['signal'] ?? '') === $signal)>{{ App\Models\Signal::label($signal) }}</option>
                    @endforeach
                </select>
            </label>
            <label>Duplicado posible
                <select name="possible_duplicate">
                    <option value="">Cualquiera</option>
                    <option value="1" @selected(($filters['possible_duplicate'] ?? '') === '1')>Sí</option>
                    <option value="0" @selected(($filters['possible_duplicate'] ?? '') === '0')>No</option>
                </select>
            </label>
            <label>Contactado
                <select name="contacted">
                    <option value="">Cualquiera</option>
                    <option value="1" @selected(($filters['contacted'] ?? '') === '1')>Sí</option>
                    <option value="0" @selected(($filters['contacted'] ?? '') === '0')>No</option>
                </select>
            </label>
            <label>Respondió
                <select name="replied">
                    <option value="">Cualquiera</option>
                    <option value="1" @selected(($filters['replied'] ?? '') === '1')>Sí</option>
                    <option value="0" @selected(($filters['replied'] ?? '') === '0')>No</option>
                </select>
            </label>
            <label>Adquirido desde<input type="date" name="acquired_from" value="{{ $filters['acquired_from'] ?? '' }}"></label>
            <label>Adquirido hasta<input type="date" name="acquired_to" value="{{ $filters['acquired_to'] ?? '' }}"></label>
            <label>Orden
                <select name="sort">
                    @foreach (['score' => 'Prioridad', 'date' => 'Fecha', 'name' => 'Nombre', 'status' => 'Estado', 'activity' => 'Última actividad'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['sort'] ?? 'score') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
    </div></details>
</form>
@if(($savedViews ?? collect())->isNotEmpty())<div class="saved-views"><span class="muted">Vistas:</span>@foreach($savedViews as $savedView)<span class="saved-view"><a href="{{ ($filterAction ?? route('leads')).'?'.http_build_query(($savedView->filters ?? [])+($fixedFilters ?? [])) }}">{{ $savedView->name }}</a><form method="post" action="{{ route('lead-views.delete',$savedView) }}">@csrf @method('DELETE')<button class="icon-button" aria-label="Eliminar vista {{ $savedView->name }}">@include('sales.partials.icon',['name'=>'close'])</button></form></span>@endforeach</div>@endif
<details class="save-view-form"><summary>Guardar estos filtros como una vista</summary><form method="post" action="{{ route('lead-views.store') }}" class="actions">@csrf @foreach(($filterQuery ?? []) as $key=>$value) @if(!is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<label class="sr-only" for="saved-view-name">Nombre de la vista</label><input id="saved-view-name" name="name" placeholder="Nombre de la vista" required><button class="secondary">Guardar vista</button></form></details>
</section>
