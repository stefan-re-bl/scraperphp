@extends('layouts.app')

@section('content')
<section>
    <h1>Adquisición</h1>
    <form method="post" action="/campaign-queries">
        @csrf
        <div class="grid">
            <label>Campaña
                <select name="campaign_id" required>
                    @foreach ($campaigns as $campaign)
                        <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Query<input name="query" required></label>
            <label>Ubicación<input name="location"></label>
            <label>Límite<input type="number" name="result_limit" value="50" min="1" max="500"></label>
        </div>
        <button>Crear query</button>
    </form>
</section>

<section>
    <h2>Queries</h2>
    <table>
        <tr><th>Query</th><th>Ubicación</th><th>Límite</th><th>Ejecutar</th></tr>
        @foreach ($queries as $query)
            <tr>
                <td>{{ $query->query }}</td>
                <td>{{ $query->location }}</td>
                <td>{{ $query->result_limit }}</td>
                <td>
                    <form method="post" action="/scraper/run">
                        @csrf
                        <input type="hidden" name="campaign_query_id" value="{{ $query->id }}">
                        <input type="hidden" name="dry_run" value="0"><label><input type="checkbox" name="dry_run" value="1" checked> Simular</label>
                        <button>Ejecutar</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </table>
</section>

<section>
    <h2>Búsquedas</h2><div class="table-wrap"><table><tr><th>Búsqueda</th><th>Estado</th><th>Etapa</th><th>Importados</th><th>Errores</th><th>Acciones</th></tr>
    @foreach ($runs as $run)<tr @if(in_array($run->status, ['queued','running'])) data-run-poll="{{ route('scraper.status', $run) }}" @endif>
    <td>{{ $run->query }}</td><td data-run-field="status">{{ ['queued'=>'En cola','running'=>'En curso','completed'=>'Completada','failed'=>'Error','cancelled'=>'Cancelada','dry_run'=>'Simulación'][$run->status] ?? $run->status }}</td><td data-run-field="phase">{{ $run->phase }}</td><td data-run-field="imported_count">{{ $run->imported_count }}</td><td data-run-field="error_count">{{ $run->error_count }}</td><td>
    @if(in_array($run->status, ['queued','running']))<form method="post" action="{{ route('scraper.cancel', $run) }}">@csrf<button class="secondary">Cancelar</button></form>@endif
    @if(in_array($run->status, ['failed','cancelled']))<form method="post" action="{{ route('scraper.retry', $run) }}">@csrf<button>Reintentar</button></form>@endif
    @if($run->stderr)<details><summary>Detalle del error</summary><p>{{ $run->stderr }}</p></details>@endif
    </td></tr>@endforeach</table></div>
</section>
@endsection
