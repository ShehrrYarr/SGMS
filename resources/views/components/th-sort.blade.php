{{-- Sortable table header: <x-th-sort field="name" :sort="$sortField" :dir="$sortDirection">Name</x-th-sort> --}}
@props(['field', 'sort' => null, 'dir' => 'asc'])

<th {{ $attributes->merge(['class' => 'th-sortable']) }} wire:click="sortBy('{{ $field }}')" role="button">
    <span class="d-inline-flex align-items-center gap-1">
        {{ $slot }}
        @if ($sort === $field)
            <i class="{{ $dir === 'asc' ? 'ri-arrow-up-line' : 'ri-arrow-down-line' }} text-primary opacity-100"></i>
        @else
            <i class="ri-arrow-up-down-line"></i>
        @endif
    </span>
</th>
