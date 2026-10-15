@extends('layouts.app')
@section('content')
<div class="page-head"><div><span class="eyebrow">Tu base de contactos</span><h1>Cartera</h1><p class="muted">Gestiona prospectos, clientes y colaboradores en una sola cartera.</p></div><div class="actions"><a class="button light" href="{{ route('imports.create') }}">@include('sales.partials.icon',['name'=>'upload']) Importar CSV</a><button type="button" data-drawer-open="lead-create">@include('sales.partials.icon',['name'=>'plus']) Nuevo registro</button></div></div>
<div class="actions" style="margin-bottom:18px"><a class="button light" href="{{ route('leads') }}">Todos</a>@foreach(['prospect'=>'Prospectos','customer'=>'Clientes','collaborator'=>'Colaboradores'] as $type=>$label)<a class="button light" href="{{ route('leads',['relationship_type'=>$type]) }}">{{ $label }}</a>@endforeach<a class="button light" href="{{ route('leads',['classification_pending'=>1]) }}">Pendientes de clasificación</a></div>
@include('sales.partials.lead-filters',['filterAction'=>route('leads')])
@include('sales.partials.lead-table',['tableTitle'=>'Registros de esta vista'])
@component('sales.partials.drawer',['drawerId'=>'lead-create','drawerTitle'=>'Añadir registro comercial','drawerEyebrow'=>'Nuevo contacto'])
<form method="post" action="{{ route('leads.store') }}" data-create-form>@csrf @include('sales.partials.lead-form',['editingLead'=>null])<div style="margin-top:18px">@include('sales.partials.campaign-select',['selectedCampaignId'=>request('campaign')])<p class="form-help">Podés vincularlo a una campaña ahora o hacerlo después.</p></div><div class="form-footer"><button type="button" class="secondary" data-drawer-close>Cancelar</button><button>Crear registro</button></div></form>
@endcomponent
@endsection
