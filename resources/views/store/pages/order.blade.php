@extends('layouts.store')

@php
    $sf = storefront();
    $account = $order->paymentAccount;
    $needsPayment = $order->isPrepaid() && in_array($order->payment_status, ['unpaid', 'awaiting'], true) && $order->status !== 'cancelled';
    $steps = $order->timeline();
@endphp

@section('title', 'Order '.$order->order_number)
@section('noindex', '1')

@section('content')
<div class="sf-container sf-order-page">
    <header class="sf-order__head">
        @if ($justPlaced)
            <span class="sf-order__check"><x-store.icon name="check" /></span>
            <h1 class="sf-page-title">Thank you, {{ \Illuminate\Support\Str::before($order->customer_name, ' ') }}. Your order is placed.</h1>
            <p class="sf-muted">Order <b>{{ $order->order_number }}</b>. Keep this number to track your order. The shop will confirm it shortly{{ $order->payment_method === 'cod' ? ' by phone' : '' }}.</p>
        @else
            <h1 class="sf-page-title">Order {{ $order->order_number }}</h1>
            <p class="sf-muted">Placed {{ $order->created_at->format('j M Y, g:i a') }}</p>
        @endif
    </header>

    <ol @class(['sf-timeline', 'is-cancelled' => $order->status === 'cancelled'])>
        @foreach ($steps as $label => $at)
            <li @class(['is-done' => $at])>
                <span class="sf-timeline__dot">@if ($at)<x-store.icon name="check" />@endif</span>
                <span class="sf-timeline__label">{{ $label }}</span>
                <span class="sf-timeline__at">{{ $at?->format('j M, g:i a') }}</span>
            </li>
        @endforeach
    </ol>

    @if ($order->status === 'cancelled' && $order->cancel_reason)
        <p class="sf-notice sf-notice--error"><x-store.icon name="info" /> This order was cancelled. Reason: {{ $order->cancel_reason }}</p>
    @endif
    @if ($order->tracking_number)
        <p class="sf-notice"><x-store.icon name="truck" /> Shipped with {{ $order->courier ?: 'courier' }}. Tracking number: <b>{{ $order->tracking_number }}</b></p>
    @endif

    <div class="sf-order">
        <div class="sf-order__main">
            @if ($needsPayment && $account)
                <section class="sf-card-box sf-paybox">
                    <h2>{{ $order->payment_status === 'awaiting' ? 'Payment receipt received' : 'Complete your payment' }}</h2>
                    @if ($order->payment_status === 'awaiting')
                        <p class="sf-muted">The shop is checking your payment. You can replace the receipt below if needed.</p>
                    @else
                        <p>Send <b>{{ $sf->price($order->total) }}</b> to the account below, then upload a screenshot of the receipt.</p>
                    @endif
                    <dl class="sf-specs">
                        <div><dt>{{ $order->payment_method === 'wallet' ? 'Wallet' : 'Bank' }}</dt><dd>{{ $account->bank_name ?: $account->account_name }}</dd></div>
                        <div><dt>Account title</dt><dd>{{ $account->account_name }}</dd></div>
                        @if ($account->account_number)<div><dt>Account number</dt><dd><b class="sf-mono">{{ $account->account_number }}</b></dd></div>@endif
                        <div><dt>Amount</dt><dd><b>{{ $sf->price($order->total) }}</b></dd></div>
                        <div><dt>Reference</dt><dd>{{ $order->order_number }}</dd></div>
                    </dl>
                    @if ($instructions = $sf->settings->get('payment_instructions'))<p class="sf-muted">{{ $instructions }}</p>@endif
                    <form action="{{ route('store.order.receipt', $order) }}" method="post" enctype="multipart/form-data" class="sf-stack">
                        @csrf
                        <label class="sf-upload">
                            <x-store.icon name="upload" />
                            <span>Upload payment receipt <small>JPG, PNG or PDF, up to 4 MB</small></span>
                            <input type="file" name="receipt" accept="image/*,application/pdf" required onchange="this.closest('label').querySelector('span').firstChild.textContent = this.files[0]?.name + ' '">
                        </label>
                        @error('receipt')<span class="sf-error">{{ $message }}</span>@enderror
                        <label class="sf-field"><span class="sf-label">Transaction ID (optional)</span><input name="reference" class="sf-input" maxlength="100" value="{{ $order->payment_reference }}"></label>
                        <button type="submit" class="sf-btn sf-btn--accent">Send receipt</button>
                    </form>
                </section>
            @endif

            <section class="sf-card-box">
                <h2>Items</h2>
                <ul class="sf-lines sf-lines--static">
                    @foreach ($order->items as $item)
                        @php $product = $item->variant?->product; @endphp
                        <li class="sf-line">
                            <span class="sf-line__media">
                                @if ($product && ($img = $sf->image($product, $item->variant->color_id)))<img src="{{ $img }}" alt="" width="80" height="100" loading="lazy">@endif
                            </span>
                            <div class="sf-line__body">
                                <span class="sf-line__title">{{ $item->product_name }}</span>
                                <p class="sf-line__variant">{{ $item->variant_name }}, qty {{ $item->quantity }}</p>
                            </div>
                            <span class="sf-line__price">{{ $sf->price($item->total) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        <aside class="sf-order__side">
            <section class="sf-card-box">
                <h2>Summary</h2>
                <dl class="sf-totals">
                    <div><dt>Subtotal</dt><dd>{{ $sf->price($order->subtotal) }}</dd></div>
                    @if ((float) $order->discount > 0)<div class="sf-totals__save"><dt>Discount{{ $order->coupon_code ? ' ('.$order->coupon_code.')' : '' }}</dt><dd>-{{ $sf->price($order->discount) }}</dd></div>@endif
                    <div><dt>Delivery</dt><dd>{{ (float) $order->shipping_fee > 0 ? $sf->price($order->shipping_fee) : 'Free' }}</dd></div>
                    @if ((float) $order->tax > 0)<div><dt>Tax</dt><dd>{{ $sf->price($order->tax) }}</dd></div>@endif
                    <div class="sf-totals__total"><dt>Total</dt><dd>{{ $sf->price($order->total) }}</dd></div>
                </dl>
                <p class="sf-order__pay"><x-store.icon :name="$order->payment_method === 'cod' ? 'cash' : ($order->payment_method === 'wallet' ? 'wallet' : 'bank')" /> {{ $order->payment_method_label }}: {{ strtolower($order->payment_status_label) }}</p>
            </section>
            <section class="sf-card-box">
                <h2>Delivery to</h2>
                <address class="sf-address">
                    <b>{{ $order->customer_name }}</b><br>
                    {{ $order->address }}<br>{{ $order->city }}<br>
                    {{ $order->phone }}
                </address>
            </section>
            @if ($chat = $sf->settings->whatsappUrl('Hi, I have a question about my order '.$order->order_number))
                <a href="{{ $chat }}" class="sf-btn sf-btn--ghost sf-btn--block" target="_blank" rel="noopener"><x-store.icon name="whatsapp" /> Ask about this order</a>
            @endif
            <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--block">Continue shopping</a>
        </aside>
    </div>
</div>
@endsection
