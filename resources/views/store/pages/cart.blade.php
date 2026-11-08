@extends('layouts.store')

@php($sf = storefront())

@section('title', 'Your bag')
@section('noindex', '1')

@section('content')
<div class="sf-container sf-cart-page">
    <h1 class="sf-page-title">Your bag</h1>

    @if ($lines->isEmpty())
        <div class="sf-empty">
            <x-store.icon name="bag" class="sf-empty__icon" />
            <p class="sf-empty__title">Your bag is empty</p>
            <p class="sf-muted">Browse the latest arrivals and add your favourites.</p>
            <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--accent">Continue shopping</a>
        </div>
    @else
        <div class="sf-cart">
            <div class="sf-cart__lines">
                @if ($summary['free_delivery_over'])
                    @php($pct = min(100, round(($summary['subtotal'] - $summary['discount']) / $summary['free_delivery_over'] * 100)))
                    <div class="sf-progress">
                        <p>{!! $summary['free_delivery_gap'] > 0 ? 'Add <b>'.e($sf->price($summary['free_delivery_gap'])).'</b> more for free delivery' : '<b>You get free delivery</b>' !!}</p>
                        <span class="sf-progress__bar"><span style="width: {{ $pct }}%"></span></span>
                    </div>
                @endif

                <ul class="sf-lines sf-lines--page">
                    @foreach ($lines as $line)
                        @php($variant = $line['variant'])
                        <li class="sf-line">
                            <a href="{{ route('store.product', $variant->product->slug) }}" class="sf-line__media">
                                @if ($line['image'])<img src="{{ $line['image'] }}" alt="" width="120" height="150">@endif
                            </a>
                            <div class="sf-line__body">
                                <a href="{{ route('store.product', $variant->product->slug) }}" class="sf-line__title">{{ $variant->product->name }}</a>
                                <p class="sf-line__variant">
                                    @if ($variant->color)Colour: {{ $variant->color->name }}@endif
                                    @if ($variant->color && $variant->size) <br> @endif
                                    @if ($variant->size)Size: {{ $variant->size->code }}@endif
                                </p>
                                <p class="sf-line__unit">
                                    {{ $sf->price($line['unit_price']) }}
                                    @if ($line['compare_at'])<s class="sf-muted">{{ $sf->price($line['compare_at']) }}</s>@endif
                                </p>
                                <div class="sf-line__row">
                                    <form action="{{ route('store.cart.update', $variant->id) }}" method="post" class="sf-qty">
                                        @csrf @method('PATCH')
                                        <button type="submit" name="quantity" value="{{ $line['quantity'] - 1 }}" aria-label="One less"><x-store.icon name="minus" /></button>
                                        <output aria-label="Quantity">{{ $line['quantity'] }}</output>
                                        <button type="submit" name="quantity" value="{{ $line['quantity'] + 1 }}" aria-label="One more" @disabled($line['quantity'] >= min($line['available'], \App\Services\Store\Cart::MAX_QTY))><x-store.icon name="plus" /></button>
                                    </form>
                                    <span class="sf-line__price">{{ $sf->price($line['total']) }}</span>
                                </div>
                                @if ($line['available'] <= 0)
                                    <p class="sf-line__warn">Sold out. Remove it to continue.</p>
                                @elseif ($line['quantity'] > $line['available'])
                                    <p class="sf-line__warn">Only {{ $line['available'] }} available</p>
                                @endif
                            </div>
                            <form action="{{ route('store.cart.remove', $variant->id) }}" method="post" class="sf-line__remove">
                                @csrf @method('DELETE')
                                <button type="submit" class="sf-iconbtn sf-iconbtn--sm" aria-label="Remove {{ $variant->product->name }}"><x-store.icon name="trash" /></button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>

            <aside class="sf-summary">
                <h2 class="sf-summary__title">Order summary</h2>

                @if ($summary['coupon'])
                    <form action="{{ route('store.cart.coupon.remove') }}" method="post" class="sf-coupon sf-coupon--applied">
                        @csrf @method('DELETE')
                        <span><x-store.icon name="tag" /> <b>{{ $summary['coupon']->code }}</b> {{ $summary['coupon']->label }}</span>
                        <button type="submit" class="sf-link">Remove</button>
                    </form>
                @else
                    <form action="{{ route('store.cart.coupon') }}" method="post" class="sf-coupon">
                        @csrf
                        <label for="sf-coupon" class="sf-visually-hidden">Discount code</label>
                        <input id="sf-coupon" name="code" class="sf-input" placeholder="Discount code" value="{{ old('code') }}" autocomplete="off" autocapitalize="characters">
                        <button type="submit" class="sf-btn sf-btn--ghost">Apply</button>
                    </form>
                    @if ($summary['coupon_error'])<p class="sf-error">{{ $summary['coupon_error'] }}</p>@endif
                @endif

                <dl class="sf-totals">
                    <div><dt>Subtotal</dt><dd>{{ $sf->price($summary['subtotal']) }}</dd></div>
                    @if ($summary['discount'] > 0)<div class="sf-totals__save"><dt>Discount</dt><dd>-{{ $sf->price($summary['discount']) }}</dd></div>@endif
                    <div><dt>Delivery</dt><dd>{{ $summary['delivery'] > 0 ? $sf->price($summary['delivery']) : 'Free' }}</dd></div>
                    @if ($summary['tax'] > 0)<div><dt>Tax</dt><dd>{{ $sf->price($summary['tax']) }}</dd></div>@endif
                    <div class="sf-totals__total"><dt>Total</dt><dd>{{ $sf->price($summary['total']) }}</dd></div>
                </dl>
                <a href="{{ route('store.checkout') }}" class="sf-btn sf-btn--accent sf-btn--block sf-btn--lg">Checkout</a>
                <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--ghost sf-btn--block">Continue shopping</a>
                <ul class="sf-trust sf-trust--compact">
                    <li><x-store.icon name="shield" /><span>Your details are only used to deliver your order</span></li>
                    @if (isset($sf->paymentMethods()['cod']))<li><x-store.icon name="cash" /><span>Pay cash when your order arrives</span></li>@endif
                </ul>
            </aside>
        </div>

        <div class="sf-mobilebar">
            <span><small>Total</small><b>{{ $sf->price($summary['total']) }}</b></span>
            <a href="{{ route('store.checkout') }}" class="sf-btn sf-btn--accent">Checkout</a>
        </div>
    @endif
</div>
@endsection
