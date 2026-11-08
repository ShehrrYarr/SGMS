<?php

namespace App\Services\Store;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Session cart of the current shopper, one per shop. Stores only
 * variant ids and quantities; prices and stock are always read live.
 */
class Cart
{
    public const MAX_QTY = 20;

    protected ?Collection $lines = null;

    protected function key(string $name = 'items'): string
    {
        return "store.{$name}.".tenant_id();
    }

    /** @return array<int,int> variant_id => quantity */
    public function raw(): array
    {
        return array_map('intval', (array) session($this->key(), []));
    }

    public function count(): int
    {
        return array_sum($this->raw());
    }

    public function isEmpty(): bool
    {
        return $this->raw() === [];
    }

    /** Add units of a variant, capped at what is available online. Returns the new line quantity. */
    public function add(int $variantId, int $quantity = 1): int
    {
        $variant = $this->findVariant($variantId);
        $items = $this->raw();
        $wanted = ($items[$variantId] ?? 0) + max(1, $quantity);
        $available = (int) $variant->available;

        if ($available <= 0) {
            throw new BusinessException("{$variant->label} is sold out.");
        }

        if (($items[$variantId] ?? 0) >= min($available, self::MAX_QTY)) {
            throw new BusinessException("You already have all {$available} available of {$variant->label} in your bag.");
        }

        $items[$variantId] = min($wanted, $available, self::MAX_QTY);
        $this->store($items);

        return $items[$variantId];
    }

    public function update(int $variantId, int $quantity): int
    {
        $items = $this->raw();

        if ($quantity <= 0) {
            unset($items[$variantId]);
            $this->store($items);

            return 0;
        }

        $variant = $this->findVariant($variantId);
        $items[$variantId] = max(1, min($quantity, max(1, (int) $variant->available), self::MAX_QTY));
        $this->store($items);

        return $items[$variantId];
    }

    public function remove(int $variantId): void
    {
        $this->update($variantId, 0);
    }

    public function clear(): void
    {
        session()->forget([$this->key(), $this->key('coupon')]);
        $this->lines = null;
    }

    /**
     * Cart lines with live product data. Lines whose product was removed
     * from the store are dropped automatically.
     *
     * @return Collection<int, array{variant: ProductVariant, quantity: int, available: int, unit_price: float, total: float, image: ?string}>
     */
    public function lines(): Collection
    {
        if ($this->lines !== null) {
            return $this->lines;
        }

        $items = $this->raw();

        if (! $items) {
            return $this->lines = collect();
        }

        $variants = ProductVariant::query()
            ->withOnlineStock()
            ->whereIn('product_variants.id', array_keys($items))
            ->whereHas('product', fn ($q) => $q->online())
            ->with(['product.colorImages', 'color:id,name,hex_code', 'size:id,name,code'])
            ->get()
            ->keyBy('id');

        if ($variants->count() !== count($items)) {
            $this->store(array_intersect_key($items, $variants->all()));
        }

        return $this->lines = $variants->map(function (ProductVariant $variant) use ($items) {
            $quantity = $items[$variant->id];
            $price = (float) $variant->retail_price;
            $image = $variant->product->colorImages->firstWhere('color_id', $variant->color_id)?->url ?? $variant->product->image_url;

            return [
                'variant' => $variant,
                'quantity' => $quantity,
                'available' => max(0, (int) $variant->available),
                'unit_price' => $price,
                'compare_at' => (float) $variant->product->compare_at_price > $price ? (float) $variant->product->compare_at_price : null,
                'total' => round_money($price * $quantity),
                'image' => $image,
            ];
        })->values();
    }

    public function subtotal(): float
    {
        return round_money($this->lines()->sum('total'));
    }

    /* Coupons ------------------------------------------------------------ */

    public function applyCoupon(string $code): Coupon
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first()
            ?? throw new BusinessException('That code is not valid. Check the spelling and try again.');

        if ($problem = $coupon->problemFor($this->subtotal())) {
            throw new BusinessException($problem);
        }

        session([$this->key('coupon') => $coupon->code]);

        return $coupon;
    }

    public function removeCoupon(): void
    {
        session()->forget($this->key('coupon'));
    }

    public function couponCode(): ?string
    {
        return session($this->key('coupon'));
    }

    /**
     * Totals for display. The order service recomputes them when the order is placed.
     *
     * @return array{subtotal: float, discount: float, coupon: ?Coupon, coupon_error: ?string, delivery: float, free_delivery_over: ?float, free_delivery_gap: float, tax_rate: float, tax: float, total: float}
     */
    public function summary(): array
    {
        $settings = tenant()->store();
        $subtotal = $this->subtotal();
        $coupon = null;
        $couponError = null;
        $discount = 0.0;

        if ($code = $this->couponCode()) {
            $coupon = Coupon::query()->where('code', $code)->first();
            $couponError = $coupon ? $coupon->problemFor($subtotal) : 'That code is no longer valid.';

            if ($couponError === null) {
                $discount = $coupon->discountFor($subtotal);
            }
        }

        $value = round_money($subtotal - $discount);
        $delivery = $this->isEmpty() ? 0.0 : $settings->deliveryFeeFor($value);
        $taxRate = (float) tenant()->tax_rate;
        $tax = round_money($value * $taxRate / 100);
        $threshold = $settings->freeDeliveryOver();

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon' => $couponError === null ? $coupon : null,
            'coupon_error' => $couponError,
            'delivery' => $delivery,
            'free_delivery_over' => $threshold,
            'free_delivery_gap' => $threshold !== null && $delivery > 0 ? round_money($threshold - $value) : 0.0,
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'total' => round_money($value + $tax + $delivery),
        ];
    }

    /* ------------------------------------------------------------------ */

    protected function findVariant(int $variantId): ProductVariant
    {
        return ProductVariant::query()
            ->withOnlineStock()
            ->whereHas('product', fn ($q) => $q->online())
            ->with(['product', 'color', 'size'])
            ->find($variantId)
            ?? throw new BusinessException('This item is no longer available.');
    }

    protected function store(array $items): void
    {
        session([$this->key() => array_filter($items, fn ($q) => $q > 0)]);
        $this->lines = null;
    }
}
