@extends('layouts.app')
@section('content')
<section><h1>Métricas segmentadas</h1><p class="muted">Solo contactos realizados. El estado histórico de puntuación se conserva al contactar; los resultados posteriores se comparan dentro de la misma oportunidad.</p>
<form method="get" action="{{ route('metrics.segmented') }}" class="grid">
<label>Dimensión<select name="dimension">@foreach(['campaign'=>'Campaña','channel'=>'Canal','offer'=>'Oferta','segment'=>'Segmento','city'=>'Ciudad','country'=>'País','source'=>'Fuente','language'=>'Idioma','variant'=>'Variante'] as $key=>$label)<option value="{{ $key }}" @selected($dimension===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Campaña<select name="campaign"><option value="">Todas</option>@foreach($campaigns as $campaignOption)<option value="{{ $campaignOption->id }}" @selected(request('campaign')==$campaignOption->id)>{{ $campaignOption->name }}</option>@endforeach</select></label>
<label>Oferta<select name="offer_id"><option value="">Todas</option>@foreach($offers as $offerOption)<option value="{{ $offerOption->id }}" @selected(request('offer_id')==$offerOption->id)>{{ $offerOption->name }}</option>@endforeach</select></label>
<label>Versión de puntuación<input name="rule_version" value="{{ request('rule_version') }}" placeholder="Todas las versiones"></label>
<label>Desde<input type="date" name="from" value="{{ request('from') }}"></label><label>Hasta<input type="date" name="to" value="{{ request('to') }}"></label><button>Ver</button></form></section>
<section>@include('sales.partials.metric-table')</section>
<section><h2>Evaluación histórica de la puntuación</h2><p>{{ $evaluation['warning'] }}</p>
<p>Con puntuación conocida: {{ $evaluation['known_sample_size'] }} · Desconocida: {{ $evaluation['unknown_sample_size'] }}. Los contactos antiguos no reciben puntuaciones reconstruidas.</p>
<table><tr><th>Puntuación al contactar</th><th>Muestra</th><th>Respuesta</th><th>Interés</th><th>Propuesta</th><th>Cierre</th></tr>
@foreach($evaluation['scores'] as $row)<tr><td>{{ $row['range'] }}</td><td>{{ $row['sample_size'] }}</td><td>{{ $row['rates']['reply_rate'] }}%</td><td>{{ $row['rates']['interest_rate'] }}%</td><td>{{ $row['rates']['proposal_rate'] }}%</td><td>{{ $row['rates']['close_rate'] }}%</td></tr>@endforeach
</table><p>Preparación registrada: {{ $evaluation['preparation']['sample_size'] }} contactos; promedio {{ $evaluation['preparation']['average_seconds'] ?? 'sin datos' }} segundos.</p>
<details><summary>Comparación de versiones</summary><table><tr><th>Versión</th><th>Muestra</th><th>Puntuación desconocida</th><th>Respuesta</th><th>Cierre</th></tr>@forelse($evaluation['versions'] as $version)<tr><td>{{ $version['rule_version'] }}</td><td>{{ $version['sample_size'] }}</td><td>{{ $version['unknown_sample_size'] }}</td><td>{{ $version['rates']['reply_rate'] }}%</td><td>{{ $version['rates']['close_rate'] }}%</td></tr>@empty<tr><td colspan="5">Todavía no hay contactos suficientes para comparar versiones.</td></tr>@endforelse</table></details>
<details><summary>Resultados por campaña y oferta</summary><table><tr><th>Campaña</th><th>Oferta</th><th>Versión</th><th>Muestra</th><th>Respuesta</th><th>Cierre</th></tr>@forelse($evaluation['comparisons'] as $comparison)<tr><td>{{ $campaigns->firstWhere('id',$comparison['campaign_id'])?->name ?? 'Sin campaña' }}</td><td>{{ $offers->firstWhere('id',$comparison['offer_id'])?->name ?? 'Sin oferta' }}</td><td>{{ $comparison['rule_version'] }}</td><td>{{ $comparison['sample_size'] }}</td><td>{{ $comparison['rates']['reply_rate'] }}%</td><td>{{ $comparison['rates']['close_rate'] }}%</td></tr>@empty<tr><td colspan="6">No hay cohortes registradas.</td></tr>@endforelse</table></details>
<p class="form-help">Son asociaciones exploratorias. Cambiar pesos o declarar una versión mejor requiere muestras suficientes y una comparación supervisada.</p>
</section>
@endsection
