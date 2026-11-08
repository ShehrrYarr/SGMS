<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the active tenant.
 * When no tenant is active (super admin, console) the scope is a no-op.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($tenantId = app(TenantContext::class)->id()) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
    }
}
