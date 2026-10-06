<?php

namespace App\Providers;

use App\Http\Middleware\SetCompanyContext;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Policies\CompanyPolicy;
use App\Policies\CompanyUserPolicy;
use App\Policies\RolePolicy;
use App\Services\Audit\AuditService;
use App\Services\Purchasing\PurchasePostingScope;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use App\Services\Purchasing\VendorPaymentApplicationScope;
use App\Services\Purchasing\VendorPaymentPostingScope;
use App\Services\Purchasing\VendorPaymentReversalScope;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CompanyContext::class, fn () => new CompanyContext);
        $this->app->singleton(AuditService::class, fn () => new AuditService);
        $this->app->scoped(PurchasePostingScope::class, fn () => new PurchasePostingScope);
        $this->app->scoped(PurchaseReturnPostingScope::class, fn () => new PurchaseReturnPostingScope);
        $this->app->scoped(VendorPaymentPostingScope::class, fn () => new VendorPaymentPostingScope);
        $this->app->scoped(VendorPaymentApplicationScope::class, fn () => new VendorPaymentApplicationScope);
        $this->app->scoped(VendorPaymentReversalScope::class, fn () => new VendorPaymentReversalScope);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production') || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Register Policies
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(CompanyUser::class, CompanyUserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        // Register Livewire persistent middleware for tenancy
        Livewire::addPersistentMiddleware([
            SetCompanyContext::class,
        ]);
    }
}
