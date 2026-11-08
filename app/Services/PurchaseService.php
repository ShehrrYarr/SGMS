<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lot intake: receives garments into a branch, updates weighted average
 * costs, posts the vendor ledger and settles the payment split.
 */
class PurchaseService
{
    public function __construct(
        protected AccountService $accounts,
        protected InventoryService $inventory,
        protected LedgerService $ledger,
        protected ProductService $products,
    ) {}

    /**
     * @param  array{
     *   branch_id:int, vendor_id:int, lot_number?:string|null, lot_name?:string|null,
     *   purchase_date:string, total_lot_cost:float, notes?:string|null,
     *   items: array<int, array{product_id:int, color_id?:int|null, size_id?:int|null, barcode?:string|null,
     *          quantity:int, cost_per_item:float, retail_price:float, wholesale_price?:float|null}>,
     *   payments?: array<int, array{payment_account_id:int, amount:float, reference_no?:string|null}>
     * }  $data
     */
    public function create(array $data): Purchase
    {
        $items = collect($data['items'] ?? [])->filter(fn ($row) => (int) ($row['quantity'] ?? 0) > 0)->values();
        $payments = collect($data['payments'] ?? [])->filter(fn ($p) => round_money($p['amount'] ?? 0) > 0)->values();

        if ($items->isEmpty()) {
            throw new BusinessException('Add at least one garment item to the lot.');
        }

        $total = round_money($data['total_lot_cost']);
        $paid = round_money($payments->sum('amount'));

        if ($total <= 0) {
            throw new BusinessException('Total lot cost must be greater than zero.');
        }

        if ($paid - $total > 0.009) {
            throw new BusinessException('Payments ('.money($paid).') exceed the lot cost ('.money($total).').');
        }

        return DB::transaction(function () use ($data, $items, $payments, $total, $paid) {
            $vendor = Vendor::query()->findOrFail($data['vendor_id']);
            $date = $data['purchase_date'] ?? today()->toDateString();
            $lotNumber = trim((string) ($data['lot_number'] ?? '')) ?: DocumentNumber::next('purchase', 5);

            if (Purchase::query()->where('lot_number', $lotNumber)->exists()) {
                throw new BusinessException("Lot number {$lotNumber} already exists.");
            }

            $purchase = Purchase::create([
                'branch_id' => $data['branch_id'],
                'vendor_id' => $vendor->id,
                'lot_number' => $lotNumber,
                'lot_name' => $data['lot_name'] ?? null,
                'total_lot_cost' => $total,
                'total_garment_qty' => (int) $items->sum('quantity'),
                'payment_status' => 'unpaid',
                'payment_method' => 'credit',
                'paid_amount' => 0,
                'due_amount' => $total,
                'purchase_date' => $date,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth('web')->id(),
            ]);

            $this->receiveItems($purchase, $items);

            // 1) The full invoice is owed to the vendor...
            $this->ledger->postVendor($vendor->id, 'purchase', 0, $total, [
                'purchase_id' => $purchase->id,
                'reference_no' => $lotNumber,
                'note' => 'Lot purchase '.$lotNumber.($purchase->lot_name ? " ({$purchase->lot_name})" : ''),
                'transaction_date' => $date,
            ]);

            // 2) ...then each payment leg reduces it; the remainder stays as vendor credit.
            $accounts = PaymentAccount::query()->whereIn('id', $payments->pluck('payment_account_id'))->get()->keyBy('id');

            foreach ($payments as $payment) {
                $account = $accounts->get($payment['payment_account_id']) ?? throw new BusinessException('Payment account not found.');
                $this->payPurchase($purchase, $account, (float) $payment['amount'], $date, $payment['reference_no'] ?? null);
            }

            $purchase->forceFill([
                'paid_amount' => $paid,
                'due_amount' => round_money($total - $paid),
                'payment_status' => Purchase::statusFor($total, $paid),
                'payment_method' => $this->deriveMethod($payments->map(fn ($p) => $accounts->get($p['payment_account_id']))),
            ])->save();

            return $purchase->fresh(['vendor', 'items.variant', 'payments']);
        }, 3);
    }

