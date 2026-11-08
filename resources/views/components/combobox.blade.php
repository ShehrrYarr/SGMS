{{--
    Searchable dropdown (Alpine). Bind with wire:model / wire:model.live.

    Static:  <x-combobox wire:model="category_id" :options="$opts" />          opts = [['id'=>1,'label'=>'..','sub'=>'..']]
    Remote:  <x-combobox wire:model.live="vendor_id" search="searchVendors" :label="$vendorLabel" />
             (searchVendors must be a #[Renderless] method returning the same shape)
--}}
@props(['options' => null, 'search' => null, 'label' => null, 'placeholder' => 'Select…', 'nullable' => true, 'size' => null, 'invalid' => false])

<div
    x-data="combobox({ options: @js($options), search: @js($search), label: @js($label), placeholder: @js($placeholder), nullable: @js($nullable) })"
    x-modelable="value"
    {{ $attributes->whereStartsWith('wire:model') }}
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'sgms-combobox']) }}
    x-on:click.outside="close()"
    x-on:keydown.escape.stop="close()"
>
    <button type="button" class="form-select combobox-toggle {{ $size ? 'form-select-'.$size : '' }} {{ $invalid ? 'is-invalid' : '' }}" x-on:click="toggle()" x-on:keydown.down.prevent="show()">
        <span x-text="selectedLabel || placeholder" :class="{ 'text-muted': !selectedLabel }"></span>
        @if ($nullable)
            <i class="ri-close-circle-fill text-muted" x-show="selectedLabel" x-on:click.stop="clear()" role="button" aria-label="Clear"></i>
        @endif
    </button>

    <div class="dropdown-menu shadow" :class="{ 'show': open }" x-show="open" x-cloak style="display: none;">
        <div class="position-relative mb-2">
            <input x-ref="q" type="search" class="form-control form-control-sm" style="padding-left: 1.9rem" placeholder="Type to search…"
                   x-model="query" x-on:input.debounce.250ms="fetch()"
                   x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
                   x-on:keydown.enter.prevent="choose(results[active])">
            <i class="ri-search-line position-absolute text-muted" style="left: .55rem; top: 50%; transform: translateY(-50%); font-size: .85rem;"></i>
        </div>
        <div class="combobox-list" x-ref="list">
            <template x-for="(opt, i) in results" :key="opt.id">
                <button type="button" class="dropdown-item py-2" :class="{ 'active': i === active }" x-on:click="choose(opt)" x-on:mouseenter="active = i">
                    <div class="d-flex align-items-center gap-2">
                        <span class="color-dot" x-show="opt.color" :style="'background:' + opt.color"></span>
                        <div class="min-w-0">
                            <div class="fw-medium text-truncate" x-text="opt.label"></div>
                            <small class="text-muted d-block text-truncate" x-show="opt.sub" x-text="opt.sub"></small>
                        </div>
                    </div>
                </button>
            </template>
            <div x-show="loading" class="small text-muted px-2 py-1"><span class="spinner-border spinner-border-sm me-1"></span>Searching…</div>
            <div x-show="!loading && results.length === 0" class="small text-muted px-2 py-1">No matches found.</div>
        </div>
        @isset($footer)
            <div class="border-top mt-2 pt-2">{{ $footer }}</div>
        @endisset
    </div>
</div>
