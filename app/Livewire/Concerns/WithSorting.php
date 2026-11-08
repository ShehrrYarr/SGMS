<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

/**
 * Column sorting for list pages; state is kept in the query string.
 * Components must define $sortable = ['column', ...] to whitelist columns.
 */
trait WithSorting
{
    #[Url(as: 'sort', except: '')]
    public string $sortField = '';

    #[Url(as: 'dir', except: 'desc')]
    public string $sortDirection = 'desc';

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortable ?? [], true)) {
            return;
        }

        $this->sortDirection = $this->sortField === $field && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortField = $field;

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    protected function applySorting($query, string $default = 'id', string $defaultDirection = 'desc')
    {
        $field = in_array($this->sortField, $this->sortable ?? [], true) ? $this->sortField : $default;
        $direction = $this->sortField ? ($this->sortDirection === 'asc' ? 'asc' : 'desc') : $defaultDirection;

        return $query->orderBy($field, $direction);
    }
}
