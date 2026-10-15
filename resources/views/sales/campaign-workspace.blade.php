@extends('layouts.app')

@section('content')
<div class="page-head"><div><span class="eyebrow"><a href="{{ route('campaigns') }}">Campañas</a> / Contexto comercial</span><h1>{{ $campaign->name }}</h1><p class="muted">{{ $campaign->segment ?: 'General' }} · {{ $campaign->city }} {{ $campaign->country }} · {{ $campaign->offer?->name ?? 'Sin oferta' }}</p><div class="actions" style="margin-top:10px">@include('sales.partials.status-badge',['status'=>$campaign->kill_switch ? 'paused' : $campaign->status])<span class="muted">{{ ['csv_manual'=>'CSV','google_maps'=>'Google Maps','referral'=>'Referidos','web'=>'Web'][$campaign->source] ?? $campaign->source }}</span></div></div><div class="actions"><a class="button" href="{{ route('queue',['campaign'=>$campaign->id]) }}">Abrir cola</a><a class="button light" href="{{ route('campaigns.show',['campaign'=>$campaign->id,'tab'=>'acquisition']) }}">Obtener prospectos</a><button type="button" class="icon-button" data-drawer-open="campaign-edit" aria-label="Editar campaña y seguimientos">@include('sales.partials.icon',['name'=>'edit'])</button><details class="action-menu"><summary aria-label="Más opciones de campaña">@include('sales.partials.icon',['name'=>'more'])</summary><div class="action-menu-list"><a href="{{ route('exports.csv',['dataset'=>'leads','campaign'=>$campaign->id]) }}">Exportar prospectos</a><a href="{{ route('campaigns.show',['campaign'=>$campaign->id,'tab'=>'leads']) }}">Revisar prospectos</a></div></details></div></div>
@if($campaign->kill_switch)<div class="panel-note">Esta campaña está pausada.<form method="post" action="{{ route('campaigns.resume',$campaign) }}">@csrf<button class="small secondary">Reactivar campaña</button></form></div>@endif
@if($stats)<section class="campaign-overview"><div class="summary-strip">@foreach(['leads'=>'Prospectos','contacts'=>'Contactados','replies'=>'Respuestas','proposals'=>'Propuestas','won'=>'Ganados'] as $key=>$label)<div class="kpi"><div class="muted">{{ $label }}</div><div class="metric">{{ $stats[$key] }}</div></div>@endforeach</div></section>
@endif
<div class="tabs">
    @foreach (['summary' => 'Resumen', 'leads' => 'Prospectos', 'acquisition' => 'Adquisición', 'contact' => 'Contacto', 'pipeline' => 'Oportunidades', 'experiments' => 'Experimentos', 'metrics' => 'Métricas'] as $key => $label)
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
                <div class="kpi"><div class="muted">Ganados</div><div class="metric">{{ $stats['won'] }}</div></div>
                <div class="kpi"><div class="muted">Perdidos</div><div class="metric">{{ $stats['lost'] }}</div></div>
                <div class="kpi"><div class="muted">Posibles duplicados</div><div class="metric">{{ $stats['possible_duplicates'] }}</div></div>
            </div>
        </section>
        <section>
            <h2>Próxima acción</h2>
            @if ($stats['leads'] === 0)
                <p>No hay prospectos todavía.</p>
                <a class="button" href="/campaigns/{{ $campaign->id }}?tab=acquisition">Obtener prospectos</a>
            @elseif ($stats['possible_duplicates'] > 0)
                <p>Revisa posibles duplicados antes de contactar.</p>
                <a class="button" href="/campaigns/{{ $campaign->id }}?tab=leads&possible_duplicate=1">Revisar duplicados</a>
            @elseif ($stats['queue'] > 0)
                <p>Hay prospectos listos para contacto manual asistido.</p>
                <a class="button" href="{{ route('queue', ['campaign' => $campaign->id]) }}">Abrir cola</a>
            @else
                <p>Revisa los prospectos y envía los mejores a cola.</p>
                <a class="button" href="/campaigns/{{ $campaign->id }}?tab=leads">Revisar prospectos</a>
            @endif
        </section>
    </div>

    <div class="grid">
        <section>
            <h2>Búsquedas recientes</h2>
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
            <h2>Seguimientos pendientes</h2>
            @forelse ($pendingFollowUps as $attempt)
                <div class="card"><strong>{{ $attempt->lead?->name }}</strong><div class="muted">{{ $attempt->step }} · vence {{ $attempt->due_at }}</div></div>
            @empty
                <div class="empty">No hay seguimientos vencidos.</div>
            @endforelse
        </section>
    </div>
@elseif ($tab === 'leads')
    @include('sales.partials.lead-filters', [
        'filterAction' => '/campaigns/'.$campaign->id,
        'fixedFilters' => ['tab' => 'leads'],
    ])
    @include('sales.partials.lead-table', [
        'tableTitle' => 'Prospectos de campaña',
        'campaign' => $campaign,
    ])
@elseif ($tab === 'acquisition')
    <section>
        <h2>Obtener prospectos</h2>
        <form method="post" action="/campaigns/{{ $campaign->id }}/acquisition">
            @csrf
            <div class="grid">
                <label>Búsqueda<input name="query" value="{{ trim(($campaign->segment ?? '').' '.($campaign->city ?? '').' '.($campaign->country ?? '')) }}" required></label>
                <label>Ubicación<input name="location" value="{{ $campaign->city }}"></label>
                <label>Límite<input type="number" name="result_limit" value="{{ $campaign->scrape_limit ?? 50 }}" min="1" max="500"></label>
            </div>
            <button>Obtener prospectos</button>
        </form>
