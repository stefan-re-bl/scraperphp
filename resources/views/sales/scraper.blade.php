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
                        <label><input type="checkbox" name="dry_run" value="1" checked> Dry-run</label>
                        <button>Ejecutar</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </table>
</section>

<section>
    <h2>Runs</h2>
    <table>
        <tr><th>ID</th><th>Estado</th><th>Query</th><th>Archivo</th><th>Importados</th><th>Errores</th></tr>
        @foreach ($runs as $run)
            <tr>
                <td>{{ $run->id }}</td>
                <td>{{ $run->status }}</td>
                <td>{{ $run->query }}</td>
                <td>{{ $run->output_file }}</td>
                <td>{{ $run->imported_count }}</td>
                <td>{{ $run->error_count }}</td>
            </tr>
        @endforeach
    </table>
</section>
@endsection

