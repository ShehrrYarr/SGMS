@extends('layouts.store')

@section('title', 'Track your order')
@section('noindex', '1')

@section('content')
<div class="sf-container sf-narrow">
    <h1 class="sf-page-title">Track your order</h1>
    <p class="sf-muted">Enter the order number from your confirmation and the phone number you ordered with.</p>

    <form action="{{ route('store.track.lookup') }}" method="post" class="sf-card-box sf-stack">
        @csrf
        <label class="sf-field">
            <span class="sf-label">Order number</span>
            <input name="order_number" class="sf-input" placeholder="ORD-000123" required value="{{ old('order_number') }}" autocapitalize="characters" autocomplete="off">
            @error('order_number')<span class="sf-error">{{ $message }}</span>@enderror
        </label>
        <label class="sf-field">
            <span class="sf-label">Phone number</span>
            <input name="phone" type="tel" inputmode="tel" autocomplete="tel" class="sf-input" placeholder="0300 1234567" required value="{{ old('phone') }}">
            @error('phone')<span class="sf-error">{{ $message }}</span>@enderror
        </label>
        <button type="submit" class="sf-btn sf-btn--accent sf-btn--lg sf-btn--block">Find my order</button>
    </form>

    @if (storefront()->customer())
        <p class="sf-muted sf-center">Signed in? See every order in <a href="{{ route('portal.orders') }}">My orders</a>.</p>
    @endif
</div>
@endsection
