{{-- Product card. Expects $product from StoreCatalog::cards(); optional $eager (above the fold). --}}
@php
    $sf = storefront();
    $min = (float) $product->min_price;
    $max = (float) $product->max_price;
    $was = (float) $product->compare_at_price;
    $off = $product->discountPercent($min);
    $soldOut = (int) $product->available <= 0;
    $low = ! $soldOut && (int) $product->available <= 3;
    $colors = $product->variants->pluck('color')->filter()->unique('id')->values();
    $image = $sf->image($product);
    $hover = $product->colorImages->pluck('url')->first(fn ($url) => $url !== $image);
    $isNew = $product->created_at?->gt(now()->subDays(21));
    $url = route('store.product', $product->slug);
@endphp
<article @class(['sf-card', 'is-soldout' => $soldOut])>
    <a href="{{ $url }}" class="sf-card__media" tabindex="-1" aria-hidden="true">
        @if ($image)
            <img src="{{ $image }}" alt="" class="sf-card__img" loading="{{ ! empty($eager) ? 'eager' : 'lazy' }}" decoding="async" width="600" height="750">
            @if ($hover)<img src="{{ $hover }}" alt="" class="sf-card__img sf-card__img--alt" loading="lazy" decoding="async" width="600" height="750">@endif
        @else
            <span class="sf-card__placeholder"><x-store.icon name="bag" /></span>
        @endif
        @if ($soldOut)
            <span class="sf-tag sf-tag--soldout">Sold out</span>
        @elseif ($off)
            <span class="sf-tag sf-tag--sale">-{{ $off }}%</span>
        @elseif ($isNew)
            <span class="sf-tag">New</span>
        @endif
    </a>
    <div class="sf-card__wish" x-data="wish(@js($sf->wishlist()->has($product->id)))">
        <button type="button" class="sf-heart" :class="saved && 'is-on'" :aria-pressed="saved.toString()"
                @click="toggle(@js(route('store.wishlist.toggle', $product->id)))" aria-label="Save {{ $product->name }} to wishlist">
            <x-store.icon name="heart" />
        </button>
    </div>
    <div class="sf-card__body">
        <h3 class="sf-card__title"><a href="{{ $url }}">{{ $product->name }}</a></h3>
        @if ($product->category)<p class="sf-card__meta">{{ $product->category->name }}</p>@endif
        <p @class(['sf-price', 'sf-price--sale' => $off])>
            <span class="sf-price__now">{{ $max > $min ? 'From ' : '' }}{{ $sf->price($min) }}</span>
            @if ($off)<s class="sf-price__was">{{ $sf->price($was) }}</s>@endif
        </p>
        @if ($colors->count() > 1)
            <ul class="sf-card__swatches" aria-label="{{ $colors->count() }} colours">
                @foreach ($colors->take(5) as $color)
                    <li class="sf-dot" style="--dot: {{ $color->hex_code }}" title="{{ $color->name }}"></li>
                @endforeach
                @if ($colors->count() > 5)<li class="sf-card__more">+{{ $colors->count() - 5 }}</li>@endif
            </ul>
        @endif
        @if ($low)<p class="sf-card__low">Only {{ (int) $product->available }} left</p>@endif
    </div>
</article>
