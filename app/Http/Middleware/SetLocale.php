<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(
        protected CompanyContext $context
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $preferredLocale = session('locale');

        if (! $preferredLocale && auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            $preferredLocale = $user->locale;
        }

        if (! $preferredLocale) {
            $preferredLocale = config('app.locale', 'ar');
        }

        if (! in_array($preferredLocale, ['ar', 'en'], true)) {
            $preferredLocale = 'ar';
        }

        $effectiveLocale = $preferredLocale;

        if ($this->context->hasCompany()) {
            $company = $this->context->company();

            if ($effectiveLocale === 'en' && ! $company->isLanguageEnabled('en')) {
                $effectiveLocale = $company->default_locale ?: 'ar';
            }
        }

        app()->setLocale($effectiveLocale);

        return $next($request);
    }
}
