<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Illuminate\Support\Facades\DB;

/**
 * Running-balance ledgers for vendors (payable) and customers (receivable).
 * The party row is locked so concurrent postings never corrupt the balance.
 */
class LedgerService
{
    /**
     * Vendor ledger: credit = we owe more, debit = we owe less.
     */
    public function postVendor(int $vendorId, string $type, float $debit, float $credit, array $attributes = []): VendorLedger
    {
        return DB::transaction(function () use ($vendorId, $type, $debit, $credit, $attributes) {
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $balance = round_money((float) $vendor->current_balance + $credit - $debit);
            $vendor->forceFill(['current_balance' => $balance])->save();

            return VendorLedger::create([
                ...$attributes,
                'tenant_id' => $vendor->tenant_id,
                'vendor_id' => $vendor->id,
                'type' => $type,
                'debit' => round_money($debit),
                'credit' => round_money($credit),
                'running_balance' => $balance,
                'transaction_date' => $attributes['transaction_date'] ?? today(),
                'created_by' => auth('web')->id(),
            ]);
        }, 3);
    }

    /**
     * Customer ledger: debit = customer owes more, credit = customer owes less.
     */
    public function postCustomer(int $customerId, string $type, float $debit, float $credit, array $attributes = []): CustomerLedger
    {
        return DB::transaction(function () use ($customerId, $type, $debit, $credit, $attributes) {
            $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($customerId);
            $balance = round_money((float) $customer->current_balance + $debit - $credit);
            $customer->forceFill(['current_balance' => $balance])->save();

            return CustomerLedger::create([
                ...$attributes,
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'type' => $type,
                'debit' => round_money($debit),
                'credit' => round_money($credit),
                'running_balance' => $balance,
                'transaction_date' => $attributes['transaction_date'] ?? today(),
                'created_by' => auth('web')->id(),
            ]);
        }, 3);
    }

    /** Post an opening balance for a new vendor (positive = we owe them). */
    public function openVendor(Vendor $vendor, float $amount): void
    {
        if (abs($amount) < 0.01) {
            return;
        }

        $amount > 0
            ? $this->postVendor($vendor->id, 'opening_balance', 0, $amount, ['note' => 'Opening balance'])
            : $this->postVendor($vendor->id, 'opening_balance', abs($amount), 0, ['note' => 'Opening balance (advance)']);
    }

    /** Post an opening balance for a new customer (positive = they owe us). */
    public function openCustomer(Customer $customer, float $amount): void
    {
        if (abs($amount) < 0.01) {
            return;
        }

        $amount > 0
            ? $this->postCustomer($customer->id, 'opening_balance', $amount, 0, ['note' => 'Opening balance'])
            : $this->postCustomer($customer->id, 'opening_balance', 0, abs($amount), ['note' => 'Opening balance (advance)']);
    }
}
