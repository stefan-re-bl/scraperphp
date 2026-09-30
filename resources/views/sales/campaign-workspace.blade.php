@extends('layouts.app')

@section('content')
<div class="page-head">
    <div>
        <div class="muted"><a href="/campaigns">Campañas</a> → {{ $campaign->name }}</div>
        <h1>{{ $campaign->name }}</h1>
        <p class="muted">{{ $campaign->segment }} · {{ $campaign->city }} {{ $campaign->country }} · {{ $campaign->source }} · {{ $campaign->offer?->name ?? 'Sin oferta' }}</p>
    </div>
    <div class="actions">
        <a class="button" href="/campaigns/{{ $campaign->id }}?tab=acquisition">Obtener leads</a>
        <a class="button secondary" href="/campaigns/{{ $campaign->id }}?tab=leads">Revisar leads</a>
        <a class="button secondary" href="/contact-queue?campaign={{ $campaign->id }}">Ir a cola</a>
        <a class="button light" href="{{ route('exports.csv', ['dataset' => 'leads', 'campaign' => $campaign->id]) }}">Exportar</a>
    </div>
</div>

<section>
    <div class="kpis">
        <div class="kpi"><div class="muted">Estado</div><div class="metric">{{ $campaign->kill_switch ? 'Pausada' : $campaign->status }}</div></div>
        <div class="kpi"><div class="muted">Oferta</div><strong>{{ $campaign->offer?->name ?? 'Sin oferta' }}</strong></div>
        <div class="kpi"><div class="muted">Leads</div><div class="metric">{{ $stats['leads'] }}</div></div>
        <div class="kpi"><div class="muted">Contactos</div><div class="metric">{{ $stats['contacts'] }}</div></div>
        <div class="kpi"><div class="muted">Respuestas</div><div class="metric">{{ $stats['replies'] }}</div></div>
        <div class="kpi"><div class="muted">Interesados</div><div class="metric">{{ $stats['interested'] }}</div></div>
        <div class="kpi"><div class="muted">Propuestas</div><div class="metric">{{ $stats['proposals'] }}</div></div>
        <div class="kpi"><div class="muted">Cierres</div><div class="metric">{{ $stats['won'] }}</div></div>
    </div>
</section>

<div class="tabs">
    @foreach (['summary' => 'Resumen', 'leads' => 'Leads', 'acquisition' => 'Adquisición', 'contact' => 'Contacto', 'pipeline' => 'Pipeline', 'experiments' => 'Experimentos', 'metrics' => 'Métricas'] as $key => $label)
        <a href="/campaigns/{{ $campaign->id }}?tab={{ $key }}" @class(['active' => $tab === $key])>{{ $label }}</a>
    @endforeach
</div>

@if ($tab === 'summary')
    <div class="two-col">
        <section>
            <h2>Resumen de campaña</h2>
            <div class="kpis">
                <div class="kpi"><div class="muted">Obtenidos</div><div class="metric">{{ $stats['leads'] }}</div></div>
                <div class="kpi"><div class="muted">Calificados</div><div class="metric">{{ $stats['qualified'] }}</div></div>
                <div class="kpi"><div class="muted">Cola pendiente</div><div class="metric">{{ $stats['queue'] }}</div></div>
                <div class="kpi"><div class="muted">Won</div><div class="metric">{{ $stats['won'] }}</div></div>
                <div class="kpi"><div class="muted">Lost</div><div class="metric">{{ $stats['lost'] }}</div></div>
                <div class="kpi"><div class="muted">Posibles duplicados</div><div class="metric">{{ $stats['possible_duplicates'] }}</div></div>
            </div>
        </section>
        <section>
            <h2>Próxima acción</h2>
            @if ($stats['leads'] === 0)
                <p>No hay leads todavía.</p>
                <a class="button" href="/campaigns/{{ $campaign->id }}?tab=acquisition">Obtener leads</a>
            @elseif ($stats['possible_duplicates'] > 0)
                <p>Revisa posibles duplicados antes de contactar.</p>
                <a class="button" href="/campaigns/{{ $campaign->id }}?tab=leads&possible_duplicate=1">Revisar duplicados</a>
            @elseif ($stats['queue'] > 0)
                <p>Hay leads listos para contacto manual asistido.</p>
                <a class="button" href="/contact-queue?campaign={{ $campaign->id }}">Abrir cola</a>
            @else
                <p>Revisa los leads y envía los mejores a cola.</p>
                <a class="button" href="/campaigns/{{ $campaign->id }}?tab=leads">Revisar leads</a>
            @endif
        </section>
    </div>

    <div class="grid">
        <section>
            <h2>Queries recientes</h2>
            @forelse ($queries as $query)
                <div class="card">{{ $query->query }}<div class="muted">{{ $query->location }} · límite {{ $query->result_limit }}</div></div>
            @empty
                <div class="empty">No hay queries. Crea una en Adquisición.</div>
            @endforelse
        </section>
        <section>
            <h2>Últimas respuestas</h2>
            @forelse ($recentReplies as $reply)
                <div class="card"><strong>{{ $reply->lead?->name }}</strong><div class="muted">{{ $reply->category }} · {{ $reply->received_at }}</div></div>
            @empty
                <div class="empty">No hay respuestas registradas.</div>
            @endforelse
        </section>
        <section>
            <h2>Follow-ups pendientes</h2>
            @forelse ($pendingFollowUps as $attempt)
                <div class="card"><strong>{{ $attempt->lead?->name }}</strong><div class="muted">{{ $attempt->step }} · vence {{ $attempt->due_at }}</div></div>
            @empty
                <div class="empty">No hay follow-ups vencidos.</div>
            @endforelse
        </section>
    </div>
