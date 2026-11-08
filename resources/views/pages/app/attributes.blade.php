<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\ExpenseCategory;
use App\Models\Size;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Attributes')] class extends Component {
    use InteractsWithUi;

    protected const TABS = [
        'categories' => [Category::class, 'categories', 'Categories', 'ri-folder-3-line'],
        'brands' => [Brand::class, 'brands', 'Brands', 'ri-award-line'],
        'colors' => [Color::class, 'colors', 'Colours', 'ri-palette-line'],
        'sizes' => [Size::class, 'sizes', 'Sizes', 'ri-ruler-2-line'],
        'expense' => [ExpenseCategory::class, 'expense_categories', 'Expense types', 'ri-wallet-3-line'],
    ];

    #[Url]
    public string $tab = 'categories';

    public ?int $editId = null;

    public array $form = ['name' => '', 'code' => '', 'hex_code' => '#111111', 'sort_order' => 0];

    public function updatedTab(): void
    {
        $this->cancel();
    }

    public function edit(int $id): void
    {
        $model = $this->modelClass()::query()->findOrFail($id);
        $this->editId = $model->id;
        $this->form = [
            'name' => $model->name,
            'code' => $model->code ?? '',
            'hex_code' => $model->hex_code ?? '#111111',
            'sort_order' => $model->sort_order ?? 0,
        ];
    }

    public function cancel(): void
    {
        $this->editId = null;
        $this->form = ['name' => '', 'code' => '', 'hex_code' => '#111111', 'sort_order' => 0];
        $this->resetValidation();
    }

    public function save(): void
    {
        [$class, $table] = self::TABS[$this->tab] ?? abort(404);
        $unique = fn (string $column) => Rule::unique($table, $column)->where('tenant_id', tenant_id())->ignore($this->editId);

        $rules = ['form.name' => ['required', 'string', 'max:80']];

        match ($this->tab) {
            'sizes' => $rules += ['form.code' => ['required', 'string', 'max:20', $unique('code')], 'form.sort_order' => ['required', 'integer', 'min:0']],
            'colors' => $rules += ['form.hex_code' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'form.name' => ['required', 'string', 'max:80', $unique('name')]],
            'categories' => $rules['form.name'][] = fn ($a, $v, $fail) => Category::query()->where('slug', Str::slug($v))->when($this->editId, fn ($q) => $q->whereKeyNot($this->editId))->exists() && $fail('This category already exists.'),
            default => $rules['form.name'][] = $unique('name'),
        };

        $this->validate($rules, [], ['form.name' => 'name', 'form.code' => 'code']);

        $attributes = match ($this->tab) {
            'sizes' => ['name' => $this->form['name'], 'code' => Str::upper($this->form['code']), 'sort_order' => (int) $this->form['sort_order']],
            'colors' => ['name' => $this->form['name'], 'hex_code' => $this->form['hex_code']],
            'categories' => ['name' => $this->form['name'], 'slug' => Str::slug($this->form['name'])],
            default => ['name' => $this->form['name']],
        };

        $this->editId ? $class::query()->findOrFail($this->editId)->update($attributes) : $class::create($attributes);

        $this->toast($this->editId ? 'Updated.' : 'Added.');
        $this->cancel();
    }

    public function delete(int $id): void
    {
        $model = $this->modelClass()::query()->findOrFail($id);

        $inUse = match ($this->tab) {
            'categories', 'brands' => $model->products()->exists(),
            'colors', 'sizes' => $model->variants()->withTrashed()->exists(),
            'expense' => $model->expenses()->exists(),
        };

        if ($inUse) {
            $this->toast("“{$model->name}” is in use and cannot be deleted.", 'error');

            return;
        }

        $model->delete();
        $this->toast('Deleted.');
    }

    protected function modelClass(): string
    {
        return (self::TABS[$this->tab] ?? abort(404))[0];
    }

    public function with(): array
    {
        $class = $this->modelClass();
        $query = $class::query();

        $query = match ($this->tab) {
            'categories', 'brands' => $query->withCount('products')->orderBy('name'),
            'colors' => $query->withCount('variants')->orderBy('name'),
            'sizes' => $query->withCount('variants')->orderBy('sort_order'),
            'expense' => $query->withCount('expenses')->orderBy('name'),
        };

        return ['items' => $query->get(), 'tabs' => self::TABS];
    }
}; ?>

<div>
    <x-page-header title="Garment attributes" subtitle="Categories, brands, colours, sizes and expense types used across the shop." />

    <ul class="nav nav-pills nav-tabs-scroll mb-3">
        @foreach ($tabs as $key => [$c, $t, $label, $icon])
            <li class="nav-item"><button class="nav-link {{ $tab === $key ? 'active' : '' }}" wire:click="$set('tab', '{{ $key }}')"><i class="{{ $icon }} me-1"></i>{{ $label }}</button></li>
        @endforeach
    </ul>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">{{ $editId ? 'Edit' : 'Add' }} {{ strtolower(\Illuminate\Support\Str::singular($tabs[$tab][2])) }}</h6></div>
                <form wire:submit="save" class="card-body vstack gap-3">
                    <div>
                        <label class="form-label">Name</label>
                        <input wire:model="form.name" class="form-control @error('form.name') is-invalid @enderror" placeholder="{{ ['categories' => 'Shirts', 'brands' => 'Urban Basics', 'colors' => 'Olive Green', 'sizes' => 'Extra Large', 'expense' => 'Rent'][$tab] }}">
                        @error('form.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if ($tab === 'sizes')
                        <div class="row g-2">
                            <div class="col-7"><label class="form-label">Code</label><input wire:model="form.code" class="form-control @error('form.code') is-invalid @enderror" placeholder="XL / 32">@error('form.code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-5"><label class="form-label">Order</label><input wire:model="form.sort_order" type="number" min="0" class="form-control"></div>
                        </div>
                    @endif
                    @if ($tab === 'colors')
                        <div>
                            <label class="form-label">Colour</label>
                            <div class="input-group">
                                <input wire:model.live="form.hex_code" type="color" class="form-control form-control-color" style="max-width: 60px">
                                <input wire:model.live="form.hex_code" class="form-control font-monospace @error('form.hex_code') is-invalid @enderror">
                            </div>
                        </div>
                    @endif
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary flex-grow-1" type="submit"><i class="ri-save-line me-1"></i>{{ $editId ? 'Update' : 'Add' }}</button>
                        @if ($editId)<button class="btn btn-light" type="button" wire:click="cancel">Cancel</button>@endif
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Name</th>@if ($tab === 'sizes')<th>Code</th>@endif<th class="text-center">Used by</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($items as $item)
                                <tr wire:key="{{ $tab }}-{{ $item->id }}" class="{{ $editId === $item->id ? 'table-active' : '' }}">
                                    <td>@if ($tab === 'colors')<span class="color-dot me-2" style="background: {{ $item->hex_code }}"></span>@endif{{ $item->name }}</td>
                                    @if ($tab === 'sizes')<td><span class="badge bg-light text-body border">{{ $item->code }}</span></td>@endif
                                    <td class="text-center">{{ $item->products_count ?? $item->variants_count ?? $item->expenses_count ?? 0 }}</td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-light-secondary" wire:click="edit({{ $item->id }})"><i class="ri-pencil-line"></i></button>
                                        <button class="btn btn-sm btn-light-danger" x-on:click="SGMS.confirm({ title: 'Delete {{ addslashes($item->name) }}?' }).then(ok => ok && $wire.delete({{ $item->id }}))"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><x-empty-state title="Nothing added yet" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
