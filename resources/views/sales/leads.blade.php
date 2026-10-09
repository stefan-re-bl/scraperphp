@extends('layouts.app')
@section('content')
<div class="page-head"><div><span class="eyebrow">Tu base de contactos</span><h1>Prospectos</h1><p class="muted">Encontrá el contacto adecuado y decidí la próxima acción.</p></div><div class="actions"><a class="button light" href="{{ route('imports.create') }}">@include('sales.partials.icon',['name'=>'upload']) Importar CSV</a><button type="button" data-drawer-open="lead-create">@include('sales.partials.icon',['name'=>'plus']) Nuevo prospecto</button></div></div>
@include('sales.partials.lead-filters',['filterAction'=>route('leads')])
@include('sales.partials.lead-table',['tableTitle'=>'Prospectos de esta vista'])
@component('sales.partials.drawer',['drawerId'=>'lead-create','drawerTitle'=>'Agregar prospecto','drawerEyebrow'=>'Nuevo contacto'])
<form method="post" action="{{ route('leads.store') }}" data-create-form>@csrf @include('sales.partials.lead-form',['editingLead'=>null])<div style="margin-top:18px">@include('sales.partials.campaign-select',['selectedCampaignId'=>request('campaign')])<p class="form-help">Podés vincularlo a una campaña ahora o hacerlo después.</p></div><div class="form-footer"><button type="button" class="secondary" data-drawer-close>Cancelar</button><button>Crear prospecto</button></div></form>
@endcomponent
@endsection
