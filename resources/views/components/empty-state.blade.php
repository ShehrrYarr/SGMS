@props(['icon' => 'ri-inbox-line', 'title' => 'Nothing here yet', 'text' => null])

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    <i class="{{ $icon }}"></i>
    <div class="fw-semibold text-body">{{ $title }}</div>
    @if ($text)
        <div class="small mt-1">{{ $text }}</div>
    @endif
    {{ $slot }}
</div>
