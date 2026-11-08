<?php

namespace App\Services\Store;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\ProductVariant;
use App\Services\DocumentNumber;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Online order lifecycle:
 *
 *   pending   — placed by the shopper; units reserved against combined stock
 *   confirmed — staff pick the shipping branch; a sale invoice is created there
 *               (stock out, customer ledger, any verified payment)
 *   shipped   — handed to the courier
 *   delivered — cash on delivery can be collected into an account
 *   cancelled — before confirmation nothing is posted; after it the goods are
 *               returned to stock and the delivery charge is waived/refunded
 */
class OrderService
{
    public function __construct(
        protected Cart $cart,
        protected SaleService $sales,
        protected SaleReturnService $returns,
    ) {}

    /**
     * @param  array{name:string, phone:string, email?:?string, address:string, city:string, notes?:?string,
     *   payment_method:string, payment_account_id?:?int, payment_reference?:?string, password?:?string}  $data
     */
    public function place(array $data, ?Customer $customer = null, ?UploadedFile $proof = null): Order
    {
        $lines = $this->cart->lines();

        if ($lines->isEmpty()) {
            throw new BusinessException('Your bag is empty.');
        }

        $methods = tenant()->store()->paymentMethods();
        $method = $data['payment_method'] ?? '';

        if (! isset($methods[$method])) {
            throw new BusinessException('Choose a payment method.');
        }

        $accountId = null;

        if ($method !== 'cod') {
            $accountId = (int) ($data['payment_account_id'] ?? 0);

            if (! $methods[$method]['accounts']->contains('id', $accountId)) {
                throw new BusinessException('Choose the account you paid into.');
            }
        }

        $proofPath = $proof?->store('payments/'.tenant_id(), 'public');

        try {
            $order = DB::transaction(function () use ($data, $customer, $lines, $method, $accountId, $proofPath) {
                $quantities = $lines->mapWithKeys(fn ($line) => [$line['variant']->id => $line['quantity']])->all();

                // Serialise checkouts of the same items, then re-read live availability.
                ProductVariant::query()->whereIn('id', array_keys($quantities))->lockForUpdate()->get(['id']);
                $variants = ProductVariant::query()->withOnlineStock()->with(['product', 'color', 'size'])
                    ->whereIn('product_variants.id', array_keys($quantities))->get()->keyBy('id');

                $subtotal = 0.0;
                $items = [];

                foreach ($quantities as $variantId => $quantity) {
                    $variant = $variants->get($variantId) ?? throw new BusinessException('An item in your bag is no longer available.');
                    $available = max(0, (int) $variant->available);

                    if ($quantity > $available) {
                        throw new BusinessException($available > 0
                            ? "Only {$available} left of {$variant->label}. Update your bag to continue."
                            : "{$variant->label} has just sold out. Remove it from your bag to continue.");
                    }

                    $price = round_money($variant->retail_price);
                    $items[] = [
                        'product_variant_id' => $variant->id,
                        'product_name' => $variant->product->name,
                        'variant_name' => $variant->variant_name,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'total' => round_money($price * $quantity),
                    ];
                    $subtotal += $price * $quantity;
                }

                $subtotal = round_money($subtotal);
                [$coupon, $discount] = $this->lockCoupon($subtotal);

                $settings = tenant()->store();
                $value = round_money($subtotal - $discount);
                $taxRate = (float) tenant()->tax_rate;
                $tax = round_money($value * $taxRate / 100);
                $delivery = $settings->deliveryFeeFor($value);

                $customer = $this->resolveCustomer($data, $customer);

                $order = Order::create([
                    'order_number' => DocumentNumber::next('order'),
                    'customer_id' => $customer->id,
                    'status' => 'pending',
                    'payment_method' => $method,
                    'payment_account_id' => $accountId,
                    'payment_status' => $method === 'cod' ? 'unpaid' : ($proofPath ? 'awaiting' : 'unpaid'),
                    'payment_proof' => $proofPath,
                    'payment_reference' => $data['payment_reference'] ?? null,
                    'customer_name' => trim($data['name']),
                    'phone' => normalize_phone($data['phone']),
                    'email' => $data['email'] ?? null,
                    'address' => trim($data['address']),
                    'city' => trim($data['city']),
                    'notes' => $data['notes'] ?? null,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'coupon_id' => $coupon?->id,
                    'coupon_code' => $coupon?->code,
                    'tax_rate' => $taxRate,
                    'tax' => $tax,
                    'shipping_fee' => $delivery,
                    'total' => round_money($value + $tax + $delivery),
                ]);

                foreach ($items as $item) {
                    $order->items()->create($item + ['tenant_id' => $order->tenant_id]);
                }

                $coupon?->increment('used_count');

                return $order;
            }, 3);
        } catch (\Throwable $e) {
            if ($proofPath) {
                Storage::disk('public')->delete($proofPath);
            }

            throw $e;
        }

        $this->cart->clear();
        session()->push('store.orders.'.tenant_id(), $order->id);

        return $order;
    }

