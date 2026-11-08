<?php

namespace App\Providers;

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\ResolvePortalTenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request / job (Octane & queue safe).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Re-apply tenant + role middleware on every Livewire update request.
        Livewire::addPersistentMiddleware([
            ResolvePortalTenant::class,
            IdentifyTenant::class,
            EnsureRole::class,
        ]);

        // Surface N+1 queries during development without breaking pages.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            Log::warning(sprintf('N+1 query: lazy loading [%s] on [%s].', $relation, $model::class));
        });

        Gate::define('manage', fn (User $user) => $user->canManage());
        Gate::define('owner', fn (User $user) => $user->isOwner());
    }
}
