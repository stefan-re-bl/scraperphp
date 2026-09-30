@extends('layouts.app')

@section('content')
<section>
    <h1>Respuestas</h1>
    <form method="post" action="/replies">
        @csrf
        <div class="grid">
            <label>Lead
                <select name="lead_id" required>
                    @foreach ($leads as $lead)
                        <option value="{{ $lead->id }}">{{ $lead->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Contacto
                <select name="contact_attempt_id">
                    <option value="">Sin asociar</option>
                    @foreach ($attempts as $attempt)
                        <option value="{{ $attempt->id }}">#{{ $attempt->id }} · {{ $attempt->channel }} · {{ $attempt->performed_at }}</option>
                    @endforeach
                </select>
            </label>
            <label>Categoría
                <select name="category" required>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </select>
            </label>
            <label>Fecha<input type="datetime-local" name="received_at" required></label>
        </div>
        <label>Texto recibido<textarea name="body"></textarea></label>
        <button>Registrar respuesta</button>
    </form>
</section>

<section>
    <table>
        <tr><th>Lead</th><th>Categoría</th><th>Fecha</th><th>Intervención</th></tr>
        @foreach ($replies as $reply)
            <tr>
                <td>{{ $reply->lead_id }}</td>
                <td>{{ $reply->category }}</td>
                <td>{{ $reply->received_at }}</td>
                <td>{{ $reply->requires_human ? 'requerida' : 'no' }}</td>
            </tr>
        @endforeach
    </table>
</section>
@endsection

