<?php

use App\Exceptions\BusinessException;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Branch;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Services\DocumentNumber;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Support\Catalog;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('New Lot Purchase')] class extends Component {
    use InteractsWithUi;

    public int $step = 1;

    // Step 1 — vendor
    public ?int $vendor_id = null;

    public string $vendorLabel = '';

    // Step 2 — lot details
    public ?int $branch_id = null;

    public string $lot_number = '';

    public string $lot_name = '';

    public string $purchase_date = '';

    public $total_lot_cost = '';

    public string $notes = '';

    // Step 3 — items
    public array $items = [];

    // Step 4 — payment split
    public string $payment_mode = 'cash'; // cash | bank | split | credit | partial

    public $cash_amount = '';

    public ?int $bank_account_id = null;

    public $bank_amount = '';

    public string $bank_reference = '';

    // Inline product + size-run helpers
    public array $newProduct = ['name' => '', 'category_id' => null, 'brand_id' => null, 'base_price' => '', 'row' => null];

    public array $sizeRun = ['product_id' => null, 'product_label' => '', 'color_ids' => [], 'size_ids' => [], 'quantity' => 10, 'retail_price' => '', 'wholesale_price' => ''];

    public function mount(): void
    {
        $this->branch_id = tenancy()->operationalBranchId();
        $this->purchase_date = today()->toDateString();
        $this->bank_account_id = PaymentAccount::query()->active()->whereIn('account_type', [PaymentAccount::TYPE_BANK, PaymentAccount::TYPE_WALLET])->value('id');
        $this->addRow();

        if ($vendorId = request()->integer('vendor')) {
            $this->vendorSaved($vendorId);
        }
    }

    /* ---------------- Vendors ---------------- */

    #[Renderless]
    public function searchVendors(string $term = ''): array
    {
        return Vendor::query()->where('status', 'active')->search($term)->orderBy('name')->limit(20)
            ->get(['id', 'name', 'company', 'phone', 'current_balance'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'label' => $v->name.($v->company ? ' — '.$v->company : ''),
                'sub' => trim(($v->phone ?? '').' · Balance '.money($v->current_balance), ' ·'),
            ])->all();
    }

    #[On('vendor-saved')]
    public function vendorSaved(int $id, ?string $label = null): void
    {
        $vendor = Vendor::query()->find($id);

        if ($vendor) {
            $this->vendor_id = $vendor->id;
            $this->vendorLabel = $label ?? $vendor->name.($vendor->company ? ' — '.$vendor->company : '');
        }
    }

    public function updatedVendorId($value): void
    {
        $vendor = $value ? Vendor::query()->find($value) : null;
        $this->vendorLabel = $vendor ? $vendor->name.($vendor->company ? ' — '.$vendor->company : '') : '';
    }

    /* ---------------- Items ---------------- */

    #[Renderless]
    public function searchProducts(string $term = ''): array
    {
        return Product::query()->active()->search($term)->with('category:id,name')->orderBy('name')->limit(25)
            ->get(['id', 'name', 'sku', 'category_id', 'base_price'])
            ->map(fn ($p) => ['id' => $p->id, 'label' => $p->name, 'sub' => $p->sku.($p->category ? ' · '.$p->category->name : '')])
            ->all();
    }

    public function addRow(array $values = []): void
    {
        $this->items[] = array_merge([
            'key' => Str::random(8),
            'product_id' => null,
            'product_label' => '',
            'color_id' => null,
            'size_id' => null,
            'barcode' => '',
            'quantity' => 1,
            'cost_per_item' => '',
            'cost_overridden' => false,
            'retail_price' => '',
            'wholesale_price' => '',
        ], $values);

        $this->recalculateCosts();
    }

    public function duplicateRow(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $row = $this->items[$index];
        $this->addRow(array_merge($row, ['key' => Str::random(8), 'size_id' => null, 'barcode' => '']));
    }

    public function removeRow(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if (! $this->items) {
            $this->addRow();
        }

        $this->recalculateCosts();
    }

    public function updatedItems($value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if (! isset($this->items[$index])) {
            return;
        }

        if ($field === 'product_id') {
            $product = $value ? Product::query()->find($value) : null;
            $this->items[$index]['product_label'] = $product?->name ?? '';

            if ($product && blank($this->items[$index]['retail_price'])) {
                $this->items[$index]['retail_price'] = (float) $product->base_price ?: '';
            }
        }

        if ($field === 'cost_per_item') {
            $this->items[$index]['cost_overridden'] = $value !== '' && $value !== null;
        }

        if (in_array($field, ['quantity', 'cost_per_item'], true)) {
            $this->recalculateCosts();
        }
    }

    public function updatedTotalLotCost(): void
    {
        $this->recalculateCosts();
    }

    public function resetCost(int $index): void
    {
        $this->items[$index]['cost_overridden'] = false;
        $this->recalculateCosts();
    }

    /**
     * Auto-average: the lot cost not taken by manually priced rows is spread
     * evenly over the pieces of all other rows.
     */
    public function recalculateCosts(): void
    {
        $lotCost = (float) $this->total_lot_cost;

        if ($lotCost <= 0) {
            return;
        }

        $fixedCost = 0.0;
        $autoQty = 0;

        foreach ($this->items as $row) {
            if ($row['cost_overridden']) {
                $fixedCost += (float) $row['cost_per_item'] * (int) $row['quantity'];
            } else {
                $autoQty += max(0, (int) $row['quantity']);
            }
        }

        if ($autoQty <= 0) {
            return;
        }

        $average = round(max(0, $lotCost - $fixedCost) / $autoQty, 2);

        foreach ($this->items as $i => $row) {
            if (! $row['cost_overridden']) {
                $this->items[$i]['cost_per_item'] = $average;
            }
        }
    }

    public function openSizeRun(): void
    {
        $this->sizeRun = ['product_id' => null, 'product_label' => '', 'color_ids' => [], 'size_ids' => [], 'quantity' => 10, 'retail_price' => '', 'wholesale_price' => ''];
        $this->resetValidation();
        $this->openModal('size-run');
    }

    public function updatedSizeRunProductId($value): void
    {
        $product = $value ? Product::query()->find($value) : null;
        $this->sizeRun['product_label'] = $product?->name ?? '';
        $this->sizeRun['retail_price'] = $product ? (float) $product->base_price : '';
    }

    public function applySizeRun(): void
    {
        $this->validate([
            'sizeRun.product_id' => ['required', 'integer'],
            'sizeRun.size_ids' => ['required', 'array', 'min:1'],
            'sizeRun.quantity' => ['required', 'integer', 'min:1'],
            'sizeRun.retail_price' => ['required', 'numeric', 'min:0'],
        ], [], ['sizeRun.product_id' => 'product', 'sizeRun.size_ids' => 'sizes']);

        // Drop the empty starter row.
        $this->items = array_values(array_filter($this->items, fn ($r) => $r['product_id']));

        $colors = $this->sizeRun['color_ids'] ?: [null];
        $sizes = collect(Catalog::sizes())->pluck('id')->filter(fn ($id) => in_array($id, array_map('intval', $this->sizeRun['size_ids']), true));

        foreach ($colors as $colorId) {
            foreach ($sizes as $sizeId) {
                $this->addRow([
                    'product_id' => (int) $this->sizeRun['product_id'],
                    'product_label' => $this->sizeRun['product_label'],
                    'color_id' => $colorId ? (int) $colorId : null,
                    'size_id' => $sizeId,
                    'quantity' => (int) $this->sizeRun['quantity'],
                    'retail_price' => $this->sizeRun['retail_price'],
                    'wholesale_price' => $this->sizeRun['wholesale_price'],
                ]);
            }
        }

        $this->closeModal('size-run');
        $this->toast(count($colors) * $sizes->count().' rows added.');
    }

    public function startNewProduct(?int $row = null): void
    {
        $this->newProduct = ['name' => '', 'category_id' => null, 'brand_id' => null, 'base_price' => '', 'row' => $row];
        $this->resetValidation();
        $this->openModal('new-product');
    }

    public function createProduct(ProductService $products): void
    {
        $this->validate([
            'newProduct.name' => ['required', 'string', 'max:150'],
            'newProduct.category_id' => ['nullable', 'integer'],
            'newProduct.brand_id' => ['nullable', 'integer'],
            'newProduct.base_price' => ['nullable', 'numeric', 'min:0'],
        ], [], ['newProduct.name' => 'product name']);

        $product = $products->create([
            'name' => $this->newProduct['name'],
            'category_id' => $this->newProduct['category_id'] ?: null,
            'brand_id' => $this->newProduct['brand_id'] ?: null,
            'base_price' => $this->newProduct['base_price'] ?: 0,
        ]);

        $row = $this->newProduct['row'];

        if ($row !== null && isset($this->items[$row])) {
            $this->items[$row]['product_id'] = $product->id;
            $this->items[$row]['product_label'] = $product->name;
            $this->items[$row]['retail_price'] = $this->items[$row]['retail_price'] ?: (float) $product->base_price;
        } elseif ($row === null && $this->sizeRun['product_id'] === null) {
            $this->sizeRun['product_id'] = $product->id;
            $this->sizeRun['product_label'] = $product->name;
            $this->sizeRun['retail_price'] = (float) $product->base_price;
        }

        $this->closeModal('new-product');
        $this->toast("Product “{$product->name}” created.");
    }

    /* ---------------- Steps & totals ---------------- */

    protected function calcTotals(): array
    {
        $qty = 0;
        $cost = 0.0;

        foreach ($this->items as $row) {
            $qty += max(0, (int) $row['quantity']);
            $cost += max(0, (int) $row['quantity']) * (float) $row['cost_per_item'];
        }

        $lot = round((float) $this->total_lot_cost, 2);
        $cost = round($cost, 2);

        return ['qty' => $qty, 'cost' => $cost, 'lot' => $lot, 'difference' => round($lot - $cost, 2), 'tolerance' => max(1, $qty * 0.01)];
    }

    protected function paymentLegs(): array
    {
        $total = (float) $this->total_lot_cost;
        $cash = $this->cashAccountId();

        return match ($this->payment_mode) {
            'cash' => [['payment_account_id' => $cash, 'amount' => $total]],
            'bank' => [['payment_account_id' => $this->bank_account_id, 'amount' => $total, 'reference_no' => $this->bank_reference]],
            'split', 'partial' => array_values(array_filter([
                (float) $this->cash_amount > 0 ? ['payment_account_id' => $cash, 'amount' => (float) $this->cash_amount] : null,
                (float) $this->bank_amount > 0 ? ['payment_account_id' => $this->bank_account_id, 'amount' => (float) $this->bank_amount, 'reference_no' => $this->bank_reference] : null,
            ])),
            default => [],
        };
    }

    protected function cashAccountId(): ?int
    {
        return PaymentAccount::query()->where('branch_id', $this->branch_id)->where('account_type', PaymentAccount::TYPE_CASH)->where('is_default', true)->value('id');
    }

    public function goTo(int $step): void
    {
        for ($s = 1; $s < $step; $s++) {
            if (! $this->validateStep($s)) {
                $this->step = $s;

                return;
            }
        }

        $this->step = max(1, min(4, $step));

        if ($this->step === 4 && $this->payment_mode === 'split' && $this->cash_amount === '' && $this->bank_amount === '') {
            $this->cash_amount = round((float) $this->total_lot_cost / 2, 2);
            $this->bank_amount = round((float) $this->total_lot_cost - (float) $this->cash_amount, 2);
        }
    }

    protected function validateStep(int $step): bool
    {
        try {
            match ($step) {
                1 => $this->validate(['vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('tenant_id', tenant_id())]], ['vendor_id.required' => 'Select or add a vendor.']),
                2 => $this->validate([
                    'branch_id' => ['required', 'integer', Rule::in(tenancy()->allowedBranchIds())],
                    'lot_number' => ['nullable', 'string', 'max:40', Rule::unique('purchases', 'lot_number')->where('tenant_id', tenant_id())],
                    'lot_name' => ['nullable', 'string', 'max:150'],
                    'purchase_date' => ['required', 'date', 'before_or_equal:today'],
                    'total_lot_cost' => ['required', 'numeric', 'gt:0'],
                ], [], ['total_lot_cost' => 'total lot cost']),
                3 => $this->validateItems(),
                default => true,
            };
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return false;
        }

        return true;
    }

    protected function validateItems(): void
    {
        $this->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.color_id' => ['nullable', 'integer'],
            'items.*.size_id' => ['nullable', 'integer'],
            'items.*.barcode' => ['nullable', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.cost_per_item' => ['required', 'numeric', 'min:0'],
            'items.*.retail_price' => ['required', 'numeric', 'min:0'],
            'items.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'items.*.product_id.required' => 'Pick a product on every row.',
            'items.*.retail_price.required' => 'Set a selling price on every row.',
        ]);

        $combos = collect($this->items)->map(fn ($r) => $r['product_id'].'-'.($r['color_id'] ?: 0).'-'.($r['size_id'] ?: 0));

        if ($combos->duplicates()->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'The same product, colour and size appears on more than one row. Combine them.']);
        }

        $totals = $this->calcTotals();

        if (abs($totals['difference']) > $totals['tolerance']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'Item costs add up to '.money($totals['cost']).' but the lot cost is '.money($totals['lot']).'. Adjust the costs or the lot total.']);
        }
    }

    public function matchLotToItems(): void
    {
        $this->total_lot_cost = $this->calcTotals()['cost'];
        $this->resetErrorBag('items');
    }

    public function save(PurchaseService $service): void
    {
        foreach ([1, 2, 3] as $s) {
            if (! $this->validateStep($s)) {
                $this->step = $s;

                return;
            }
        }

        $total = round((float) $this->total_lot_cost, 2);
        $payments = $this->paymentLegs();
        $paid = round(collect($payments)->sum('amount'), 2);

        $rules = ['payment_mode' => ['required', Rule::in(['cash', 'bank', 'split', 'credit', 'partial'])]];

        if (in_array($this->payment_mode, ['bank', 'split', 'partial'], true) && (float) $this->bank_amount + ($this->payment_mode === 'bank' ? 1 : 0) > 0) {
            $rules['bank_account_id'] = ['required', 'integer'];
        }

        $this->validate($rules);

        $error = match ($this->payment_mode) {
            'split' => abs($paid - $total) > 0.009 ? 'Cash + bank must equal the lot cost ('.money($total).').' : ((float) $this->cash_amount <= 0 || (float) $this->bank_amount <= 0 ? 'Enter both a cash and a bank amount for a split payment.' : null),
            'partial' => $paid <= 0 ? 'Enter the amount paid now (cash and/or bank).' : ($paid >= $total ? 'Partial payment must be less than the lot cost — use Cash, Bank or Split instead.' : null),
            default => null,
        };

        if ($error) {
            $this->addError('payment', $error);

            return;
        }

        try {
            $purchase = $service->create([
                'branch_id' => $this->branch_id,
                'vendor_id' => $this->vendor_id,
                'lot_number' => $this->lot_number ?: null,
                'lot_name' => $this->lot_name ?: null,
                'purchase_date' => $this->purchase_date,
                'total_lot_cost' => $total,
                'notes' => $this->notes ?: null,
                'items' => collect($this->items)->map(fn ($r) => [
                    'product_id' => (int) $r['product_id'],
                    'color_id' => $r['color_id'] ? (int) $r['color_id'] : null,
                    'size_id' => $r['size_id'] ? (int) $r['size_id'] : null,
                    'barcode' => $r['barcode'] ?: null,
                    'quantity' => (int) $r['quantity'],
                    'cost_per_item' => (float) $r['cost_per_item'],
                    'retail_price' => (float) $r['retail_price'],
                    'wholesale_price' => (float) ($r['wholesale_price'] ?: 0),
                ])->all(),
                'payments' => $payments,
            ]);
        } catch (BusinessException $e) {
            $this->addError('payment', $e->getMessage());
            $this->toast($e->getMessage(), 'error');

            return;
        }

        session()->flash('success', "Lot {$purchase->lot_number} received: {$purchase->total_garment_qty} pieces added to stock.");
        $this->redirectRoute('app.purchases.show', $purchase, navigate: true);
    }

    public function with(): array
    {
        $branches = tenancy()->branches()->whereIn('id', tenancy()->allowedBranchIds());

        return [
            'branches' => $branches,
            'colors' => Catalog::colors(),
            'sizes' => Catalog::sizes(),
            'categories' => Catalog::categories(),
            'brands' => Catalog::brands(),
            'banks' => PaymentAccount::query()->active()->whereIn('account_type', [PaymentAccount::TYPE_BANK, PaymentAccount::TYPE_WALLET])->get(),
            'cashAccount' => PaymentAccount::query()->find($this->cashAccountId()),
            'nextLot' => DocumentNumber::peek('purchase', 5),
            'totals' => $this->calcTotals(),
            'paymentTotal' => round(collect($this->paymentLegs())->sum('amount'), 2),
        ];
    }
}; ?>

