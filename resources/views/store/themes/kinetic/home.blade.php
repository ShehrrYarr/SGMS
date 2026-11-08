{{-- Kinetic: giant condensed headline over the drop photo, block category tiles, tight grids. --}}
@php
    $sf = storefront();
    $categories = $sf->featuredCategories(3);
    $banner = $banners->first();
@endphp

<section class="kn-hero">
    <div class="sf-container">
        <h1 class="kn-hero__title">{{ $banner?->heading ?: ($sf->settings->get('tagline') ?: 'New drop. Limited run.') }}</h1>
        <div class="kn-hero__stage">
            @if ($banner)
                <img src="{{ $banner->image_url }}" alt="" class="kn-hero__img" fetchpriority="high" width="1600" height="900">
            @endif
            <div class="kn-hero__panel">
                @if ($banner?->subheading)<p>{{ $banner->subheading }}</p>@else<p>Fresh styles land every week. When a size is gone, it's gone.</p>@endif
                <a href="{{ $banner?->link ?: route('store.shop', ['sort' => 'newest']) }}" class="sf-btn sf-btn--accent sf-btn--lg">{{ $banner?->button_text ?: 'Shop the drop' }}</a>
            </div>
        </div>
    </div>
</section>

@if ($banners->count() > 1)
    <section class="kn-promos">
        <div class="sf-container kn-promos__grid">
            @foreach ($banners->slice(1)->take(2) as $promo)
                <a href="{{ $promo->link ?: route('store.shop') }}" class="kn-promo">
                    <img src="{{ $promo->image_url }}" alt="" loading="lazy" width="900" height="600">
                    <span class="kn-promo__text"><b>{{ $promo->heading }}</b>@if ($promo->button_text)<span>{{ $promo->button_text }}</span>@endif</span>
                </a>
            @endforeach
        </div>
    </section>
@endif

@if ($categories->isNotEmpty())
    <section class="sf-section kn-cats">
        <div class="sf-container kn-cats__grid">
            @foreach ($categories as $cat)
                <a href="{{ route('store.category', $cat->slug) }}" class="kn-cat">
                    @if ($cat->cover)<img src="{{ $cat->cover }}" alt="" loading="lazy" width="600" height="600">@endif
                    <span class="kn-cat__name">{{ $cat->name }}</span>
                    <span class="kn-cat__count">{{ $cat->products_count }}</span>
                </a>
            @endforeach
        </div>
    </section>
@endif

@include('store.partials.section', ['title' => 'Latest drop', 'products' => $newArrivals, 'link' => route('store.shop', ['sort' => 'newest']), 'linkText' => 'Shop all new', 'class' => 'kn-section'])

@if ($onSale->isNotEmpty())
    <div class="kn-saleband">
        <div class="kn-saleband__ticker" aria-hidden="true"><div>@for ($i = 0; $i < 10; $i++)<span>Sale</span>@endfor</div></div>
        @include('store.partials.section', ['title' => 'Marked down', 'products' => $onSale->take(4), 'link' => route('store.shop', ['sale' => 1]), 'linkText' => 'Every deal', 'class' => 'kn-section'])
    </div>
@endif

@include('store.partials.section', ['title' => 'Most wanted', 'products' => $bestSellers, 'link' => route('store.shop', ['sort' => 'bestselling']), 'class' => 'kn-section'])
