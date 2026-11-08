{{-- Bag drawer content. Expects $lines, $summary (Cart). Forms are upgraded by store.js (data-bag). --}}
@php($sf = storefront())
@if ($lines->isEmpty())
    <div class="sf-empty sf-empty--compact">
        <x-store.icon name="bag" class="sf-empty__icon" />
        <p class="sf-empty__title">Your bag is empty</p>
        <p class="sf-muted">Find something you like and add it here.</p>
        <a href="{{ route('store.shop') }}" class="sf-btn">Continue shopping</a>
    </div>
@else
    @if ($summary['free_delivery_over'])
        @php($pct = min(100, round(($summary['subtotal'] - $summary['discount']) / $summary['free_delivery_over'] * 100)))
        <div class="sf-progress" role="status">
            <p>{!! $summary['free_delivery_gap'] > 0 ? 'Add <b>'.e($sf->price($summary['free_delivery_gap'])).'</b> more for free delivery' : '<b>You get free delivery</b>' !!}</p>
            <span class="sf-progress__bar"><span style="width: {{ $pct }}%"></span></span>
        </div>
    @endif

    <ul class="sf-lines">
        @foreach ($lines as $line)
            @php($variant = $line['variant'])
            <li class="sf-line">
                <a href="{{ route('store.product', $variant->product->slug) }}" class="sf-line__media">
                    @if ($line['image'])<img src="{{ $line['image'] }}" alt="" loading="lazy" width="80" height="100">@endif
                </a>
                <div class="sf-line__body">
                    <a href="{{ route('store.product', $variant->product->slug) }}" class="sf-line__title">{{ $variant->product->name }}</a>
                    <p class="sf-line__variant">
                        @if ($variant->color)Colour: {{ $variant->color->name }}@endif
                        @if ($variant->color && $variant->size) <br> @endif
                        @if ($variant->size)Size: {{ $variant->size->code }}@endif
                    </p>
                    <div class="sf-line__row">
                        <form action="{{ route('store.cart.update', $variant->id) }}" method="post" data-bag class="sf-qty sf-qty--sm">
                            @csrf @method('PATCH')
                            <button type="submit" name="quantity" value="{{ $line['quantity'] - 1 }}" aria-label="One less">
                                <x-store.icon name="minus" />
                            </button>
                            <output aria-label="Quantity">{{ $line['quantity'] }}</output>
                            <button type="submit" name="quantity" value="{{ $line['quantity'] + 1 }}" aria-label="One more" @disabled($line['quantity'] >= $line['available'])>
                                <x-store.icon name="plus" />
                            </button>
                        </form>
                        <span class="sf-line__price">{{ $sf->price($line['total']) }}</span>
                    </div>
                    @if ($line['quantity'] > $line['available'])
                        <p class="sf-line__warn">Only {{ $line['available'] }} available</p>
                    @endif
                </div>
                <form action="{{ route('store.cart.remove', $variant->id) }}" method="post" data-bag class="sf-line__remove">
                    @csrf @method('DELETE')
                    <button type="submit" class="sf-iconbtn sf-iconbtn--sm" aria-label="Remove {{ $variant->product->name }}"><x-store.icon name="close" /></button>
                </form>
            </li>
        @endforeach
    </ul>

    <div class="sf-drawer__foot">
        <dl class="sf-totals">
            <div><dt>Subtotal</dt><dd>{{ $sf->price($summary['subtotal']) }}</dd></div>
            @if ($summary['discount'] > 0)
                <div class="sf-totals__save"><dt>Discount ({{ $summary['coupon']->code }})</dt><dd>-{{ $sf->price($summary['discount']) }}</dd></div>
            @endif
            <div><dt>Delivery</dt><dd>{{ $summary['delivery'] > 0 ? $sf->price($summary['delivery']) : 'Free' }}</dd></div>
            @if ($summary['tax'] > 0)
                <div><dt>Tax ({{ rtrim(rtrim(number_format($summary['tax_rate'], 2), '0'), '.') }}%)</dt><dd>{{ $sf->price($summary['tax']) }}</dd></div>
            @endif
            <div class="sf-totals__total"><dt>Total</dt><dd>{{ $sf->price($summary['total']) }}</dd></div>
        </dl>
        <a href="{{ route('store.checkout') }}" class="sf-btn sf-btn--accent sf-btn--block sf-btn--lg">Checkout</a>
        <a href="{{ route('store.cart') }}" class="sf-btn sf-btn--ghost sf-btn--block">View bag</a>
    </div>
@endif
