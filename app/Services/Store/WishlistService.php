<?php

namespace App\Services\Store;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;

/**
 * Saved products. Signed-in customers keep them in the database; guests in
 * the session, merged into their account when they sign in.
 */
class WishlistService
{
    protected ?array $ids = null;

    protected function customer(): ?Customer
    {
        return auth('customer')->user();
    }

    protected function key(): string
    {
        return 'store.wishlist.'.tenant_id();
    }

    /** @return array<int> product ids */
    public function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }

        $customer = $this->customer();

        return $this->ids = $customer
            ? Wishlist::query()->where('customer_id', $customer->id)->latest()->pluck('product_id')->all()
            : array_values(array_map('intval', (array) session($this->key(), [])));
    }

    public function has(int $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /** Add or remove; returns true when the product is now saved. */
    public function toggle(int $productId): bool
    {
        Product::query()->online()->findOrFail($productId);
        $customer = $this->customer();
        $saved = ! $this->has($productId);

        if ($customer) {
            $saved
                ? Wishlist::query()->firstOrCreate(['customer_id' => $customer->id, 'product_id' => $productId])
                : Wishlist::query()->where('customer_id', $customer->id)->where('product_id', $productId)->delete();
        } else {
            $ids = array_diff($this->ids(), [$productId]);
            session([$this->key() => $saved ? array_merge([$productId], $ids) : array_values($ids)]);
        }

        $this->ids = null;

        return $saved;
    }

    /** Move the guest list into the customer's account after sign-in. */
    public function mergeGuestList(Customer $customer): void
    {
        $guest = array_map('intval', (array) session($this->key(), []));

        foreach ($guest as $productId) {
            Wishlist::query()->firstOrCreate(['customer_id' => $customer->id, 'product_id' => $productId]);
        }

        session()->forget($this->key());
        $this->ids = null;
    }
}
