<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Ventas' }}</title>
    <style>
        :root { color-scheme: light; --ink:#16202e; --muted:#617083; --line:#dbe1ea; --bg:#f5f7fa; --panel:#fff; --brand:#135f8f; --soft:#eef6fb; --warn:#8a5a00; --bad:#9f1239; --good:#166534; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: var(--ink); background: var(--bg); }
        header { background: #102033; color: white; border-bottom: 1px solid #0a1624; }
        .topbar { max-width: 1440px; margin: auto; padding: 14px 28px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .brand { font-weight: 700; color: white; text-decoration: none; letter-spacing: .02em; }
        nav { display: flex; gap: 8px; flex-wrap: wrap; }
        nav a { color: #dbeafe; padding: 8px 10px; border-radius: 6px; text-decoration: none; font-size: 14px; }
        nav a.active, nav a:hover { background: rgba(255,255,255,.12); color: white; }
        main { max-width: 1440px; margin: auto; padding: 24px 28px 44px; }
        h1, h2, h3 { margin: 0 0 10px; line-height: 1.2; }
        h1 { font-size: 26px; }
        h2 { font-size: 18px; }
        h3 { font-size: 15px; }
        p { margin: 0 0 10px; }
        a { color: var(--brand); }
        section, .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 18px; }
        .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap: 10px; }
        .kpi { background: #f9fafb; border: 1px solid var(--line); border-radius: 8px; padding: 12px; }
        .metric { font-size: 27px; font-weight: 750; }
        .muted { color: var(--muted); font-size: 13px; }
        .small { font-size: 12px; }
        table { width: 100%; border-collapse: collapse; background: white; }
        th, td { border-bottom: 1px solid #e7ebf1; padding: 9px; text-align: left; font-size: 14px; vertical-align: top; }
        th { color: #46566b; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; background: #f9fafb; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #c8d0dc; border-radius: 6px; background: white; color: var(--ink); }
        textarea { min-height: 80px; resize: vertical; }
        label { display: block; font-size: 13px; color: #3d4a5c; }
        button, .button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 12px; border: 0; border-radius: 6px; background: var(--brand); color: white; text-decoration: none; cursor: pointer; font-size: 14px; }
        .button.secondary, button.secondary { background: #e8edf3; color: #203044; }
        .button.danger, button.danger { background: var(--bad); color: white; }
        .button.light { background: white; color: var(--brand); border: 1px solid var(--line); }
        .status { padding: 10px 12px; background: #e8f4ec; border: 1px solid #badbcc; border-radius: 8px; margin-bottom: 12px; color: var(--good); }
        .error { padding: 10px 12px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; margin-bottom: 12px; color: var(--bad); }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 7px; border-radius: 999px; background: #eef2f7; color: #334155; font-size: 12px; white-space: nowrap; }
        .badge.good { background: #dcfce7; color: #166534; }
        .badge.warn { background: #fef3c7; color: var(--warn); }
        .badge.bad { background: #ffe4e6; color: var(--bad); }
        .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 14px 0; border-bottom: 1px solid var(--line); }
        .tabs a { padding: 10px 12px; text-decoration: none; color: #475569; border: 1px solid transparent; border-bottom: 0; border-radius: 8px 8px 0 0; }
        .tabs a.active { background: white; border-color: var(--line); color: var(--ink); margin-bottom: -1px; }
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; align-items: end; }
        .two-col { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(300px, .55fr); gap: 16px; align-items: start; }
        .kanban { display: grid; grid-template-columns: repeat(6, minmax(180px, 1fr)); gap: 10px; overflow-x: auto; }
        .kanban-col { min-height: 240px; background: #f9fafb; border: 1px solid var(--line); border-radius: 8px; padding: 10px; }
        .card { background: white; border: 1px solid var(--line); border-radius: 8px; padding: 10px; margin-bottom: 10px; }
        .timeline { border-left: 2px solid var(--line); padding-left: 14px; }
        .timeline-item { margin-bottom: 12px; position: relative; }
        .timeline-item:before { content: ""; width: 9px; height: 9px; border-radius: 50%; background: var(--brand); position: absolute; left: -20px; top: 5px; }
        .empty { padding: 24px; text-align: center; color: var(--muted); background: #f9fafb; border: 1px dashed var(--line); border-radius: 8px; }
        .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 18px 0 0; padding: 12px 0; }
        .pagination-summary { color: var(--muted); font-size: 13px; }
        .pagination-links { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .page-link { min-width: 36px; min-height: 36px; display: inline-flex; align-items: center; justify-content: center; padding: 7px 10px; border: 1px solid var(--line); border-radius: 6px; background: white; color: #203044; text-decoration: none; font-size: 14px; }
        .page-link:hover { border-color: var(--brand); color: var(--brand); }
        .page-link.active { background: var(--brand); border-color: var(--brand); color: white; font-weight: 700; }
        .page-link.disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
        @media (max-width: 900px) {
            .two-col { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; flex-direction: column; }
            main { padding: 18px; }
            .pagination { align-items: flex-start; flex-direction: column; }
            .pagination-links { justify-content: flex-start; }
        }
    </style>
</head>
<body>
<header>
    <div class="topbar">
        <a class="brand" href="/">Ventas</a>
        <nav>
            <a href="/" @class(['active' => request()->is('/') || request()->is('dashboard')])>Inicio</a>
            <a href="/campaigns" @class(['active' => request()->is('campaigns*')])>Campañas</a>
            <a href="/leads" @class(['active' => request()->is('leads*')])>Leads</a>
            <a href="/settings" @class(['active' => request()->is('settings') || request()->is('offers') || request()->is('templates') || request()->is('exclusions')])>Configuración</a>
        </nav>
    </div>
</header>
<main>
    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="error">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
