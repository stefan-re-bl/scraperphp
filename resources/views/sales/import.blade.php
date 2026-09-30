@extends('layouts.app')

@section('content')
<section>
    <h1>Importar CSV</h1>
    <p class="muted">Usa CSV exportado por la herramienta externa de Google Maps.</p>
    <form method="post" action="/imports" enctype="multipart/form-data">
        @csrf
        <div class="grid">
            <label>Campaña
                <select name="campaign_id" required>
                    @foreach ($campaigns as $campaign)
                        <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Consulta<input name="query"></label>
            <label>Ubicación<input name="location"></label>
            <label>CSV<input type="file" name="csv" required></label>
        </div>
        <button>Importar</button>
    </form>
</section>
@endsection

