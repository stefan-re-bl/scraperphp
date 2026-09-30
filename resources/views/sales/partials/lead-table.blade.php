<section>
    <div class="page-head">
        <div>
            <h2>{{ $tableTitle ?? 'Leads' }}</h2>
            <p class="muted">Selecciona leads para operar sobre esta vista.</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('exports.csv', ['dataset' => 'leads'] + ($filterQuery ?? [])) }}">Exportar vista</a>
        </div>
    </div>

    @if ($leads->isEmpty())
        <div class="empty">
            No hay leads para estos filtros.
            @if (! empty($campaign ?? null))
                <div><a class="button" href="/campaigns/{{ $campaign->id }}?tab=acquisition">Obtener leads</a></div>
            @endif
        </div>
    @else
        <form method="post" action="{{ route('leads.bulk') }}">
            @csrf
            <div class="actions" style="margin-bottom: 10px;">
                <select name="action" required style="max-width: 190px;">
                    <option value="queue">Enviar a cola</option>
                    <option value="change_offer">Cambiar oferta</option>
                    <option value="change_status">Cambiar estado</option>
                    <option value="mark_review">Marcar para revisión</option>
                    <option value="export">Exportar seleccionados</option>
                    <option value="exclude">Excluir</option>
                </select>
                <select name="offer_id" style="max-width: 220px;">
                    <option value="">Oferta</option>
                    @foreach ($offers as $offer)
                        <option value="{{ $offer->id }}">{{ $offer->name }}</option>
                    @endforeach
                </select>
                <select name="status" style="max-width: 160px;">
                    <option value="">Estado</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ $status }}</option>
                    @endforeach
                </select>
                <input name="reason" placeholder="Motivo" style="max-width: 220px;">
                <button>Aplicar</button>
            </div>
            <table>
                <tr>
                    <th></th>
                    <th>Nombre</th>
                    <th>Ciudad</th>
                    <th>Contacto</th>
                    <th>Score</th>
                    <th>Señales</th>
                    <th>Estado</th>
                    <th>Oferta</th>
                    <th>Última actividad</th>
                    <th>Acción</th>
                </tr>
                @foreach ($leads as $lead)
                    <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="{{ $lead->id }}"></td>
                        <td>
                            <strong><a href="/leads/{{ $lead->id }}">{{ $lead->name }}</a></strong>
                            <div class="muted">{{ $lead->category }}</div>
                            @if ($lead->duplicate_status === 'possible_duplicate')
                                <span class="badge warn">possible_duplicate · {{ $lead->duplicate_rule }}</span>
                            @endif
                        </td>
                        <td>{{ $lead->location }}<br><span class="muted">{{ $lead->country }}</span></td>
                        <td>
                            @if ($lead->email)<div>Email</div><span class="muted">{{ $lead->email }}</span>@endif
                            @if ($lead->phone)<div>Teléfono</div><span class="muted">{{ $lead->phone }}</span>@endif
                            @if ($lead->website_url)<div><a href="{{ $lead->website_url }}" target="_blank" rel="noreferrer">Web</a></div>@endif
                        </td>
                        <td><span @class(['badge', 'good' => $lead->score_total >= 60, 'warn' => $lead->score_total >= 35 && $lead->score_total < 60])>{{ $lead->score_total }}</span></td>
                        <td>
                            @forelse ($lead->signalRows->take(3) as $signal)
                                <span class="badge">{{ $signal->type }}</span>
                            @empty
                                <span class="muted">Sin señales</span>
                            @endforelse
                        </td>
                        <td><span class="badge">{{ $lead->status }}</span></td>
                        <td>{{ $lead->suggestedOffer?->name ?? 'Sin oferta' }}</td>
                        <td>{{ optional($lead->updated_at)->format('Y-m-d H:i') }}</td>
                        <td class="row-actions">
                            <a class="button light" href="/leads/{{ $lead->id }}">Ficha</a>
                            <a class="button light" href="/contact-queue?lead={{ $lead->id }}">Cola</a>
                        </td>
                    </tr>
                @endforeach
            </table>
        </form>
        @if (method_exists($leads, 'links'))
            {{ $leads->links() }}
        @endif
    @endif
</section>
