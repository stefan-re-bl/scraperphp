<section>
    <div class="section-head"><h2>Puntuaciones por oferta</h2></div>
    <p class="form-help">Estimaciones iniciales basadas en lo conocido. No demuestran intención de compra y no habilitan contactos bloqueados.</p>
    <p class="form-help">Último enriquecimiento: @dateDisplay($lead->enriched_at)</p>
    @php
        $rankedScores = $lead->offerScores->sortByDesc('total');
        $bestScore = $rankedScores->first(fn ($row) => $currentScoreIds->contains($row->id));
        $dimensionLabels = ['fit'=>'Adecuación comercial','need'=>'Necesidad observable','contact'=>'Contactabilidad','evidence'=>'Calidad de evidencia'];
    @endphp
    @if($bestScore)
        <p><strong>Mejor puntuación actual: {{ $bestScore->offer?->name ?? 'Oferta archivada' }}</strong> · {{ $bestScore->total }}/100</p>
        @if($bestScore->confidence < 0.5 || $bestScore->coverage < 0.5)<p class="panel-note">Evidencia insuficiente. Revisa las fuentes y los datos desconocidos antes de usar esta recomendación.</p>@endif
    @endif
    @forelse($rankedScores as $offerScore)
        <details style="margin-top:14px">
            <summary>{{ $offerScore->offer?->name ?? 'Oferta archivada' }} · {{ $currentScoreIds->contains($offerScore->id) ? $offerScore->total.'/100' : 'Necesita actualización' }}</summary>
            <p class="form-help">Calculada: @dateDisplay($offerScore->calculated_at) · versión {{ $offerScore->rule_version }}</p>
            <p>Confianza {{ round($offerScore->confidence * 100) }}% · cobertura {{ round($offerScore->coverage * 100) }}%. Son indicadores de evidencia, no probabilidades de venta.</p>
            <table class="compact-table" data-static-table><tr><th>Dimensión</th><th>Índice</th></tr>
                @foreach($offerScore->dimensions ?? [] as $dimension=>$points)
                    <tr><td>{{ $dimensionLabels[$dimension] ?? $dimension }}</td><td>{{ $points }}/100</td></tr>
                @endforeach
            </table>
            <p class="form-help">Un cero significa que esta dimensión no aportó puntos; no demuestra una ausencia en el negocio.</p>
            <h3>Señales a favor</h3>
            <ul>@forelse(collect($offerScore->explanation['rules'] ?? [])->where('points','>',0)->sortByDesc('points')->take(4) as $rule)<li>{{ $rule['reason'] }}</li>@empty<li>No hay señales suficientes para justificar una necesidad comercial.</li>@endforelse</ul>
            @if($offerScore->explanation['unknowns'] ?? [])
                <h3>Información desconocida</h3><ul>@foreach($offerScore->explanation['unknowns'] as $unknown)<li>{{ $unknown }}</li>@endforeach</ul>
            @endif
            @if($offerScore->explanation['contradictions'] ?? [])
                <h3>Contradicciones que requieren revisión</h3><ul>@foreach($offerScore->explanation['contradictions'] as $conflict)<li>{{ $conflict['reason'] }}</li>@endforeach</ul>
            @endif
            <h3>Evidencias utilizadas</h3>
            <ul>@forelse($offerScore->explanation['evidence'] ?? [] as $evidence)<li>{{ App\Models\Signal::label($evidence['type']) }} · {{ ['website'=>'Sitio oficial','google_maps'=>'Google Maps','osm'=>'OpenStreetMap','crm'=>'CRM','manual'=>'Revisión humana','import'=>'Importación','dns_http'=>'DNS y HTTP','dns'=>'DNS','http'=>'HTTP','system'=>'Sistema'][$evidence['source']] ?? $evidence['source'] }} · {{ $evidence['source_reference'] ?? 'Sin referencia' }}</li>@empty<li>Solo datos actuales de la ficha; no hay evidencia externa confirmada.</li>@endforelse</ul>
        </details>
    @empty
        <p class="muted">Las ofertas todavía no tienen una evaluación. Solicita una revisión del sitio desde las acciones de esta ficha.</p>
    @endforelse
</section>
