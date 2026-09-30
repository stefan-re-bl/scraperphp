@extends('layouts.app')

@section('content')
<section>
    <h1>Métricas segmentadas</h1>
    <form method="get" action="/metrics">
        <label>Dimensión
            <select name="dimension">
                @foreach (['campaign','channel','offer','segment','city','country','source','language','variant'] as $item)
                    <option value="{{ $item }}" @selected($dimension === $item)>{{ $item }}</option>
                @endforeach
            </select>
        </label>
        <button>Ver</button>
    </form>
</section>

<section>
    <table>
        <tr><th>Grupo</th><th>Contactos</th><th>Respuestas</th><th>Positivas</th><th>Interés</th><th>Propuestas</th><th>Cierres</th><th>Reply %</th><th>Interest %</th><th>Proposal %</th><th>Close %</th></tr>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td>{{ $row['contacts'] }}</td>
                <td>{{ $row['replies'] }}</td>
                <td>{{ $row['positive_replies'] }}</td>
                <td>{{ $row['interested'] }}</td>
                <td>{{ $row['proposals'] }}</td>
                <td>{{ $row['won'] }}</td>
                <td>{{ $row['reply_rate'] }}</td>
                <td>{{ $row['interest_rate'] }}</td>
                <td>{{ $row['proposal_rate'] }}</td>
                <td>{{ $row['close_rate'] }}</td>
            </tr>
        @endforeach
    </table>
</section>
@endsection

