@extends('layouts.app')

@section('content')
<div class="page-head">
    <div>
        <div class="muted"><a href="/leads">Leads</a> → {{ $lead->name }}</div>
        <h1>{{ $lead->name }}</h1>
        <p class="muted">{{ $lead->category }} · {{ $lead->location }} · {{ $lead->country }}</p>
    </div>
    <div class="actions">
        @if ($lead->website_url)<a class="button light" href="{{ $lead->website_url }}" target="_blank" rel="noreferrer">Abrir sitio</a>@endif
        @if ($lead->email)<a class="button light" href="mailto:{{ $lead->email }}">Copiar email</a>@endif
        @if ($lead->phone)<a class="button light" href="tel:{{ $lead->phone }}">Copiar teléfono</a>@endif
        <a class="button secondary" href="/contact-queue?lead={{ $lead->id }}">Enviar a cola</a>
    </div>
</div>

@if ($lead->duplicate_status === 'possible_duplicate')
    <section>
        <h2>Posible duplicado</h2>
        <p>Posible relación con <a href="/leads/{{ $lead->possibleDuplicateOf?->id }}">{{ $lead->possibleDuplicateOf?->name ?? 'otro lead' }}</a>.</p>
        <div class="grid">
            <div class="kpi"><div class="muted">Regla</div>{{ $lead->duplicate_rule }}</div>
            <div class="kpi"><div class="muted">Dominio</div>{{ $lead->domain ?? 'No disponible' }}</div>
            <div class="kpi"><div class="muted">Teléfono</div>{{ $lead->phone ?? 'No disponible' }}</div>
        </div>
        <p class="muted">No se combinan automáticamente. Revisa ambos leads antes de contactar.</p>
    </section>
@endif

