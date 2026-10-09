@extends('layouts.app')
@section('content')
<div class="page-head"><div><span class="eyebrow">Confirmá el contenido del archivo</span><h1>Revisar importación</h1><p class="muted">Asigná las columnas y revisá los ejemplos antes de incorporar los datos.</p></div></div>
<div class="import-steps"><span class="import-step"><b>1</b> Elegir archivo</span><span class="import-connector"></span><span class="import-step active"><b>2</b> Revisar columnas</span><span class="import-connector"></span><span class="import-step"><b>3</b> Incorporar datos</span></div>
<section><h2>Asignación de columnas</h2><p class="muted">Relacioná cada dato con la columna del archivo que lo contiene.</p>
<form method="post" action="{{ route('imports.confirm') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><div class="grid">
@foreach ($fields as $field)<label>{{ ['name'=>'Nombre','category'=>'Categoría','location'=>'Dirección','city'=>'Ciudad','website'=>'Sitio','email'=>'Email','phone'=>'Teléfono','place_id'=>'Identificador de fuente','contact_person'=>'Persona de contacto','contact_role'=>'Cargo','tags'=>'Etiquetas'][$field] }}
<select name="mapping[{{ $field }}]"><option value="">Detectar automáticamente</option>@foreach ($preview['headers'] as $header)<option value="{{ $header }}" @selected($header === $field)>{{ $header }}</option>@endforeach</select></label>@endforeach</div><div class="form-footer"><button>Confirmar importación @include('sales.partials.icon',['name'=>'check'])</button></div></form></section>
<section><div class="section-head"><h2>Primeras filas del archivo</h2><span class="muted">Vista previa</span></div><div class="table-wrap"><table><thead><tr>@foreach ($preview['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@foreach ($preview['rows'] as $row)<tr>@foreach ($preview['headers'] as $header)<td>{{ $row[$header] ?? '' }}</td>@endforeach</tr>@endforeach</tbody></table></div></section>
@endsection
