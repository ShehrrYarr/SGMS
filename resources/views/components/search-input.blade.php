@props(['placeholder' => 'Search…'])

<div class="position-relative">
    <i class="ri-search-line position-absolute text-muted" style="left: .8rem; top: 50%; transform: translateY(-50%);"></i>
    <input type="search" {{ $attributes->merge(['class' => 'form-control', 'style' => 'padding-left: 2.3rem']) }} placeholder="{{ $placeholder }}">
</div>
