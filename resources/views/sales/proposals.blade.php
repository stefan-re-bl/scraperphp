@extends('layouts.app')

@section('content')
<section>
    <h1>Propuestas</h1>
    <form method="post" action="/proposals">
        @csrf
        <div class="grid">
            <label>Lead
                <select name="lead_id" required>
                    @foreach ($leads as $lead)
                        <option value="{{ $lead->id }}">{{ $lead->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Servicio<input name="service" required></label>
            <label>Importe<input type="number" step="0.01" name="amount"></label>
            <label>Moneda<input name="currency" value="USD" maxlength="3" required></label>
            <label>Versión<input type="number" name="version" value="1" min="1" required></label>
            <label>Fecha<input type="datetime-local" name="sent_at"></label>
            <label>Resultado
                <select name="result">
                    <option value="pending">Pendiente</option>
                    <option value="won">Ganada</option>
                    <option value="lost">Perdida</option>
                </select>
            </label>
        </div>
        <label>Respuesta<textarea name="response"></textarea></label>
        <label>Objeciones<textarea name="objections"></textarea></label>
        <label>Modificaciones<textarea name="modifications"></textarea></label>
        <button>Registrar propuesta</button>
    </form>
</section>

<section>
    <table>
        <tr><th>Lead</th><th>Servicio</th><th>Importe</th><th>Versión</th><th>Resultado</th></tr>
        @foreach ($proposals as $proposal)
            <tr>
                <td>{{ $proposal->lead_id }}</td>
                <td>{{ $proposal->service }}</td>
                <td>{{ $proposal->amount }} {{ $proposal->currency }}</td>
                <td>{{ $proposal->version }}</td>
                <td>{{ $proposal->result }}</td>
            </tr>
        @endforeach
    </table>
</section>
@endsection

