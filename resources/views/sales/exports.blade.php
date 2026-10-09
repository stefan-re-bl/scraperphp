@extends('layouts.app')

@section('content')
<section>
    <h1>Exportación</h1>
    <p class="muted">Exporta datos crudos para análisis externo.</p>
    <h2>CSV</h2>
    <div class="grid">
        @foreach ($datasets as $dataset)
            <a class="button" href="/exports/csv/{{ $dataset }}">{{ $dataset }}</a>
        @endforeach
    </div>
</section>

<section>
    <h2>JSON completo</h2>
    <a class="button" href="/exports/json">Descargar JSON</a>
</section>

<section>
    <h2>Dataset analítico</h2>
    <a class="button" href="/exports/analytics">Descargar analytics_export.csv</a>
</section>

<section>
    <h2>Teléfonos</h2>
    <p class="muted">Exporta teléfonos normalizados según el país registrado y separados por coma.</p>
    <a class="button" href="/exports/phones">Descargar telefonos.txt</a>
</section>

<section>
    <h2>Respaldos verificados</h2>
    <p>La descarga genera una copia consistente y verifica su integridad.</p>
    @if ($databaseExists)
        <a class="button" href="/exports/sqlite">Descargar SQLite</a>
    @else
        <p>No existe la base SQLite.</p>
    @endif
</section>
<section><h2>Respaldos disponibles</h2>@foreach ($backups as $backup)<p><a href="{{ route('backups.download', $backup->getFilename()) }}">{{ $backup->getFilename() }}</a></p>@endforeach</section>
<section><h2>Verificar y restaurar</h2><form method="post" action="{{ route('backups.verify') }}" enctype="multipart/form-data">@csrf<label>Respaldo SQLite<input type="file" name="backup" required></label><button>Verificar archivo</button></form></section>
@endsection
