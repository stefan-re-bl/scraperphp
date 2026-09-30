@extends('layouts.app')

@section('content')
<div class="page-head">
    <div>
        <h1>Cola de trabajo</h1>
        <p class="muted">Responde a la pregunta: ¿a quién contacto ahora?</p>
    </div>
    <form method="get" action="/contact-queue" class="actions">
        <select name="campaign">
            <option value="">Todas las campañas</option>
            @foreach ($campaigns as $campaignOption)
                <option value="{{ $campaignOption->id }}" @selected(($campaign?->id ?? null) === $campaignOption->id)>{{ $campaignOption->name }}</option>
            @endforeach
        </select>
        <button>Filtrar</button>
    </form>
</div>

@forelse ($leads as $lead)
    <section>
        <div class="two-col">
            <div>
                <h2><a href="/leads/{{ $lead->id }}">{{ $lead->name }}</a></h2>
                <p class="muted">{{ $lead->location }} · score {{ $lead->score_total }} · {{ $lead->status }}</p>
                <div class="actions">
                    @if ($lead->website_url)<a class="button light" href="{{ $lead->website_url }}" target="_blank" rel="noreferrer">Abrir web</a>@endif
                    @if ($lead->email)<a class="button light" href="mailto:{{ $lead->email }}">Abrir email</a>@endif
                    @if ($lead->phone)<a class="button light" href="tel:{{ $lead->phone }}">Abrir teléfono</a>@endif
                </div>

                <h3>Datos útiles</h3>
                <div class="grid">
                    <div class="kpi"><div class="muted">Oferta</div>{{ $lead->suggestedOffer?->name ?? 'Sin oferta' }}</div>
                    <div class="kpi"><div class="muted">Email</div>{{ $lead->email ?? 'No disponible' }}</div>
                    <div class="kpi"><div class="muted">Teléfono</div>{{ $lead->phone ?? 'No disponible' }}</div>
                </div>

                <h3>Señales importantes</h3>
                @forelse ($lead->signalRows->take(5) as $signal)
                    <span class="badge">{{ $signal->type }}</span>
                @empty
                    <span class="muted">Sin señales registradas.</span>
                @endforelse
            </div>

            <div>
                <h3>Mensaje preparado</h3>
                @php
                    $template = $templates->firstWhere('offer_id', $lead->suggested_offer_id) ?? $templates->first();
                    $businessToken = chr(123).chr(123).'negocio'.chr(125).chr(125);
                    $ctaToken = chr(123).chr(123).'CTA'.chr(125).chr(125);
                    $preparedMessage = $template?->body
                        ? str_replace([$businessToken, $ctaToken], [$lead->name, $lead->primary_cta ?? '¿Te interesa verlo?'], $template->body)
                        : 'No hay plantilla activa.';
                @endphp
                <textarea readonly>{{ $preparedMessage }}</textarea>
                <div class="actions">
                    <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(this.closest('div').previousElementSibling.value)">Copiar mensaje</button>
                </div>

                <form method="post" action="/contact-attempts">
                    @csrf
                    <input type="hidden" name="lead_id" value="{{ $lead->id }}">
                    <div class="grid">
                        <label>Campaña
                            <select name="campaign_id" required>
                                @foreach ($campaigns as $campaignOption)
                                    <option value="{{ $campaignOption->id }}" @selected(($campaign?->id ?? null) === $campaignOption->id)>{{ $campaignOption->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Canal<input name="channel" value="{{ $campaign?->channel ?? 'email' }}" required></label>
                        <label>Paso
                            <select name="step">
                                <option value="initial">Contacto inicial</option>
                                <option value="follow_up_1">Follow-up 1</option>
                                <option value="follow_up_2">Follow-up 2</option>
                                <option value="closed">Fin</option>
                            </select>
                        </label>
                        <label>Fecha realizada<input type="datetime-local" name="performed_at" value="{{ now()->format('Y-m-d\TH:i') }}"></label>
                    </div>
                    <label>Mensaje enviado<textarea name="message_snapshot">{{ $preparedMessage }}</textarea></label>
                    <label>Resultado<input name="result" value="contacted"></label>
                    <button>Marcar contactado</button>
                </form>

                <div class="actions" style="margin-top: 8px;">
                    <form method="post" action="/leads/{{ $lead->id }}/status">
                        @csrf
                        <input type="hidden" name="status" value="PAUSED">
                        <input type="hidden" name="reason" value="queue_discarded">
                        <button class="secondary">Descartar</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@empty
    <section><div class="empty">No hay leads en cola. Envía leads a cola desde la vista de leads.</div></section>
@endforelse

@if ($leads->hasPages())
    <section>
        {{ $leads->links() }}
    </section>
@endif
@endsection
