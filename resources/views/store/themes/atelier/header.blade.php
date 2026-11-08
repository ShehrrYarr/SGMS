@php($sf = storefront())
@if ($note = $sf->settings->get('announcement'))
    <div class="at-announce">{{ $note }}</div>
@endif
<header class="at-header" x-data="{ search: false }">
    <div class="sf-container at-header__bar">
        <div class="at-header__left">
            <button type="button" class="sf-iconbtn sf-only-mobile" @click="$store.shop.openMenu()" aria-label="Open menu"><x-store.icon name="menu" /></button>
            <button type="button" class="sf-iconbtn" @click="search = !search; $nextTick(() => search && $refs.q.focus())" :aria-expanded="search.toString()" aria-label="Search"><x-store.icon name="search" /></button>
        </div>
        @include('store.partials.logo', ['class' => 'at-logo'])
        @include('store.partials.header-icons')
    </div>
    <nav class="at-nav sf-only-desktop" aria-label="Categories">
        <a href="{{ route('store.shop', ['sort' => 'newest']) }}">New in</a>
        @foreach ($sf->categories() as $cat)
            <a href="{{ route('store.category', $cat->slug) }}" @class(['is-on' => request()->route('category')?->id === $cat->id])>{{ $cat->name }}</a>
        @endforeach
        <a href="{{ route('store.shop', ['sale' => 1]) }}">Sale</a>
    </nav>
    <form action="{{ route('store.shop') }}" method="get" class="at-search" x-show="search" x-cloak x-transition.opacity role="search" @keydown.escape="search = false">
        <div class="sf-container">
            <label for="at-q" class="sf-visually-hidden">Search</label>
            <input id="at-q" x-ref="q" type="search" name="q" placeholder="What are you looking for?" value="{{ request('q') }}">
        </div>
    </form>
</header>
