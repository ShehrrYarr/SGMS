@props(['label', 'value', 'icon' => 'ri-bar-chart-2-line', 'color' => 'primary', 'hint' => null, 'href' => null])

<div {{ $attributes->merge(['class' => 'card card-h-100 mb-0']) }}>
    <div class="card-body p-4">
        <div class="d-flex align-items-start gap-3">
            <div class="stat-icon bg-{{ $color }}-subtle text-{{ $color }}">
                <i class="{{ $icon }}"></i>
            </div>
            <div class="flex-grow-1 min-w-0">
                <h6 class="mb-1 fs-13 text-muted fw-medium">{{ $label }}</h6>
                <h4 class="fw-semibold fs-5 mb-0 text-truncate tabular-nums">{{ $value }}</h4>
                @if ($hint)
                    <div class="text-muted fs-12 mt-1 text-truncate">{{ $hint }}</div>
                @endif
            </div>
            @if ($href)
                <a href="{{ $href }}" class="btn btn-sm icon-btn-sm btn-light-{{ $color }} rounded-circle flex-shrink-0" wire:navigate aria-label="Open">
                    <i class="ri-arrow-right-up-line"></i>
                </a>
            @endif
        </div>
    </div>
</div>