<p class="muted">La búsqueda se procesa en segundo plano y muestra su progreso automáticamente.</p>
    </section>
    <section>
        <h2>Resultados recientes</h2><a class="button secondary" href="{{ route('imports.create', ['campaign'=>$campaign->id]) }}">Importar CSV con revisión</a>
        <table>
            <tr><th>Estado</th><th>Búsqueda</th><th>Encontrados</th><th>Importados</th><th>Duplicados</th><th>Errores</th><th>Progreso y acciones</th></tr>
            @forelse ($scrapeRuns as $run)
                <tr @if(in_array($run->status,['queued','running'])) data-run-poll="{{ route('scraper.status',$run) }}" @endif>
                    <td><span class="badge" data-run-field="status">{{ ['queued'=>'En cola','running'=>'En curso','completed'=>'Completada','failed'=>'Error','cancelled'=>'Cancelada','dry_run'=>'Simulación'][$run->status] ?? $run->status }}</span></td>
                    <td>{{ $run->query }}<br><span class="muted">{{ $run->location }}</span></td>
                    <td data-run-field="raw_count">{{ $run->raw_count }}</td>
                    <td data-run-field="imported_count">{{ $run->imported_count }}</td>
                    <td data-run-field="duplicate_count">{{ $run->duplicate_count }}</td>
                    <td data-run-field="error_count">{{ $run->error_count }}</td>
                    <td><span class="muted" data-run-field="phase">{{ ['queued'=>'En cola','searching'=>'Buscando','importing'=>'Importando','completed'=>'Completada','failed'=>'Error','cancelled'=>'Cancelada','simulation'=>'Simulación'][$run->phase] ?? $run->phase }}</span>
@if(in_array($run->status,['queued','running']))<form method="post" action="{{ route('scraper.cancel',$run) }}">@csrf<button class="secondary">Cancelar</button></form>@endif
@if(in_array($run->status,['failed','cancelled']))<form method="post" action="{{ route('scraper.retry',$run) }}">@csrf<button>Reintentar</button></form>@endif
@if($run->stderr)<details><summary>{{ $run->status === 'failed' ? 'Ver error' : 'Ver registro' }}</summary><div>{{ $run->stderr }}</div></details>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty">No hay ejecuciones todavía.</div></td></tr>
            @endforelse
        </table>
    </section>
    <section><h2>Importaciones</h2>@foreach ($imports as $importRun)<div class="card" @if(in_array($importRun->status,['queued','processing'])) data-run-poll="{{ route('imports.status',$importRun) }}" @endif><span data-run-field="status">{{ ['queued'=>'En cola','processing'=>'Importando','completed'=>'Completada','failed'=>'Error','cancelled'=>'Cancelada'][$importRun->status] ?? $importRun->status }}</span> · Nuevos: <span data-run-field="imported_count">{{ $importRun->imported_count }}</span> · Duplicados: {{ $importRun->duplicate_count }} · Excluidos: {{ $importRun->excluded_count }} · Errores: <span data-run-field="error_count">{{ $importRun->error_count }}</span> @if($importRun->error_count)<a href="{{ route('imports.rejections',$importRun) }}">Descargar filas rechazadas</a>@endif
@if($importRun->error_message)<p>{{ $importRun->error_message }}</p>@endif @if(in_array($importRun->status,['failed','cancelled']))<form method="post" action="{{ route('imports.retry',$importRun) }}">@csrf<button>Reintentar importación</button></form>@endif</div>@endforeach</section>
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
                    <a class="button light" href="{{ route('queue', ['campaign' => $campaign->id, 'lead' => $lead->id]) }}">Abrir cola enfocada</a>
                </div>
            </div>
        @empty
            <div class="empty">No hay prospectos en cola. Revisa leads y envía los mejores a cola.</div>
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
                    <td>{{ $experiment->variants->sum('real_attempts_count') }}</td>
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
            <div class="kpi"><div class="muted">Ganados</div><div class="metric">{{ $stats['won'] }}</div></div>
        </div>
    </section>
    <section>
        <h2>Segmentación</h2>
        <form method="get" action="/campaigns/{{ $campaign->id }}" id="campaign-period">
            <input type="hidden" name="tab" value="metrics">
            <label>Dimensión
                <select name="dimension">
                    @foreach (['city','offer','variant','channel','source','segment'] as $item)
                        <option value="{{ $item }}" @selected($dimension === $item)>{{ $item }}</option>
                    @endforeach
                </select>
            </label>
            <div class="grid"><label>Desde<input type="date" name="from" value="{{ request('from') }}"></label><label>Hasta<input type="date" name="to" value="{{ request('to') }}"></label></div><button>Ver</button>
        </form>
        @include('sales.partials.metric-table', ['rows'=>$metricRows])
    </section>
@endif
@component('sales.partials.drawer',['drawerId'=>'campaign-edit','drawerTitle'=>'Editar campaña','drawerEyebrow'=>$campaign->name])
<form method="post" action="{{ route('campaigns.update',$campaign) }}">@csrf @method('PUT') @include('sales.partials.campaign-form',['editingCampaign'=>$campaign])<div class="form-footer"><button type="button" class="secondary" data-drawer-close>Cancelar</button><button>Guardar campaña</button></div></form>
@endcomponent
@endsection