<div class="two-col">
    <div>
        <section>
            <h2>Identidad</h2>
            <div class="grid">
                <div class="kpi"><div class="muted">Website</div>{{ $lead->website_url ?? 'No disponible' }}</div>
                <div class="kpi"><div class="muted">Email</div>{{ $lead->email ?? 'No disponible' }}</div>
                <div class="kpi"><div class="muted">Teléfono</div>{{ $lead->phone ?? 'No disponible' }}</div>
                <div class="kpi"><div class="muted">Fuente</div>{{ $lead->source }}<br><span class="muted">{{ $lead->source_identifier }}</span></div>
            </div>
        </section>

        <section>
            <h2>Diagnóstico</h2>
            <div class="kpis">
                <div class="kpi"><div class="muted">Score</div><div class="metric">{{ $lead->score_total }}</div></div>
                <div class="kpi"><div class="muted">Estado</div><span class="badge">{{ $lead->status }}</span></div>
                <div class="kpi"><div class="muted">Oferta sugerida</div>{{ $lead->suggestedOffer?->name ?? 'Sin oferta' }}</div>
            </div>
            <h3>Componentes del score</h3>
            <table>
                <tr><th>Componente</th><th>Puntos</th><th>Evidencia</th></tr>
                @foreach ($lead->scoreComponents as $component)
                    <tr><td>{{ $component->component }}</td><td>{{ $component->points }}</td><td>{{ $component->evidence }}</td></tr>
                @endforeach
            </table>
            <h3>Señales</h3>
            @forelse ($lead->signalRows as $signal)
                <div class="card">
                    <strong>{{ $signal->type }}</strong> {{ $signal->value }}
                    <div class="muted">{{ $signal->evidence }}</div>
                </div>
            @empty
                <div class="empty">No hay señales registradas.</div>
            @endforelse
        </section>

        <section>
            <h2>Actividad</h2>
            <div class="timeline">
                @if ($lead->acquired_at)
                    <div class="timeline-item"><strong>Adquirido</strong><div class="muted">{{ $lead->acquired_at }}</div></div>
                @endif
                @if ($lead->enriched_at)
                    <div class="timeline-item"><strong>Enriquecido</strong><div class="muted">{{ $lead->enriched_at }}</div></div>
                @endif
                @foreach ($lead->sources as $source)
                    <div class="timeline-item"><strong>Fuente registrada</strong><div class="muted">{{ $source->query }} · {{ $source->observed_at }}</div></div>
                @endforeach
                @foreach ($lead->contactAttempts as $attempt)
                    <div class="timeline-item"><strong>{{ $attempt->step }}</strong><div class="muted">{{ $attempt->channel }} · {{ $attempt->status }} · {{ $attempt->performed_at ?? $attempt->due_at }}</div></div>
                @endforeach
                @foreach ($lead->replies as $reply)
                    <div class="timeline-item"><strong>Respuesta {{ $reply->category }}</strong><div class="muted">{{ $reply->received_at }}</div><div>{{ $reply->body }}</div></div>
                @endforeach
                @foreach ($lead->proposals as $proposal)
                    <div class="timeline-item"><strong>Propuesta {{ $proposal->result ?? 'pending' }}</strong><div class="muted">{{ $proposal->service }} · {{ $proposal->amount }} {{ $proposal->currency }}</div></div>
                @endforeach
                @foreach ($lead->statusHistories as $history)
                    <div class="timeline-item"><strong>Estado {{ $history->to_status }}</strong><div class="muted">{{ $history->reason }} · {{ $history->changed_at }}</div></div>
                @endforeach
            </div>
        </section>
    </div>

    <div>
        <section>
            <h2>Acciones</h2>
            <form method="post" action="/leads/{{ $lead->id }}/status">
                @csrf
                <label>Estado
                    <select name="status">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($lead->status === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Motivo<input name="reason" value="lead_detail"></label>
                <button>Actualizar estado</button>
            </form>
        </section>

        <section>
            <h2>Registrar contacto</h2>
            <form method="post" action="/contact-attempts">
                @csrf
                <input type="hidden" name="lead_id" value="{{ $lead->id }}">
                <div class="grid">
                    <label>Campaña
                        <select name="campaign_id" required>
                            @foreach ($campaigns as $campaign)
                                <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Canal<input name="channel" value="email" required></label>
                    <label>Paso
                        <select name="step"><option value="initial">initial</option><option value="follow_up_1">follow_up_1</option><option value="follow_up_2">follow_up_2</option><option value="closed">closed</option></select>
                    </label>
                    <label>Fecha realizada<input type="datetime-local" name="performed_at" value="{{ now()->format('Y-m-d\TH:i') }}"></label>
                </div>
                <label>Mensaje<textarea name="message_snapshot"></textarea></label>
                <button>Registrar contacto</button>
            </form>
        </section>

        <section>
            <h2>Registrar respuesta</h2>
            <form method="post" action="/replies">
                @csrf
                <input type="hidden" name="lead_id" value="{{ $lead->id }}">
                <label>Categoría
                    <select name="category">
                        @foreach ($categories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Fecha<input type="datetime-local" name="received_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></label>
                <label>Texto<textarea name="body"></textarea></label>
                <button>Registrar respuesta</button>
            </form>
        </section>

        <section>
            <h2>Registrar propuesta</h2>
            <form method="post" action="/proposals">
                @csrf
                <input type="hidden" name="lead_id" value="{{ $lead->id }}">
                <label>Servicio<input name="service" value="{{ $lead->suggestedOffer?->name ?? 'Servicio' }}" required></label>
                <div class="grid">
                    <label>Importe<input type="number" step="0.01" name="amount"></label>
                    <label>Moneda<input name="currency" value="USD" maxlength="3" required></label>
                    <label>Versión<input type="number" name="version" value="1" min="1" required></label>
                    <label>Resultado
                        <select name="result"><option value="pending">Pendiente</option><option value="won">Ganada</option><option value="lost">Perdida</option></select>
                    </label>
                </div>
                <label>Fecha<input type="datetime-local" name="sent_at" value="{{ now()->format('Y-m-d\TH:i') }}"></label>
                <button>Registrar propuesta</button>
            </form>
        </section>
    </div>
</div>
@endsection
