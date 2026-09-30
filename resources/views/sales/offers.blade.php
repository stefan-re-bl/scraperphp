@extends('layouts.app')

@section('content')
<section>
    <h1>Ofertas</h1>
    <form method="post" action="/offers">
        @csrf
        <div class="grid">
            <label>Nombre<input name="name" required></label>
            <label>Cliente objetivo<input name="target_customer"></label>
            <label>Segmento<input name="segment"></label>
            <label>Demo<input name="demo_url"></label>
            <label>Portfolio<input name="portfolio_url"></label>
            <label>CTA sugerido<input name="suggested_cta"></label>
        </div>
        <label>Problemas<textarea name="pains"></textarea></label>
        <label>Beneficios<textarea name="benefits"></textarea></label>
        <label>Señales compatibles<textarea name="compatibility_signals"></textarea></label>
        <label>Activos<textarea name="assets"></textarea></label>
        <button>Crear oferta</button>
    </form>
</section>

<section>
    <table>
        <tr><th>Nombre</th><th>Cliente</th><th>CTA</th></tr>
        @foreach ($offers as $offer)
            <tr>
                <td>{{ $offer->name }}</td>
                <td>{{ $offer->target_customer }}</td>
                <td>{{ $offer->suggested_cta }}</td>
            </tr>
        @endforeach
    </table>
</section>
@endsection
