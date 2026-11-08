@if ($paginator->hasPages())
    <nav class="sf-pager" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="sf-pager__btn is-disabled" aria-hidden="true"><x-store.icon name="chevron-left" /></span>
        @else
            <a class="sf-pager__btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page"><x-store.icon name="chevron-left" /></a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="sf-pager__gap">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="sf-pager__btn is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="sf-pager__btn" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="sf-pager__btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page"><x-store.icon name="chevron-right" /></a>
        @else
            <span class="sf-pager__btn is-disabled" aria-hidden="true"><x-store.icon name="chevron-right" /></span>
        @endif
    </nav>
@endif
