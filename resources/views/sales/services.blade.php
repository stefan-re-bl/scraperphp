@extends('layouts.app')
@section('content')
<div class="page-head"><div><span class="eyebrow">Cartera</span><h1>Servicios y renovaciones</h1><p class="muted">Consulta los trabajos contratados y sus próximas renovaciones.</p></div><a class="button light" href="{{ route('leads', ['relationship_type'=>'customer']) }}">Ver clientes</a></div>
<section><form method="get" action="{{ route('services') }}"><div class="filters">
<label>Buscar<input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Servicio o cliente"></label>
<label>Tipo<select name="type"><option value="">Todos</option>@foreach(App\Models\ClientService::TYPES as $value=>$label)<option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
<label>Estado<select name="status"><option value="">Todos</option>@foreach(App\Models\ClientService::STATUSES as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
<label>Renovaciones hasta<input type="date" name="until" value="{{ $filters['until'] ?? now()->addDays(30)->format('Y-m-d') }}"></label>
<label class="check"><input type="checkbox" name="renewals" value="1" @checked($filters['renewals'] ?? false)> Solo renovaciones pendientes</label>
</div><div class="actions"><button>Filtrar</button><a class="button light" href="{{ route('services') }}">Limpiar</a></div></form></section>
<section><div class="table-wrap"><table class="responsive-table" data-static-table><thead><tr><th>Cliente</th><th>Servicio</th><th>Estado</th><th>Importe</th><th>Renovación</th></tr></thead><tbody>
@forelse($services as $service)<tr><td><a href="{{ route('leads.show', $service->lead) }}">{{ $service->lead->name }}</a></td><td>{{ $service->name }}<div class="muted">{{ App\Models\ClientService::TYPES[$service->type] }}</div></td><td>{{ App\Models\ClientService::STATUSES[$service->status] }}</td><td>{{ $service->amount !== null ? $service->amount.' '.$service->currency : 'Sin definir' }}</td><td>{{ $service->renews_on?->format('d/m/Y') ?? 'Sin definir' }}</td></tr>
@empty<tr><td colspan="5">No hay servicios para estos filtros. Añade servicios desde la ficha de un cliente.</td></tr>@endforelse
</tbody></table></div>{{ $services->links() }}</section>
@endsection
