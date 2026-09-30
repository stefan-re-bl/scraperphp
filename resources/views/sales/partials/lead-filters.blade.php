<section>
    <h2>Filtros</h2>
    <form method="get" action="{{ $filterAction ?? '/leads' }}">
        @foreach (($fixedFilters ?? []) as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <div class="filters">
            <label>Campaña
                <select name="campaign">
                    <option value="">Todas</option>
                    @foreach ($campaigns as $campaignOption)
                        <option value="{{ $campaignOption->id }}" @selected(($filters['campaign'] ?? '') == $campaignOption->id)>{{ $campaignOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Estado
                <select name="status">
                    <option value="">Todos</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </label>
            <label>Segmento<input name="segment" value="{{ $filters['segment'] ?? '' }}"></label>
            <label>Ciudad<input name="city" value="{{ $filters['city'] ?? '' }}"></label>
            <label>País<input name="country" value="{{ $filters['country'] ?? '' }}"></label>
            <label>Score mínimo<input type="number" name="min_score" value="{{ $filters['min_score'] ?? '' }}"></label>
            <label>Score máximo<input type="number" name="max_score" value="{{ $filters['max_score'] ?? '' }}"></label>
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
                        <option value="{{ $signal }}" @selected(($filters['signal'] ?? '') === $signal)>{{ $signal }}</option>
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
                    @foreach (['score' => 'Score', 'date' => 'Fecha', 'name' => 'Nombre', 'status' => 'Estado', 'activity' => 'Última actividad'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['sort'] ?? 'score') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="actions" style="margin-top: 12px;">
            <button>Aplicar filtros</button>
            <a class="button secondary" href="{{ $filterAction ?? '/leads' }}">Limpiar</a>
            <a class="button light" href="{{ route('exports.csv', ['dataset' => 'leads'] + ($filterQuery ?? [])) }}">Exportar vista actual CSV</a>
            <a class="button light" href="{{ route('exports.json', $filterQuery ?? []) }}">Exportar vista actual JSON</a>
            <a class="button light" href="{{ route('exports.phones', $filterQuery ?? []) }}">Exportar teléfonos</a>
            <a class="button light" href="{{ route('exports.analytics', $filterQuery ?? []) }}">Exportar analytics filtrado</a>
        </div>
    </form>
</section>
