<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCompanyContext
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
        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();

            $company = $this->context->resolveForUser($user);

            if ($company) {
                $this->context->setCompany($company, $user);
            } else {
                $this->context->clear();
            }
        } else {
            $this->context->clear();
        }

        return $next($request);
    }
}
