<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accounts receivable ledger. debit = customer owes more, credit = owes less.
 */
class CustomerLedger extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPES = [
        'opening_balance' => 'Opening Balance',
        'sale' => 'Sale Invoice',
        'payment' => 'Payment Received',
        'return' => 'Return Credit',
        'refund' => 'Refund',
        'adjustment' => 'Adjustment',
    ];

    protected $fillable = [
        'tenant_id', 'customer_id', 'sale_id', 'sale_return_id', 'type', 'debit', 'credit',
        'running_balance', 'payment_account_id', 'reference_no', 'note', 'transaction_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
