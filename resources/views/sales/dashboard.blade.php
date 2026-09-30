@extends('layouts.app')

@section('content')
<div class="page-head">
    <div>
        <h1>Inicio</h1>
        <p class="muted">Acciones pendientes y embudo reciente.</p>
    </div>
    <div class="actions">
        <a class="button" href="/campaigns">Abrir campañas</a>
        <a class="button secondary" href="/leads">Revisar leads</a>
    </div>
</div>

<section>
    <h2>Acciones de hoy</h2>
    <div class="grid">
        <a class="panel" href="/leads?status=QUEUED">
            <div class="muted">Leads listos para contactar</div>
            <div class="metric">{{ $readyToContact->count() }}</div>
        </a>
        <a class="panel" href="/contact-queue">
            <div class="muted">Follow-ups vencidos</div>
            <div class="metric">{{ $overdueFollowUps->count() }}</div>
        </a>
        <a class="panel" href="/replies">
            <div class="muted">Respuestas pendientes</div>
            <div class="metric">{{ $pendingReplies->count() }}</div>
        </a>
        <a class="panel" href="/proposals">
            <div class="muted">Propuestas pendientes</div>
            <div class="metric">{{ $pendingProposals->count() }}</div>
        </a>
        <a class="panel" href="/leads?possible_duplicate=1">
            <div class="muted">Posibles duplicados</div>
            <div class="metric">{{ $possibleDuplicates->count() }}</div>
        </a>
    </div>
</section>

<section>
    <h2>Resumen comercial</h2>
    <div class="kpis">
        @foreach (['contacted', 'replied', 'interested', 'proposal', 'won'] as $name)
            <div class="kpi">
                <div class="muted">{{ strtoupper($name) }}</div>
                <div class="metric">{{ $metrics['counts'][$name] ?? 0 }}</div>
                @if (($metrics['sample_size'] ?? 0) > 0 && isset($metrics['rates'][$name.'_rate']))
                    <div class="muted">{{ $metrics['rates'][$name.'_rate'] }}%</div>
                @endif
            </div>
        @endforeach
    </div>
</section>

<section>
    <h2>Tasas</h2>
    <table>
        <tr><th>Métrica</th><th>Porcentaje</th><th>Muestra</th></tr>
        @foreach ($metrics['rates'] as $name => $value)
            <tr>
                <td>{{ str_replace('_', ' ', $name) }}</td>
                <td>{{ $value }}%</td>
                <td>{{ $metrics['sample_size'] }}</td>
            </tr>
        @endforeach
    </table>
</section>

<div class="two-col">
    <section>
        <h2>Campañas activas</h2>
        @forelse ($activeCampaigns as $campaign)
                <div class="card">
                    <strong><a href="/campaigns/{{ $campaign->id }}">{{ $campaign->name }}</a></strong>
                <div class="muted">{{ $campaign->segment }} · {{ $campaign->country }} · {{ $campaign->source }}</div>
                <div class="muted">Oferta: {{ $campaign->offer?->name ?? 'Sin oferta' }}</div>
            </div>
        @empty
            <div class="empty">
                No hay campañas activas.
                <div><a class="button" href="/campaigns">Crear campaña</a></div>
            </div>
        @endforelse
    </section>

    <section>
        <h2>Trabajo inmediato</h2>
        @forelse ($readyToContact as $lead)
            <div class="card">
                <strong><a href="/leads/{{ $lead->id }}">{{ $lead->name }}</a></strong>
                <div class="muted">Score {{ $lead->score_total }} · {{ $lead->location }}</div>
                <a class="button light" href="/contact-queue">Contactar</a>
            </div>
        @empty
            <div class="empty">No hay leads en cola. Obtén o revisa leads desde una campaña.</div>
        @endforelse
    </section>
</div>
@endsection
