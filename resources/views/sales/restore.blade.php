@extends('layouts.app')
@section('content')
<section><h1>Restaurar respaldo</h1><p>Integridad verificada. Contiene {{ $summary['leads'] }} prospectos y {{ $summary['campaigns'] }} campañas.</p>
<p>La restauración reemplazará los datos actuales y conservará una copia de la base previa. Las tareas en curso deben haber terminado.</p>
<form method="post" action="{{ route('backups.restore') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><label>Escribí RESTAURAR para confirmar<input name="confirmation" required autocomplete="off"></label><button class="danger">Restaurar datos</button></form></section>
@endsection
