<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runtime release gate, independent of the already-deployed V1 read boundary. */
final class MobileMerchantBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mobile.merchant_api_enabled', false)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MOBILE_MERCHANT_DISABLED',
                message: 'The requested resource is unavailable.',
                status: 404,
            );
        }

        return $next($request);
    }
}
