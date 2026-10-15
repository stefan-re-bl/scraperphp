@extends('layouts.app')

@section('content')
<section>
    <h1>Exclusiones</h1>
    <form method="post" action="/exclusions">
        @csrf
        <div class="grid">
            <label>Tipo
                <select name="type">
                    <option value="email">email</option>
                    <option value="domain">domain</option>
                    <option value="phone">phone</option>
                    <option value="name">name</option>
                    <option value="source_identifier">source_identifier</option>
                </select>
            </label>
            <label>Valor<input name="value" required></label>
            <label>Motivo<input name="reason"></label>
        </div>
        <button>Guardar</button>
    </form>
</section>

<section data-infinite-list="exclusions">
    <table>
        <thead><tr><th>Tipo</th><th>Valor</th><th>Motivo</th><th></th></tr></thead><tbody data-infinite-items>
        @foreach ($exclusions as $exclusion)
            <tr data-infinite-item="{{ $exclusion->id }}">
                <td>{{ $exclusion->type }}</td>
                <td>{{ $exclusion->value }}</td>
                <td>{{ $exclusion->reason }}</td>
                <td>
                    <form method="post" action="/exclusions/{{ $exclusion->id }}">
                        @csrf
                        @method('delete')
                        <button>Quitar</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </tbody></table>
    {{ $exclusions->links() }}
</section>
@endsection
