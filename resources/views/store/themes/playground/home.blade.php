{{-- Playground: blob hero, bubble categories, wavy sunshine band. --}}
@php
    $sf = storefront();
    $categories = $sf->featuredCategories(6);
@endphp

<section class="pg-hero" x-data="slider({{ max(1, $banners->count()) }})">
    <div class="pg-hero__track" x-ref="track" @scroll.debounce.80ms="sync($el)">
        @forelse ($banners as $banner)
            <div class="pg-hero__slide">
                <div class="sf-container pg-hero__inner">
                    <div class="pg-hero__copy">
                        <{{ $loop->first ? 'h1' : 'h2' }} class="pg-hero__title">{{ $banner->heading }}</{{ $loop->first ? 'h1' : 'h2' }}>
                        @if ($banner->subheading)<p class="pg-hero__sub">{{ $banner->subheading }}</p>@endif
                        <a href="{{ $banner->link ?: route('store.shop') }}" class="sf-btn sf-btn--accent sf-btn--lg">{{ $banner->button_text ?: 'Let’s shop' }}</a>
                    </div>
                    <div class="pg-blob"><img src="{{ $banner->image_url }}" alt="" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif width="800" height="800"></div>
                </div>
            </div>
        @empty
            <div class="pg-hero__slide">
                <div class="sf-container pg-hero__inner pg-hero__inner--plain">
                    <div class="pg-hero__copy">
                        <h1 class="pg-hero__title">{{ $sf->settings->get('tagline') ?: 'Clothes made for play.' }}</h1>
                        <p class="pg-hero__sub">Soft, bright and built for climbing, running and growing.</p>
                        <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--accent sf-btn--lg">Let’s shop</a>
                    </div>
                </div>
            </div>
        @endforelse
    </div>
    @if ($banners->count() > 1)
        <div class="pg-hero__dots" aria-label="Choose slide">
            @foreach ($banners as $banner)
                <button type="button" :class="index === {{ $loop->index }} && 'is-on'" @click="go({{ $loop->index }}); stop()" aria-label="Slide {{ $loop->iteration }}"></button>
            @endforeach
        </div>
    @endif
</section>

@if ($categories->isNotEmpty())
    <section class="sf-section pg-cats">
        <div class="sf-container">
            <h2 class="sf-section__title pg-center">What are we shopping for?</h2>
            <div class="pg-cats__row">
                @foreach ($categories as $cat)
                    <a href="{{ route('store.category', $cat->slug) }}" class="pg-cat">
                        <span class="pg-cat__bubble">@if ($cat->cover)<img src="{{ $cat->cover }}" alt="" loading="lazy" width="300" height="300">@endif</span>
                        <span class="pg-cat__name">{{ $cat->name }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

@include('store.partials.section', ['title' => 'Just arrived', 'products' => $newArrivals, 'link' => route('store.shop', ['sort' => 'newest']), 'linkText' => 'See them all'])

<div class="pg-band">
    <svg class="pg-wave" viewBox="0 0 1440 40" preserveAspectRatio="none" aria-hidden="true"><path d="M0 40V20C120 0 240 0 360 20s240 20 360 0 240-20 360 0 240 20 360 0v20Z"/></svg>
    <div class="pg-band__inner">
        @include('store.partials.section', ['title' => $onSale->isNotEmpty() ? 'Big savings, small sizes' : 'Kids’ favourites', 'products' => ($onSale->isNotEmpty() ? $onSale : $bestSellers)->take(4), 'link' => $onSale->isNotEmpty() ? route('store.shop', ['sale' => 1]) : route('store.shop', ['sort' => 'bestselling'])])
    </div>
    <svg class="pg-wave pg-wave--flip" viewBox="0 0 1440 40" preserveAspectRatio="none" aria-hidden="true"><path d="M0 40V20C120 0 240 0 360 20s240 20 360 0 240-20 360 0 240 20 360 0v20Z"/></svg>
</div>

<section class="pg-perks">
    <div class="sf-container pg-perks__grid">
        @foreach ($sf->perks() as [$icon, $title, $text])
            <div><span class="pg-perks__icon"><x-store.icon :name="$icon" /></span><p><b>{{ $title }}</b><span>{{ $text }}</span></p></div>
        @endforeach
    </div>
</section>
