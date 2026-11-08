{{--
    Alpine-driven Bootstrap modal that survives Livewire re-renders.
    Open:  $this->dispatch('open-modal', name: 'vendor')   |  $dispatch('open-modal', { name: 'vendor' })
    Close: $this->dispatch('close-modal', name: 'vendor')
    Renders as a bottom sheet on phones.
--}}
@props(['name', 'title' => null, 'size' => 'md', 'static' => false])

<div
    x-data="{ show: false, name: @js($name) }"
    x-on:open-modal.window="if ($event.detail.name === name) show = true"
    x-on:close-modal.window="if ($event.detail.name === name) show = false"
    x-on:keydown.escape.window="if (show && !@js($static)) show = false"
    x-effect="document.body.classList.toggle('overflow-hidden', show)"
    class="sgms-modal"
    {{ $attributes }}
>
    <template x-if="show">
        <div class="sgms-modal-backdrop" x-transition.opacity></div>
    </template>
    <div class="modal modal-sheet fade" :class="{ 'show': show }" x-show="show" x-cloak style="display: none;"
         tabindex="-1" role="dialog" aria-modal="true" @if (! $static) x-on:mousedown.self="show = false" @endif>
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-{{ $size }}">
            <div class="modal-content">
                @if ($title || isset($header))
                    <div class="modal-header position-relative">
                        @isset($header)
                            {{ $header }}
                        @else
                            <h5 class="modal-title fw-semibold">{{ $title }}</h5>
                        @endisset
                        <button type="button" class="btn-close" x-on:click="show = false" aria-label="Close"></button>
                    </div>
                @endif
                <div class="modal-body">
                    {{ $slot }}
                </div>
                @isset($footer)
                    <div class="modal-footer">
                        {{ $footer }}
                    </div>
                @endisset
            </div>
        </div>
    </div>
</div>
