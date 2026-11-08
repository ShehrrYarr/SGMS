@extends('layouts.store')

@php($sf = storefront())

@section('title', 'Wishlist')
@section('noindex', '1')

@section('content')
<div class="sf-container">
    <h1 class="sf-page-title">Wishlist</h1>

    @if ($products->isEmpty())
        <div class="sf-empty">
            <x-store.icon name="heart" class="sf-empty__icon" />
            <p class="sf-empty__title">Nothing saved yet</p>
            <p class="sf-muted">Tap the heart on any product to keep it here for later.</p>
            <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--accent">Browse products</a>
        </div>
    @else
        @unless ($sf->customer())
            <p class="sf-muted"><a href="{{ route('portal.login') }}">Sign in</a> to keep your wishlist on every device.</p>
        @endunless
        <div class="sf-grid sf-grid--listing">
            @foreach ($products as $product)
                @include($sf->view('card'), ['product' => $product])
            @endforeach
        </div>
    @endif
</div>
@endsection
