<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Coupon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Coupons')] class extends Component {
    use InteractsWithUi;

    public array $form = [];

    public function mount(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->form = ['id' => null, 'code' => '', 'type' => 'percent', 'value' => '', 'min_order' => '', 'max_discount' => '', 'starts_at' => '', 'expires_at' => '', 'usage_limit' => '', 'is_active' => true];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->resetValidation();
        $this->openModal('coupon');
    }

    public function edit(int $id): void
    {
        $coupon = Coupon::query()->findOrFail($id);
        $this->form = [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'min_order' => (float) $coupon->min_order ?: '',
            'max_discount' => $coupon->max_discount !== null ? (float) $coupon->max_discount : '',
            'starts_at' => $coupon->starts_at?->toDateString() ?? '',
            'expires_at' => $coupon->expires_at?->toDateString() ?? '',
            'usage_limit' => $coupon->usage_limit ?? '',
            'is_active' => $coupon->is_active,
        ];
        $this->resetValidation();
        $this->openModal('coupon');
    }

    public function save(): void
    {
        $this->form['code'] = strtoupper(trim((string) $this->form['code']));

        $this->validate([
            'form.code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->where('tenant_id', tenant_id())->ignore($this->form['id'])],
            'form.type' => ['required', Rule::in(array_keys(Coupon::TYPES))],
            'form.value' => ['required', 'numeric', 'min:1', $this->form['type'] === 'percent' ? 'max:90' : 'max:1000000'],
            'form.min_order' => ['nullable', 'numeric', 'min:0'],
            'form.max_discount' => ['nullable', 'numeric', 'min:0'],
            'form.starts_at' => ['nullable', 'date'],
            'form.expires_at' => ['nullable', 'date', 'after_or_equal:form.starts_at'],
            'form.usage_limit' => ['nullable', 'integer', 'min:1'],
        ], ['form.code.regex' => 'Use letters, numbers, dashes or underscores only.'], [
            'form.code' => 'code', 'form.value' => 'discount', 'form.expires_at' => 'end date',
        ]);

        $data = collect($this->form)->except('id')->map(fn ($v) => $v === '' ? null : $v)->all();
        $data['min_order'] ??= 0;
        $data['is_active'] = (bool) $this->form['is_active'];

        Coupon::query()->updateOrCreate(['id' => $this->form['id']], $data);

        $this->closeModal('coupon');
        $this->toast('Coupon saved.');
    }

    public function toggle(int $id): void
    {
        $coupon = Coupon::query()->findOrFail($id);
        $coupon->update(['is_active' => ! $coupon->is_active]);
    }

    public function delete(int $id): void
    {
        Coupon::query()->findOrFail($id)->delete();
        $this->toast('Coupon deleted.');
    }

    public function with(): array
    {
        return [
            'coupons' => Coupon::query()->withCount('orders')->withSum(['orders as discount_given' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'discount')->latest()->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Coupons" subtitle="Discount codes shoppers enter in the bag or at checkout.">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="create"><i class="ri-add-line me-1"></i>New coupon</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th>Code</th><th>Discount</th><th>Conditions</th><th>Valid</th><th class="text-center">Used</th><th class="text-end">Given</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($coupons as $coupon)
                        @php($problem = $coupon->problemFor(PHP_FLOAT_MAX))
                        <tr wire:key="coupon-{{ $coupon->id }}">
                            <td data-label="Code"><span class="font-monospace fw-semibold">{{ $coupon->code }}</span></td>
                            <td data-label="Discount">{{ $coupon->label }}@if ($coupon->type === 'percent' && $coupon->max_discount)<div class="small text-muted">up to {{ money($coupon->max_discount, decimals: 0) }}</div>@endif</td>
                            <td data-label="Conditions" class="small">{{ (float) $coupon->min_order > 0 ? 'Orders over '.money($coupon->min_order, decimals: 0) : 'Any order' }}</td>
                            <td data-label="Valid" class="small">
                                {{ $coupon->starts_at?->format('d M Y') ?? 'Now' }} to {{ $coupon->expires_at?->format('d M Y') ?? 'no end' }}
                            </td>
                            <td data-label="Used" class="text-md-center">{{ $coupon->used_count }}{{ $coupon->usage_limit ? ' / '.$coupon->usage_limit : '' }}</td>
                            <td data-label="Given" class="text-end tabular-nums">{{ money($coupon->discount_given ?? 0) }}</td>
                            <td data-label="Status">
                                @if (! $coupon->is_active)
                                    <x-status-badge status="inactive" label="Off" />
                                @elseif ($problem)
                                    <span class="badge bg-warning-subtle text-warning" title="{{ $problem }}">Not usable</span>
                                @else
                                    <x-status-badge status="active" />
                                @endif
                            </td>
                            <td class="text-end td-actions text-nowrap">
                                <div class="form-check form-switch d-inline-block align-middle me-2 mb-0" title="On / off">
                                    <input class="form-check-input" type="checkbox" @checked($coupon->is_active) wire:click="toggle({{ $coupon->id }})" aria-label="Coupon on or off">
                                </div>
                                <button type="button" class="btn btn-sm btn-light-primary" wire:click="edit({{ $coupon->id }})" title="Edit"><i class="ri-pencil-line"></i></button>
                                <button type="button" class="btn btn-sm btn-light-danger" wire:click="delete({{ $coupon->id }})" wire:confirm="Delete coupon {{ $coupon->code }}? Past orders keep their discount." title="Delete"><i class="ri-delete-bin-line"></i></button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty-state icon="ri-coupon-3-line" title="No coupons yet" text="Create a code such as EID20 for 20% off, or WELCOME500 for Rs. 500 off a first order." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-modal name="coupon" :title="$form['id'] ? 'Edit coupon' : 'New coupon'">
        <form wire:submit="save" id="coupon-form" class="row g-3">
            <div class="col-sm-6">
                <label class="form-label">Code</label>
                <input wire:model="form.code" class="form-control font-monospace text-uppercase @error('form.code') is-invalid @enderror" placeholder="EID20" autocomplete="off">
                @error('form.code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6">
                <label class="form-label">Type</label>
                <select wire:model.live="form.type" class="form-select">@foreach (Coupon::TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div class="col-sm-6">
                <label class="form-label">{{ $form['type'] === 'percent' ? 'Percent off' : 'Amount off' }}</label>
                <div class="input-group">
                    @if ($form['type'] !== 'percent')<span class="input-group-text">{{ tenant()->currency_symbol }}</span>@endif
                    <input wire:model="form.value" type="number" step="0.01" min="0" class="form-control @error('form.value') is-invalid @enderror">
                    @if ($form['type'] === 'percent')<span class="input-group-text">%</span>@endif
                </div>
                @error('form.value')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6">
                <label class="form-label">Maximum discount</label>
                <input wire:model="form.max_discount" type="number" step="1" min="0" class="form-control" placeholder="No cap" @disabled($form['type'] !== 'percent')>
            </div>
            <div class="col-sm-6">
                <label class="form-label">Minimum order</label>
                <input wire:model="form.min_order" type="number" step="1" min="0" class="form-control" placeholder="Any amount">
            </div>
            <div class="col-sm-6">
                <label class="form-label">Usage limit</label>
                <input wire:model="form.usage_limit" type="number" step="1" min="1" class="form-control" placeholder="Unlimited">
            </div>
            <div class="col-sm-6"><label class="form-label">Starts</label><input wire:model="form.starts_at" type="date" class="form-control"></div>
            <div class="col-sm-6">
                <label class="form-label">Ends</label>
                <input wire:model="form.expires_at" type="date" class="form-control @error('form.expires_at') is-invalid @enderror">
                @error('form.expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12"><div class="form-check form-switch"><input wire:model="form.is_active" class="form-check-input" type="checkbox" id="cp-active"><label class="form-check-label" for="cp-active">Active</label></div></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'coupon' })">Cancel</button>
            <button type="submit" form="coupon-form" class="btn btn-primary">Save coupon</button>
        </x-slot:footer>
    </x-modal>
</div>
