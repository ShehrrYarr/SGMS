@props(['title', 'subtitle' => null, 'breadcrumbs' => []])

<div class="hstack flex-wrap gap-3 mb-4">
    <div class="flex-grow-1 min-w-0">
        <h4 class="mb-1 fw-semibold">{{ $title }}</h4>
        @if ($breadcrumbs)
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-arrow mb-0">
                    @foreach ($breadcrumbs as $label => $url)
                        @if ($url)
                            <li class="breadcrumb-item"><a href="{{ $url }}" wire:navigate>{{ $label }}</a></li>
                        @else
                            <li class="breadcrumb-item active" aria-current="page">{{ $label }}</li>
                        @endif
                    @endforeach
                </ol>
            </nav>
        @elseif ($subtitle)
            <p class="text-muted mb-0">{{ $subtitle }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
            {{ $actions }}
        </div>
    @endif
</div>