<div>
    <x-page-header title="New lot purchase" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Lot purchases' => route('app.purchases.index'), 'New lot' => null]" />

    {{-- Stepper --}}
    <div class="wizard-steps mb-3">
        @foreach ([1 => ['Vendor', 'ri-truck-line'], 2 => ['Lot details', 'ri-file-list-2-line'], 3 => ['Garment breakdown', 'ri-t-shirt-2-line'], 4 => ['Payment', 'ri-secure-payment-line']] as $n => [$label, $icon])
            <div class="wizard-step {{ $step === $n ? 'active' : ($step > $n ? 'done' : '') }}" wire:click="goTo({{ $n }})">
                <span class="num">@if ($step > $n)<i class="ri-check-line"></i>@else{{ $n }}@endif</span>
                <span class="fw-medium d-none d-sm-inline">{{ $label }}</span>
                <i class="{{ $icon }} d-sm-none"></i>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-xl-9">
            {{-- STEP 1 --}}
            @if ($step === 1)
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">Step 1 · Select vendor</h6></div>
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label">Vendor / supplier</label>
                                <x-combobox wire:model.live="vendor_id" search="searchVendors" :label="$vendorLabel" placeholder="Search vendors by name, company or phone…" wire:key="vendor-{{ $vendor_id }}" :invalid="$errors->has('vendor_id')">
                                    <x-slot:footer>
                                        <button type="button" class="btn btn-sm btn-light-primary w-100" x-on:click="close(); $dispatch('vendor-form', { id: null })"><i class="ri-add-line me-1"></i>Add new vendor</button>
                                    </x-slot:footer>
                                </x-combobox>
                                @error('vendor_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-light-primary w-100" x-on:click="$dispatch('vendor-form', { id: null })"><i class="ri-user-add-line me-1"></i>Add vendor</button>
                            </div>
                        </div>
                        @if ($vendor_id && ($v = \App\Models\Vendor::find($vendor_id)))
                            <div class="alert alert-light border mt-3 mb-0 d-flex flex-wrap gap-3 align-items-center">
                                <i class="ri-truck-line fs-3 text-primary"></i>
                                <div class="flex-grow-1"><div class="fw-semibold">{{ $v->name }}</div><small class="text-muted">{{ $v->company }} {{ $v->phone ? '· '.$v->phone : '' }}</small></div>
                                <div class="text-end"><small class="text-muted d-block">Current payable</small><b class="{{ (float) $v->current_balance > 0 ? 'text-danger' : '' }}">{{ money($v->current_balance) }}</b></div>
                            </div>
                        @endif
                    </div>
                    <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary" wire:click="goTo(2)">Continue <i class="ri-arrow-right-line ms-1"></i></button></div>
                </div>
            @endif

            {{-- STEP 2 --}}
            @if ($step === 2)
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">Step 2 · Lot details</h6></div>
                    <div class="card-body row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Lot number</label>
                            <input wire:model="lot_number" class="form-control @error('lot_number') is-invalid @enderror" placeholder="Auto: {{ $nextLot }}">
                            @error('lot_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Lot name</label>
                            <input wire:model="lot_name" class="form-control" placeholder="e.g. Eid collection — mixed shirts">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Receive into branch</label>
                            <select wire:model="branch_id" class="form-select @error('branch_id') is-invalid @enderror">
                                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Purchase date</label>
                            <input wire:model="purchase_date" type="date" class="form-control @error('purchase_date') is-invalid @enderror" max="{{ today()->toDateString() }}">
                            @error('purchase_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total lot cost <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">{{ tenant()->currency_symbol }}</span>
                                <input wire:model.live.debounce.500ms="total_lot_cost" type="number" step="0.01" min="0" class="form-control fw-semibold @error('total_lot_cost') is-invalid @enderror">
                                @error('total_lot_cost')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-text">Amount paid / owed for the whole lot, incl. freight.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea wire:model="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <button class="btn btn-light" wire:click="goTo(1)"><i class="ri-arrow-left-line me-1"></i>Back</button>
                        <button class="btn btn-primary" wire:click="goTo(3)">Continue <i class="ri-arrow-right-line ms-1"></i></button>
                    </div>
                </div>
            @endif

            {{-- STEP 3 --}}
            @if ($step === 3)
                <div class="card">
                    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-0">Step 3 · Garment breakdown</h6>
                            <small class="text-muted">Costs auto-average from the lot total; type a cost to override a row.</small>
                        </div>
                        <button type="button" class="btn btn-sm btn-light-info" wire:click="openSizeRun"><i class="ri-grid-line me-1"></i>Size run</button>
                        <button type="button" class="btn btn-sm btn-light-secondary" wire:click="startNewProduct"><i class="ri-add-circle-line me-1"></i>New product</button>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="addRow"><i class="ri-add-line me-1"></i>Add row</button>
                    </div>
                    <div class="card-body p-2 p-lg-0">
                        @error('items')<div class="alert alert-danger m-2 d-flex flex-wrap gap-2 align-items-center"><span class="flex-grow-1">{{ $message }}</span>@if (str_contains($message, 'lot cost'))<button class="btn btn-sm btn-danger" wire:click="matchLotToItems">Set lot cost to items total</button>@endif</div>@enderror
                        <div class="lot-items">
                            <table class="table table-borderless mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th style="min-width: 170px">Product</th><th style="width: 104px">Colour</th><th style="width: 84px">Size</th><th style="width: 118px">Barcode</th><th style="width: 72px">Qty</th><th style="width: 118px">Cost / pc</th><th style="width: 96px">Retail</th><th style="width: 96px">Wholesale</th><th style="width: 76px"></th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($items as $i => $row)
                                        <tr wire:key="row-{{ $row['key'] }}" class="border-bottom">
                                            <td class="col-wide" data-label="Product">
                                                <div class="d-flex gap-1">
                                                    <x-combobox class="flex-grow-1" wire:model.live="items.{{ $i }}.product_id" search="searchProducts" :label="$row['product_label']" placeholder="Search product…" size="sm" wire:key="p-{{ $row['key'] }}-{{ $row['product_id'] }}" :invalid="$errors->has('items.'.$i.'.product_id')" />
                                                    <button type="button" class="btn btn-sm btn-light" wire:click="startNewProduct({{ $i }})" title="Create product"><i class="ri-add-line"></i></button>
                                                </div>
                                            </td>
                                            <td data-label="Colour">
                                                <select wire:model="items.{{ $i }}.color_id" class="form-select form-select-sm">
                                                    <option value="">—</option>
                                                    @foreach ($colors as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach
                                                </select>
                                            </td>
                                            <td data-label="Size">
                                                <select wire:model="items.{{ $i }}.size_id" class="form-select form-select-sm">
                                                    <option value="">—</option>
                                                    @foreach ($sizes as $s)<option value="{{ $s['id'] }}">{{ $s['code'] }}</option>@endforeach
                                                </select>
                                            </td>
                                            <td data-label="Barcode"><input wire:model="items.{{ $i }}.barcode" class="form-control form-control-sm" placeholder="Auto"></td>
                                            <td data-label="Qty"><input wire:model.live.debounce.400ms="items.{{ $i }}.quantity" type="number" min="1" class="form-control form-control-sm @error('items.'.$i.'.quantity') is-invalid @enderror"></td>
                                            <td data-label="Cost / pc">
                                                <div class="input-group input-group-sm">
                                                    <input wire:model.live.debounce.500ms="items.{{ $i }}.cost_per_item" type="number" step="0.01" min="0" class="form-control {{ $row['cost_overridden'] ? 'border-warning' : 'bg-light' }}" title="{{ $row['cost_overridden'] ? 'Manual cost' : 'Auto-averaged' }}">
                                                    @if ($row['cost_overridden'])
                                                        <button class="btn btn-light border" type="button" wire:click="resetCost({{ $i }})" title="Back to auto-average"><i class="ri-refresh-line"></i></button>
                                                    @endif
                                                </div>
                                            </td>
                                            <td data-label="Retail"><input wire:model="items.{{ $i }}.retail_price" type="number" step="0.01" min="0" class="form-control form-control-sm @error('items.'.$i.'.retail_price') is-invalid @enderror"></td>
                                            <td data-label="Wholesale"><input wire:model="items.{{ $i }}.wholesale_price" type="number" step="0.01" min="0" class="form-control form-control-sm"></td>
                                            <td class="text-end text-nowrap" data-label="">
                                                <button type="button" class="btn btn-sm btn-light" wire:click="duplicateRow({{ $i }})" title="Duplicate (next size)"><i class="ri-file-copy-line"></i></button>
                                                <button type="button" class="btn btn-sm btn-light-danger" wire:click="removeRow({{ $i }})" title="Remove"><i class="ri-delete-bin-line"></i></button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <button class="btn btn-light" wire:click="goTo(2)"><i class="ri-arrow-left-line me-1"></i>Back</button>
                        <div class="small {{ abs($totals['difference']) > $totals['tolerance'] ? 'text-danger' : 'text-success' }}">
                            {{ $totals['qty'] }} pcs · items {{ money($totals['cost']) }} / lot {{ money($totals['lot']) }}
                            @if (abs($totals['difference']) > 0.009) ({{ $totals['difference'] > 0 ? '+' : '' }}{{ money($totals['difference']) }}) @endif
                        </div>
                        <button class="btn btn-primary" wire:click="goTo(4)">Continue <i class="ri-arrow-right-line ms-1"></i></button>
                    </div>
                </div>
            @endif

            {{-- STEP 4 --}}
            @if ($step === 4)
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">Step 4 · Payment split</h6></div>
                    <div class="card-body">
                        <div class="row g-2 mb-4">
                            @foreach (['cash' => ['Cash', 'ri-safe-2-line', 'From branch drawer'], 'bank' => ['Bank', 'ri-bank-line', 'One bank account'], 'split' => ['Split', 'ri-git-branch-line', 'Cash + bank'], 'credit' => ['Full credit', 'ri-book-open-line', 'All to vendor ledger'], 'partial' => ['Partial credit', 'ri-pie-chart-2-line', 'Pay part, owe rest']] as $mode => [$label, $icon, $hint])
                                <div class="col-6 col-md">
                                    <div class="pay-mode {{ $payment_mode === $mode ? 'active' : '' }}" wire:click="$set('payment_mode', '{{ $mode }}')">
                                        <i class="{{ $icon }}"></i><div class="fw-semibold small">{{ $label }}</div><div class="text-muted" style="font-size: .72rem">{{ $hint }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="row g-3">
                            @if (in_array($payment_mode, ['cash', 'split', 'partial'], true))
                                <div class="col-md-6">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between mb-2"><span class="fw-semibold"><i class="ri-safe-2-line me-1"></i>{{ $cashAccount?->account_name ?? 'No cash drawer' }}</span><small class="text-muted">Balance {{ money($cashAccount?->current_balance) }}</small></div>
                                        @if ($payment_mode === 'cash')
                                            <div class="fs-4 fw-bold">{{ money($total_lot_cost) }}</div>
                                        @else
                                            <label class="form-label small">Cash amount</label>
                                            <input wire:model.live.debounce.400ms="cash_amount" type="number" step="0.01" min="0" class="form-control">
                                        @endif
                                    </div>
                                </div>
                            @endif
                            @if (in_array($payment_mode, ['bank', 'split', 'partial'], true))
                                <div class="col-md-6">
                                    <div class="border rounded-3 p-3 h-100">
                                        <label class="form-label small">Bank / wallet account</label>
                                        <select wire:model.live="bank_account_id" class="form-select mb-2 @error('bank_account_id') is-invalid @enderror">
                                            <option value="">Select account…</option>
                                            @foreach ($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->label }} — {{ money($bank->current_balance) }}</option>@endforeach
                                        </select>
                                        @if ($payment_mode === 'bank')
                                            <div class="fs-4 fw-bold mb-2">{{ money($total_lot_cost) }}</div>
                                        @else
                                            <label class="form-label small">Bank amount</label>
                                            <input wire:model.live.debounce.400ms="bank_amount" type="number" step="0.01" min="0" class="form-control mb-2">
                                        @endif
                                        <input wire:model="bank_reference" class="form-control form-control-sm" placeholder="Cheque / transfer reference">
                                    </div>
                                </div>
                            @endif
                            @if ($payment_mode === 'credit')
                                <div class="col-12"><div class="alert alert-warning mb-0"><i class="ri-book-open-line me-1"></i>No money leaves your accounts. The full <b>{{ money($total_lot_cost) }}</b> is added to the vendor ledger as payable.</div></div>
                            @endif
                        </div>

                        @php $due = round((float) $total_lot_cost - $paymentTotal, 2); @endphp
                        <div class="d-flex flex-wrap gap-4 mt-4 p-3 bg-light rounded-3">
                            <div><small class="text-muted d-block">Lot cost</small><b>{{ money($total_lot_cost) }}</b></div>
                            <div><small class="text-muted d-block">Paying now</small><b class="text-success">{{ money($paymentTotal) }}</b></div>
                            <div><small class="text-muted d-block">To vendor ledger</small><b class="{{ $due > 0 ? 'text-danger' : '' }}">{{ money(max(0, $due)) }}</b></div>
                        </div>
                        @error('payment')<div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>@enderror
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <button class="btn btn-light" wire:click="goTo(3)"><i class="ri-arrow-left-line me-1"></i>Back</button>
                        <button class="btn btn-success" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save"><i class="ri-check-double-line me-1"></i>Receive lot</span>
                            <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1"></span>Saving…</span>
                        </button>
                    </div>
                </div>
            @endif
        </div>

        {{-- Summary --}}
        <div class="col-xl-3">
            <div class="card position-sticky" style="top: 80px">
                <div class="card-header"><h6 class="card-title mb-0">Lot summary</h6></div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Vendor</span><span class="text-end text-truncate ms-2">{{ $vendorLabel ?: '—' }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Lot #</span><span>{{ $lot_number ?: $nextLot }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Branch</span><span>{{ $branches->firstWhere('id', $branch_id)?->name }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Rows / pieces</span><span>{{ count($items) }} / {{ $totals['qty'] }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Avg cost / pc</span><span>{{ $totals['qty'] ? money($totals['lot'] / $totals['qty']) : '—' }}</span></li>
                    <li class="list-group-item d-flex justify-content-between fw-semibold"><span>Total lot cost</span><span>{{ money($totals['lot']) }}</span></li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Size run helper --}}
    <x-modal name="size-run" title="Add a size run" size="lg">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Product</label>
                <div class="d-flex gap-2">
                    <x-combobox class="flex-grow-1" wire:model.live="sizeRun.product_id" search="searchProducts" :label="$sizeRun['product_label']" wire:key="sr-{{ $sizeRun['product_id'] }}" :invalid="$errors->has('sizeRun.product_id')" />
                    <button type="button" class="btn btn-light" wire:click="startNewProduct"><i class="ri-add-line"></i></button>
                </div>
                @error('sizeRun.product_id')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">Colours</label>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($colors as $c)
                        <label class="chip chip-sm cursor-pointer {{ in_array((string) $c['id'], array_map('strval', $sizeRun['color_ids']), true) ? 'active' : '' }}">
                            <input type="checkbox" class="d-none" wire:model.live="sizeRun.color_ids" value="{{ $c['id'] }}"><span class="color-dot" style="background: {{ $c['hex_code'] }}"></span>{{ $c['name'] }}
                        </label>
                    @endforeach
                </div>
                <div class="form-text">Leave empty for a single-colour item.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Sizes</label>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($sizes as $s)
                        <label class="chip chip-sm cursor-pointer {{ in_array((string) $s['id'], array_map('strval', $sizeRun['size_ids']), true) ? 'active' : '' }}">
                            <input type="checkbox" class="d-none" wire:model.live="sizeRun.size_ids" value="{{ $s['id'] }}">{{ $s['code'] }}
                        </label>
                    @endforeach
                </div>
                @error('sizeRun.size_ids')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4"><label class="form-label">Qty per size</label><input wire:model="sizeRun.quantity" type="number" min="1" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Retail price</label><input wire:model="sizeRun.retail_price" type="number" step="0.01" class="form-control @error('sizeRun.retail_price') is-invalid @enderror"></div>
            <div class="col-md-4"><label class="form-label">Wholesale price</label><input wire:model="sizeRun.wholesale_price" type="number" step="0.01" class="form-control"></div>
        </div>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'size-run' })">Cancel</button>
            <button type="button" class="btn btn-primary" wire:click="applySizeRun"><i class="ri-grid-line me-1"></i>Add rows</button>
        </x-slot:footer>
    </x-modal>

    {{-- New product --}}
    <x-modal name="new-product" title="Create product">
        <form wire:submit="createProduct" id="np-form" class="vstack gap-3">
            <div><label class="form-label">Product name</label><input wire:model="newProduct.name" class="form-control @error('newProduct.name') is-invalid @enderror" placeholder="e.g. Linen Kurta">@error('newProduct.name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Category</label><select wire:model="newProduct.category_id" class="form-select"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select></div>
                <div class="col-6"><label class="form-label">Brand</label><select wire:model="newProduct.brand_id" class="form-select"><option value="">—</option>@foreach ($brands as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</select></div>
            </div>
            <div><label class="form-label">Default selling price</label><input wire:model="newProduct.base_price" type="number" step="0.01" class="form-control"></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'new-product' })">Cancel</button>
            <button type="submit" form="np-form" class="btn btn-primary">Create</button>
        </x-slot:footer>
    </x-modal>

    <livewire:vendors.quick-create />
</div>
