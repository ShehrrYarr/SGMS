<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\FlushesTenantCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use BelongsToTenant, FlushesTenantCache;

    protected $fillable = ['tenant_id', 'name'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
