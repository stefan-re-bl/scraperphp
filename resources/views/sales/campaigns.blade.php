@extends('layouts.app')

@section('content')
<div class="page-head">
    <div>
        <h1>Campañas</h1>
        <p class="muted">Usa la campaña como contexto principal de trabajo.</p>
    </div>
</div>

<section>
    <h2>Crear campaña</h2>
    <form method="post" action="/campaigns">
        @csrf
        <div class="grid">
            <label>Nombre<input name="name" required></label>
            <label>Segmento<input name="segment"></label>
            <label>País<input name="country"></label>
            <label>Ciudad<input name="city"></label>
            <label>Oferta
                <select name="offer_id">
                    <option value="">Sin oferta</option>
                    @foreach ($offers as $offer)
                        <option value="{{ $offer->id }}">{{ $offer->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Fuente
                <select name="source" required>
                    <option value="google_maps">Google Maps</option>
                    <option value="csv_manual">CSV manual</option>
                    <option value="referral">Referido</option>
                    <option value="web">Web propia</option>
                </select>
            </label>
            <label>Límite de adquisición<input name="scrape_limit" type="number" value="50" min="1"></label>
        </div>
        <input type="hidden" name="language" value="es">
        <input type="hidden" name="channel" value="manual">
        <input type="hidden" name="mode" value="manual">
        <input type="hidden" name="daily_limit" value="20">
        <input type="hidden" name="follow_up_1_delay_days" value="3">
        <input type="hidden" name="follow_up_2_delay_days" value="7">
        <details class="panel">
            <summary>Configuración avanzada</summary>
            <div class="grid" style="margin-top: 12px;">
                <label>Idioma<input name="language_override" value="es" disabled></label>
                <label>Contacto operativo<input value="manual" disabled></label>
                <label>Límite diario<input value="20" disabled></label>
                <label>Follow-up 1 días<input value="3" disabled></label>
                <label>Follow-up 2 días<input value="7" disabled></label>
            </div>
            <p class="muted">Estos valores quedan fijos mientras no haya mensajería automática.</p>
        </details>
        <div class="actions">
            <label><input type="checkbox" name="dry_run" value="1" checked> Dry-run</label>
            <label><input type="checkbox" name="kill_switch" value="1"> Pausar desde el inicio</label>
            <button>Crear campaña</button>
        </div>
    </form>
</section>

<section>
    <h2>Workspaces</h2>
    @if ($campaigns->isEmpty())
        <div class="empty">No hay campañas todavía. Crea una para iniciar el flujo comercial.</div>
    @else
        <table>
            <tr>
                <th>Campaña</th>
                <th>Contexto</th>
                <th>Oferta</th>
                <th>Estado</th>
                <th>Actividad</th>
                <th>Acciones</th>
            </tr>
            @foreach ($campaigns as $campaign)
                <tr>
                    <td>
                        <strong><a href="/campaigns/{{ $campaign->id }}">{{ $campaign->name }}</a></strong>
                        <div class="muted">{{ $campaign->source }}</div>
                    </td>
                    <td>{{ $campaign->segment }}<br><span class="muted">{{ $campaign->city }} {{ $campaign->country }}</span></td>
                    <td>{{ $campaign->offer?->name ?? 'Sin oferta' }}</td>
                    <td>
                        <span @class(['badge', 'warn' => $campaign->kill_switch, 'good' => ! $campaign->kill_switch])>
                            {{ $campaign->kill_switch ? 'pausada' : $campaign->status }}
                        </span>
                    </td>
                    <td>
                        {{ $campaign->queries_count }} queries<br>
                        <span class="muted">{{ $campaign->contact_attempts_count }} contactos</span>
                    </td>
                    <td class="row-actions">
                        <a class="button" href="/campaigns/{{ $campaign->id }}">Abrir</a>
                        <a class="button secondary" href="/campaigns/{{ $campaign->id }}?tab=acquisition">Obtener leads</a>
                        @unless ($campaign->kill_switch)
                            <form method="post" action="/campaigns/{{ $campaign->id }}/kill-switch">
                                @csrf
                                <button class="danger">Pausar</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</section>
@endsection
