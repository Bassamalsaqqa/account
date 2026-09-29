<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyContext
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
        if (! auth()->check()) {
            return redirect()->guest(route('login'));
        }

        if (! $this->context->hasCompany()) {
            /** @var User $user */
            $user = auth()->user();

            $activeCount = $user->activeCompanies()->where('companies.status', 'active')->count();

            if ($activeCount === 0) {
                if ($request->routeIs('companies.setup', 'logout', 'locale.switch')) {
                    return $next($request);
                }

                return redirect()->route('companies.setup');
            }

            // Multiple companies require explicit selection
            if ($request->routeIs('companies.select', 'company.switch', 'logout', 'locale.switch')) {
                return $next($request);
            }

            return redirect()->route('companies.select');
        }

        return $next($request);
    }
}
