@if ($paginator->total() > 0)
    <div class="infinite-scroll-footer" data-infinite-pagination data-total="{{ $paginator->total() }}">
        <p class="muted" data-infinite-status role="status" aria-live="polite">{{ $paginator->count() }} de {{ $paginator->total() }} registros cargados{{ $paginator->hasMorePages() ? '' : ' · Fin de la lista' }}</p>
        @if ($paginator->hasMorePages())
            <a class="button light" href="{{ $paginator->nextPageUrl() }}" rel="next" data-infinite-next>Cargar más</a>
        @endif
    </div>
@endif