    /** Pay (part of) the outstanding balance of a specific lot. */
    public function addPayment(Purchase $purchase, int $accountId, float $amount, ?string $date = null, ?string $reference = null, ?string $note = null): PurchasePayment
    {
        return DB::transaction(function () use ($purchase, $accountId, $amount, $date, $reference, $note) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $amount = round_money($amount);

            if ($amount <= 0 || $amount - (float) $purchase->due_amount > 0.009) {
                throw new BusinessException('Amount must be between 0 and the due amount ('.money($purchase->due_amount).').');
            }

            $account = PaymentAccount::query()->findOrFail($accountId);
            $payment = $this->payPurchase($purchase, $account, $amount, $date ?? today()->toDateString(), $reference, $note);
            $this->applyToPurchase($purchase, $amount, $account);

            return $payment;
        }, 3);
    }

    /**
     * Pay a vendor from the ledger page. The amount is allocated FIFO to the
     * oldest unpaid lots; anything left becomes an advance.
     */
    public function payVendor(Vendor $vendor, int $accountId, float $amount, ?string $date = null, ?string $reference = null, ?string $note = null): VendorLedger
    {
        $amount = round_money($amount);

        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($vendor, $accountId, $amount, $date, $reference, $note) {
            $date ??= today()->toDateString();
            $account = PaymentAccount::query()->findOrFail($accountId);

            $entry = $this->ledger->postVendor($vendor->id, 'payment', $amount, 0, [
                'payment_account_id' => $account->id,
                'reference_no' => $reference,
                'note' => $note ?: 'Payment via '.$account->account_name,
                'transaction_date' => $date,
            ]);

            $this->accounts->debit($account->id, $amount, 'vendor_payment', $entry->id, 'Payment to '.$vendor->name);

            $remaining = $amount;
            $openLots = Purchase::query()
                ->where('vendor_id', $vendor->id)
                ->where('due_amount', '>', 0)
                ->orderBy('purchase_date')->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($openLots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $allocated = min($remaining, (float) $lot->due_amount);

                PurchasePayment::create([
                    'purchase_id' => $lot->id,
                    'payment_account_id' => $account->id,
                    'vendor_ledger_id' => $entry->id,
                    'amount' => $allocated,
                    'payment_method' => $account->paymentMethod(),
                    'reference_no' => $reference,
                    'payment_date' => $date,
                    'notes' => 'Allocated from vendor payment',
                    'created_by' => auth('web')->id(),
                ]);

                $this->applyToPurchase($lot, $allocated, $account);
                $remaining = round_money($remaining - $allocated);
            }

            return $entry;
        }, 3);
    }

    /* ------------------------------------------------------------------ */

    protected function receiveItems(Purchase $purchase, Collection $items): void
    {
        $productIds = $items->pluck('product_id')->unique();
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($items as $row) {
            $product = $products->get($row['product_id']) ?? throw new BusinessException('Selected product no longer exists.');
            $quantity = (int) $row['quantity'];
            $cost = round_money($row['cost_per_item']);
            $retail = round_money($row['retail_price'] ?? 0);

            $variant = $this->products->resolveVariant($product, $row['color_id'] ?? null, $row['size_id'] ?? null, [
                'barcode' => $row['barcode'] ?? null,
                'cost_price' => $cost,
                'retail_price' => $retail,
                'wholesale_price' => $row['wholesale_price'] ?? 0,
            ]);

            // Weighted average cost across all branches.
            $onHand = max(0, $this->inventory->totalStock($variant->id));
            $averageCost = $onHand > 0
                ? round_money((($onHand * (float) $variant->cost_price) + ($quantity * $cost)) / ($onHand + $quantity))
                : $cost;

            $variant->forceFill([
                'cost_price' => $averageCost,
                'retail_price' => $retail > 0 ? $retail : $variant->retail_price,
                'wholesale_price' => ($row['wholesale_price'] ?? 0) > 0 ? round_money($row['wholesale_price']) : $variant->wholesale_price,
            ])->save();

            $purchase->items()->create([
                'tenant_id' => $purchase->tenant_id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'cost_per_item' => $cost,
                'retail_price_set' => $retail,
                'total_cost' => round_money($quantity * $cost),
            ]);

            $this->inventory->adjust($purchase->branch_id, $variant->id, $quantity, 'purchase', 'purchase', $purchase->id, $cost, 'Lot '.$purchase->lot_number, movedAt: business_time($purchase->purchase_date));
        }
    }

    protected function payPurchase(Purchase $purchase, PaymentAccount $account, float $amount, string $date, ?string $reference = null, ?string $note = null): PurchasePayment
    {
        $vendorName = $purchase->vendor?->name ?? 'vendor';

        $this->accounts->debit($account->id, $amount, 'purchase', $purchase->id, "Lot {$purchase->lot_number} payment to {$vendorName}", $purchase->branch_id);

        $entry = $this->ledger->postVendor($purchase->vendor_id, 'payment', $amount, 0, [
            'purchase_id' => $purchase->id,
            'payment_account_id' => $account->id,
            'reference_no' => $reference ?: $purchase->lot_number,
            'note' => $note ?: 'Paid via '.$account->account_name,
            'transaction_date' => $date,
        ]);

        return PurchasePayment::create([
            'tenant_id' => $purchase->tenant_id,
            'purchase_id' => $purchase->id,
            'payment_account_id' => $account->id,
            'vendor_ledger_id' => $entry->id,
            'amount' => round_money($amount),
            'payment_method' => $account->paymentMethod(),
            'reference_no' => $reference,
            'payment_date' => $date,
            'notes' => $note,
            'created_by' => auth('web')->id(),
        ]);
    }

    protected function applyToPurchase(Purchase $purchase, float $amount, PaymentAccount $account): void
    {
        $paid = round_money((float) $purchase->paid_amount + $amount);
        $kind = $account->isCash() ? 'cash' : 'bank';
        $method = match (true) {
            $purchase->payment_method === 'credit', (float) $purchase->paid_amount <= 0 => $kind,
            $purchase->payment_method === $kind => $kind,
            default => 'split',
        };

        $purchase->forceFill([
            'paid_amount' => $paid,
            'due_amount' => round_money((float) $purchase->total_lot_cost - $paid),
            'payment_status' => Purchase::statusFor((float) $purchase->total_lot_cost, $paid),
            'payment_method' => $method,
        ])->save();
    }

    /** cash | bank | split | credit from the accounts used. */
    public function deriveMethod(Collection $accounts): string
    {
        $kinds = $accounts->filter()->map(fn (PaymentAccount $a) => $a->isCash() ? 'cash' : 'bank')->unique()->values();

        return match ($kinds->count()) {
            0 => 'credit',
            1 => $kinds->first(),
            default => 'split',
        };
    }
}
