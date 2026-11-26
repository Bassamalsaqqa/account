<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Diagnostics\RequestId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (RequestId::isApiBoundaryRequest($request)) {
            return $next($request);
        }

        $requestId = RequestId::forRequest($request);
        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $requestId);

        return $response;
    }
}
