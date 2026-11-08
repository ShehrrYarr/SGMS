<?php

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaleReturn;
use App\Services\SaleService;
use App\Support\Catalog;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.pos')] #[Title('POS Terminal')] class extends Component {
    #[Locked]
    public int $branchId;

    #[Locked]
    public ?int $exchangeId = null;

    public function mount(): void
    {
        $context = tenancy();
        $requested = (int) request()->query('branch');
        $this->branchId = $requested && $context->canAccessBranch($requested) ? $requested : (int) $context->operationalBranchId();
        abort_unless($this->branchId, 403, 'No branch is assigned to your account.');

        if ($exchange = request()->integer('exchange')) {
            $return = SaleReturn::query()->find($exchange);

            if ($return?->isPendingExchange() && $context->canAccessBranch($return->branch_id)) {
                $this->exchangeId = $return->id;
                $this->branchId = $return->branch_id;
            }
        }
    }

    /** Whole sellable catalogue for this branch, compact keys to keep the payload small. */
    #[Renderless]
    public function catalog(): array
    {
        return Product::query()->active()
            ->with(['variants' => fn ($q) => $q
                ->select('product_variants.id', 'product_id', 'color_id', 'size_id', 'sku', 'barcode', 'retail_price', 'wholesale_price')
                ->withStock($this->branchId)])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category_id', 'image'])
            ->filter(fn ($p) => $p->variants->isNotEmpty())
            ->map(fn ($p) => $this->productPayload($p, $p->variants))
            ->values()
            ->all();
    }

    /** Fallback for codes not in the loaded catalogue (e.g. inactive products). */
    #[Renderless]
    public function findByCode(string $code): ?array
    {
        $variant = ProductVariant::query()
            ->select('product_variants.*')
            ->withStock($this->branchId)
            ->with('product')
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->first();

        if (! $variant || ! $variant->product) {
            return null;
        }

        $payload = $this->productPayload($variant->product, collect([$variant]));

        return ['p' => array_diff_key($payload, ['v' => 1]), 'v' => $payload['v'][0]];
    }

    #[Renderless]
    public function searchCustomers(string $term = ''): array
    {
        return Customer::query()->where('status', 'active')->search($term)
            ->orderBy('name')->limit(20)
            ->get(['id', 'name', 'phone', 'current_balance'])
            ->map(fn ($c) => $this->customerPayload($c))
            ->all();
    }

    #[Renderless]
    public function quickCustomer(string $name, string $phone): array
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        $validator = Validator::make(compact('name', 'phone'), [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'min:7', 'max:30', Rule::unique('customers', 'phone')->where('tenant_id', tenant_id())],
        ], ['phone.unique' => 'A customer with this phone number already exists.']);

        if ($validator->fails()) {
            return ['ok' => false, 'message' => $validator->errors()->first()];
        }

        $customer = Customer::create(['name' => trim($name), 'phone' => $phone]);

        return ['ok' => true, 'customer' => $this->customerPayload($customer)];
    }

    #[Renderless]
    public function checkout(array $payload, SaleService $sales): array
    {
        $user = auth('web')->user();

        $validator = Validator::make($payload, [
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'apply_tax' => ['boolean'],
            'payments' => ['array', 'max:6'],
            'payments.*.payment_account_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'message' => $validator->errors()->first()];
        }

        $usable = PaymentAccount::query()->active()->usableAt($this->branchId)->pluck('id')->all();

        foreach ($payload['payments'] ?? [] as $payment) {
            if (! in_array((int) $payment['payment_account_id'], $usable, true)) {
                return ['ok' => false, 'message' => 'One of the selected payment accounts is not available at this branch.'];
            }
        }

        // Cashiers may sell at retail or wholesale price only; managers can override.
        if (! $user->canManage()) {
            $prices = ProductVariant::query()->whereIn('id', collect($payload['items'])->pluck('variant_id'))->get(['id', 'retail_price', 'wholesale_price'])->keyBy('id');

            foreach ($payload['items'] as $item) {
                $variant = $prices->get($item['variant_id']);
                $allowed = $variant ? [(float) $variant->retail_price, (float) $variant->wholesale_price] : [];

                if (! collect($allowed)->contains(fn ($p) => abs($p - (float) $item['unit_price']) < 0.01)) {
                    return ['ok' => false, 'message' => 'Only managers can change item prices. Use a discount instead.'];
                }
            }
        }

        try {
            $sale = $sales->create([
                'branch_id' => $this->branchId,
                'customer_id' => $payload['customer_id'] ?? null,
                'items' => $payload['items'],
                'discount' => $payload['discount'] ?? 0,
                'tax_rate' => ! empty($payload['apply_tax']) ? (float) tenant()->tax_rate : 0,
                'payments' => $payload['payments'] ?? [],
                'exchange_return_id' => $this->exchangeId,
                'notes' => $payload['notes'] ?? null,
            ]);
        } catch (BusinessException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $this->exchangeId = null;
        $tendered = round((float) ($payload['tendered'] ?? 0), 2);
        $change = round((float) ($payload['change'] ?? 0), 2);

        return [
            'ok' => true,
            'sale' => [
                'id' => $sale->id,
                'invoice' => $sale->invoice_number,
                'total' => (float) $sale->total_amount,
                'paid' => (float) $sale->paid_amount,
                'due' => (float) $sale->due_amount,
                'customer' => $sale->customer_name,
            ],
            'receipt_url' => route('app.sales.receipt', array_filter(['sale' => $sale->id, 'autoprint' => 1, 'tendered' => $tendered ?: null, 'change' => $change ?: null])),
            'view_url' => route('app.sales.show', $sale),
            'stock' => $sale->items->groupBy('product_variant_id')->map->sum('quantity'),
        ];
    }

    protected function productPayload(Product $product, $variants): array
    {
        return [
            'id' => $product->id,
            'n' => $product->name,
            'sku' => $product->sku,
            'c' => $product->category_id,
            'img' => $product->image_url,
            'v' => $variants->map(fn ($v) => [
                'id' => $v->id,
                's' => $v->sku,
                'bc' => $v->barcode,
                'co' => $v->color_id,
                'sz' => $v->size_id,
                'p' => (float) $v->retail_price,
                'w' => (float) $v->wholesale_price,
                'q' => (int) $v->stock,
            ])->values()->all(),
        ];
    }

    protected function customerPayload(Customer $customer): array
    {
        $balance = (float) $customer->current_balance;

        return [
            'id' => $customer->id,
            'label' => $customer->name,
            'phone' => $customer->phone,
            'balance' => $balance,
            'sub' => $customer->phone.($balance > 0 ? ' · Due '.money($balance) : ($balance < 0 ? ' · Advance '.money(abs($balance)) : '')),
        ];
    }

    public function with(): array
    {
        $tenant = tenant();
        $user = auth('web')->user();
        $accounts = PaymentAccount::query()->active()->usableAt($this->branchId)
            ->orderByRaw("FIELD(account_type, 'cash_drawer', 'bank', 'wallet')")->get();
        $exchange = $this->exchangeId ? SaleReturn::query()->with('customer', 'sale')->find($this->exchangeId) : null;

        return [
            'branch' => Branch::query()->find($this->branchId),
            'branches' => $user->isOwner() ? tenancy()->branches() : collect(),
            'user' => $user,
            'config' => [
                'currency' => $tenant->currency_symbol,
                'taxRate' => (float) $tenant->tax_rate,
                'storageKey' => 'sgms-pos-'.$tenant->id.'-'.$this->branchId,
                'canEditPrice' => $user->canManage(),
                'autoPrint' => (bool) $tenant->setting('auto_print', true),
                'categories' => Catalog::categories(),
                'colors' => Catalog::colors(),
                'sizes' => Catalog::sizes(),
                'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->account_name, 'type' => $a->account_type, 'icon' => $a->icon])->values(),
                'cashAccountId' => $accounts->first(fn ($a) => $a->account_type === PaymentAccount::TYPE_CASH && $a->branch_id === $this->branchId)?->id,
                'exchange' => $exchange ? [
                    'id' => $exchange->id,
                    'number' => $exchange->return_number,
                    'credit' => $exchange->exchange_credit,
                    'customer' => $exchange->customer ? $this->customerPayload($exchange->customer) : null,
                ] : null,
            ],
        ];
    }
}; ?>

<div class="d-flex flex-column h-100" style="min-height: 0; flex: 1" x-data="posTerminal(@js($config))">
    {{-- ================= Top bar ================= --}}
    <header class="pos-topbar">
        <a href="{{ route('app.dashboard') }}" class="btn btn-light-light icon-btn" title="Back to dashboard"><i class="ri-arrow-left-line"></i></a>
        <span class="sgms-brand d-none d-md-inline-flex">
            <span class="sgms-brand-icon"><i class="ri-shopping-cart-2-fill"></i></span>
            <span class="sgms-brand-text">{{ tenant()->name }}<small>{{ $branch->name }}</small></span>
        </span>

        @if ($branches->count() > 1)
            <div class="dropdown">
                <button class="btn btn-sm btn-light-primary d-inline-flex align-items-center gap-1" data-bs-toggle="dropdown">
                    <i class="ri-store-2-line"></i><span class="d-md-none">{{ $branch->code }}</span><i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-menu">
                    @foreach ($branches as $b)
                        <a href="{{ route('app.pos', ['branch' => $b->id]) }}" class="dropdown-item {{ $b->id === $branch->id ? 'active' : '' }}">{{ $b->name }}</a>
                    @endforeach
                </div>
            </div>
        @else
            <span class="badge bg-light text-body border d-md-none">{{ $branch->code }}</span>
        @endif

        <div class="ms-auto d-flex align-items-center gap-1">
            <span class="d-none d-lg-inline text-muted small me-2"><kbd>F2</kbd> search · <kbd>F9</kbd> pay</span>
            <button type="button" class="btn btn-light-warning btn-sm position-relative" x-on:click="heldOpen = true" title="Held carts">
                <i class="ri-pause-circle-line"></i><span class="d-none d-sm-inline ms-1">Held</span>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning" x-show="held.length" x-text="held.length"></span>
            </button>
            <button type="button" class="btn icon-btn btn-text-primary rounded-circle" x-on:click="load()" title="Refresh stock"><i class="ri-refresh-line" :class="{ 'ri-spin': loading }"></i></button>
            <button type="button" class="btn icon-btn btn-text-primary rounded-circle d-none d-sm-inline-flex" data-sgms-fullscreen title="Fullscreen"><i class="ri-fullscreen-line"></i></button>
            <button type="button" class="btn icon-btn btn-text-primary rounded-circle" data-sgms-theme-toggle title="Theme"><i class="ri-contrast-2-line"></i></button>
            <div class="dropdown">
                <button class="btn profile-btn shadow-none px-1" data-bs-toggle="dropdown">
                    <span class="avatar avatar-sm avatar-item avatar-title bg-primary-subtle text-primary rounded-circle fw-semibold">{{ $user->initials }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <div class="px-3 py-2"><div class="fw-semibold">{{ $user->name }}</div><small class="text-muted">{{ $user->role_label }}</small></div>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="{{ route('app.sales.index') }}"><i class="ri-file-list-3-line me-2"></i>Today's sales</a>
                    <a class="dropdown-item" href="{{ route('app.returns.create') }}"><i class="ri-arrow-go-back-line me-2"></i>Return / exchange</a>
                    <div class="dropdown-divider"></div>
                    <form method="POST" action="{{ route('app.logout') }}">@csrf<button class="dropdown-item text-danger"><i class="ri-logout-box-r-line me-2"></i>Sign out</button></form>
                </div>
            </div>
        </div>
    </header>

    <div class="pos-shell">
        {{-- ================= Catalogue ================= --}}
        <section class="pos-catalog">
            <div class="pos-toolbar">
                <div class="d-flex gap-2">
                    <div class="pos-search flex-grow-1">
                        <i class="ri-barcode-box-line"></i>
                        <input x-ref="search" type="search" class="form-control" x-model="query" x-on:keydown.enter.prevent="submitSearch()"
                               placeholder="Scan barcode or search name / SKU…" autocomplete="off" autofocus>
                    </div>
                    <button type="button" class="btn btn-light position-relative px-3" x-on:click="showFilters = !showFilters" :class="{ 'btn-light-primary': showFilters || activeFilterCount }" title="Filters">
                        <i class="ri-equalizer-line fs-5"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary" x-show="activeFilterCount" x-text="activeFilterCount"></span>
                    </button>
                </div>

                <div class="chip-row">
                    <button type="button" class="chip" :class="{ active: !category }" x-on:click="category = null">All</button>
                    <template x-for="c in config.categories" :key="c.id">
                        <button type="button" class="chip" :class="{ active: category === c.id }" x-on:click="category = category === c.id ? null : c.id" x-text="c.name"></button>
                    </template>
                </div>

                <div x-show="showFilters" x-collapse x-cloak>
                    <div class="chip-row">
                        <span class="small text-muted align-self-center me-1">Colour</span>
                        <template x-for="c in config.colors" :key="c.id">
                            <button type="button" class="chip chip-sm" :class="{ active: colorId === c.id }" x-on:click="colorId = colorId === c.id ? null : c.id">
                                <span class="color-dot" :style="'background:' + c.hex_code"></span><span x-text="c.name"></span>
                            </button>
                        </template>
                    </div>
                    <div class="chip-row">
                        <span class="small text-muted align-self-center me-1">Size</span>
                        <template x-for="s in config.sizes" :key="s.id">
                            <button type="button" class="chip chip-sm" :class="{ active: sizeId === s.id }" x-on:click="sizeId = sizeId === s.id ? null : s.id" x-text="s.code"></button>
                        </template>
                        <button type="button" class="chip chip-sm" :class="{ active: inStockOnly }" x-on:click="inStockOnly = !inStockOnly"><i class="ri-checkbox-circle-line"></i>In stock</button>
                        <button type="button" class="chip chip-sm" x-show="activeFilterCount" x-on:click="colorId = null; sizeId = null; inStockOnly = false"><i class="ri-close-line"></i>Clear</button>
                    </div>
                </div>
            </div>

            <div class="pos-grid-wrap">
                <div class="pos-grid" x-show="loading">
                    <template x-for="i in 12" :key="i"><div class="pos-product placeholder-glow"><div class="pos-thumb placeholder"></div><div class="info"><span class="placeholder col-9"></span><span class="placeholder col-5"></span></div></div></template>
                </div>

                <div class="pos-grid" x-show="!loading" x-cloak>
                    <template x-for="p in visible" :key="p.id">
                        <button type="button" class="pos-product" :class="{ 'is-out': stockOf(p) <= 0 }" x-on:click="openProduct(p)">
                            <div class="pos-thumb">
                                <template x-if="p.img"><img :src="p.img" :alt="p.n" loading="lazy"></template>
                                <template x-if="!p.img"><span class="initials" x-text="initials(p.n)"></span></template>
                                <span class="badge stock-badge" :class="stockOf(p) > 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'" x-text="stockOf(p) > 0 ? stockOf(p) + ' pcs' : 'Out'"></span>
                            </div>
                            <div class="info">
                                <div class="name text-truncate-2" x-text="p.n"></div>
                                <div class="d-flex flex-wrap justify-content-between align-items-baseline column-gap-2 mt-1">
                                    <span class="price text-nowrap" x-text="priceLabel(p)"></span>
                                    <small class="text-muted" x-text="p.v.length > 1 ? p.v.length + ' variants' : ''"></small>
                                </div>
                            </div>
                        </button>
                    </template>
                </div>

                <div x-show="!loading && filtered.length === 0" x-cloak>
                    <x-empty-state icon="ri-search-eye-line" title="No products match">
                        <div class="small mt-1">Press <kbd>Enter</kbd> to look up a barcode on the server.</div>
                    </x-empty-state>
                </div>

                <div class="text-center my-3" x-show="filtered.length > limit" x-cloak>
                    <button type="button" class="btn btn-light-primary" x-on:click="limit += 60">Show more (<span x-text="filtered.length - limit"></span>)</button>
                </div>
            </div>
        </section>

        {{-- ================= Cart ================= --}}
        <div class="pos-cart-backdrop d-lg-none" x-show="cartOpen" x-transition.opacity x-on:click="cartOpen = false" x-cloak></div>
        <aside class="pos-cart" :class="{ open: cartOpen }">
            <div class="pos-cart-head">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h6 class="mb-0 fw-semibold flex-grow-1">Current sale <span class="badge bg-primary-subtle text-primary ms-1" x-text="itemCount + ' items'"></span></h6>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Price list">
                        <button type="button" class="btn" :class="priceMode === 'retail' ? 'btn-primary' : 'btn-light'" x-on:click="setPriceMode('retail')">Retail</button>
                        <button type="button" class="btn" :class="priceMode === 'wholesale' ? 'btn-primary' : 'btn-light'" x-on:click="setPriceMode('wholesale')">Wholesale</button>
                    </div>
                    <button type="button" class="btn btn-sm btn-light d-lg-none" x-on:click="cartOpen = false"><i class="ri-close-line"></i></button>
                </div>

                <template x-if="config.exchange">
                    <div class="alert alert-info py-2 px-3 mb-2 small d-flex align-items-center gap-2">
                        <i class="ri-exchange-line fs-5"></i>
                        <div>Exchange <b x-text="config.exchange.number"></b> — credit <b x-text="money(config.exchange.credit)"></b></div>
                    </div>
                </template>

                {{-- Customer selector --}}
                <div class="position-relative" x-on:click.outside="customerOpen = false">
                    <div class="d-flex align-items-center gap-2 border rounded-3 p-2 cursor-pointer" x-on:click="openCustomerSearch()">
                        <span class="avatar avatar-sm avatar-item avatar-title rounded-circle" :class="customer ? 'bg-primary text-white' : 'bg-light text-muted'"><i class="ri-user-3-line"></i></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate" x-text="customer ? customer.label : 'Walk-in Customer'"></div>
                            <small class="text-muted d-block text-truncate" x-text="customer ? customer.sub : 'Tap to select a registered customer'"></small>
                        </div>
                        <button type="button" class="btn btn-sm btn-light" x-show="customer && !config.exchange?.customer" x-on:click.stop="walkIn()" title="Walk-in"><i class="ri-close-line"></i></button>
                        <i class="ri-arrow-down-s-line text-muted" x-show="!customer"></i>
                    </div>
                    <div class="dropdown-menu shadow w-100 p-2" :class="{ show: customerOpen }" x-show="customerOpen" x-cloak style="z-index: 1050">
                        <input x-ref="customerSearch" type="search" class="form-control form-control-sm mb-2" placeholder="Name or phone…" x-model="customerQuery" x-on:input.debounce.300ms="searchCustomers()">
                        <div style="max-height: 240px; overflow-y: auto">
                            <button type="button" class="dropdown-item rounded py-2" x-on:click="walkIn(); customerOpen = false"><i class="ri-walk-line me-2"></i>Walk-in Customer</button>
                            <template x-for="c in customerResults" :key="c.id">
                                <button type="button" class="dropdown-item rounded py-2" x-on:click="chooseCustomer(c)">
                                    <div class="text-start"><div class="fw-medium" x-text="c.label"></div><small class="text-muted d-block" x-text="c.sub"></small></div>
                                </button>
                            </template>
                            <div class="small text-muted px-2" x-show="customerLoading">Searching…</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-light-primary w-100 mt-2" x-on:click="newCustomer.open = true; customerOpen = false; newCustomer.name = customerQuery"><i class="ri-user-add-line me-1"></i>New customer</button>
                    </div>
                </div>
            </div>

            <div class="pos-cart-items">
                <template x-for="line in cart" :key="line.id">
                    <div class="cart-line" :class="{ 'bg-primary-subtle rounded-3': lastAdded === line.id }" style="transition: background .4s">
                        <span class="color-dot mt-1" :style="'background:' + (line.hex || '#e5e7eb')"></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex gap-2">
                                <div class="fw-semibold text-truncate flex-grow-1" x-text="line.name"></div>
                                <button type="button" class="btn btn-sm p-0 text-danger" x-on:click="remove(line)" title="Remove"><i class="ri-delete-bin-6-line"></i></button>
                            </div>
                            <small class="text-muted d-block" x-text="line.variant + ' · ' + line.sku"></small>
                            <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                                <div class="qty-stepper">
                                    <button type="button" x-on:click="dec(line)"><i class="ri-subtract-line"></i></button>
                                    <input type="number" min="1" :value="line.qty" x-on:change="setQty(line, $event.target.value)">
                                    <button type="button" x-on:click="inc(line)"><i class="ri-add-line"></i></button>
                                </div>
                                <span class="text-muted small">×</span>
                                <template x-if="config.canEditPrice">
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" style="width: 92px" x-model.number="line.price" x-on:change="persist()" title="Unit price">
                                </template>
                                <template x-if="!config.canEditPrice"><span class="small" x-text="money(line.price)"></span></template>
                                <div class="ms-auto fw-semibold tabular-nums" x-text="money(lineTotal(line))"></div>
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-1" x-data="{ edit: false }">
                                <button type="button" class="btn btn-link btn-sm p-0 small text-decoration-none" x-show="!edit && !Number(line.discount)" x-on:click="edit = true"><i class="ri-price-tag-3-line"></i> Discount</button>
                                <div class="input-group input-group-sm" style="width: 150px" x-show="edit || Number(line.discount)">
                                    <span class="input-group-text">Disc.</span>
                                    <input type="number" step="0.01" min="0" class="form-control" x-model.number="line.discount" x-on:change="persist()">
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <div x-show="!cart.length" class="text-center text-muted py-5">
                    <i class="ri-shopping-basket-2-line" style="font-size: 3rem; opacity: .35"></i>
                    <div class="fw-medium mt-2">Cart is empty</div>
                    <small>Scan a barcode or tap a product</small>
                </div>
            </div>

            <div class="pos-cart-foot">
                <div class="pos-totals">
                    <div class="row-line"><span class="text-muted">Subtotal</span><span class="tabular-nums" x-text="money(subtotal)"></span></div>
                    <div class="row-line align-items-center">
                        <span class="text-muted d-flex align-items-center gap-2">Discount
                            <span class="input-group input-group-sm" style="width: 130px">
                                <input type="number" min="0" step="0.01" class="form-control" x-model.number="discountValue" x-on:change="persist()">
                                <button type="button" class="btn btn-light border" x-on:click="discountType = discountType === 'amount' ? 'percent' : 'amount'; persist()" x-text="discountType === 'amount' ? config.currency : '%'"></button>
                            </span>
                        </span>
                        <span class="tabular-nums text-danger" x-text="'-' + money(discountAmount)"></span>
                    </div>
                    <template x-if="config.taxRate > 0">
                        <div class="row-line">
                            <label class="text-muted d-flex align-items-center gap-2 mb-0"><input type="checkbox" class="form-check-input mt-0" x-model="applyTax"> Tax (<span x-text="config.taxRate"></span>%)</label>
                            <span class="tabular-nums" x-text="money(taxAmount)"></span>
                        </div>
                    </template>
                    <template x-if="exchangeCredit > 0">
                        <div class="row-line text-info"><span>Exchange credit</span><span class="tabular-nums" x-text="'-' + money(exchangeCredit)"></span></div>
                    </template>
                    <div class="row-line grand"><span>Total</span><span class="tabular-nums" x-text="money(payable)"></span></div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-light-warning px-3" x-on:click="hold()" :disabled="!cart.length" title="Hold cart"><i class="ri-pause-line"></i></button>
                    <button type="button" class="btn btn-light-danger px-3" x-on:click="clearCart()" :disabled="!cart.length" title="Clear cart"><i class="ri-delete-bin-line"></i></button>
                    <button type="button" class="btn btn-primary pos-pay-btn flex-grow-1" x-on:click="openCheckout()" :disabled="!cart.length">
                        <i class="ri-secure-payment-line me-1"></i>Pay <span x-text="money(payable)"></span>
                    </button>
                </div>
            </div>
        </aside>
    </div>

    {{-- Mobile bottom bar --}}
    <div class="pos-mobile-bar" x-on:click="cartOpen = true" x-show="!cartOpen" x-cloak>
        <i class="ri-shopping-cart-2-fill fs-4"></i>
        <div class="flex-grow-1 lh-sm">
            <div class="fw-semibold" x-text="itemCount + ' item' + (itemCount === 1 ? '' : 's')"></div>
            <small class="opacity-75" x-text="customer ? customer.label : 'Walk-in'"></small>
        </div>
        <span class="fw-bold fs-5 tabular-nums" x-text="money(payable)"></span>
        <button type="button" class="btn btn-light btn-sm fw-semibold" x-on:click.stop="openCheckout()" :disabled="!cart.length">Pay</button>
    </div>

    {{-- ================= Variant picker (colour x size matrix) ================= --}}
    <div class="sgms-modal" x-show="picker" x-cloak>
        <div class="sgms-modal-backdrop" x-on:click="picker = null"></div>
        <div class="modal modal-sheet show" style="display:block" x-on:mousedown.self="picker = null">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" x-show="picker" x-transition>
                    <div class="modal-header position-relative">
                        <div>
                            <h5 class="modal-title fw-semibold" x-text="picker?.n"></h5>
                            <small class="text-muted">Tap a size to add it to the cart</small>
                        </div>
                        <button type="button" class="btn-close" x-on:click="picker = null"></button>
                    </div>
                    <div class="modal-body variant-matrix">
                        <table class="table table-borderless mb-0">
                            <thead>
                                <tr>
                                    <th class="text-start text-muted small">Colour</th>
                                    <template x-for="sz in pickerSizes" :key="'h' + sz"><th class="small" x-text="sz ? sizeCode(sz) : 'One size'"></th></template>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="co in pickerColors" :key="'r' + co">
                                    <tr>
                                        <td class="text-start text-nowrap"><span class="color-dot me-1" :style="'background:' + (colorHex(co) || '#e5e7eb')"></span><span class="small fw-medium" x-text="co ? colorName(co) : 'Standard'"></span></td>
                                        <template x-for="sz in pickerSizes" :key="'c' + co + '-' + sz">
                                            <td>
                                                <template x-if="pickerCell(co, sz)">
                                                    <button type="button" class="variant-cell" :disabled="pickerCell(co, sz).q <= 0" x-on:click="addVariant(pickerCell(co, sz).id)">
                                                        <span class="fw-semibold" x-text="money(pickerCell(co, sz).p).replace('.00', '')"></span>
                                                        <small x-text="pickerCell(co, sz).q > 0 ? pickerCell(co, sz).q + ' in stock' : 'Sold out'"></small>
                                                    </button>
                                                </template>
                                                <template x-if="!pickerCell(co, sz)"><span class="text-muted">—</span></template>
                                            </td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" x-on:click="picker = null">Done</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= Checkout ================= --}}
    <div class="sgms-modal" x-show="checkoutOpen" x-cloak>
        <div class="sgms-modal-backdrop" x-on:click="if (!submitting && !result) checkoutOpen = false"></div>
        <div class="modal modal-sheet show" style="display:block">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    {{-- Success --}}
                    <template x-if="result">
                        <div class="modal-body text-center py-5">
                            <span class="success-check mb-3"><i class="ri-check-line"></i></span>
                            <h4 class="fw-semibold mb-1">Sale completed</h4>
                            <p class="text-muted mb-3"><span x-text="result.sale.invoice"></span> · <span x-text="result.sale.customer"></span></p>
                            <div class="d-inline-flex flex-column gap-1 text-start border rounded-3 p-3 mb-4" style="min-width: 260px">
                                <div class="d-flex justify-content-between gap-4"><span class="text-muted">Total</span><b x-text="money(result.sale.total)"></b></div>
                                <div class="d-flex justify-content-between gap-4"><span class="text-muted">Paid</span><span x-text="money(result.sale.paid)"></span></div>
                                <div class="d-flex justify-content-between gap-4 text-warning" x-show="result.sale.due > 0"><span>On account</span><b x-text="money(result.sale.due)"></b></div>
                                <div class="d-flex justify-content-between gap-4 fs-4 text-success" x-show="result.change > 0"><span>Change</span><b x-text="money(result.change)"></b></div>
                            </div>
                            <div class="d-flex flex-wrap justify-content-center gap-2">
                                <button type="button" class="btn btn-light-primary" x-on:click="SGMS.print(result.receipt_url)"><i class="ri-printer-line me-1"></i>Print receipt</button>
                                <a :href="result.view_url" class="btn btn-light"><i class="ri-file-text-line me-1"></i>View invoice</a>
                                <button type="button" class="btn btn-primary px-4" x-on:click="newSale()" x-init="$el.focus()"><i class="ri-add-line me-1"></i>New sale</button>
                            </div>
                        </div>
                    </template>

                    {{-- Payment --}}
                    <template x-if="!result">
                        <div class="d-flex flex-column" style="min-height: 0">
                            <div class="modal-header position-relative">
                                <div>
                                    <h5 class="modal-title fw-semibold">Checkout</h5>
                                    <small class="text-muted" x-text="(customer ? customer.label : 'Walk-in Customer') + ' · ' + itemCount + ' items'"></small>
                                </div>
                                <button type="button" class="btn-close" x-on:click="checkoutOpen = false" :disabled="submitting"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <div class="bg-primary-subtle rounded-3 p-3 text-center mb-3">
                                            <div class="text-muted small">Amount payable</div>
                                            <div class="fs-2 fw-bold text-primary tabular-nums" x-text="money(payable)"></div>
                                            <div class="small text-info" x-show="exchangeCredit > 0" x-text="'after ' + money(exchangeCredit) + ' exchange credit'"></div>
                                        </div>
                                        <template x-if="customer && customer.balance > 0">
                                            <div class="alert alert-warning py-2 small mb-3"><i class="ri-error-warning-line me-1"></i>Existing balance due: <b x-text="money(customer.balance)"></b></div>
                                        </template>
                                        <div class="small text-muted mb-2">Quick pay in full</div>
                                        <div class="d-grid gap-2">
                                            <template x-for="a in config.accounts" :key="'q' + a.id">
                                                <button type="button" class="btn btn-light text-start d-flex align-items-center gap-2" x-on:click="payFullWith(a.id)">
                                                    <i :class="a.icon"></i><span class="flex-grow-1 text-truncate" x-text="a.name"></span><i class="ri-arrow-right-s-line"></i>
                                                </button>
                                            </template>
                                            <button type="button" class="btn btn-light-warning text-start d-flex align-items-center gap-2" x-on:click="putOnAccount()" :disabled="!customer">
                                                <i class="ri-book-open-line"></i><span class="flex-grow-1">Add to customer account (credit)</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-7">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0">Payments</h6>
                                            <button type="button" class="btn btn-sm btn-light-primary" x-on:click="addPayment()"><i class="ri-add-line me-1"></i>Split</button>
                                        </div>
                                        <div class="vstack gap-2">
                                            <template x-for="(p, i) in payments" :key="i">
                                                <div class="pay-row">
                                                    <div class="d-flex gap-2">
                                                        <select class="form-select form-select-sm" x-model.number="p.account">
                                                            <template x-for="a in config.accounts" :key="'o' + a.id"><option :value="a.id" x-text="a.name" :selected="a.id === Number(p.account)"></option></template>
                                                        </select>
                                                        <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end fw-semibold" style="max-width: 130px" x-model.number="p.amount">
                                                        <button type="button" class="btn btn-sm btn-light-danger" x-on:click="removePayment(i)"><i class="ri-close-line"></i></button>
                                                    </div>
                                                    <input type="text" class="form-control form-control-sm mt-2" placeholder="Reference / transaction ID (optional)" x-model="p.ref" x-show="!isCash(p.account)">
                                                </div>
                                            </template>
                                            <div class="text-muted small text-center py-2 border rounded-3" x-show="!payments.length">No payment — full amount goes to the customer's account.</div>
                                        </div>

                                        <div class="mt-3" x-show="cashPayment">
                                            <label class="form-label small mb-1">Cash tendered</label>
                                            <div class="quick-cash mb-2">
                                                <template x-for="v in quickCashOptions" :key="'c' + v">
                                                    <button type="button" class="btn btn-sm btn-light" x-on:click="setTendered(v)" x-text="money(v).replace('.00', '')"></button>
                                                </template>
                                            </div>
                                            <input type="number" min="0" step="1" class="form-control" placeholder="Amount received" x-model.number="tendered">
                                            <div class="d-flex justify-content-between mt-2 fs-5" x-show="change > 0"><span>Change to return</span><b class="text-success tabular-nums" x-text="money(change)"></b></div>
                                        </div>

                                        <div class="border-top mt-3 pt-3">
                                            <div class="d-flex justify-content-between"><span class="text-muted">Paying now</span><b class="tabular-nums" x-text="money(paidTotal)"></b></div>
                                            <div class="d-flex justify-content-between" :class="remaining > 0 ? 'text-warning' : 'text-success'" x-show="Math.abs(remaining) > 0.009">
                                                <span x-text="remaining > 0 ? 'To customer account (credit)' : 'Over payment'"></span>
                                                <b class="tabular-nums" x-text="money(Math.abs(remaining))"></b>
                                            </div>
                                        </div>
                                        <input type="text" class="form-control form-control-sm mt-3" placeholder="Note on invoice (optional)" x-model="notes">
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer flex-column align-items-stretch">
                                <div class="alert alert-danger py-2 small mb-2" x-show="checkoutError" x-text="checkoutError"></div>
                                <button type="button" class="btn btn-success btn-lg w-100" x-on:click="submit()" :disabled="submitting || !!checkoutError">
                                    <span x-show="!submitting"><i class="ri-check-double-line me-1"></i>Complete sale · <span x-text="money(payable)"></span></span>
                                    <span x-show="submitting"><span class="spinner-border spinner-border-sm me-2"></span>Processing…</span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= Held carts ================= --}}
    <div class="sgms-modal" x-show="heldOpen" x-cloak>
        <div class="sgms-modal-backdrop" x-on:click="heldOpen = false"></div>
        <div class="modal modal-sheet show" style="display:block" x-on:mousedown.self="heldOpen = false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header position-relative"><h5 class="modal-title fw-semibold">Held carts</h5><button type="button" class="btn-close" x-on:click="heldOpen = false"></button></div>
                    <div class="modal-body">
                        <template x-for="(h, i) in held" :key="h.at">
                            <div class="d-flex align-items-center gap-3 border rounded-3 p-2 mb-2">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold" x-text="h.label"></div>
                                    <small class="text-muted" x-text="h.items + ' items · ' + timeAgo(h.at)"></small>
                                </div>
                                <b class="tabular-nums" x-text="money(h.total)"></b>
                                <button type="button" class="btn btn-sm btn-primary" x-on:click="recall(i)">Recall</button>
                                <button type="button" class="btn btn-sm btn-light-danger" x-on:click="dropHeld(i)"><i class="ri-delete-bin-line"></i></button>
                            </div>
                        </template>
                        <div x-show="!held.length"><x-empty-state icon="ri-pause-circle-line" title="No held carts" text="Use the pause button in the cart to park a sale." /></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= New customer ================= --}}
    <div class="sgms-modal" x-show="newCustomer.open" x-cloak>
        <div class="sgms-modal-backdrop" x-on:click="newCustomer.open = false"></div>
        <div class="modal modal-sheet show" style="display:block" x-on:mousedown.self="newCustomer.open = false">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-header position-relative"><h5 class="modal-title fw-semibold">New customer</h5><button type="button" class="btn-close" x-on:click="newCustomer.open = false"></button></div>
                    <form class="modal-body vstack gap-3" x-on:submit.prevent="saveNewCustomer()">
                        <div><label class="form-label">Name</label><input class="form-control" x-model="newCustomer.name" required></div>
                        <div><label class="form-label">Phone</label><input class="form-control" x-model="newCustomer.phone" inputmode="tel" placeholder="03XXXXXXXXX" required></div>
                        <button type="submit" class="btn btn-primary" :disabled="newCustomer.saving"><i class="ri-user-add-line me-1"></i>Save & select</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
