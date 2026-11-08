{{-- Atelier: full-bleed editorial hero, asymmetric category pair, statement band. --}}
@php
    $sf = storefront();
    $categories = $sf->featuredCategories(2);
@endphp

<section class="at-hero" x-data="slider({{ max(1, $banners->count()) }}, 7000)">
    @if ($banners->isEmpty())
        <div class="at-hero__slide at-hero__slide--plain">
            <div class="sf-container at-hero__copy">
                <h1 class="at-hero__title">{{ $sf->settings->get('tagline') ?: 'Considered pieces for every day.' }}</h1>
                <a href="{{ route('store.shop', ['sort' => 'newest']) }}" class="sf-btn sf-btn--lg">Discover the collection</a>
            </div>
        </div>
    @else
        <div class="at-hero__track" x-ref="track" @scroll.debounce.80ms="sync($el)">
            @foreach ($banners as $banner)
                <div class="at-hero__slide">
                    <img src="{{ $banner->image_url }}" alt="" class="at-hero__img" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif width="1800" height="1100">
                    <div class="sf-container at-hero__copy">
                        <{{ $loop->first ? 'h1' : 'h2' }} class="at-hero__title">{{ $banner->heading }}</{{ $loop->first ? 'h1' : 'h2' }}>
                        @if ($banner->subheading)<p class="at-hero__sub">{{ $banner->subheading }}</p>@endif
                        <a href="{{ $banner->link ?: route('store.shop') }}" class="at-hero__cta">{{ $banner->button_text ?: 'Discover' }}</a>
                    </div>
                </div>
            @endforeach
        </div>
        @if ($banners->count() > 1)
            <div class="at-hero__bars" aria-label="Choose slide">
                @foreach ($banners as $banner)
                    <button type="button" :class="index === {{ $loop->index }} && 'is-on'" @click="go({{ $loop->index }}); stop()" aria-label="Slide {{ $loop->iteration }}"></button>
                @endforeach
            </div>
        @endif
    @endif
</section>

@if ($categories->isNotEmpty())
    <section class="at-pair">
        <div class="sf-container at-pair__grid">
            @foreach ($categories as $cat)
                <a href="{{ route('store.category', $cat->slug) }}" class="at-pair__item">
                    <span class="at-pair__media">@if ($cat->cover)<img src="{{ $cat->cover }}" alt="" loading="lazy" width="900" height="1200">@endif</span>
                    <span class="at-pair__name">{{ $cat->name }}</span>
                    <span class="at-pair__link">Shop {{ \Illuminate\Support\Str::lower($cat->name) }}</span>
                </a>
            @endforeach
        </div>
    </section>
@endif

@include('store.partials.section', ['title' => 'New in', 'products' => $newArrivals, 'link' => route('store.shop', ['sort' => 'newest']), 'linkText' => 'View all new pieces', 'class' => 'at-section'])

<section class="at-statement">
    <div class="sf-container">
        <p class="at-statement__text">{{ $sf->settings->get('tagline') ?: 'Chosen with care, made to be worn often.' }}</p>
        <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--lg at-statement__btn">Shop the collection</a>
    </div>
</section>

@include('store.partials.section', ['title' => $onSale->isNotEmpty() ? 'Final pieces at reduced prices' : 'Most loved', 'products' => $onSale->isNotEmpty() ? $onSale->take(4) : $bestSellers->take(4), 'link' => $onSale->isNotEmpty() ? route('store.shop', ['sale' => 1]) : route('store.shop', ['sort' => 'bestselling']), 'class' => 'at-section'])

<section class="at-perks">
    <div class="sf-container at-perks__grid">
        @foreach ($sf->perks() as [$icon, $title, $text])
            <p><b>{{ $title }}</b><span>{{ $text }}</span></p>
        @endforeach
    </div>
</section>