    /** Shopper uploads (or replaces) a payment receipt after ordering. */
    public function attachProof(Order $order, UploadedFile $proof, ?string $reference = null): Order
    {
        if (! $order->isPrepaid() || in_array($order->payment_status, ['paid', 'refunded'], true) || $order->status === 'cancelled') {
            throw new BusinessException('This order does not need a payment receipt.');
        }

        $old = $order->payment_proof;
        $order->forceFill([
            'payment_proof' => $proof->store('payments/'.tenant_id(), 'public'),
            'payment_reference' => $reference ?: $order->payment_reference,
            'payment_status' => 'awaiting',
        ])->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $order;
    }

    /** Create the sale invoice in the shipping branch. */
    public function confirm(Order $order, int $branchId): Order
    {
        return DB::transaction(function () use ($order, $branchId) {
            $order = Order::query()->lockForUpdate()->with('items')->findOrFail($order->id);

            if ($order->status !== 'pending') {
                throw new BusinessException("Order {$order->order_number} is already {$order->status_label}.");
            }

            if (! tenancy()->canAccessBranch($branchId)) {
                throw new BusinessException('You cannot ship from that branch.');
            }

            $paid = $order->payment_status === 'paid' && $order->payment_account_id;

            $sale = $this->sales->create([
                'branch_id' => $branchId,
                'customer_id' => $order->customer_id,
                'sale_date' => now(),
                'channel' => 'online',
                'notes' => 'Online order '.$order->order_number,
                'discount' => (float) $order->discount,
                'tax_rate' => (float) $order->tax_rate,
                'shipping_fee' => (float) $order->shipping_fee,
                'items' => $order->items->map(fn ($item) => [
                    'variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ])->all(),
                'payments' => $paid ? [[
                    'payment_account_id' => $order->payment_account_id,
                    'amount' => (float) $order->total,
                    'reference_no' => $order->payment_reference ?: $order->order_number,
                ]] : [],
            ]);

            if (abs((float) $sale->total_amount - (float) $order->total) > 0.009) {
                throw new BusinessException('Order and invoice totals differ ('.money($order->total).' vs '.money($sale->total_amount).'). Check the shop tax rate.');
            }

            $order->forceFill([
                'status' => 'confirmed',
                'sale_id' => $sale->id,
                'branch_id' => $branchId,
                'confirmed_at' => now(),
                'handled_by' => auth('web')->id(),
            ])->save();

            return $order;
        }, 3);
    }

    /** Staff checked the bank/wallet transfer. */
    public function markPaid(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->isPrepaid() || ! $order->payment_account_id) {
                throw new BusinessException('Cash on delivery is collected when the order is delivered.');
            }

            if ($order->payment_status === 'paid' || $order->status === 'cancelled') {
                throw new BusinessException('Nothing to verify on this order.');
            }

            if ($order->sale && (float) $order->sale->due_amount > 0) {
                $this->sales->settleInvoice($order->sale, $order->payment_account_id, (float) $order->sale->due_amount, $order->payment_reference ?: $order->order_number, 'Online payment '.$order->order_number);
            }

            $order->forceFill(['payment_status' => 'paid', 'handled_by' => auth('web')->id()])->save();

            return $order;
        }, 3);
    }

    public function ship(Order $order, ?string $courier, ?string $tracking): Order
    {
        if ($order->status !== 'confirmed') {
            throw new BusinessException('Confirm the order before marking it shipped.');
        }

        $order->forceFill([
            'status' => 'shipped',
            'courier' => $courier ?: null,
            'tracking_number' => $tracking ?: null,
            'shipped_at' => now(),
            'handled_by' => auth('web')->id(),
        ])->save();

        return $order;
    }

    /** @param  int|null  $collectInto  account receiving the cash collected on delivery */
    public function deliver(Order $order, ?int $collectInto = null): Order
    {
        return DB::transaction(function () use ($order, $collectInto) {
            $order = Order::query()->lockForUpdate()->with('sale')->findOrFail($order->id);

            if (! in_array($order->status, ['confirmed', 'shipped'], true)) {
                throw new BusinessException('Only confirmed or shipped orders can be marked delivered.');
            }

            if ($collectInto && $order->sale && (float) $order->sale->due_amount > 0) {
                $this->sales->settleInvoice($order->sale, $collectInto, (float) $order->sale->due_amount, $order->order_number, 'Cash on delivery '.$order->order_number);
            }

            $due = (float) ($order->sale?->fresh()->due_amount ?? 0);

            $order->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'shipped_at' => $order->shipped_at ?? now(),
                'payment_status' => $due <= 0 ? 'paid' : $order->payment_status,
                'handled_by' => auth('web')->id(),
            ])->save();

            return $order;
        }, 3);
    }

    /**
     * Cancel an order (or record a courier return). Confirmed orders are
     * reversed: stock back to the branch, invoice cleared, payments refunded
     * from $refundAccountId (defaults to the account the customer paid into).
     */
    public function cancel(Order $order, string $reason, ?int $refundAccountId = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $refundAccountId) {
            $order = Order::query()->lockForUpdate()->with('sale.items')->findOrFail($order->id);

            if (! $order->isOpen()) {
                throw new BusinessException($order->status === 'delivered'
                    ? 'Delivered orders are returned from the invoice (Sales › Returns).'
                    : 'This order is already cancelled.');
            }

            $refunded = false;

            if ($order->sale) {
                $sale = $order->sale;
                $refundAccountId ??= $order->payment_account_id;
                $refundAccount = $refundAccountId ? PaymentAccount::query()->find($refundAccountId) : null;
                $paid = (float) $sale->paid_amount > 0;

                if ($paid && ! $refundAccount) {
                    throw new BusinessException('Choose the account to refund the customer from.');
                }

                $quantities = $sale->items->mapWithKeys(fn ($item) => [$item->id => $item->returnable_quantity])->filter()->all();

                if ($quantities) {
                    $this->returns->create($sale, $quantities, $paid ? $refundAccount->paymentMethod() : 'ledger', $refundAccount?->id, 'Online order '.$order->order_number.' cancelled: '.$reason);
                }

                $this->sales->waiveShipping($sale, $refundAccount?->id, 'Delivery charge cancelled ('.$order->order_number.')');
                $refunded = $paid;
            }

            if ($order->coupon_id) {
                Coupon::query()->whereKey($order->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            }

            $order->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'payment_status' => $refunded ? 'refunded' : ($order->payment_status === 'paid' && ! $order->sale ? 'refunded' : $order->payment_status),
                'handled_by' => auth('web')->id(),
            ])->save();

            return $order;
        }, 3);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0: ?Coupon, 1: float} */
    protected function lockCoupon(float $subtotal): array
    {
        $code = $this->cart->couponCode();

        if (! $code) {
            return [null, 0.0];
        }

        $coupon = Coupon::query()->where('code', $code)->lockForUpdate()->first();

        if (! $coupon || ($problem = $coupon->problemFor($subtotal))) {
            $this->cart->removeCoupon();

            throw new BusinessException('Your discount code was removed: '.lcfirst($problem ?? 'it is no longer valid.').' Review your total and place the order again.');
        }

        return [$coupon, $coupon->discountFor($subtotal)];
    }

    /**
     * The signed-in customer, or the shop customer with this phone number,
     * or a new customer. Guest details never overwrite an existing record.
     */
    protected function resolveCustomer(array $data, ?Customer $customer): Customer
    {
        $phone = normalize_phone($data['phone']);

        if (strlen($phone) < 10) {
            throw new BusinessException('Enter a valid mobile number, e.g. 0300 1234567.');
        }

        $customer ??= Customer::query()->where('phone', $phone)->first();

        if ($customer) {
            if ($customer->status !== 'active') {
                throw new BusinessException('We could not place this order. Please contact the shop.');
            }

            return $customer;
        }

        $email = trim((string) ($data['email'] ?? '')) ?: null;

        if ($email && Customer::query()->where('email', $email)->exists()) {
            $email = null; // belongs to another customer; keep it on the order only
        }

        return Customer::create([
            'name' => trim($data['name']),
            'phone' => $phone,
            'email' => $email,
            'address' => trim($data['address'].', '.$data['city'], ', '),
            'password' => ! empty($data['password']) ? $data['password'] : null,
        ]);
    }
}
