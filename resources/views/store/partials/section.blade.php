{{-- Product row on the home page. Expects $title, $products; optional $link, $linkText, $class. --}}
@if ($products->isNotEmpty())
    <section class="sf-section {{ $class ?? '' }}">
        <div class="sf-container">
            <div class="sf-section__head">
                <h2 class="sf-section__title">{{ $title }}</h2>
                @isset($link)<a href="{{ $link }}" class="sf-section__link">{{ $linkText ?? 'View all' }}</a>@endisset
            </div>
            <div class="sf-grid">
                @foreach ($products as $product)
                    @include(storefront()->view('card'), ['product' => $product])
                @endforeach
            </div>
        </div>
    </section>
@endif
