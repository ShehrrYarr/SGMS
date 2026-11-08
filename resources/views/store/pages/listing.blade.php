@extends('layouts.store')

@php
    $sf = storefront();
    $heading = $category?->name ?? (! empty($filters['q']) ? 'Results for “'.$filters['q'].'”' : (! empty($filters['sale']) ? 'Sale' : 'Shop all'));
    $baseUrl = $category ? route('store.category', $category->slug) : route('store.shop');
    $sizes = array_map('intval', (array) ($filters['sizes'] ?? []));
    $colors = array_map('intval', (array) ($filters['colors'] ?? []));

    // Active filters as removable pills.
    $without = function (string $key, $value = null) use ($filters) {
        $query = collect($filters)->except(['category'])->filter(fn ($v) => $v !== null && $v !== '' && $v !== []);
        if ($value === null) {
            $query->forget($key);
        } else {
            $query[$key] = array_values(array_diff((array) $query->get($key, []), [(string) $value, (int) $value]));
        }
        return $query->filter(fn ($v) => $v !== [])->all();
    };
    $pills = [];
    foreach ($facets['sizes']->whereIn('id', $sizes) as $s) { $pills[] = ['Size '.$s->code, $without('sizes', $s->id)]; }
    foreach ($facets['colors']->whereIn('id', $colors) as $c) { $pills[] = [$c->name, $without('colors', $c->id)]; }
    if (is_numeric($filters['min'] ?? null) || is_numeric($filters['max'] ?? null)) {
        $pills[] = [trim(($filters['min'] ?? '') !== '' ? $sf->price($filters['min']) : '').' – '.(($filters['max'] ?? '') !== '' ? $sf->price($filters['max']) : 'any'), array_diff_key($without('min'), ['max' => 1])];
    }
    if (! empty($filters['sale'])) { $pills[] = ['On sale', $without('sale')]; }
    if (! empty($filters['in_stock'])) { $pills[] = ['In stock', $without('in_stock')]; }
    if (! empty($filters['q'])) { $pills[] = ['“'.$filters['q'].'”', $without('q')]; }
    $filterCount = count($pills);
@endphp

@section('title', $heading)
@section('description', $category ? 'Shop '.$category->name.' at '.$sf->name().'.' : 'Browse every product at '.$sf->name().'.')

