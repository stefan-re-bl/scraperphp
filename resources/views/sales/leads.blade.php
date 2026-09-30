@extends('layouts.app')

@section('content')
<div class="page-head">
    <div>
        <h1>Leads</h1>
        <p class="muted">Revisa, filtra, selecciona y exporta prospectos.</p>
    </div>
    <div class="actions">
        <a class="button" href="/campaigns">Ir a campañas</a>
        <a class="button secondary" href="/contact-queue">Abrir cola</a>
    </div>
</div>

@include('sales.partials.lead-filters', [
    'filterAction' => '/leads',
])

@include('sales.partials.lead-table', [
    'tableTitle' => 'Leads filtrados',
])
@endsection
