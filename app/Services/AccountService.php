<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\FundTransfer;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Posts money movements to payment accounts (cash drawers, banks, wallets).
 *
 * Every posting locks the account row, updates current_balance and writes a
 * Transaction with the resulting balance_after, so statements always add up.
 */
class AccountService
{
    /** Money IN (sale, customer payment, opening balance). */
    public function credit(int $accountId, float $amount, string $referenceType, ?int $referenceId = null, ?string $note = null, ?int $branchId = null): Transaction
    {
        return $this->post(Transaction::CREDIT, $accountId, $amount, $referenceType, $referenceId, $note, $branchId);
    }

    /** Money OUT (purchase, vendor payment, expense, refund). */
    public function debit(int $accountId, float $amount, string $referenceType, ?int $referenceId = null, ?string $note = null, ?int $branchId = null): Transaction
    {
        return $this->post(Transaction::DEBIT, $accountId, $amount, $referenceType, $referenceId, $note, $branchId);
    }

    public function transferFunds(int $fromId, int $toId, float $amount, string $date, ?string $note = null): FundTransfer
    {
        if ($fromId === $toId) {
            throw new BusinessException('Choose two different accounts.');
        }

        return DB::transaction(function () use ($fromId, $toId, $amount, $date, $note) {
            $transfer = FundTransfer::create([
                'from_account_id' => $fromId,
                'to_account_id' => $toId,
                'amount' => round_money($amount),
                'transfer_date' => $date,
                'note' => $note,
                'created_by' => auth('web')->id(),
            ]);

            // Lock in a stable order to avoid deadlocks between opposite transfers.
            PaymentAccount::query()->whereIn('id', [$fromId, $toId])->orderBy('id')->lockForUpdate()->get();

            $this->debit($fromId, $amount, 'fund_transfer', $transfer->id, $note ?: 'Fund transfer out');
            $this->credit($toId, $amount, 'fund_transfer', $transfer->id, $note ?: 'Fund transfer in');

            return $transfer;
        }, 3);
    }

    /** Manually correct an account to a counted balance (cash count, bank reconciliation). */
    public function adjustTo(int $accountId, float $countedBalance, ?string $note = null): ?Transaction
    {
        return DB::transaction(function () use ($accountId, $countedBalance, $note) {
            $account = PaymentAccount::query()->lockForUpdate()->findOrFail($accountId);
            $difference = round_money($countedBalance - (float) $account->current_balance);

            if (abs($difference) < 0.01) {
                return null;
            }

            return $difference > 0
                ? $this->credit($accountId, $difference, 'adjustment', null, $note ?: 'Balance adjustment')
                : $this->post(Transaction::DEBIT, $accountId, abs($difference), 'adjustment', null, $note ?: 'Balance adjustment', null, true);
        }, 3);
    }

    protected function post(string $type, int $accountId, float $amount, string $referenceType, ?int $referenceId, ?string $note, ?int $branchId, bool $allowNegative = false): Transaction
    {
        $amount = round_money($amount);

        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($type, $accountId, $amount, $referenceType, $referenceId, $note, $branchId, $allowNegative) {
            $account = PaymentAccount::query()->lockForUpdate()->find($accountId)
                ?? throw new BusinessException('Payment account not found.');

            if ($account->status !== 'active') {
                throw new BusinessException("{$account->account_name} is inactive.");
            }

            $balance = (float) $account->current_balance;
            $newBalance = round_money($type === Transaction::CREDIT ? $balance + $amount : $balance - $amount);

            $negativeAllowed = $allowNegative || (bool) tenant()?->setting('allow_negative_balance', false);

            if ($type === Transaction::DEBIT && $newBalance < 0 && ! $negativeAllowed) {
                throw new BusinessException(sprintf(
                    'Insufficient balance in %s. Available: %s, required: %s.',
                    $account->account_name, money($balance), money($amount)
                ));
            }

            $account->forceFill(['current_balance' => $newBalance])->save();

            return Transaction::create([
                'tenant_id' => $account->tenant_id,
                'branch_id' => $branchId ?? $account->branch_id,
                'payment_account_id' => $account->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'note' => $note,
                'created_by' => auth('web')->id(),
            ]);
        }, 3);
    }
}
