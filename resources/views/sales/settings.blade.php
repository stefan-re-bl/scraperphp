@extends('layouts.app')

@section('content')
<div class="page-head">
    <div>
        <h1>Configuración</h1>
        <p class="muted">Funciones administrativas fuera del flujo diario.</p>
    </div>
</div>

<section><h2>Preferencias de uso</h2><form method="post" action="{{ route('preferences.update') }}">@csrf @method('PUT')<div class="grid">
<label>Zona horaria<select name="timezone">@foreach (['America/Argentina/Buenos_Aires','America/Santiago','America/Montevideo','Europe/Madrid','UTC'] as $zone)<option @selected($preferences['timezone']===$zone)>{{ $zone }}</option>@endforeach</select></label>
<label>Moneda<input name="currency" value="{{ $preferences['currency'] }}" maxlength="3" required></label><label>País<input name="country" value="{{ $preferences['country'] }}" required></label><label>Idioma<input name="language" value="{{ $preferences['language'] }}" required></label>
<label>Formato de fechas<select name="date_format">@foreach (['d/m/Y H:i'=>'Día/mes/año','Y-m-d H:i'=>'Año-mes-día','m/d/Y H:i'=>'Mes/día/año'] as $key=>$label)<option value="{{ $key }}" @selected($preferences['date_format']===$key)>{{ $label }}</option>@endforeach</select></label></div><button>Guardar preferencias</button></form></section>
<div class="grid">
    <section>
        <h2>Ofertas</h2>
        <p class="muted">Define qué se vende y a quién.</p>
        <a class="button" href="/offers">Gestionar ofertas</a>
        <table>
            <tr><th>Oferta</th><th>Segmento</th></tr>
            @foreach ($offers->take(5) as $offer)
                <tr><td>{{ $offer->name }}</td><td>{{ $offer->segment }}</td></tr>
            @endforeach
        </table>
    </section>

    <section>
        <h2>Plantillas</h2>
        <p class="muted">Mensajes base para contacto y follow-up.</p>
        <a class="button" href="/templates">Gestionar plantillas</a>
        <table>
            <tr><th>Plantilla</th><th>Etapa</th></tr>
            @foreach ($templates->take(5) as $template)
                <tr><td>{{ $template->name }}</td><td>{{ $template->stage }}</td></tr>
            @endforeach
        </table>
    </section>

    <section>
        <h2>Exclusiones</h2>
        <p class="muted">Evita contactar dominios, emails o teléfonos bloqueados.</p>
        <a class="button" href="/exclusions">Gestionar exclusiones</a>
        <table>
            <tr><th>Tipo</th><th>Valor</th></tr>
            @foreach ($exclusions as $exclusion)
                <tr><td>{{ $exclusion->type }}</td><td>{{ $exclusion->value }}</td></tr>
            @endforeach
        </table>
    </section>

    <section>
        <h2>Herramientas avanzadas</h2><a class="button" href="{{ route('scoring') }}">Reglas de prioridad</a>
        <div class="actions">
            <a class="button secondary" href="/llm-bridge">LLM Bridge</a>
            <a class="button secondary" href="/exports">Exportaciones completas</a>
            <a class="button secondary" href="/metrics">Métricas segmentadas</a>
            <a class="button secondary" href="/scraper">Adquisición técnica</a>
        </div>
    </section>
</div>
@endsection
