@php
    $navigation = [
        ['dashboard', 'Inicio', 'home', request()->is('/') || request()->is('dashboard')],
        ['leads', 'Cartera', 'people', request()->is('leads*')],
        ['queue', 'Cola de trabajo', 'queue', request()->is('contact-queue')],
        ['pipeline', 'Oportunidades', 'pipeline', request()->is('pipeline') || request()->is('campaigns/*/pipeline')],
        ['campaigns', 'Campañas', 'campaign', request()->is('campaigns*') && !request()->is('campaigns/*/pipeline')],
        ['replies', 'Respuestas', 'reply', request()->is('replies*')],
        ['services', 'Servicios', 'calendar', request()->is('services*')],
        ['proposals', 'Propuestas', 'proposal', request()->is('proposals*')],
    ];
    $administration = [
        ['metrics.segmented', 'Métricas', 'chart', request()->is('metrics')],
        ['imports.create', 'Importar datos', 'upload', request()->is('imports*') || request()->is('scraper*')],
        ['settings', 'Configuración', 'settings', request()->is('settings*', 'offers*', 'templates*', 'exclusions*', 'scoring*', 'exports*', 'llm-bridge*', 'experiments*')],
    ];
    $currentPage = collect(array_merge($navigation, $administration))->first(fn (array $entry): bool => $entry[3]);
@endphp
<!doctype html>
<html lang="{{ $preferences['language'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2563eb">
    <title>{{ $title ?? ($currentPage[1] ?? 'Ventas') }} · Ventas</title>
    <link rel="stylesheet" href="{{ asset('sales.css') }}?v={{ filemtime(public_path('sales.css')) }}">
    <script src="{{ asset('sales.js') }}?v={{ filemtime(public_path('sales.js')) }}" defer></script>
    <noscript><style>.drawer:not([open]){display:block;position:static;width:100%;max-width:none;height:auto;margin:24px 0;box-shadow:none}.sidebar{position:static;transform:none}.app-shell{display:block}.nav-toggle{display:none}</style></noscript>
</head>
<body data-timezone="{{ $preferences['timezone'] }}">
<a href="#main-content" class="skip-link">Ir al contenido</a>
<div class="app-shell">
    <aside class="sidebar" id="app-sidebar" aria-label="Navegación principal">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">@include('sales.partials.icon', ['name'=>'pipeline'])</span><span>Ventas<small>Tu espacio comercial</small></span></a>
        <button type="button" class="icon-button sidebar-close" data-nav-close aria-label="Cerrar navegación">@include('sales.partials.icon', ['name'=>'close'])</button>
        <nav aria-label="Trabajo diario"><span class="nav-caption">Trabajo diario</span>
            @foreach ($navigation as [$destination, $label, $navIcon, $isActive])
                <a href="{{ route($destination) }}" @class(['nav-link', 'active'=>$isActive]) @if($isActive) aria-current="page" @endif>@include('sales.partials.icon', ['name'=>$navIcon])<span>{{ $label }}</span></a>
            @endforeach
        </nav>
        <nav aria-label="Organización"><span class="nav-caption">Organización</span>
            @foreach ($administration as [$destination, $label, $navIcon, $isActive])
                <a href="{{ route($destination) }}" @class(['nav-link', 'active'=>$isActive]) @if($isActive) aria-current="page" @endif>@include('sales.partials.icon', ['name'=>$navIcon])<span>{{ $label }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-foot"><span class="sidebar-foot-icon">@include('sales.partials.icon', ['name'=>'check'])</span><p>Todo en un lugar<small>Contactos, actividad y oportunidades.</small></p></div>
    </aside>
    <div class="nav-backdrop" data-nav-close hidden></div>
    <div class="app-body">
        <header class="app-header">
            <div class="header-location"><button type="button" class="icon-button nav-toggle" data-nav-toggle aria-controls="app-sidebar" aria-expanded="false" aria-label="Abrir navegación">@include('sales.partials.icon', ['name'=>'menu'])</button><span class="header-section">Espacio de trabajo</span><span class="header-divider">/</span><strong>{{ $currentPage[1] ?? 'Ventas' }}</strong></div>
            <form method="get" action="{{ route('leads') }}" class="global-search" role="search">@include('sales.partials.icon', ['name'=>'search'])<input name="q" aria-label="Buscar en la cartera" placeholder="Buscar en la cartera…" value="{{ request()->routeIs('leads') ? request('q') : '' }}"><button type="submit" class="icon-button" aria-label="Buscar">@include('sales.partials.icon', ['name'=>'arrow'])</button></form>
            <span class="header-date">@include('sales.partials.icon', ['name'=>'calendar']){{ now()->timezone($preferences['timezone'])->format('d/m/Y') }}</span>
        </header>
        <main id="main-content" class="main-content" tabindex="-1">
            @if (session('status'))<div class="status app-notice" data-operation-success role="status">@include('sales.partials.icon', ['name'=>'check'])<span>{{ session('status') }}</span><button type="button" class="icon-button" data-notice-dismiss aria-label="Cerrar aviso">@include('sales.partials.icon', ['name'=>'close'])</button></div>@endif
            @if ($errors->any())<div class="error app-notice" role="alert" tabindex="-1" data-error-summary>@include('sales.partials.icon', ['name'=>'warning'])<div><strong>Revisá los datos para continuar</strong>@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div></div>@endif
            <script type="application/json" id="form-old-input">@json(session()->getOldInput())</script>
            <script type="application/json" id="field-errors">@json($errors->messages())</script>
            @yield('content')
        </main>
    </div>
</div>
<div data-toast hidden class="toast" role="status" aria-live="polite"></div>
</body>
</html>
