@extends('layouts.app')

@section('content')
<section>
    <h1>Experimentos</h1>
    <form method="post" action="/experiments">
        @csrf
        <div class="grid">
            <label>Campaña
                <select name="campaign_id">@foreach ($campaigns as $campaign)<option value="{{ $campaign->id }}">{{ $campaign->name }}</option>@endforeach</select>
            </label>
            <label>Nombre<input name="name" required></label>
            <label>Variable<input name="variable" value="apertura" required></label>
            <label>Estado
                <select name="status"><option value="draft">draft</option><option value="active">active</option><option value="paused">paused</option><option value="completed">completed</option></select>
            </label>
        </div>
        <button>Crear experimento</button>
    </form>
</section>

<section>
    <h2>Variante</h2>
    <form method="post" action="/experiment-variants">
        @csrf
        <div class="grid">
            <label>Experimento
                <select name="experiment_id">@foreach ($experiments as $experiment)<option value="{{ $experiment->id }}">{{ $experiment->name }}</option>@endforeach</select>
            </label>
            <label>Nombre<input name="name" required></label>
            <label>Plantilla
                <select name="message_template_id"><option value="">Sin plantilla</option>@foreach ($templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select>
            </label>
        </div>
        <label>Contenido<textarea name="content"></textarea></label>
        <button>Crear variante</button>
    </form>
</section>

<section>
    <table>
        <tr><th>Experimento</th><th>Variable</th><th>Estado</th><th>Variantes</th></tr>
        @foreach ($experiments as $experiment)
            <tr>
                <td>{{ $experiment->name }}</td>
                <td>{{ $experiment->variable }}</td>
                <td>{{ $experiment->status }}</td>
                <td>{{ $experiment->variants->pluck('name')->join(', ') }}</td>
            </tr>
        @endforeach
    </table>
</section>
@endsection

