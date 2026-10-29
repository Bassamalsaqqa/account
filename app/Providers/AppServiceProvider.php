<?php

namespace App\Providers;

use App\Application\AI\AiProviderDeadline;
use App\Application\Platform\TenantWriteAccess;
use App\Http\Middleware\SetCompanyContext;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Policies\CompanyPolicy;
use App\Policies\CompanyUserPolicy;
use App\Policies\RolePolicy;
use App\Services\Audit\AuditService;
use App\Services\Inventory\StockEventScope;
use App\Services\Money\MoneyEventScope;
use App\Services\Phase7\Phase7EventScope;
use App\Services\Purchasing\PurchasePostingScope;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use App\Services\Purchasing\VendorPaymentApplicationScope;
use App\Services\Purchasing\VendorPaymentPostingScope;
use App\Services\Purchasing\VendorPaymentReversalScope;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->scoped(TenantWriteAccess::class, fn () => new TenantWriteAccess);
        $this->app->scoped(AiProviderDeadline::class, fn () => new AiProviderDeadline);
        $this->app->singleton(CompanyContext::class, fn () => new CompanyContext);
        $this->app->singleton(AuditService::class, fn () => new AuditService);
        $this->app->scoped(PurchasePostingScope::class, fn () => new PurchasePostingScope);
        $this->app->scoped(PurchaseReturnPostingScope::class, fn () => new PurchaseReturnPostingScope);
        $this->app->scoped(VendorPaymentPostingScope::class, fn () => new VendorPaymentPostingScope);
        $this->app->scoped(VendorPaymentApplicationScope::class, fn () => new VendorPaymentApplicationScope);
        $this->app->scoped(VendorPaymentReversalScope::class, fn () => new VendorPaymentReversalScope);
        $this->app->scoped(StockEventScope::class, fn () => new StockEventScope);
        $this->app->scoped(MoneyEventScope::class, fn () => new MoneyEventScope);
        $this->app->scoped(Phase7EventScope::class, fn () => new Phase7EventScope);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(TransactionCommitting::class,
            static fn ($event) => app(TenantWriteAccess::class)->committing($event));
        RateLimiter::for('platform', static fn ($request) => Limit::perMinute(30)->by('platform:'.$request->user()->id));
        RateLimiter::for('platform-provision', static fn ($request) => Limit::perMinute(5)->by('platform-provision:'.$request->user()->id));

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