@elseif ($tab === 'leads')
    @include('sales.partials.lead-filters', [
        'filterAction' => '/campaigns/'.$campaign->id,
        'fixedFilters' => ['tab' => 'leads'],
    ])
    @include('sales.partials.lead-table', [
        'tableTitle' => 'Leads de campaña',
        'campaign' => $campaign,
    ])
@elseif ($tab === 'acquisition')
    <section>
        <h2>Obtener leads</h2>
        <form method="post" action="/campaigns/{{ $campaign->id }}/acquisition">
            @csrf
            <div class="grid">
                <label>Query<input name="query" value="{{ trim(($campaign->segment ?? '').' '.($campaign->city ?? '').' '.($campaign->country ?? '')) }}" required></label>
                <label>Ubicación<input name="location" value="{{ $campaign->city }}"></label>
                <label>Límite<input type="number" name="result_limit" value="{{ $campaign->scrape_limit ?? 50 }}" min="1" max="500"></label>
                <label>Ejecución
                    <select name="dry_run">
                        <option value="1">Dry-run</option>
                        <option value="0">Ejecutar scraper real</option>
                    </select>
                </label>
            </div>
            <button>Obtener leads</button>
        </form>
        <p class="muted">Dry-run vuelve al instante. Scraper real corre en segundo plano y actualiza resultados al refrescar.</p>
    </section>
    <section>
        <h2>Resultados recientes</h2>
        <table>
            <tr><th>Estado</th><th>Query</th><th>Encontrados</th><th>Importados</th><th>Duplicados</th><th>Errores</th><th>Archivo</th></tr>
            @forelse ($scrapeRuns as $run)
                <tr>
                    <td><span class="badge">{{ $run->status }}</span></td>
                    <td>{{ $run->query }}<br><span class="muted">{{ $run->location }}</span></td>
                    <td>{{ $run->raw_count }}</td>
                    <td>{{ $run->imported_count }}</td>
                    <td>{{ $run->duplicate_count }}</td>
                    <td>{{ $run->error_count }}</td>
                    <td><span class="muted">{{ $run->output_file }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty">No hay ejecuciones todavía.</div></td></tr>
            @endforelse
        </table>
    </section>
@elseif ($tab === 'contact')
    <section>
        <h2>Cola de contacto</h2>
        @forelse ($queueLeads as $lead)
            <div class="card">
                <div class="page-head">
                    <div>
                        <strong><a href="/leads/{{ $lead->id }}">{{ $lead->name }}</a></strong>
                        <div class="muted">{{ $lead->location }} · score {{ $lead->score_total }} · {{ $lead->suggestedOffer?->name }}</div>
                    </div>
                    <a class="button light" href="/contact-queue?campaign={{ $campaign->id }}">Abrir cola enfocada</a>
                </div>
            </div>
        @empty
            <div class="empty">No hay leads en cola. Revisa leads y envía los mejores a cola.</div>
        @endforelse
    </section>
@elseif ($tab === 'pipeline')
    <section>
        <h2>Oportunidades activas</h2>
        <a class="button" href="/campaigns/{{ $campaign->id }}/pipeline">Abrir Kanban</a>
    </section>
@elseif ($tab === 'experiments')
    <section>
        <h2>Experimentos</h2>
        <form method="post" action="/experiments">
            @csrf
            <input type="hidden" name="campaign_id" value="{{ $campaign->id }}">
            <div class="grid">
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
        <table>
            <tr><th>Experimento</th><th>Variable</th><th>Estado</th><th>Variantes</th><th>Muestra</th></tr>
            @foreach ($experiments as $experiment)
                <tr>
                    <td>{{ $experiment->name }}</td>
                    <td>{{ $experiment->variable }}</td>
                    <td>{{ $experiment->status }}</td>
                    <td>{{ $experiment->variants->pluck('name')->join(', ') }}</td>
                    <td>{{ $experiment->variants->sum('sent_count') }}</td>
                </tr>
            @endforeach
        </table>
    </section>
@elseif ($tab === 'metrics')
    <section>
        <h2>Métricas de campaña</h2>
        <div class="kpis">
            <div class="kpi"><div class="muted">Contacted</div><div class="metric">{{ $stats['contacts'] }}</div></div>
            <div class="kpi"><div class="muted">Replied</div><div class="metric">{{ $stats['replies'] }}</div></div>
            <div class="kpi"><div class="muted">Interested</div><div class="metric">{{ $stats['interested'] }}</div></div>
            <div class="kpi"><div class="muted">Proposal</div><div class="metric">{{ $stats['proposals'] }}</div></div>
            <div class="kpi"><div class="muted">Won</div><div class="metric">{{ $stats['won'] }}</div></div>
        </div>
    </section>
    <section>
        <h2>Segmentación</h2>
        <form method="get" action="/campaigns/{{ $campaign->id }}">
            <input type="hidden" name="tab" value="metrics">
            <label>Dimensión
                <select name="dimension">
                    @foreach (['city','offer','variant','channel','source','segment'] as $item)
                        <option value="{{ $item }}" @selected($dimension === $item)>{{ $item }}</option>
                    @endforeach
                </select>
            </label>
            <button>Ver</button>
        </form>
        <p class="muted">La tabla usa la capa actual de métricas segmentadas.</p>
    </section>
@endif
@endsection
