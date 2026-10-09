@extends('layouts.app')
@section('content')
<section><h1>Reglas de prioridad</h1><p>La prioridad suma calidad de datos, posibilidad de contacto y encaje. Las reglas de una oferta reemplazan sus equivalentes generales; una regla desactivada aporta cero.</p>
<form method="post" action="{{ route('scoring.store') }}">@csrf @include('sales.partials.scoring-form', ['editingRule' => null])<button>Agregar regla</button></form></section>
@foreach ($rules as $rule)<section><details><summary>{{ $rule->description }} · {{ $rule->offer?->name ?? 'General' }} · {{ $rule->active ? $rule->points * $rule->weight.' puntos' : 'Desactivada' }}</summary>
<form method="post" action="{{ route('scoring.update', $rule) }}">@csrf @method('PUT') @include('sales.partials.scoring-form', ['editingRule' => $rule])<button>Guardar regla</button></form></details></section>@endforeach
@endsection
