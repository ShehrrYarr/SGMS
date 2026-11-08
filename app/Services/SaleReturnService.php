<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\PaymentAccount;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;

/**
 * Returns & exchanges against an invoice.
 *
 * The value of returned goods first clears any unpaid due on the invoice;
 * the remainder is refunded (cash/bank/wallet), credited to the customer
 * account (ledger) or held as exchange credit for a new POS sale.
 */
class SaleReturnService
{
    public function __construct(
        protected AccountService $accounts,
        protected InventoryService $inventory,
        protected LedgerService $ledger,
    ) {}

    /**
     * @param  array<int,int>  $quantities  sale_item_id => quantity to return
     */
    public function create(Sale $sale, array $quantities, string $method, ?int $accountId = null, ?string $reason = null, bool $restock = true): SaleReturn
    {
        $quantities = array_filter(array_map('intval', $quantities), fn ($q) => $q > 0);

        if (! $quantities) {
            throw new BusinessException('Select at least one item to return.');
        }

        if (! array_key_exists($method, SaleReturn::METHODS)) {
            throw new BusinessException('Invalid refund method.');
        }

        if ($method === 'ledger' && ! $sale->customer_id) {
            throw new BusinessException('Account credit is only available for registered customers.');
        }

        return DB::transaction(function () use ($sale, $quantities, $method, $accountId, $reason, $restock) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $items = SaleItem::query()->where('sale_id', $sale->id)->whereIn('id', array_keys($quantities))->lockForUpdate()->get()->keyBy('id');

            // Spread invoice-level discount and tax proportionally over the lines (delivery charges excluded).
            $ratio = (float) $sale->subtotal > 0 ? ((float) $sale->total_amount - (float) $sale->shipping_fee) / (float) $sale->subtotal : 0;
            $lines = [];
            $total = 0.0;

            foreach ($quantities as $itemId => $quantity) {
                $item = $items->get($itemId) ?? throw new BusinessException('Item does not belong to this invoice.');

                if ($quantity > $item->returnable_quantity) {
                    throw new BusinessException("Only {$item->returnable_quantity} unit(s) of an item can still be returned.");
                }

                $unit = round_money($item->net_unit_price * $ratio);
                $lineTotal = round_money($unit * $quantity);
                $lines[] = compact('item', 'quantity', 'unit', 'lineTotal');
                $total += $lineTotal;
            }

            $total = round_money($total);
            $adjusted = round_money(min((float) $sale->due_amount, $total));
            $remaining = round_money($total - $adjusted);

            $account = null;

            if (in_array($method, ['cash', 'bank', 'wallet'], true) && $remaining > 0) {
                $account = PaymentAccount::query()->find($accountId) ?? throw new BusinessException('Choose the account to refund from.');
            }

            $return = SaleReturn::create([
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'return_number' => DocumentNumber::next('return'),
                'total_amount' => $total,
                'refund_method' => $method,
                'payment_account_id' => $account?->id,
                'adjusted_amount' => $adjusted,
                'refunded_amount' => 0,
                'status' => $method === 'exchange' && $remaining > 0 ? 'pending_exchange' : 'completed',
                'reason' => $reason,
                'return_date' => now(),
                'created_by' => auth('web')->id(),
            ]);

            foreach ($lines as $line) {
                $return->items()->create([
                    'tenant_id' => $return->tenant_id,
                    'sale_item_id' => $line['item']->id,
                    'product_variant_id' => $line['item']->product_variant_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit'],
                    'total_price' => $line['lineTotal'],
                    'restock' => $restock,
                ]);

                $line['item']->increment('returned_quantity', $line['quantity']);

                if ($restock) {
                    $this->inventory->adjust($sale->branch_id, $line['item']->product_variant_id, $line['quantity'], 'sale_return', 'sale_return', $return->id, (float) $line['item']->cost_price, 'Return '.$return->return_number);
                }
            }

            // 1) Clear the unpaid part of the invoice.
            if ($adjusted > 0 && $sale->customer_id) {
                $this->ledger->postCustomer($sale->customer_id, 'return', 0, $adjusted, [
                    'sale_id' => $sale->id,
                    'sale_return_id' => $return->id,
                    'reference_no' => $return->return_number,
                    'note' => 'Returned goods against '.$sale->invoice_number,
                ]);
            }

            // 2) Settle the remainder.
            $this->settle($return, $sale, $method, $remaining, $account);

            $netTotal = round_money((float) $sale->total_amount - (float) $sale->returned_amount - $total);
            $sale->forceFill([
                'returned_amount' => round_money((float) $sale->returned_amount + $total),
                'due_amount' => round_money(max(0, (float) $sale->due_amount - $adjusted)),
                'payment_status' => Sale::statusFor(max(0, $netTotal), (float) $sale->paid_amount),
            ])->save();

            return $return->fresh(['items.variant.product', 'sale', 'customer']);
        }, 3);
    }

    /** Turn an unused exchange credit into a refund or account credit. */
    public function settlePendingExchange(SaleReturn $return, string $method, ?int $accountId = null): SaleReturn
    {
        return DB::transaction(function () use ($return, $method, $accountId) {
            $return = SaleReturn::query()->lockForUpdate()->findOrFail($return->id);

            if (! $return->isPendingExchange()) {
                throw new BusinessException('This return is already settled.');
            }

            if (! in_array($method, ['cash', 'bank', 'wallet', 'ledger'], true)) {
                throw new BusinessException('Invalid refund method.');
            }

            if ($method === 'ledger' && ! $return->customer_id) {
                throw new BusinessException('Account credit is only available for registered customers.');
            }

            $account = $method === 'ledger' ? null : (PaymentAccount::query()->find($accountId) ?? throw new BusinessException('Choose the account to refund from.'));

            $this->settle($return, $return->sale, $method, $return->exchange_credit, $account);
            $return->forceFill(['status' => 'completed', 'refund_method' => $method, 'payment_account_id' => $account?->id])->save();

            return $return;
        }, 3);
    }

    protected function settle(SaleReturn $return, Sale $sale, string $method, float $amount, ?PaymentAccount $account): void
    {
        if ($amount <= 0 || $method === 'exchange') {
            return;
        }

        $attributes = [
            'sale_id' => $sale->id,
            'sale_return_id' => $return->id,
            'reference_no' => $return->return_number,
        ];

        if ($method === 'ledger') {
            $this->ledger->postCustomer($return->customer_id, 'return', 0, $amount, $attributes + ['note' => 'Return credited to account']);

            return;
        }

        $this->accounts->debit($account->id, $amount, 'sale_return', $return->id, 'Refund '.$return->return_number.' ('.$sale->invoice_number.')', $sale->branch_id);

        if ($return->customer_id) {
            // Show both sides on the customer statement: goods returned, money refunded.
            $this->ledger->postCustomer($return->customer_id, 'return', 0, $amount, $attributes + ['note' => 'Returned goods']);
            $this->ledger->postCustomer($return->customer_id, 'refund', $amount, 0, $attributes + ['payment_account_id' => $account->id, 'note' => 'Refunded via '.$account->account_name]);
        }

        $return->forceFill(['refunded_amount' => round_money((float) $return->refunded_amount + $amount)])->save();
    }
}
