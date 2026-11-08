@extends('layouts.store')

@php
    $sf = storefront();
    $settings = $sf->settings;
    $variants = $product->variants;
    $colorImages = $product->colorImages->keyBy('color_id');
    $colors = $variants->pluck('color')->filter()->unique('id')->sortBy('name')->values()
        ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'hex' => $c->hex_code, 'image' => $colorImages->get($c->id)?->url]);
    $sizes = $variants->pluck('size')->filter()->unique('id')->sortBy('sort_order')->values()
        ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name]);
    $config = [
        'variants' => $variants->map(fn ($v) => ['id' => $v->id, 'color' => $v->color_id, 'size' => $v->size_id, 'price' => (float) $v->retail_price, 'available' => max(0, (int) $v->available)])->values(),
        'colors' => $colors,
        'sizes' => $sizes,
        'images' => $colorImages->map->url,
        'base' => $sf->image($product),
        'initialColor' => (int) request('colour') ?: $product->colorImages->firstWhere('image', $product->image)?->color_id,
    ];
    $prices = $variants->pluck('retail_price')->map(fn ($p) => (float) $p);
    $min = $prices->min();
    $was = (float) $product->compare_at_price;
    $totalStock = $variants->sum(fn ($v) => max(0, (int) $v->available));
    $fee = (float) $settings->get('delivery_fee');
    $free = $settings->freeDeliveryOver();
    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'sku' => $product->sku,
        'image' => array_values(array_filter([$config['base'], ...$colors->pluck('image')->all()])),
        'description' => \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 500) ?: $product->name,
        'brand' => ['@type' => 'Brand', 'name' => $product->brand?->name ?? $sf->name()],
        'offers' => [
            '@type' => 'AggregateOffer',
            'priceCurrency' => 'PKR',
            'lowPrice' => $min,
            'highPrice' => $prices->max(),
            'offerCount' => $variants->count(),
            'availability' => $totalStock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        ],
    ];
    if ($product->reviews_count) {
        $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round((float) $product->rating, 1), 'reviewCount' => $product->reviews_count];
    }
@endphp

@section('title', $product->name)
@section('description', \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 155) ?: $product->name.' — '.$sf->price($min).' at '.$sf->name().'.')
@section('og_type', 'product')
@section('og_image', $config['base'] ?? '')

