<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Branch awareness for branch-owned records. Unlike tenancy this is an
 * explicit local scope, because owners legitimately see every branch.
 */
trait HasBranchScope
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Filter to the selected branch, or to all branches the user may access. */
    public function scopeInBranchScope(Builder $query, string $column = 'branch_id'): Builder
    {
        return $query->whereIn($this->qualifyColumn($column), app(TenantContext::class)->branchScopeIds());
    }
}
