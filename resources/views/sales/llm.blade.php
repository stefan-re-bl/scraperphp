@extends('layouts.app')

@section('content')
<section>
    <h1>LLM Bridge</h1>
    <form method="post" action="/llm-bridge/prompts">
        @csrf
        <label>Caso de uso<input name="use_case" value="pain_hypothesis" required></label>
        <div class="grid">
            @foreach ($leads as $lead)
                <label><input type="checkbox" name="lead_ids[]" value="{{ $lead->id }}"> {{ $lead->name }} · {{ $lead->score_total }}</label>
            @endforeach
        </div>
        <button>Crear prompt</button>
    </form>
</section>

<section>
    <h2>Importar JSON</h2>
    <form method="post" action="/llm-bridge/import">
        @csrf
        <label>Run
            <select name="llm_bridge_run_id">
                @foreach ($runs as $run)
                    <option value="{{ $run->id }}">#{{ $run->id }} {{ $run->use_case }} {{ $run->status }}</option>
                @endforeach
            </select>
        </label>
        <label>JSON<textarea name="json"></textarea></label>
        <button>Importar</button>
    </form>
</section>

<section>
    <h2>Prompts</h2>
    @foreach ($runs as $run)
        <div class="panel">
            <strong>#{{ $run->id }} {{ $run->use_case }} {{ $run->status }}</strong>
            <textarea readonly>{{ $run->prompt }}</textarea>
        </div>
    @endforeach
</section>
@endsection

