<div class="empty">
    <span class="empty-icon">@include('sales.partials.icon', ['name' => $emptyIcon ?? 'people'])</span>
    <h3>{{ $emptyTitle ?? 'Todavía no hay registros' }}</h3>
    <p>{{ $emptyText ?? 'Los registros que agregues aparecerán acá.' }}</p>
    @if (!empty($emptyAction ?? null))<a class="button" href="{{ $emptyUrl }}">{{ $emptyAction }} @include('sales.partials.icon', ['name'=>'arrow'])</a>@endif
    @if (!empty($emptyDrawer ?? null))<button type="button" data-drawer-open="{{ $emptyDrawer }}">{{ $emptyButton ?? 'Agregar registro' }} @include('sales.partials.icon', ['name'=>'plus'])</button>@endif
</div>