@section('content')
<div class="sf-container sf-listing-page" x-data="{ filters: false }">
    <nav class="sf-crumbs" aria-label="Breadcrumb">
        <a href="{{ route('store.home') }}">Home</a>
        <x-store.icon name="chevron-right" />
        @if ($category)
            <a href="{{ route('store.shop') }}">Shop</a>
            <x-store.icon name="chevron-right" />
        @endif
        <span aria-current="page">{{ $category?->name ?? 'Shop' }}</span>
    </nav>

    <header class="sf-listing__head">
        <h1 class="sf-page-title">{{ $heading }}</h1>
        <p class="sf-muted">
            @if ($products->total())
                Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }}
            @endif
        </p>
    </header>

    @if (! $category && $facets['categories']->count() > 1)
        <nav class="sf-chips" aria-label="Categories">
            <a href="{{ route('store.shop', array_filter(['sale' => $filters['sale'] ?? null])) }}" class="sf-chip is-on">All</a>
            @foreach ($facets['categories'] as $cat)
                <a href="{{ route('store.category', $cat->slug) }}" class="sf-chip">{{ $cat->name }}</a>
            @endforeach
        </nav>
    @endif

    <div class="sf-listing">
        {{-- Filters: sidebar on desktop, drawer on mobile --}}
        <form action="{{ $baseUrl }}" method="get" id="sf-filters" class="sf-filters" :class="filters && 'is-open'" aria-label="Filters">
            <div class="sf-filters__head">
                <h2>Filters</h2>
                <button type="button" class="sf-iconbtn" @click="filters = false; document.body.classList.remove('sf-locked')" aria-label="Close filters"><x-store.icon name="close" /></button>
            </div>
            <div class="sf-filters__body">
                @if (! empty($filters['q']))<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif
                <input type="hidden" name="sort" value="{{ $filters['sort'] ?? '' }}">

                @if ($category && $facets['categories']->count() > 1)
                    <details class="sf-filter" open>
                        <summary>Category</summary>
                        <div class="sf-filter__links">
                            @foreach ($facets['categories'] as $cat)
                                <a href="{{ route('store.category', $cat->slug) }}" @class(['is-on' => $cat->id === $category->id])>{{ $cat->name }} <span>{{ $cat->products_count }}</span></a>
                            @endforeach
                        </div>
                    </details>
                @endif

                @if ($facets['sizes']->isNotEmpty())
                    <details class="sf-filter" open>
                        <summary>Size</summary>
                        <div class="sf-filter__sizes">
                            @foreach ($facets['sizes'] as $size)
                                <label class="sf-size sf-size--check">
                                    <input type="checkbox" name="sizes[]" value="{{ $size->id }}" @checked(in_array($size->id, $sizes, true))>
                                    <span>{{ $size->code }}</span>
                                </label>
                            @endforeach
                        </div>
                    </details>
                @endif

                @if ($facets['colors']->isNotEmpty())
                    <details class="sf-filter" open>
                        <summary>Colour</summary>
                        <div class="sf-filter__colors">
                            @foreach ($facets['colors'] as $color)
                                <label class="sf-colorcheck">
                                    <input type="checkbox" name="colors[]" value="{{ $color->id }}" @checked(in_array($color->id, $colors, true))>
                                    <span class="sf-dot" style="--dot: {{ $color->hex_code }}"></span>
                                    <span>{{ $color->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </details>
                @endif

                <details class="sf-filter" open>
                    <summary>Price</summary>
                    <div class="sf-filter__price">
                        <label><span class="sf-visually-hidden">Minimum price</span>
                            <input type="number" name="min" inputmode="numeric" min="0" step="1" placeholder="{{ $facets['price']['min'] }}" value="{{ $filters['min'] ?? '' }}" class="sf-input">
                        </label>
                        <span aria-hidden="true">–</span>
                        <label><span class="sf-visually-hidden">Maximum price</span>
                            <input type="number" name="max" inputmode="numeric" min="0" step="1" placeholder="{{ $facets['price']['max'] }}" value="{{ $filters['max'] ?? '' }}" class="sf-input">
                        </label>
                    </div>
                </details>

                <details class="sf-filter" open>
                    <summary>Availability</summary>
                    <label class="sf-check"><input type="checkbox" name="in_stock" value="1" @checked(! empty($filters['in_stock']))> In stock only</label>
                    <label class="sf-check"><input type="checkbox" name="sale" value="1" @checked(! empty($filters['sale']))> On sale</label>
                </details>
            </div>
            <div class="sf-filters__foot">
                <a href="{{ $baseUrl }}" class="sf-btn sf-btn--ghost">Clear all</a>
                <button type="submit" class="sf-btn sf-btn--accent">Show results</button>
            </div>
        </form>

        <div class="sf-listing__main">
            <div class="sf-toolbar">
                <button type="button" class="sf-btn sf-btn--ghost sf-toolbar__filter" @click="filters = true; document.body.classList.add('sf-locked')">
                    <x-store.icon name="filter" /> Filters @if ($filterCount)<span class="sf-count">{{ $filterCount }}</span>@endif
                </button>
                <form action="{{ $baseUrl }}" method="get" class="sf-sort">
                    @foreach (collect($filters)->except(['sort', 'category']) as $key => $value)
                        @foreach ((array) $value as $v)
                            @if ($v !== null && $v !== '')<input type="hidden" name="{{ is_array($value) ? $key.'[]' : $key }}" value="{{ $v }}">@endif
                        @endforeach
                    @endforeach
                    <label for="sf-sort-select">Sort by</label>
                    <select id="sf-sort-select" name="sort" class="sf-select" onchange="this.form.submit()">
                        @foreach ($sorts as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['sort'] ?? 'featured') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="sf-btn sf-btn--ghost">Sort</button></noscript>
                </form>
            </div>

            @if ($pills)
                <div class="sf-pills" aria-label="Active filters">
                    @foreach ($pills as [$label, $query])
                        <a href="{{ $baseUrl.($query ? '?'.http_build_query($query) : '') }}" class="sf-pill">{{ $label }} <x-store.icon name="close" /></a>
                    @endforeach
                    <a href="{{ $baseUrl }}" class="sf-pill sf-pill--clear">Clear all</a>
                </div>
            @endif

            @if ($products->isEmpty())
                <div class="sf-empty">
                    <x-store.icon name="search" class="sf-empty__icon" />
                    <p class="sf-empty__title">No products match {{ $filterCount ? 'these filters' : 'your search' }}</p>
                    <p class="sf-muted">Try removing a filter or searching for something broader.</p>
                    <a href="{{ $baseUrl }}" class="sf-btn sf-btn--accent">Clear all filters</a>
                </div>
            @else
                <div class="sf-grid sf-grid--listing">
                    @foreach ($products as $product)
                        @include($sf->view('card'), ['product' => $product, 'eager' => $loop->index < 4])
                    @endforeach
                </div>
                {{ $products->links('store.partials.pagination') }}
            @endif
        </div>
    </div>
    <div class="sf-overlay sf-overlay--filters" x-show="filters" x-cloak x-transition.opacity @click="filters = false; document.body.classList.remove('sf-locked')"></div>
</div>
@endsection
