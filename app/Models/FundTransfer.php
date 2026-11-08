<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundTransfer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'from_account_id', 'to_account_id', 'amount', 'transfer_date', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transfer_date' => 'date'];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'to_account_id');
    }
}
