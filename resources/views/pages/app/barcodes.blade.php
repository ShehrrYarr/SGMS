<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Services\BarcodeService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Barcode Labels')] class extends Component {
    use InteractsWithUi;

    /** variant_id => copies */
    public array $copies = [];

    public ?int $pick = null;

    public string $template = '38x25';

    public bool $showPrice = true;

    public bool $showShop = true;

    public function mount(): void
    {
        if ($purchaseId = request()->integer('purchase')) {
            $purchase = Purchase::query()->with('items')->find($purchaseId);

            foreach ($purchase?->items ?? [] as $item) {
                $this->copies[$item->product_variant_id] = ($this->copies[$item->product_variant_id] ?? 0) + $item->quantity;
            }
        }

        if ($productId = request()->integer('product')) {
            foreach (ProductVariant::query()->where('product_id', $productId)->pluck('id') as $id) {
                $this->copies[$id] = 1;
            }
        }
    }

    #[Renderless]
    public function searchVariants(string $term = ''): array
    {
        return ProductVariant::query()->with('product:id,name', 'color:id,name,hex_code', 'size:id,code')
            ->search($term)->limit(25)->get()
            ->map(fn ($v) => ['id' => $v->id, 'label' => $v->label, 'sub' => $v->barcode.' · '.money($v->retail_price), 'color' => $v->color?->hex_code])
            ->all();
    }

    public function updatedPick($id): void
    {
        if ($id && ProductVariant::query()->whereKey($id)->exists()) {
            $this->copies[(int) $id] = ($this->copies[(int) $id] ?? 0) + 1;
        }

        $this->pick = null;
    }

    public function remove(int $id): void
    {
        unset($this->copies[$id]);
    }

    public function clear(): void
    {
        $this->copies = [];
    }

    public function with(): array
    {
        $barcodes = app(BarcodeService::class);
        $variants = ProductVariant::query()->with('product:id,name', 'color:id,name,hex_code', 'size:id,code')
            ->whereIn('id', array_keys($this->copies))->get()->keyBy('id');

        [$w, $h] = match ($this->template) {
            '50x30' => [160, 34],
            'a4' => [200, 46],
            default => [120, 28],
        };

        $svgs = $variants->map(fn ($v) => $barcodes->svg($v->barcode, $w, $h));

        return [
            'variants' => $variants,
            'svgs' => $svgs,
            'total' => array_sum(array_map('intval', $this->copies)),
        ];
    }
}; ?>

<div x-data="{ print() { document.body.classList.add('printing-labels'); window.print(); setTimeout(() => document.body.classList.remove('printing-labels'), 500); } }">
    <x-page-header title="Barcode labels" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Products' => route('app.products.index'), 'Labels' => null]">
        <x-slot:actions>
            <button class="btn btn-light-danger" wire:click="clear" @disabled(! $copies)><i class="ri-delete-bin-line me-1"></i>Clear</button>
            <button class="btn btn-primary" x-on:click="print()" @disabled(! $total)><i class="ri-printer-line me-1"></i>Print {{ $total }} labels</button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header">
                    <x-combobox wire:model.live="pick" search="searchVariants" placeholder="Search or scan item to add…" wire:key="lbl-pick-{{ count($copies) }}" />
                </div>
                <div class="table-responsive" style="max-height: 460px">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light"><tr><th>Item</th><th style="width: 90px">Copies</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($copies as $id => $count)
                                @php $v = $variants->get($id); @endphp
                                @if ($v)
                                    <tr wire:key="lb-{{ $id }}">
                                        <td><div class="fw-medium small">{{ $v->label }}</div><small class="text-muted font-monospace">{{ $v->barcode }}</small></td>
                                        <td><input wire:model.live.debounce.400ms="copies.{{ $id }}" type="number" min="0" max="500" class="form-control form-control-sm"></td>
                                        <td class="text-end"><button class="btn btn-sm btn-light-danger" wire:click="remove({{ $id }})"><i class="ri-close-line"></i></button></td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td colspan="3"><x-empty-state icon="ri-barcode-line" title="No items" text="Add items, or open this page from a lot or product." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card-body vstack gap-3">
                    <div>
                        <label class="form-label">Label size</label>
                        <select wire:model.live="template" class="form-select">
                            <option value="38x25">Thermal 38 × 25 mm (1 per row)</option>
                            <option value="50x30">Thermal 50 × 30 mm (1 per row)</option>
                            <option value="a4">A4 sheet — 63.5 × 38.1 mm (21 per page)</option>
                        </select>
                    </div>
                    <div class="d-flex gap-4">
                        <div class="form-check"><input wire:model.live="showShop" class="form-check-input" type="checkbox" id="ls"><label class="form-check-label" for="ls">Shop name</label></div>
                        <div class="form-check"><input wire:model.live="showPrice" class="form-check-input" type="checkbox" id="lp"><label class="form-check-label" for="lp">Price</label></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Preview</h6></div>
                <div class="card-body bg-light" style="max-height: 640px; overflow: auto">
                    <div id="label-print-area">
                        <style>
                            @media print { @page { size: {{ $template === 'a4' ? 'A4' : str_replace('x', 'mm ', $template).'mm' }}; margin: {{ $template === 'a4' ? '10mm 7mm' : '0' }}; } }
                        </style>
                        <div class="label-sheet {{ $template === 'a4' ? '' : 'flex-column' }}">
                            @foreach ($copies as $id => $count)
                                @php $v = $variants->get($id); @endphp
                                @if ($v)
                                    @for ($i = 0; $i < min(500, (int) $count); $i++)
                                        <div class="barcode-label label-{{ $template }}">
                                            @if ($showShop)<div class="l-shop">{{ tenant()->name }}</div>@endif
                                            <div class="l-name">{{ $v->product->name }}</div>
                                            <div class="l-variant">{{ $v->variant_name }}</div>
                                            {!! $svgs->get($id) !!}
                                            <div class="l-code">{{ $v->barcode }}</div>
                                            @if ($showPrice)<div class="l-price">{{ money($v->retail_price) }}</div>@endif
                                        </div>
                                    @endfor
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