@push('head')
    <script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<div class="sf-container sf-pdp-page" x-data="productPage(@js($config))">
    <nav class="sf-crumbs" aria-label="Breadcrumb">
        <a href="{{ route('store.home') }}">Home</a>
        <x-store.icon name="chevron-right" />
        @if ($product->category)
            <a href="{{ route('store.category', $product->category->slug) }}">{{ $product->category->name }}</a>
            <x-store.icon name="chevron-right" />
        @endif
        <span aria-current="page">{{ $product->name }}</span>
    </nav>

    <div class="sf-pdp">
        {{-- Gallery: the selected colour's photo first --}}
        <div class="sf-gallery">
            <div class="sf-gallery__track" x-ref="gallery" @scroll.debounce.60ms="onScroll($el)" tabindex="0" aria-label="Product photos">
                <figure class="sf-gallery__slide">
                    @if ($config['base'])
                        <img src="{{ $config['base'] }}" :src="gallery[0]" alt="{{ $product->name }}" :alt="@js($product->name) + (colorName ? ' in ' + colorName : '')" width="900" height="1125" fetchpriority="high">
                    @else
                        <span class="sf-card__placeholder"><x-store.icon name="bag" /></span>
                    @endif
                </figure>
                <template x-for="src in gallery.slice(1)" :key="src">
                    <figure class="sf-gallery__slide"><img :src="src" alt="{{ $product->name }}" loading="lazy" width="900" height="1125"></figure>
                </template>
            </div>
            <div class="sf-gallery__thumbs" x-show="gallery.length > 1" x-cloak aria-label="Choose photo">
                <template x-for="(src, i) in gallery" :key="'t' + src">
                    <button type="button" class="sf-thumb" :class="slide === i && 'is-on'" @click="go(i)" :aria-label="'Photo ' + (i + 1)"><img :src="src" alt=""></button>
                </template>
            </div>
            <div class="sf-gallery__dots" x-show="gallery.length > 1" x-cloak aria-hidden="true">
                <template x-for="(src, i) in gallery" :key="'d' + src"><span :class="slide === i && 'is-on'"></span></template>
            </div>
            @if ($off = $product->discountPercent((float) $min))
                <span class="sf-tag sf-tag--sale sf-gallery__tag">-{{ $off }}%</span>
            @endif
        </div>

        {{-- Buy box --}}
        <div class="sf-buybox">
            @if ($product->category)<a href="{{ route('store.category', $product->category->slug) }}" class="sf-buybox__cat">{{ $product->category->name }}</a>@endif
            <h1 class="sf-buybox__title">{{ $product->name }}</h1>
            @if ($product->reviews_count)
                <a href="#reviews" class="sf-buybox__rating">@include('store.partials.stars', ['rating' => $product->rating]) <span>{{ $product->reviews_count }} {{ \Illuminate\Support\Str::plural('review', $product->reviews_count) }}</span></a>
            @endif

            <p class="sf-price sf-price--lg" :class="@js($was) > price && 'sf-price--sale'" aria-live="polite">
                <span class="sf-price__now" x-text="(variant ? '' : (@js($prices->unique()->count() > 1) ? 'From ' : '')) + SF.money(price)">{{ $prices->unique()->count() > 1 ? 'From ' : '' }}{{ $sf->price($min) }}</span>
                @if ($was > $min)<s class="sf-price__was" x-show="@js($was) > price">{{ $sf->price($was) }}</s>@endif
            </p>

            <form action="{{ route('store.cart.add') }}" method="post" class="sf-buyform" @submit.prevent="add($el)">
                @csrf
                <input type="hidden" name="variant_id" :value="variant ? variant.id : ''">
                <input type="hidden" name="quantity" :value="qty">

                @if ($colors->isNotEmpty())
                    <fieldset class="sf-option">
                        <legend>Colour: <b x-text="colorName">{{ $colors->first()['name'] }}</b></legend>
                        <div class="sf-swatches">
                            @foreach ($colors as $color)
                                <button type="button" class="sf-swatch" :class="{ 'is-on': color === {{ $color['id'] }}, 'is-out': colorStock({{ $color['id'] }}) <= 0 }"
                                        @click="pickColor({{ $color['id'] }})" :aria-pressed="(color === {{ $color['id'] }}).toString()" aria-label="{{ $color['name'] }}" title="{{ $color['name'] }}">
                                    @if ($color['image'])
                                        <img src="{{ $color['image'] }}" alt="" loading="lazy">
                                    @else
                                        <span class="sf-dot sf-dot--lg" style="--dot: {{ $color['hex'] }}"></span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                @if ($sizes->isNotEmpty())
                    <fieldset class="sf-option" x-ref="sizes" :class="missing && 'is-missing'">
                        <legend>
                            <span>Size<span x-show="size">: <b x-text="sizes.find(s => s.id === size)?.code"></b></span></span>
                            @if ($product->size_chart_url)
                                <button type="button" class="sf-link sf-option__aside" @click="$refs.chart.showModal()"><x-store.icon name="ruler" /> Size chart</button>
                            @endif
                        </legend>
                        <div class="sf-sizes">
                            @foreach ($sizes as $size)
                                <button type="button" class="sf-size" :class="{ 'is-on': size === {{ $size['id'] }}, 'is-out': sizeStock({{ $size['id'] }}) <= 0 }"
                                        :disabled="sizeStock({{ $size['id'] }}) <= 0" @click="pickSize({{ $size['id'] }})"
                                        :aria-pressed="(size === {{ $size['id'] }}).toString()" title="{{ $size['name'] }}">{{ $size['code'] }}</button>
                            @endforeach
                        </div>
                        <p class="sf-option__error" x-show="missing" x-cloak role="alert">Choose a size to continue.</p>
                    </fieldset>
                @elseif ($product->size_chart_url)
                    <button type="button" class="sf-link" @click="$refs.chart.showModal()"><x-store.icon name="ruler" /> Size chart</button>
                @endif

                <p class="sf-stock" x-show="variant" x-cloak>
                    <template x-if="available > 5"><span class="sf-stock--in"><x-store.icon name="check" /> In stock</span></template>
                    <template x-if="available > 0 && available <= 5"><span class="sf-stock--low">Only <b x-text="available"></b> left</span></template>
                    <template x-if="available <= 0"><span class="sf-stock--out">Sold out in this size</span></template>
                </p>

                <div class="sf-buyform__row">
                    <div class="sf-qty" aria-label="Quantity">
                        <button type="button" @click="step(-1)" :disabled="qty <= 1" aria-label="One less"><x-store.icon name="minus" /></button>
                        <output x-text="qty">1</output>
                        <button type="button" @click="step(1)" :disabled="!variant || qty >= Math.min(available, 20)" aria-label="One more"><x-store.icon name="plus" /></button>
                    </div>
                    <button type="submit" class="sf-btn sf-btn--accent sf-btn--lg sf-buyform__add" :class="$store.shop.busy && 'is-loading'"
                            :disabled="$store.shop.busy || (variant && !ready) || {{ $totalStock > 0 ? 'false' : 'true' }}">
                        <span x-text="{{ $totalStock > 0 ? 'true' : 'false' }} ? (variant && !ready ? 'Sold out' : 'Add to bag') : 'Sold out'">{{ $totalStock > 0 ? 'Add to bag' : 'Sold out' }}</span>
                    </button>
                    <div x-data="wish(@js($sf->wishlist()->has($product->id)))">
                        <button type="button" class="sf-heart sf-heart--box" :class="saved && 'is-on'" :aria-pressed="saved.toString()"
                                @click="toggle(@js(route('store.wishlist.toggle', $product->id)))" aria-label="Save to wishlist"><x-store.icon name="heart" /></button>
                    </div>
                </div>
            </form>

            <ul class="sf-trust">
                <li><x-store.icon name="truck" />
                    <span>
                        @if ($free) Free delivery on orders over {{ $sf->price($free) }}@if ($fee > 0), otherwise {{ $sf->price($fee) }}@endif
                        @elseif ($fee > 0) Delivery {{ $sf->price($fee) }} anywhere in Pakistan
                        @else Free delivery @endif
                        @if ($note = $settings->get('delivery_note'))<br><small>{{ $note }}</small>@endif
                    </span>
                </li>
                @if (isset($sf->paymentMethods()['cod']))<li><x-store.icon name="cash" /><span>Cash on delivery available</span></li>@endif
                <li><x-store.icon name="return" /><span>Easy exchange in store or by courier</span></li>
            </ul>

            <div class="sf-accordion">
                @if ($product->description)
                    <details open>
                        <summary>Description <x-store.icon name="chevron-down" /></summary>
                        <div class="sf-prose">{!! nl2br(e($product->description)) !!}</div>
                    </details>
                @endif
                <details>
                    <summary>Details <x-store.icon name="chevron-down" /></summary>
                    <dl class="sf-specs">
                        <div><dt>Product code</dt><dd>{{ $product->sku }}</dd></div>
                        @if ($product->brand)<div><dt>Brand</dt><dd>{{ $product->brand->name }}</dd></div>@endif
                        @if ($colors->isNotEmpty())<div><dt>Colours</dt><dd>{{ $colors->pluck('name')->implode(', ') }}</dd></div>@endif
                        @if ($sizes->isNotEmpty())<div><dt>Sizes</dt><dd>{{ $sizes->pluck('code')->implode(', ') }}</dd></div>@endif
                    </dl>
                </details>
                <details>
                    <summary>Delivery & payment <x-store.icon name="chevron-down" /></summary>
                    <div class="sf-prose">
                        <p>Orders are packed in our store and handed to a courier once the shop confirms them. You can follow your order any time from <a href="{{ route('store.track') }}">Track an order</a>.</p>
                        <p>We accept {{ collect($sf->paymentMethods())->pluck('label')->map(fn ($l) => strtolower($l))->join(', ', ' and ') ?: 'payment on order' }}.</p>
                    </div>
                </details>
            </div>
        </div>
    </div>

    @if ($product->size_chart_url)
        <dialog class="sf-dialog" x-ref="chart" @click.self="$el.close()">
            <div class="sf-dialog__head">
                <h2>Size chart</h2>
                <button type="button" class="sf-iconbtn" @click="$refs.chart.close()" aria-label="Close size chart"><x-store.icon name="close" /></button>
            </div>
            <img src="{{ $product->size_chart_url }}" alt="Size chart for {{ $product->name }}" loading="lazy">
        </dialog>
    @endif

    {{-- Reviews --}}
    <section class="sf-reviews" id="reviews" aria-labelledby="reviews-title">
        <div class="sf-reviews__head">
            <h2 id="reviews-title" class="sf-section__title">Reviews</h2>
            @if ($product->reviews_count)
                <p class="sf-reviews__score"><b>{{ number_format((float) $product->rating, 1) }}</b> @include('store.partials.stars', ['rating' => $product->rating]) <span class="sf-muted">{{ $product->reviews_count }} {{ \Illuminate\Support\Str::plural('review', $product->reviews_count) }}</span></p>
            @endif
        </div>

        <div class="sf-reviews__grid">
            <div class="sf-reviews__list">
                @forelse ($reviews as $review)
                    <article class="sf-review">
                        @include('store.partials.stars', ['rating' => $review->rating])
                        @if ($review->title)<h3>{{ $review->title }}</h3>@endif
                        @if ($review->body)<p>{{ $review->body }}</p>@endif
                        <p class="sf-review__by">{{ $review->author }}, verified buyer, {{ $review->created_at->format('j M Y') }}</p>
                    </article>
                @empty
                    <p class="sf-muted">No reviews yet. Bought this? Tell other shoppers how it fits.</p>
                @endforelse
            </div>

            <div class="sf-reviews__form">
                @if ($canReview)
                    <form action="{{ route('store.review', $product->id) }}" method="post" class="sf-card-box" x-data="{ rating: {{ old('rating', $myReview->rating ?? 5) }} }">
                        @csrf
                        <h3>{{ $myReview ? 'Update your review' : 'Write a review' }}</h3>
                        @if ($myReview && $myReview->status === 'pending')<p class="sf-muted">Your review is waiting for the shop's approval.</p>@endif
                        <fieldset class="sf-rate">
                            <legend>Your rating</legend>
                            @for ($i = 1; $i <= 5; $i++)
                                <label :class="rating >= {{ $i }} && 'is-on'"><input type="radio" name="rating" value="{{ $i }}" x-model.number="rating" class="sf-visually-hidden"><x-store.icon name="star" /><span class="sf-visually-hidden">{{ $i }} star</span></label>
                            @endfor
                        </fieldset>
                        <label class="sf-field"><span class="sf-label">Title (optional)</span><input name="title" class="sf-input" maxlength="120" value="{{ old('title', $myReview->title ?? '') }}"></label>
                        <label class="sf-field"><span class="sf-label">Your review</span><textarea name="body" class="sf-input" rows="4" maxlength="2000">{{ old('body', $myReview->body ?? '') }}</textarea></label>
                        <button type="submit" class="sf-btn">Submit review</button>
                    </form>
                @elseif (! $sf->customer())
                    <p class="sf-muted"><a href="{{ route('portal.login') }}">Sign in</a> to review a product you bought.</p>
                @endif
            </div>
        </div>
    </section>
</div>

@include('store.partials.section', ['title' => 'You may also like', 'products' => $related, 'class' => 'sf-section--related'])
@endsection
