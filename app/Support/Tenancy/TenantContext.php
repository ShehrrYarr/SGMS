<?php

namespace App\Support\Tenancy;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;

/**
 * Request-scoped holder for the active shop (tenant) and branch.
 *
 * Bound as a "scoped" singleton so it is reset between requests / queue jobs
 * (safe for Octane). Middleware populates it; models read from it through
 * the TenantScope global scope.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    protected ?User $user = null;

    /** Selected branch. null = "All branches" (owners only). */
    protected ?int $branchId = null;

    protected ?Collection $branches = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->branches = null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->user = null;
        $this->branchId = null;
        $this->branches = null;
    }

    /**
     * Run a callback as a given tenant (seeders, jobs, super admin actions),
     * restoring the previous context afterwards.
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = [$this->tenant, $this->user, $this->branchId, $this->branches];
        $this->set($tenant);

        try {
            return $callback($tenant);
        } finally {
            [$this->tenant, $this->user, $this->branchId, $this->branches] = $previous;
        }
    }

    /* ---------------------------------------------------------------------
     | Staff / branch awareness
     * ------------------------------------------------------------------- */

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function setBranchId(?int $branchId): void
    {
        $this->branchId = $branchId;
    }

    /** The selected branch, or null when an owner views "All branches". */
    public function branchId(): ?int
    {
        return $this->branchId;
    }

    /** Branches of the current tenant (cached for the request). */
    public function branches(): Collection
    {
        return $this->branches ??= $this->tenant
            ? Branch::query()->where('tenant_id', $this->tenant->id)->orderByDesc('is_main')->orderBy('name')->get()
            : collect();
    }

    /** Branch ids the current staff user may access. */
    public function allowedBranchIds(): array
    {
        if (! $this->user || $this->user->isOwner()) {
            return $this->branches()->pluck('id')->all();
        }

        return array_filter([$this->user->branch_id]);
    }

    /**
     * Branch ids that listings should be filtered by:
     * the selected branch, otherwise every branch the user may access.
     */
    public function branchScopeIds(): array
    {
        return $this->branchId ? [$this->branchId] : $this->allowedBranchIds();
    }

    /**
     * A concrete branch for operations (POS, purchase, expense) when the
     * user has "All branches" selected: falls back to the main branch.
     */
    public function operationalBranchId(): ?int
    {
        if ($this->branchId) {
            return $this->branchId;
        }

        $allowed = $this->allowedBranchIds();
        $main = $this->branches()->firstWhere('is_main', true);

        return ($main && in_array($main->id, $allowed, true)) ? $main->id : ($allowed[0] ?? null);
    }

    public function canAccessBranch(?int $branchId): bool
    {
        return $branchId !== null && in_array($branchId, $this->allowedBranchIds(), true);
    }

    /** Tenant-isolated cache key. */
    public function cacheKey(string $key): string
    {
        return 'tenant:'.($this->id() ?? 'platform').':'.$key;
    }
}
