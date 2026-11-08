<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\PaymentAccount;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * POS checkout. Prices sent by the client are re-validated and totals are
 * recomputed server side; stock, accounts and the customer ledger are
 * updated atomically.
 */
class SaleService
{
    public function __construct(
        protected AccountService $accounts,
        protected InventoryService $inventory,
        protected LedgerService $ledger,
    ) {}

    /**
     * @param  array{
     *   branch_id:int, customer_id?:int|null, sale_date?:string|null, notes?:string|null,
     *   items: array<int, array{variant_id:int, quantity:int, unit_price:float, discount?:float}>,
     *   discount?:float, tax_rate?:float, shipping_fee?:float, channel?:string,
     *   payments?: array<int, array{payment_account_id:int, amount:float, reference_no?:string|null}>,
     *   exchange_return_id?:int|null
     * }  $data
     */
    public function create(array $data): Sale
    {
        $lines = collect($data['items'] ?? [])->filter(fn ($l) => (int) ($l['quantity'] ?? 0) > 0)->values();

        if ($lines->isEmpty()) {
            throw new BusinessException('The cart is empty.');
        }

        return DB::transaction(function () use ($data, $lines) {
            $branchId = (int) $data['branch_id'];
            $customer = ! empty($data['customer_id']) ? Customer::query()->findOrFail($data['customer_id']) : null;

            if ($customer && $customer->status !== 'active') {
                throw new BusinessException("{$customer->name}'s account is inactive.");
            }

            $variants = ProductVariant::query()->with('product')->whereIn('id', $lines->pluck('variant_id'))->get()->keyBy('id');
            $totals = $this->calculate($lines, $variants, (float) ($data['discount'] ?? 0), (float) ($data['tax_rate'] ?? 0), (float) ($data['shipping_fee'] ?? 0));

            // Exchange credit from a pending return (POS exchange flow).
            $exchange = null;
            $exchangeApplied = 0.0;

            if (! empty($data['exchange_return_id'])) {
                $exchange = SaleReturn::query()->lockForUpdate()->findOrFail($data['exchange_return_id']);

                if (! $exchange->isPendingExchange()) {
                    throw new BusinessException("Return {$exchange->return_number} has already been settled.");
                }

                $exchangeApplied = min($exchange->exchange_credit, $totals['total']);
            }

            $payments = collect($data['payments'] ?? [])->filter(fn ($p) => round_money($p['amount'] ?? 0) > 0)->values();
            $accounts = PaymentAccount::query()->whereIn('id', $payments->pluck('payment_account_id'))->get()->keyBy('id');
            $moneyPaid = round_money($payments->sum('amount'));
            $paid = round_money($moneyPaid + $exchangeApplied);
            $total = $totals['total'];

            if ($paid - $total > 0.009) {
                throw new BusinessException('Payments ('.money($paid).') exceed the bill ('.money($total).'). Enter the net amount; change is calculated at the till.');
            }

            $due = round_money($total - $paid);

            if ($due > 0.009 && ! $customer) {
                throw new BusinessException('Walk-in customers must pay in full. Select a registered customer to sell on credit.');
            }

            $sale = Sale::create([
                'branch_id' => $branchId,
                'customer_id' => $customer?->id,
                'invoice_number' => DocumentNumber::next('sale'),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'shipping_fee' => $totals['shipping'],
                'total_amount' => $total,
                'paid_amount' => $paid,
                'due_amount' => max(0, $due),
                'payment_status' => Sale::statusFor($total, $paid),
                'payment_method' => $this->deriveMethod($payments, $accounts, $exchangeApplied > 0),
                'channel' => $data['channel'] ?? 'pos',
                'sale_date' => $data['sale_date'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth('web')->id(),
            ]);

            foreach ($totals['lines'] as $line) {
                $sale->items()->create([...$line, 'tenant_id' => $sale->tenant_id]);
                $this->inventory->adjust($branchId, $line['product_variant_id'], -$line['quantity'], 'sale', 'sale', $sale->id, (float) $line['cost_price'], 'Sale '.$sale->invoice_number, movedAt: $sale->sale_date);
            }

            // Customer ledger: full invoice first, then each settlement.
            if ($customer) {
                $this->ledger->postCustomer($customer->id, 'sale', $total, 0, [
                    'sale_id' => $sale->id,
                    'reference_no' => $sale->invoice_number,
                    'note' => 'Sale invoice '.$sale->invoice_number,
                    'transaction_date' => $sale->sale_date->toDateString(),
                ]);
            }

            foreach ($payments as $payment) {
                $account = $accounts->get($payment['payment_account_id']) ?? throw new BusinessException('Payment account not found.');
                $this->recordPayment($sale, $account, (float) $payment['amount'], $payment['reference_no'] ?? null);
            }

            if ($exchange) {
                $this->applyExchange($sale, $exchange, $exchangeApplied);
            }

            return $sale->fresh(['items.variant.product', 'items.variant.color', 'items.variant.size', 'payments.paymentAccount', 'customer', 'branch', 'creator']);
        }, 3);
    }

    /**
     * Receive a payment against a customer's balance; allocated FIFO to the
     * oldest unpaid invoices. Any excess is kept as customer advance.
     */
    public function receivePayment(Customer $customer, int $accountId, float $amount, ?string $date = null, ?string $reference = null, ?string $note = null): CustomerLedger
    {
        $amount = round_money($amount);

        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($customer, $accountId, $amount, $date, $reference, $note) {
            $date ??= today()->toDateString();
            $account = PaymentAccount::query()->findOrFail($accountId);

            $entry = $this->ledger->postCustomer($customer->id, 'payment', 0, $amount, [
                'payment_account_id' => $account->id,
                'reference_no' => $reference,
                'note' => $note ?: 'Payment received via '.$account->account_name,
                'transaction_date' => $date,
            ]);

            $this->accounts->credit($account->id, $amount, 'customer_payment', $entry->id, 'Payment from '.$customer->name);

            $remaining = $amount;
            $openInvoices = Sale::query()
                ->where('customer_id', $customer->id)
                ->where('due_amount', '>', 0)
                ->orderBy('sale_date')->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($openInvoices as $sale) {
                if ($remaining <= 0) {
                    break;
                }

                $allocated = min($remaining, (float) $sale->due_amount);

                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_account_id' => $account->id,
                    'customer_ledger_id' => $entry->id,
                    'amount' => $allocated,
                    'payment_method' => $account->paymentMethod(),
                    'reference_no' => $reference,
                    'payment_date' => $date,
                    'created_by' => auth('web')->id(),
                ]);

                $paid = round_money((float) $sale->paid_amount + $allocated);
                $sale->forceFill([
                    'paid_amount' => $paid,
                    'due_amount' => max(0, round_money((float) $sale->due_amount - $allocated)),
                    'payment_status' => Sale::statusFor((float) $sale->total_amount - (float) $sale->returned_amount, $paid),
                ])->save();

                $remaining = round_money($remaining - $allocated);
            }

            return $entry;
        }, 3);
    }

    /**
     * Record a payment against one specific invoice (e.g. cash collected on
     * delivery of an online order), rather than FIFO across the account.
     */
    public function settleInvoice(Sale $sale, int $accountId, float $amount, ?string $reference = null, ?string $note = null): SalePayment
    {
        return DB::transaction(function () use ($sale, $accountId, $amount, $reference, $note) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $amount = round_money(min($amount, (float) $sale->due_amount));

            if ($amount <= 0) {
                throw new BusinessException("Invoice {$sale->invoice_number} has nothing left to pay.");
            }

            $account = PaymentAccount::query()->findOrFail($accountId);
            $date = today()->toDateString();

            $this->accounts->credit($account->id, $amount, 'sale', $sale->id, $note ?: 'Payment for '.$sale->invoice_number, $sale->branch_id);

            $entry = $sale->customer_id
                ? $this->ledger->postCustomer($sale->customer_id, 'payment', 0, $amount, [
                    'sale_id' => $sale->id,
                    'payment_account_id' => $account->id,
                    'reference_no' => $reference ?: $sale->invoice_number,
                    'note' => $note ?: 'Payment via '.$account->account_name,
                    'transaction_date' => $date,
                ])
                : null;

            $payment = SalePayment::create([
                'tenant_id' => $sale->tenant_id,
                'sale_id' => $sale->id,
                'payment_account_id' => $account->id,
                'customer_ledger_id' => $entry?->id,
                'amount' => $amount,
                'payment_method' => $account->paymentMethod(),
                'reference_no' => $reference,
                'payment_date' => $date,
                'created_by' => auth('web')->id(),
            ]);

            $paid = round_money((float) $sale->paid_amount + $amount);
            $sale->forceFill([
                'paid_amount' => $paid,
                'due_amount' => max(0, round_money((float) $sale->due_amount - $amount)),
                'payment_status' => Sale::statusFor((float) $sale->total_amount - (float) $sale->returned_amount, $paid),
            ])->save();

            return $payment;
        }, 3);
    }

    /**
     * Drop the delivery charge from an invoice (order cancelled or returned by
     * the courier). Unpaid charges are cleared from the customer's account;
     * paid charges are refunded from $refundAccountId.
     */
    public function waiveShipping(Sale $sale, ?int $refundAccountId = null, string $note = 'Delivery charge waived'): void
    {
        DB::transaction(function () use ($sale, $refundAccountId, $note) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $fee = round_money((float) $sale->shipping_fee);

            if ($fee <= 0) {
                return;
            }

            $fromDue = min($fee, (float) $sale->due_amount);
            $refund = round_money($fee - $fromDue);
            $attributes = ['sale_id' => $sale->id, 'reference_no' => $sale->invoice_number];

            if ($sale->customer_id) {
                $this->ledger->postCustomer($sale->customer_id, 'adjustment', 0, $fee, $attributes + ['note' => $note]);
            }

            if ($refund > 0) {
                $account = PaymentAccount::query()->find($refundAccountId) ?? throw new BusinessException('Choose the account to refund the delivery charge from.');
                $this->accounts->debit($account->id, $refund, 'sale_return', $sale->id, $note.' ('.$sale->invoice_number.')', $sale->branch_id);

                if ($sale->customer_id) {
                    $this->ledger->postCustomer($sale->customer_id, 'refund', $refund, 0, $attributes + ['payment_account_id' => $account->id, 'note' => 'Delivery charge refunded via '.$account->account_name]);
                }
            }

            $total = round_money((float) $sale->total_amount - $fee);
            $paid = round_money((float) $sale->paid_amount - $refund);
            $sale->forceFill([
                'shipping_fee' => 0,
                'total_amount' => $total,
                'paid_amount' => max(0, $paid),
                'due_amount' => max(0, round_money((float) $sale->due_amount - $fromDue)),
                'payment_status' => Sale::statusFor($total - (float) $sale->returned_amount, max(0, $paid)),
            ])->save();
        }, 3);
    }

    /**
     * Recompute every amount server side.
     *
     * @return array{lines: array, subtotal: float, discount: float, tax: float, shipping: float, total: float}
     */
    public function calculate(Collection $lines, Collection $variants, float $discount, float $taxRate, float $shipping = 0): array
    {
        $rows = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $variant = $variants->get($line['variant_id']) ?? throw new BusinessException('An item in the cart no longer exists.');
            $quantity = (int) $line['quantity'];
            $unitPrice = round_money($line['unit_price'] ?? $variant->retail_price);

            if ($unitPrice < 0) {
                throw new BusinessException("Invalid price for {$variant->label}.");
            }

            $gross = round_money($quantity * $unitPrice);
            $lineDiscount = min($gross, max(0, round_money($line['discount'] ?? 0)));
            $lineTotal = round_money($gross - $lineDiscount);

            $rows[] = [
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'cost_price' => $variant->cost_price,
                'discount' => $lineDiscount,
                'total_price' => $lineTotal,
            ];

            $subtotal += $lineTotal;
        }

        $subtotal = round_money($subtotal);
        $discount = min($subtotal, max(0, round_money($discount)));
        $taxRate = max(0, min(100, $taxRate));
        $tax = round_money(($subtotal - $discount) * $taxRate / 100);
        $shipping = max(0, round_money($shipping));

        return [
            'lines' => $rows,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'shipping' => $shipping,
            'total' => round_money($subtotal - $discount + $tax + $shipping),
        ];
    }

    /* ------------------------------------------------------------------ */

    protected function recordPayment(Sale $sale, PaymentAccount $account, float $amount, ?string $reference): SalePayment
    {
        $this->accounts->credit($account->id, $amount, 'sale', $sale->id, 'Sale '.$sale->invoice_number, $sale->branch_id);

        $ledgerEntry = $sale->customer_id
            ? $this->ledger->postCustomer($sale->customer_id, 'payment', 0, $amount, [
                'sale_id' => $sale->id,
                'payment_account_id' => $account->id,
                'reference_no' => $reference ?: $sale->invoice_number,
                'note' => 'Paid at POS via '.$account->account_name,
                'transaction_date' => $sale->sale_date->toDateString(),
            ])
            : null;

        return SalePayment::create([
            'tenant_id' => $sale->tenant_id,
            'sale_id' => $sale->id,
            'payment_account_id' => $account->id,
            'customer_ledger_id' => $ledgerEntry?->id,
            'amount' => round_money($amount),
            'payment_method' => $account->paymentMethod(),
            'reference_no' => $reference,
            'payment_date' => $sale->sale_date,
            'created_by' => auth('web')->id(),
        ]);
    }

    protected function applyExchange(Sale $sale, SaleReturn $exchange, float $applied): void
    {
        $credit = $exchange->exchange_credit;
        $customerId = $sale->customer_id ?? $exchange->customer_id;

        $ledgerEntry = $sale->customer_id
            ? $this->ledger->postCustomer($sale->customer_id, 'return', 0, $applied, [
                'sale_id' => $sale->id,
                'sale_return_id' => $exchange->id,
                'reference_no' => $exchange->return_number,
                'note' => 'Exchange credit from '.$exchange->return_number,
                'transaction_date' => $sale->sale_date->toDateString(),
            ])
            : null;

        if ($applied > 0) {
            SalePayment::create([
                'tenant_id' => $sale->tenant_id,
                'sale_id' => $sale->id,
                'payment_account_id' => null,
                'customer_ledger_id' => $ledgerEntry?->id,
                'amount' => round_money($applied),
                'payment_method' => 'exchange',
                'reference_no' => $exchange->return_number,
                'payment_date' => $sale->sale_date,
                'created_by' => auth('web')->id(),
            ]);
        }

        // Exchange credit larger than the new bill: hand back the difference in cash.
        $leftover = round_money($credit - $applied);
        $refunded = 0.0;

        if ($leftover > 0) {
            $drawer = PaymentAccount::query()
                ->where('branch_id', $sale->branch_id)
                ->where('account_type', PaymentAccount::TYPE_CASH)
                ->where('is_default', true)
                ->firstOrFail();

            $this->accounts->debit($drawer->id, $leftover, 'sale_return', $exchange->id, "Exchange balance refund {$exchange->return_number}", $sale->branch_id);
            $refunded = $leftover;
        }

        $exchange->forceFill([
            'status' => 'completed',
            'exchange_sale_id' => $sale->id,
            'customer_id' => $customerId,
            'refunded_amount' => round_money((float) $exchange->refunded_amount + $refunded),
        ])->save();
    }

    protected function deriveMethod(Collection $payments, Collection $accounts, bool $hasExchange): string
    {
        $kinds = $payments->map(fn ($p) => $accounts->get($p['payment_account_id']))
            ->filter()
            ->map(fn (PaymentAccount $a) => $a->isCash() ? 'cash' : 'bank')
            ->unique();

        if ($hasExchange) {
            $kinds->push('exchange');
        }

        return match ($kinds->count()) {
            0 => 'credit',
            1 => $kinds->first(),
            default => 'split',
        };
    }
}
