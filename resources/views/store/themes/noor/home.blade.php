{{-- Noor: arch-framed hero on blush, arch category tiles, emerald festive band. --}}
@php
    $sf = storefront();
    $categories = $sf->featuredCategories(4);
    $festive = $onSale->isNotEmpty() ? $onSale : $bestSellers;
@endphp

<section class="nr-hero" x-data="slider({{ max(1, $banners->count()) }})">
    <div class="nr-hero__track" x-ref="track" @scroll.debounce.80ms="sync($el)">
        @forelse ($banners as $banner)
            <div class="nr-hero__slide">
                <div class="sf-container nr-hero__inner">
                    <div class="nr-hero__copy">
                        <{{ $loop->first ? 'h1' : 'h2' }} class="nr-hero__title">{{ $banner->heading }}</{{ $loop->first ? 'h1' : 'h2' }}>
                        @if ($banner->subheading)<p class="nr-hero__sub">{{ $banner->subheading }}</p>@endif
                        <a href="{{ $banner->link ?: route('store.shop') }}" class="sf-btn sf-btn--accent sf-btn--lg">{{ $banner->button_text ?: 'Shop the collection' }}</a>
                    </div>
                    <div class="nr-arch">
                        <img src="{{ $banner->image_url }}" alt="" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif width="800" height="1000">
                    </div>
                </div>
            </div>
        @empty
            <div class="nr-hero__slide">
                <div class="sf-container nr-hero__inner nr-hero__inner--plain">
                    <div class="nr-hero__copy">
                        <h1 class="nr-hero__title">{{ $sf->settings->get('tagline') ?: 'Festive and everyday wear, beautifully made.' }}</h1>
                        <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--accent sf-btn--lg">Shop the collection</a>
                    </div>
                </div>
            </div>
        @endforelse
    </div>
    @if ($banners->count() > 1)
        <div class="nr-hero__dots" aria-label="Choose slide">
            @foreach ($banners as $banner)
                <button type="button" :class="index === {{ $loop->index }} && 'is-on'" @click="go({{ $loop->index }}); stop()" aria-label="Slide {{ $loop->iteration }}"></button>
            @endforeach
        </div>
    @endif
</section>

@if ($categories->isNotEmpty())
    <section class="sf-section nr-cats">
        <div class="sf-container">
            <h2 class="sf-section__title nr-center-title">Shop by collection</h2>
            <div class="nr-cats__row">
                @foreach ($categories as $cat)
                    <a href="{{ route('store.category', $cat->slug) }}" class="nr-cat">
                        <span class="nr-cat__arch">@if ($cat->cover)<img src="{{ $cat->cover }}" alt="" loading="lazy" width="480" height="640">@endif</span>
                        <span class="nr-cat__name">{{ $cat->name }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

@include('store.partials.section', ['title' => 'New arrivals', 'products' => $newArrivals, 'link' => route('store.shop', ['sort' => 'newest'])])

@if ($festive->isNotEmpty())
    <div class="nr-band">
        @include('store.partials.section', ['title' => $onSale->isNotEmpty() ? 'Festive offers' : 'Customer favourites', 'products' => $festive->take(4), 'link' => $onSale->isNotEmpty() ? route('store.shop', ['sale' => 1]) : route('store.shop', ['sort' => 'bestselling'])])
    </div>
@endif

<section class="nr-perks">
    <div class="sf-container nr-perks__grid">
        @foreach ($sf->perks() as [$icon, $title, $text])
            <div><span class="nr-perks__icon"><x-store.icon :name="$icon" /></span><b>{{ $title }}</b><span>{{ $text }}</span></div>
        @endforeach
    </div>
</section>
