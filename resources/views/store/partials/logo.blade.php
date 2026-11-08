@php($sf = storefront())
<a href="{{ route('store.home') }}" class="sf-logo {{ $class ?? '' }}" aria-label="{{ $sf->name() }} home">
    @if ($logo = $sf->settings->logoUrl())
        <img src="{{ $logo }}" alt="{{ $sf->name() }}" width="160" height="40">
    @else
        <span class="sf-logo__text">{{ $sf->name() }}</span>
    @endif
</a>
