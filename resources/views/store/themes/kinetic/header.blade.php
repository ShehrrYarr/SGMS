@php
    $sf = storefront();
    $note = $sf->settings->get('announcement') ?: collect($sf->perks())->pluck(1)->implode('   /   ');
@endphp
<div class="kn-ticker" aria-label="{{ $note }}">
    <div class="kn-ticker__track" aria-hidden="true">
        @for ($i = 0; $i < 6; $i++)<span>{{ $note }}</span>@endfor
    </div>
</div>
<header class="kn-header">
    <div class="sf-container kn-header__bar">
        <button type="button" class="sf-iconbtn sf-only-mobile" @click="$store.shop.openMenu()" aria-label="Open menu"><x-store.icon name="menu" /></button>
        @include('store.partials.logo', ['class' => 'kn-logo'])
        <nav class="kn-nav sf-only-desktop" aria-label="Categories">
            <a href="{{ route('store.shop', ['sort' => 'newest']) }}">New drops</a>
            @foreach ($sf->categories()->take(6) as $cat)
                <a href="{{ route('store.category', $cat->slug) }}" @class(['is-on' => request()->route('category')?->id === $cat->id])>{{ $cat->name }}</a>
            @endforeach
            <a href="{{ route('store.shop', ['sale' => 1]) }}" class="kn-nav__sale">Sale</a>
        </nav>
        @include('store.partials.header-icons')
    </div>
    <form action="{{ route('store.shop') }}" method="get" class="kn-search" role="search">
        <div class="sf-container">
            <x-store.icon name="search" />
            <input type="search" name="q" placeholder="Search the store" aria-label="Search products" value="{{ request('q') }}">
        </div>
    </form>
</header>
