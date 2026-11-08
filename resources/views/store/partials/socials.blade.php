@php($socials = storefront()->settings->socials())
@if ($socials)
    <div class="sf-socials">
        @foreach ($socials as $network => $url)
            <a href="{{ $url }}" class="sf-iconbtn" target="_blank" rel="noopener" aria-label="{{ ucfirst($network) }}"><x-store.icon :name="$network" /></a>
        @endforeach
    </div>
@endif
