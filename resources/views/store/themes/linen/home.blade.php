{{-- Linen: calm split hero, category tiles, product rows, service strip. --}}
@php
    $sf = storefront();
    $categories = $sf->featuredCategories(4);
@endphp

<section class="ln-hero" x-data="slider({{ max(1, $banners->count()) }})" @mouseenter="stop()" @mouseleave="count > 1 && play()">
    <div class="sf-container">
        @if ($banners->isEmpty())
            <div class="ln-hero__slide ln-hero__slide--plain">
                <div class="ln-hero__copy">
                    <h1 class="ln-hero__title">{{ $sf->settings->get('tagline') ?: 'New season, ready to wear.' }}</h1>
                    <p class="ln-hero__sub">Shop {{ $sf->name() }} online with delivery across Pakistan.</p>
                    <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--accent sf-btn--lg">Shop new arrivals</a>
                </div>
            </div>
        @else
            <div class="ln-hero__track" x-ref="track" @scroll.debounce.80ms="sync($el)">
                @foreach ($banners as $banner)
                    <div class="ln-hero__slide">
                        <div class="ln-hero__media"><img src="{{ $banner->image_url }}" alt="" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif width="1200" height="900"></div>
                        <div class="ln-hero__copy">
                            <{{ $loop->first ? 'h1' : 'h2' }} class="ln-hero__title">{{ $banner->heading }}</{{ $loop->first ? 'h1' : 'h2' }}>
                            @if ($banner->subheading)<p class="ln-hero__sub">{{ $banner->subheading }}</p>@endif
                            <a href="{{ $banner->link ?: route('store.shop') }}" class="sf-btn sf-btn--accent sf-btn--lg">{{ $banner->button_text ?: 'Shop now' }}</a>
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($banners->count() > 1)
                <div class="ln-hero__dots" role="tablist" aria-label="Choose slide">
                    @foreach ($banners as $banner)
                        <button type="button" :class="index === {{ $loop->index }} && 'is-on'" @click="go({{ $loop->index }})" aria-label="Slide {{ $loop->iteration }}"></button>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</section>

@if ($categories->isNotEmpty())
    <section class="sf-section ln-cats">
        <div class="sf-container">
            <div class="sf-section__head">
                <h2 class="sf-section__title">Shop by category</h2>
                <a href="{{ route('store.shop') }}" class="sf-section__link">Shop all</a>
            </div>
            <div class="ln-cats__row">
                @foreach ($categories as $cat)
                    <a href="{{ route('store.category', $cat->slug) }}" class="ln-cat">
                        <span class="ln-cat__media">@if ($cat->cover)<img src="{{ $cat->cover }}" alt="" loading="lazy" width="480" height="600">@endif</span>
                        <span class="ln-cat__name">{{ $cat->name }}</span>
                        <span class="ln-cat__count">{{ $cat->products_count }} {{ \Illuminate\Support\Str::plural('style', $cat->products_count) }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

@include('store.partials.section', ['title' => 'New arrivals', 'products' => $newArrivals, 'link' => route('store.shop', ['sort' => 'newest'])])

@if ($onSale->isNotEmpty())
    <div class="ln-band">
        @include('store.partials.section', ['title' => 'On sale now', 'products' => $onSale->take(4), 'link' => route('store.shop', ['sale' => 1]), 'linkText' => 'See every deal'])
    </div>
@endif

@include('store.partials.section', ['title' => 'Best sellers', 'products' => $bestSellers, 'link' => route('store.shop', ['sort' => 'bestselling'])])

<section class="ln-service">
    <div class="sf-container ln-service__grid">
        @foreach ($sf->perks() as [$icon, $title, $text])
            <div><x-store.icon :name="$icon" /><p><b>{{ $title }}</b><span>{{ $text }}</span></p></div>
        @endforeach
    </div>
</section>
