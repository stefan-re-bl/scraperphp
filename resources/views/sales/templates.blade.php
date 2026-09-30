@extends('layouts.app')

@section('content')
<section>
    <h1>Plantillas</h1>
    <form method="post" action="/templates">
        @csrf
        <div class="grid">
            <label>Nombre<input name="name" required></label>
            <label>Idioma<input name="language" value="es" required></label>
            <label>Canal<input name="channel" value="email" required></label>
            <label>Segmento<input name="segment"></label>
            <label>Etapa
                <select name="stage">
                    <option value="initial">initial</option>
                    <option value="follow_up_1">follow_up_1</option>
                    <option value="follow_up_2">follow_up_2</option>
                </select>
            </label>
            <label>Oferta
                <select name="offer_id">
                    <option value="">Sin oferta</option>
                    @foreach ($offers as $offer)
                        <option value="{{ $offer->id }}">{{ $offer->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <label>Asunto<input name="subject"></label>
        <label>Cuerpo<textarea name="body" required>Hola @{{negocio}}, vi una oportunidad relacionada con @{{problema_detectado}}. @{{CTA}}.</textarea></label>
        <button>Crear plantilla</button>
    </form>
</section>

<section>
    <table>
        <tr><th>Nombre</th><th>Canal</th><th>Etapa</th><th>Asunto</th></tr>
        @foreach ($templates as $template)
            <tr>
                <td>{{ $template->name }}</td>
                <td>{{ $template->channel }}</td>
                <td>{{ $template->stage }}</td>
                <td>{{ $template->subject }}</td>
            </tr>
        @endforeach
    </table>
</section>
@endsection
