<dialog id="{{ $drawerId }}" class="drawer" aria-labelledby="{{ $drawerId }}-title" data-drawer>
    <div class="drawer-head"><div><span class="eyebrow">{{ $drawerEyebrow ?? 'Nuevo registro' }}</span><h2 id="{{ $drawerId }}-title">{{ $drawerTitle }}</h2></div><button type="button" class="icon-button" data-drawer-close aria-label="Cerrar formulario">@include('sales.partials.icon', ['name'=>'close'])</button></div>
    <div class="drawer-body">{{ $slot }}</div>
</dialog>
