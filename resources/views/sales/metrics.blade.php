@extends('layouts.app')
@section('content')
<section><h1>Métricas segmentadas</h1><p class="muted">Solo contactos realizados. Cada negocio cuenta una vez por grupo; los intentos se muestran por separado. El período selecciona la cohorte de negocios contactados y sus resultados posteriores.</p>
<form method="get" action="{{ route('metrics.segmented') }}" class="grid"><label>Dimensión<select name="dimension">@foreach (['campaign'=>'Campaña','channel'=>'Canal','offer'=>'Oferta','segment'=>'Segmento','city'=>'Ciudad','country'=>'País','source'=>'Fuente','language'=>'Idioma','variant'=>'Variante'] as $key=>$label)<option value="{{ $key }}" @selected($dimension===$key)>{{ $label }}</option>@endforeach</select></label><label>Desde<input type="date" name="from" value="{{ request('from') }}"></label><label>Hasta<input type="date" name="to" value="{{ request('to') }}"></label><button>Ver</button></form></section>
<section>@include('sales.partials.metric-table')</section>
@endsection
