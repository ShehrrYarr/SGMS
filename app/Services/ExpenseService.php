<?php

namespace App\Services;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(protected AccountService $accounts) {}

    public function create(array $data): Expense
    {
        return DB::transaction(function () use ($data) {
            $expense = Expense::create([
                'branch_id' => $data['branch_id'],
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'payment_account_id' => $data['payment_account_id'],
                'amount' => round_money($data['amount']),
                'expense_date' => $data['expense_date'] ?? today()->toDateString(),
                'reference_no' => $data['reference_no'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => auth('web')->id(),
            ]);

            $label = $expense->category?->name ?? 'Expense';
            $this->accounts->debit($expense->payment_account_id, (float) $expense->amount, 'expense', $expense->id, $label.($expense->note ? ': '.$expense->note : ''), $expense->branch_id);

            return $expense;
        }, 3);
    }

    /** Delete an expense and return the money to its account. */
    public function delete(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            $this->accounts->credit($expense->payment_account_id, (float) $expense->amount, 'expense', $expense->id, 'Reversal of deleted expense #'.$expense->id, $expense->branch_id);
            $expense->delete();
        }, 3);
    }
}
