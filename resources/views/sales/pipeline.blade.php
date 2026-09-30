@extends('layouts.app')

@section('content')
<div class="page-head">
    <div>
        <div class="muted">
            @if ($campaign)
                <a href="/campaigns">Campañas</a> → <a href="/campaigns/{{ $campaign->id }}">{{ $campaign->name }}</a> → Pipeline
            @else
                Pipeline
            @endif
        </div>
        <h1>Oportunidades</h1>
        <p class="muted">Solo leads con respuesta o avance comercial.</p>
    </div>
</div>

<div class="kanban">
    @foreach ($columns as $column)
        <div class="kanban-col">
            <h2>{{ $column }}</h2>
            @forelse (($leadsByStatus[$column] ?? collect()) as $lead)
                <div class="card">
                    <strong><a href="/leads/{{ $lead->id }}">{{ $lead->name }}</a></strong>
                    <div class="muted">Score {{ $lead->score_total }} · {{ $lead->suggestedOffer?->name }}</div>
                    <form method="post" action="/leads/{{ $lead->id }}/status">
                        @csrf
                        <label>Mover a
                            <select name="status">
                                @foreach ($columns as $status)
                                    <option value="{{ $status }}" @selected($lead->status === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                        </label>
                        <input type="hidden" name="reason" value="kanban_move">
                        <button class="secondary">Mover</button>
                    </form>
                </div>
            @empty
                <div class="empty">Sin oportunidades.</div>
            @endforelse
        </div>
    @endforeach
</div>
@endsection
